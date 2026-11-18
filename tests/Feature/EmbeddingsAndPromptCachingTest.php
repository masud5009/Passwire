<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\ProviderAccount;
use App\Services\Gateway\Adapters\AnthropicAdapter;
use App\Services\Gateway\Adapters\GeminiAdapter;
use App\Services\Gateway\CostCalculatorService;
use App\Services\Gateway\PromptCompressor;
use App\Services\Gateway\ResponseCacheService;
use App\Services\Gateway\TokenizerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingsAndPromptCachingTest extends TestCase
{
    use DatabaseTransactions;

    protected GatewayKey $gatewayKey;
    protected GatewayKey $secondGatewayKey;
    protected AiProvider $provider;
    protected ProviderAccount $account;
    protected ResponseCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cacheService = new ResponseCacheService();

        $this->gatewayKey = GatewayKey::create([
            'name' => 'Embeddings Test Key 1',
            'key' => 'sk-pw-embed-test-1-' . bin2hex(random_bytes(6)),
            'is_active' => true,
            'daily_token_limit' => 100000,
            'tokens_used_today' => 0,
            'usage_reset_date' => now()->toDateString(),
        ]);

        $this->secondGatewayKey = GatewayKey::create([
            'name' => 'Embeddings Test Key 2',
            'key' => 'sk-pw-embed-test-2-' . bin2hex(random_bytes(6)),
            'is_active' => true,
            'daily_token_limit' => 100000,
            'tokens_used_today' => 0,
            'usage_reset_date' => now()->toDateString(),
        ]);

        $this->provider = AiProvider::create([
            'name' => 'Mock OpenAI Embed Provider',
            'slug' => 'mock-openai',
            'base_url' => 'https://api.mockai.com/v1',
            'format' => 'openai',
            'models_catalog' => ['text-embedding-3-small', 'text-embedding-3-large'],
            'is_active' => true,
        ]);

        $this->account = ProviderAccount::create([
            'ai_provider_id' => $this->provider->id,
            'label' => 'Mock Embed Account',
            'api_key' => 'sk-mock-embed-key',
            'priority' => 1,
            'is_active' => true,
        ]);
    }

    public function test_embeddings_cache_miss_stores_and_hit_replays_without_upstream_call(): void
    {
        $mockVector = array_fill(0, 16, 0.05);

        Http::fake([
            'https://api.mockai.com/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [
                    [
                        'object' => 'embedding',
                        'index' => 0,
                        'embedding' => $mockVector,
                    ]
                ],
                'model' => 'text-embedding-3-small',
                'usage' => [
                    'prompt_tokens' => 12,
                    'total_tokens' => 12,
                ],
            ], 200),
        ]);

        $payload = [
            'model' => 'text-embedding-3-small',
            'input' => 'The quick brown fox jumps over the lazy dog',
        ];

        // 1. Initial request (Cache MISS)
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $this->gatewayKey->key)
            ->postJson('/v1/embeddings', $payload);

        $res1->assertStatus(200);
        $res1->assertHeader('X-Passwire-Cache', 'MISS');
        $this->assertEquals($mockVector, $res1->json('data.0.embedding'));
        Http::assertSentCount(1);

        // 2. Second request with identical payload (Cache HIT)
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $this->gatewayKey->key)
            ->postJson('/v1/embeddings', $payload);

        $res2->assertStatus(200);
        $res2->assertHeader('X-Passwire-Cache', 'HIT');
        $res2->assertHeader('X-Passwire-Saved-Tokens', '12');
        $this->assertEquals($mockVector, $res2->json('data.0.embedding'));

        // Upstream was NOT invoked again
        Http::assertSentCount(1);
    }

    public function test_embeddings_cache_supports_array_input_and_dimension_isolation(): void
    {
        $vector1 = array_fill(0, 8, 0.1);
        $vector2 = array_fill(0, 8, 0.2);

        Http::fake([
            'https://api.mockai.com/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [
                    ['object' => 'embedding', 'index' => 0, 'embedding' => $vector1],
                    ['object' => 'embedding', 'index' => 1, 'embedding' => $vector2],
                ],
                'model' => 'text-embedding-3-small',
                'usage' => ['prompt_tokens' => 20, 'total_tokens' => 20],
            ], 200),
        ]);

        $payloadArray = [
            'model' => 'text-embedding-3-small',
            'input' => ['Vector test query A', 'Vector test query B'],
            'dimensions' => 8,
        ];

        $res1 = $this->withHeader('Authorization', 'Bearer ' . $this->gatewayKey->key)
            ->postJson('/v1/embeddings', $payloadArray);

        $res1->assertStatus(200);
        $res1->assertHeader('X-Passwire-Cache', 'MISS');
        $this->assertCount(2, $res1->json('data'));
        Http::assertSentCount(1);

        // Cache hit on identical array input and dimensions
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $this->gatewayKey->key)
            ->postJson('/v1/embeddings', $payloadArray);

        $res2->assertStatus(200);
        $res2->assertHeader('X-Passwire-Cache', 'HIT');
        Http::assertSentCount(1);

        // Different dimensions (e.g. 16 instead of 8) must NOT hit cache
        $payloadDiffDimensions = $payloadArray;
        $payloadDiffDimensions['dimensions'] = 16;

        $key1 = $this->cacheService->generateEmbeddingKey($payloadArray, $this->gatewayKey);
        $key2 = $this->cacheService->generateEmbeddingKey($payloadDiffDimensions, $this->gatewayKey);
        $this->assertNotEquals($key1, $key2);
    }

    public function test_embeddings_cache_respects_tenant_isolation(): void
    {
        $payload = [
            'model' => 'text-embedding-3-small',
            'input' => 'Isolated multi-tenant embedding input',
        ];

        $key1 = $this->cacheService->generateEmbeddingKey($payload, $this->gatewayKey);
        $key2 = $this->cacheService->generateEmbeddingKey($payload, $this->secondGatewayKey);

        $this->assertNotEquals($key1, $key2);
        $this->assertStringStartsWith('omni_embed_cache:', $key1);
        $this->assertStringStartsWith('omni_embed_cache:', $key2);
    }

    public function test_embeddings_cache_bypass_headers(): void
    {
        $payload = [
            'model' => 'text-embedding-3-small',
            'input' => 'Bypass test input',
        ];

        // Normal cacheable
        $this->assertTrue($this->cacheService->isEmbeddingCacheable($payload));

        // Client explicit bypass header
        $reqBypass = Request::create('/v1/embeddings', 'POST', $payload, [], [], [
            'HTTP_X_BYPASS_CACHE' => 'true',
        ]);
        $this->assertFalse($this->cacheService->isEmbeddingCacheable($payload, $reqBypass));

        // Cache-Control: no-cache
        $reqNoCache = Request::create('/v1/embeddings', 'POST', $payload, [], [], [
            'HTTP_CACHE_CONTROL' => 'no-cache',
        ]);
        $this->assertFalse($this->cacheService->isEmbeddingCacheable($payload, $reqNoCache));

        // Payload bypass_cache flag
        $payloadWithBypass = $payload;
        $payloadWithBypass['bypass_cache'] = true;
        $this->assertFalse($this->cacheService->isEmbeddingCacheable($payloadWithBypass));
    }

    public function test_anthropic_provider_native_prompt_caching_headers_and_payload(): void
    {
        $adapter = new AnthropicAdapter();

        // 1. Beta header verification
        $headers = $adapter->formatHeaders('sk-ant-test');
        $this->assertArrayHasKey('anthropic-beta', $headers);
        $this->assertEquals('prompt-caching-2024-07-31', $headers['anthropic-beta']);

        $curlHeaders = $adapter->formatCurlHeaders('sk-ant-test');
        $this->assertContains('anthropic-beta: prompt-caching-2024-07-31', $curlHeaders);

        // 2. Ephemeral cache_control attached to substantial system prompt and last tool
        $largeSystemPrompt = str_repeat('You are an expert enterprise architectural assistant. ', 25);
        $openaiPayload = [
            'messages' => [
                ['role' => 'system', 'content' => $largeSystemPrompt],
                ['role' => 'user', 'content' => 'Please explain prompt caching.'],
            ],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'fetch_docs',
                        'description' => 'Fetch documentation',
                        'parameters' => ['type' => 'object'],
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_codebase',
                        'description' => 'Search source files',
                        'parameters' => ['type' => 'object'],
                    ],
                ],
            ],
        ];

        $anthropicPayload = $adapter->formatPayload('claude-3-5-sonnet-20241022', $openaiPayload);

        // System prompt formatted with ephemeral cache control
        $this->assertIsArray($anthropicPayload['system']);
        $this->assertEquals('ephemeral', $anthropicPayload['system'][0]['cache_control']['type']);

        // Last tool formatted with ephemeral cache control
        $this->assertCount(2, $anthropicPayload['tools']);
        $this->assertArrayHasKey('cache_control', $anthropicPayload['tools'][1]);
        $this->assertEquals('ephemeral', $anthropicPayload['tools'][1]['cache_control']['type']);

        // 3. Response parsing normalizes cache_read_input_tokens
        $rawAnthropicResponse = [
            'id' => 'msg_12345',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Prompt caching improves latency and lowers cost.']
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 50,
                'output_tokens' => 20,
                'cache_read_input_tokens' => 2048,
                'cache_creation_input_tokens' => 0,
            ],
        ];

        $parsed = $adapter->parseResponse($rawAnthropicResponse, 'claude-3-5-sonnet');
        $this->assertEquals(2098, $parsed['usage']['prompt_tokens']); // 50 non-cached + 2048 cached
        $this->assertEquals(20, $parsed['usage']['completion_tokens']);
        $this->assertEquals(2048, $parsed['usage']['prompt_tokens_details']['cached_tokens']);
    }

    public function test_gemini_provider_native_prompt_caching_parsing(): void
    {
        $adapter = new GeminiAdapter();

        $rawGeminiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Gemini response with context cache hit.']
                        ]
                    ],
                    'finishReason' => 'STOP',
                ]
            ],
            'usageMetadata' => [
                'promptTokenCount' => 3500,
                'candidatesTokenCount' => 45,
                'totalTokenCount' => 3545,
                'cachedContentTokenCount' => 3000,
            ],
        ];

        $parsed = $adapter->parseResponse($rawGeminiResponse, 'gemini-1.5-pro');
        $this->assertEquals(3500, $parsed['usage']['prompt_tokens']);
        $this->assertEquals(45, $parsed['usage']['completion_tokens']);
        $this->assertEquals(3000, $parsed['usage']['prompt_tokens_details']['cached_tokens']);
    }

    public function test_tokenizer_service_extracts_stream_cached_tokens(): void
    {
        $tokenizer = new TokenizerService();

        // 1. OpenAI stream usage chunk with prompt_tokens_details
        $openAiUsageLine = 'data: ' . json_encode([
            'id' => 'chatcmpl-chunk-1',
            'choices' => [],
            'usage' => [
                'prompt_tokens' => 1500,
                'completion_tokens' => 50,
                'total_tokens' => 1550,
                'prompt_tokens_details' => [
                    'cached_tokens' => 1024,
                ],
            ],
        ]);

        $usageTracker = [];
        $text = '';
        $found = false;
        $tokenizer->parseStreamChunk($openAiUsageLine, 'openai', $usageTracker, $text, $found);
        $this->assertTrue($found);
        $this->assertEquals(1024, $usageTracker['cached_prompt_tokens']);

        $resolved = $tokenizer->resolveStreamTokens($usageTracker, [], 'test text');
        $this->assertEquals(1024, $resolved['cached_prompt_tokens']);

        $usageChunk = $tokenizer->formatStreamUsageChunk('gpt-4o', 1500, 50, 1550, 1024);
        $this->assertStringContainsString('"cached_tokens":1024', $usageChunk);
    }

    public function test_cost_calculator_applies_prompt_caching_discounts(): void
    {
        $calculator = new CostCalculatorService();

        // For Claude/Anthropic: 90% discount on cached input tokens (read cost = 10%)
        // Database pricing for claude-3-5-sonnet is $3.00 per million input ($0.000003 per token)
        // 1,000,000 total prompt tokens, of which 800,000 are cached:
        // non-cached 200,000 * 3.00 / 1M = $0.60
        // cached 800,000 * (3.00 * 0.10) / 1M = $0.24
        // prompt cost = $0.84
        // prompt cache savings = 800,000 * (3.00 * 0.90) / 1M = $2.16
        $anthropicCost = $calculator->calculate(
            model: 'claude-3-5-sonnet',
            promptTokens: 1_000_000,
            completionTokens: 0,
            savedTokens: 0,
            isCached: false,
            cachedPromptTokens: 800_000,
            provider: 'anthropic'
        );

        $this->assertEquals(0.84, $anthropicCost['cost_usd']);
        $this->assertEquals(2.16, $anthropicCost['cost_saved_usd']);

        // For OpenAI / Gemini: 50% discount on cached input tokens
        // Database pricing for gpt-4o is $2.50 per million input ($0.0000025 per token)
        // non-cached 200,000 * 2.50 / 1M = $0.50
        // cached 800,000 * (2.50 * 0.50) / 1M = $1.00
        // prompt cost = $1.50
        // prompt cache savings = 800,000 * (2.50 * 0.50) / 1M = $1.00
        $openAiCost = $calculator->calculate(
            model: 'gpt-4o',
            promptTokens: 1_000_000,
            completionTokens: 0,
            savedTokens: 0,
            isCached: false,
            cachedPromptTokens: 800_000,
            provider: 'openai'
        );

        $this->assertEquals(1.50, $openAiCost['cost_usd']);
        $this->assertEquals(1.00, $openAiCost['cost_saved_usd']);
    }

    public function test_caveman_compression_is_disabled_and_preserves_prompt_integrity(): void
    {
        $compressor = new PromptCompressor();

        $originalPrompt = 'Could you please kindly analyze this code and would you please explain it in detail.';
        $messages = [
            ['role' => 'user', 'content' => $originalPrompt]
        ];

        // Even when 'caveman' mode is requested, it must be ignored to protect user intent
        $result = $compressor->compress($messages, ['caveman', 'lite']);

        $compressedContent = $result['messages'][0]['content'];
        $this->assertStringContainsString('Could you please kindly', $compressedContent);
        $this->assertStringContainsString('would you please', $compressedContent);
        $this->assertEquals($originalPrompt, $compressedContent);
    }
}
