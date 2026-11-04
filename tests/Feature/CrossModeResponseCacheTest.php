<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\GatewayLog;
use App\Models\ProviderAccount;
use App\Models\RoutingCombo;
use App\Services\Gateway\PromptCompressor;
use App\Services\Gateway\ResponseCacheService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrossModeResponseCacheTest extends TestCase
{
    use DatabaseTransactions;

    protected GatewayKey $gatewayKey;
    protected AiProvider $provider;
    protected ProviderAccount $account;
    protected RoutingCombo $combo;
    protected ResponseCacheService $cacheService;
    protected PromptCompressor $compressor;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cacheService = new ResponseCacheService();
        $this->compressor = new PromptCompressor();

        $this->gatewayKey = GatewayKey::create([
            'name' => 'Cache Test Key',
            'key' => 'sk-pw-cache-test-' . bin2hex(random_bytes(6)),
            'is_active' => true,
        ]);

        $this->provider = AiProvider::create([
            'name' => 'Mock OpenAI Provider',
            'slug' => 'mock-openai',
            'base_url' => 'https://api.mockai.com/v1',
            'format' => 'openai',
            'models_catalog' => ['gpt-4o-mini'],
            'is_active' => true,
        ]);

        $this->account = ProviderAccount::create([
            'ai_provider_id' => $this->provider->id,
            'label' => 'Mock Account 1',
            'api_key' => 'sk-test-mock-key',
            'priority' => 1,
            'is_active' => true,
        ]);

        $this->combo = RoutingCombo::create([
            'name' => 'smart-test-combo',
            'routing_strategy' => 'priority',
            'models' => ['mock-openai/gpt-4o-mini'],
            'compression_modes' => ['dedup', 'lite'],
            'is_active' => true,
        ]);
    }

    public function test_sync_and_stream_generate_identical_cache_keys_after_compression(): void
    {
        $rawMessages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => "What is the capital   of France?   \n\n\n\nPlease answer concisely."],
        ];

        $payload = [
            'model' => 'smart-test-combo',
            'messages' => $rawMessages,
            'temperature' => 0.7,
        ];

        // Compress messages according to combo's compression_modes (dedup, lite)
        $compressed = $this->compressor->compress($payload['messages'], ['dedup', 'lite']);
        $compressedPayload = $payload;
        $compressedPayload['messages'] = $compressed['messages'];

        // Both sync and streaming now generate cache key using the post-compression payload and gateway key
        $syncKey = $this->cacheService->generateKey($compressedPayload, $this->gatewayKey);
        $streamKey = $this->cacheService->generateKey($compressedPayload, $this->gatewayKey);

        $this->assertEquals($syncKey, $streamKey);
        $this->assertStringStartsWith('omni_resp_cache:', $syncKey);

        // Verify that raw uncompressed payload would produce a DIFFERENT key
        $rawKey = $this->cacheService->generateKey($payload, $this->gatewayKey);
        $this->assertNotEquals($rawKey, $syncKey, 'Uncompressed payload must produce a different key than compressed payload.');
    }

    public function test_sync_request_populates_cache_and_streaming_hits_cache(): void
    {
        // 1. Mock upstream HTTP completion response for initial Sync request
        Http::fake([
            'https://api.mockai.com/v1/*' => Http::response([
                'id' => 'chatcmpl-mock-sync-1',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Paris is the capital of France.',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 20,
                    'completion_tokens' => 10,
                    'total_tokens' => 30,
                ],
            ], 200),
        ]);

        $payload = [
            'model' => 'smart-test-combo',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => "What is the capital   of France?  \n\n\n\nPlease answer concisely."],
            ],
            'temperature' => 0.7,
        ];

        // 2. Dispatch Sync request (First request: Cache MISS)
        $syncResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', $payload);

        $syncResponse->assertStatus(200);
        $syncResponse->assertHeader('X-Passwire-Cache', 'MISS');
        $this->assertEquals('Paris is the capital of France.', $syncResponse->json('choices.0.message.content'));

        // 3. Now send the EXACT same question via Streaming request (stream: true)
        $streamPayload = array_merge($payload, ['stream' => true]);

        $streamResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', $streamPayload);

        $streamResponse->assertStatus(200);

        // Verify the stream output contains cached chunk and [DONE]
        $content = $streamResponse->streamedContent();
        $this->assertStringContainsString('chatcmpl-cache-', $content);
        $this->assertStringContainsString('Paris is the capital of France.', $content);
        $this->assertStringContainsString('data: [DONE]', $content);

        // Verify GatewayLog recorded a cache hit
        $log = GatewayLog::where('is_cached', true)->latest('id')->first();
        $this->assertNotNull($log, 'GatewayLog should record the streaming cache hit.');
        $this->assertEquals('cache-hit', $log->account_label);
        $this->assertEquals('smart-test-combo', $log->requested_model);
    }

    public function test_streaming_request_populates_cache_and_sync_hits_cache(): void
    {
        $payload = [
            'model' => 'smart-test-combo',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a knowledgeable assistant.'],
                ['role' => 'user', 'content' => 'Explain gravity in one sentence.'],
            ],
            'temperature' => 0.7,
        ];

        // 1. Manually populate the cache as would be done by streamCompletions
        $compressed = $this->compressor->compress($payload['messages'], ['dedup', 'lite']);
        $compressedPayload = $payload;
        $compressedPayload['messages'] = $compressed['messages'];
        $cacheKey = $this->cacheService->generateKey($compressedPayload, $this->gatewayKey);

        $cachedResponse = [
            'id' => 'chatcmpl-cached-stream-1',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'gpt-4o-mini',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Gravity is the fundamental attraction between objects with mass.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 12,
                'total_tokens' => 27,
            ],
        ];

        $this->cacheService->put($cacheKey, $cachedResponse, [
            'provider' => 'mock-openai',
            'model' => 'gpt-4o-mini',
            'account' => 'Mock Account 1',
            'latency_ms' => 45,
            'saved_tokens' => 0,
            'cost_usd' => 0.0001,
            'cost_saved_usd' => 0.0,
            'redacted_pii_count' => 0,
        ]);

        // 2. Send Sync request without streaming
        // Upstream Http should NOT be called because it should hit the cache!
        Http::fake([
            '*' => Http::response('Should not be called', 500),
        ]);

        $syncResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', $payload);

        $syncResponse->assertStatus(200);
        $syncResponse->assertHeader('X-Passwire-Cache', 'HIT');
        $this->assertEquals(
            'Gravity is the fundamental attraction between objects with mass.',
            $syncResponse->json('choices.0.message.content')
        );

        // Verify GatewayLog
        $log = GatewayLog::where('is_cached', true)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('cache-hit', $log->account_label);
    }

    public function test_tenant_isolation_prevents_cross_user_cache_leakage(): void
    {
        $userKeyB = GatewayKey::create([
            'name' => 'User B Key',
            'key' => 'sk-pw-user-b-' . bin2hex(random_bytes(6)),
            'is_active' => true,
        ]);

        $payload = [
            'model' => 'smart-test-combo',
            'messages' => [
                ['role' => 'user', 'content' => 'Confidential prompt: calculate company Q4 revenue.'],
            ],
            'temperature' => 0.0,
        ];

        // Sequence: First request gets User A's response, second request gets User B's fresh response
        Http::fake([
            'https://api.mockai.com/v1/*' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-usera',
                    'object' => 'chat.completion',
                    'choices' => [
                        ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'User A private result: $5M'], 'finish_reason' => 'stop'],
                    ],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-userb',
                    'object' => 'chat.completion',
                    'choices' => [
                        ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'User B fresh result: $10M'], 'finish_reason' => 'stop'],
                    ],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                ], 200),
        ]);

        // 1. User A sends prompt (hits upstream and caches result under User A's tenant key)
        $resA = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', $payload);
        $resA->assertStatus(200);
        $resA->assertHeader('X-Passwire-Cache', 'MISS');
        $this->assertEquals('User A private result: $5M', $resA->json('choices.0.message.content'));

        // 2. User B sends the exact same prompt with User B's GatewayKey
        // User B must NOT get User A's cached response!
        $resB = $this->withHeaders([
            'Authorization' => 'Bearer ' . $userKeyB->key,
        ])->postJson('/v1/chat/completions', $payload);

        $resB->assertStatus(200);
        // User B must get a CACHE MISS (not User A's cache!)
        $resB->assertHeader('X-Passwire-Cache', 'MISS');
        $this->assertEquals('User B fresh result: $10M', $resB->json('choices.0.message.content'));

        // 3. User A sends prompt again -> User A gets CACHE HIT with User A's result
        $resA2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', $payload);
        $resA2->assertStatus(200);
        $resA2->assertHeader('X-Passwire-Cache', 'HIT');
        $this->assertEquals('User A private result: $5M', $resA2->json('choices.0.message.content'));
    }

    public function test_cache_key_includes_response_format_and_stop_and_tool_choice_and_seed(): void
    {
        $basePayload = [
            'model' => 'smart-test-combo',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ];

        $keyBase = $this->cacheService->generateKey($basePayload, $this->gatewayKey);

        // 1. response_format
        $jsonPayload = array_merge($basePayload, ['response_format' => ['type' => 'json_object']]);
        $keyJson = $this->cacheService->generateKey($jsonPayload, $this->gatewayKey);
        $this->assertNotEquals($keyBase, $keyJson, 'Cache key must differ when response_format is set.');

        // 2. stop sequences
        $stopPayload = array_merge($basePayload, ['stop' => ['END', 'STOP']]);
        $keyStop = $this->cacheService->generateKey($stopPayload, $this->gatewayKey);
        $this->assertNotEquals($keyBase, $keyStop, 'Cache key must differ when stop parameter is set.');

        // 3. tool_choice
        $toolChoicePayload = array_merge($basePayload, ['tool_choice' => 'required']);
        $keyToolChoice = $this->cacheService->generateKey($toolChoicePayload, $this->gatewayKey);
        $this->assertNotEquals($keyBase, $keyToolChoice, 'Cache key must differ when tool_choice is set.');

        // 4. seed
        $seedPayload1 = array_merge($basePayload, ['seed' => 100]);
        $seedPayload2 = array_merge($basePayload, ['seed' => 200]);
        $this->assertNotEquals(
            $this->cacheService->generateKey($seedPayload1, $this->gatewayKey),
            $this->cacheService->generateKey($seedPayload2, $this->gatewayKey),
            'Cache key must differ for different seeds.'
        );

        // 5. top_p, frequency_penalty
        $topPPayload = array_merge($basePayload, ['top_p' => 0.5]);
        $this->assertNotEquals($keyBase, $this->cacheService->generateKey($topPPayload, $this->gatewayKey));

        $freqPayload = array_merge($basePayload, ['frequency_penalty' => 1.2]);
        $this->assertNotEquals($keyBase, $this->cacheService->generateKey($freqPayload, $this->gatewayKey));
    }

    public function test_temperature_omitted_is_null_not_0_7(): void
    {
        $payloadWithoutTemp = [
            'model' => 'smart-test-combo',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ];

        $payloadWithTemp07 = array_merge($payloadWithoutTemp, ['temperature' => 0.7]);

        $key1 = $this->cacheService->generateKey($payloadWithoutTemp, $this->gatewayKey);
        $key2 = $this->cacheService->generateKey($payloadWithTemp07, $this->gatewayKey);

        $this->assertNotEquals($key1, $key2, 'Omitted temperature must not be artificially assumed as 0.7.');
    }

    public function test_deterministic_vs_non_deterministic_ttl(): void
    {
        // 1. Greedy decoding (temperature 0) -> deterministic -> 24 hours (86400s)
        $deterministicPayload = ['temperature' => 0.0, 'messages' => [['role' => 'user', 'content' => 'Test']]];
        $this->assertTrue($this->cacheService->isDeterministic($deterministicPayload));
        $this->assertEquals(86400, $this->cacheService->calculateTtl($deterministicPayload));

        // 2. Explicit seed -> deterministic -> 24 hours (86400s)
        $seedPayload = ['temperature' => 0.9, 'seed' => 42, 'messages' => [['role' => 'user', 'content' => 'Test']]];
        $this->assertTrue($this->cacheService->isDeterministic($seedPayload));
        $this->assertEquals(86400, $this->cacheService->calculateTtl($seedPayload));

        // 3. Temperature 0.7 without seed -> non-deterministic -> short TTL (300s / 5 mins)
        $nonDeterministicPayload = ['temperature' => 0.7, 'messages' => [['role' => 'user', 'content' => 'Test']]];
        $this->assertFalse($this->cacheService->isDeterministic($nonDeterministicPayload));
        $this->assertEquals(300, $this->cacheService->calculateTtl($nonDeterministicPayload));

        // 4. Custom cache_ttl overrides everything
        $customTtlPayload = ['cache_ttl' => 7200, 'temperature' => 0.7, 'messages' => [['role' => 'user', 'content' => 'Test']]];
        $this->assertEquals(7200, $this->cacheService->calculateTtl($customTtlPayload));
    }

    public function test_tool_calls_replayed_accurately_in_streaming_cache(): void
    {
        $payload = [
            'model' => 'smart-test-combo',
            'messages' => [['role' => 'user', 'content' => 'What is the weather in Dhaka?']],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Get current weather',
                        'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                    ],
                ],
            ],
            'temperature' => 0.0,
        ];

        // Pre-populate cache with tool_calls response
        $compressed = $this->compressor->compress($payload['messages'], ['dedup', 'lite']);
        $compressedPayload = $payload;
        $compressedPayload['messages'] = $compressed['messages'];
        $cacheKey = $this->cacheService->generateKey($compressedPayload, $this->gatewayKey);

        $cachedResponse = [
            'id' => 'chatcmpl-cached-tool-1',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'gpt-4o-mini',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_weather_123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"city":"Dhaka"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 25,
                'completion_tokens' => 15,
                'total_tokens' => 40,
            ],
        ];

        $this->cacheService->put($cacheKey, $cachedResponse, [
            'provider' => 'mock-openai',
            'model' => 'gpt-4o-mini',
            'account' => 'Mock Account 1',
            'latency_ms' => 30,
        ]);

        // Request via streaming (stream: true)
        $streamPayload = array_merge($payload, ['stream' => true]);

        $streamResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', $streamPayload);

        $streamResponse->assertStatus(200);
        $content = $streamResponse->streamedContent();

        // Must preserve tool_calls and finish_reason: tool_calls
        $this->assertStringContainsString('call_weather_123', $content);
        $this->assertStringContainsString('get_weather', $content);
        $this->assertStringContainsString('{\"city\":\"Dhaka\"}', $content);
        $this->assertStringContainsString('tool_calls', $content);
        $this->assertStringContainsString('data: [DONE]', $content);
    }

    public function test_cache_bypass_header_ignores_cached_entry(): void
    {
        $payload = [
            'model' => 'smart-test-combo',
            'messages' => [
                ['role' => 'user', 'content' => 'Cache bypass test query.'],
            ],
        ];

        $compressed = $this->compressor->compress($payload['messages'], ['dedup', 'lite']);
        $compressedPayload = $payload;
        $compressedPayload['messages'] = $compressed['messages'];
        $cacheKey = $this->cacheService->generateKey($compressedPayload, $this->gatewayKey);

        $this->cacheService->put($cacheKey, [
            'id' => 'cached-bypass',
            'choices' => [['message' => ['content' => 'Old cached response']]],
        ]);

        // Mock upstream to respond with fresh response
        Http::fake([
            'https://api.mockai.com/v1/*' => Http::response([
                'id' => 'chatcmpl-fresh',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Fresh response from upstream.',
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ], 200),
        ]);

        // Request with x-bypass-cache: true
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
            'x-bypass-cache' => 'true',
        ])->postJson('/v1/chat/completions', $payload);

        $response->assertStatus(200);
        $response->assertHeader('X-Passwire-Cache', 'MISS');
        $this->assertEquals('Fresh response from upstream.', $response->json('choices.0.message.content'));
    }
}
