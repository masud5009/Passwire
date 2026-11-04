<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceGatewayRateLimits;
use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateLimitAndQuotaConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    protected GatewayKey $gatewayKey;
    protected AiProvider $provider;
    protected ProviderAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->gatewayKey = GatewayKey::create([
            'name' => 'Concurrency Quota Key',
            'key' => 'sk-pw-quota-' . bin2hex(random_bytes(8)),
            'is_active' => true,
            'daily_token_limit' => 1000,
            'rpm_limit' => null,
            'tpm_limit' => null,
        ]);

        $this->provider = AiProvider::create([
            'name' => 'OpenAI Quota Provider',
            'slug' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'format' => 'openai',
            'models_catalog' => ['gpt-4o-mini'],
            'is_active' => true,
        ]);

        $this->account = ProviderAccount::create([
            'ai_provider_id' => $this->provider->id,
            'label' => 'OpenAI Quota Account',
            'api_key' => 'sk-test-quota-account-key',
            'priority' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: TPM recording is atomic and does not lose tokens across concurrent/repeated recordings.
     */
    public function test_tpm_recording_is_atomic_via_cache_add_and_increment(): void
    {
        $key = GatewayKey::create([
            'name' => 'Atomic TPM Key',
            'key' => 'sk-pw-tpm-' . bin2hex(random_bytes(8)),
            'tpm_limit' => 5000,
            'is_active' => true,
        ]);

        $window = (int) floor(time() / 60);
        $tpmCacheKey = "gateway_key_tpm:{$key->id}:{$window}";

        $this->assertFalse(Cache::has($tpmCacheKey));

        // First recording: triggers Cache::add
        EnforceGatewayRateLimits::recordTokenUsage($key, 250);
        $this->assertEquals(250, (int) Cache::get($tpmCacheKey));

        // Second recording: Cache::add returns false, atomically increments
        EnforceGatewayRateLimits::recordTokenUsage($key, 150);
        $this->assertEquals(400, (int) Cache::get($tpmCacheKey));

        // Third recording: atomically increments
        EnforceGatewayRateLimits::recordTokenUsage($key, 100);
        $this->assertEquals(500, (int) Cache::get($tpmCacheKey));

        // Status reflects the accumulated tokens
        $status = EnforceGatewayRateLimits::getRateLimitStatus($key);
        $this->assertEquals(500, $status['tpm_used']);
    }

    /**
     * Test 2: Daily token atomic pre-reservation prevents concurrent overshooting.
     */
    public function test_daily_token_pre_reservation_prevents_concurrent_overshooting(): void
    {
        $key = GatewayKey::create([
            'name' => 'Strict Daily Limit Key',
            'key' => 'sk-pw-daily-' . bin2hex(random_bytes(8)),
            'daily_token_limit' => 300,
            'tokens_used_today' => 180,
            'is_active' => true,
        ]);

        $today = now()->toDateString();
        $cacheKey = EnforceGatewayRateLimits::getDailyCacheKey($key, $today);

        // Initial daily usage: DB reports 180 tokens used, remaining quota is 120
        $this->assertEquals(180, EnforceGatewayRateLimits::getDailyUsage($key));

        // Request A estimates 80 tokens: 180 + 80 = 260 <= 300 -> SUCCEEDS
        $reservedA = EnforceGatewayRateLimits::reserveDailyTokens($key, 80);
        $this->assertTrue($reservedA);
        $this->assertEquals(260, (int) Cache::get($cacheKey));

        // Concurrent Request B arrives while Request A is still in-flight, estimating 80 tokens:
        // 260 + 80 = 340 > 300 -> FAILS and immediately rolls back to 260
        $reservedB = EnforceGatewayRateLimits::reserveDailyTokens($key, 80);
        $this->assertFalse($reservedB);
        $this->assertEquals(260, (int) Cache::get($cacheKey), 'Failed reservation must immediately rollback');

        // Request A finishes and settles with actual usage = 50 tokens
        // Difference: 50 - 80 = -30 -> Cache decremented by 30 to 230; DB updated to 180 + 50 = 230
        EnforceGatewayRateLimits::settleDailyTokens($key, 80, 50);

        $this->assertEquals(230, (int) Cache::get($cacheKey));
        $this->assertEquals(230, $key->fresh()->tokens_used_today);

        // Now Request C arrives estimating 60 tokens: 230 + 60 = 290 <= 300 -> SUCCEEDS
        $reservedC = EnforceGatewayRateLimits::reserveDailyTokens($key, 60);
        $this->assertTrue($reservedC);
        $this->assertEquals(290, (int) Cache::get($cacheKey));
    }

    /**
     * Test 3: Failed/aborted request rolls back reserved daily quota in cache.
     */
    public function test_rollback_daily_tokens_releases_reserved_quota(): void
    {
        $key = GatewayKey::create([
            'name' => 'Rollback Key',
            'key' => 'sk-pw-rollback-' . bin2hex(random_bytes(8)),
            'daily_token_limit' => 500,
            'tokens_used_today' => 400,
            'is_active' => true,
        ]);

        $today = now()->toDateString();
        $cacheKey = EnforceGatewayRateLimits::getDailyCacheKey($key, $today);

        // Pre-reserve 70 tokens
        $reserved = EnforceGatewayRateLimits::reserveDailyTokens($key, 70);
        $this->assertTrue($reserved);
        $this->assertEquals(470, (int) Cache::get($cacheKey));

        // Request fails / network aborts -> rollback
        EnforceGatewayRateLimits::rollbackDailyTokens($key, 70);
        $this->assertEquals(400, (int) Cache::get($cacheKey));

        // Key's DB usage remains unchanged
        $this->assertEquals(400, $key->fresh()->tokens_used_today);
    }

    /**
     * Test 4: End-to-end API rejects requests when daily quota is exceeded by pre-reservation.
     */
    public function test_chat_completions_returns_429_when_daily_quota_would_be_exceeded(): void
    {
        // Key with 100 limit, already consumed 90 tokens today
        $key = GatewayKey::create([
            'name' => 'Near Quota Key',
            'key' => 'sk-pw-near-' . bin2hex(random_bytes(8)),
            'daily_token_limit' => 100,
            'tokens_used_today' => 90,
            'usage_reset_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $rawKey = $key->getPlainTextKey();

        // Prompt that requires ~80 tokens (prompt + completion) -> 90 + 80 = 170 > 100
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Please provide a detailed explanation of quantum computing.'],
            ],
            'max_tokens' => 50,
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('error.type', 'insufficient_quota');
        $response->assertJsonPath('error.code', 'daily_limit_exceeded');
    }

    /**
     * Test 5: End-to-end successful request settles reservation accurately.
     */
    public function test_chat_completions_sync_settles_daily_reservation_accurately(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-quota-settle-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                        'finish_reason' => 'stop',
                    ]
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15,
                ],
            ], 200),
        ]);

        $rawKey = $this->gatewayKey->getPlainTextKey();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
            ],
            'max_tokens' => 10,
        ]);

        $response->assertStatus(200);

        // Database and Cache should both reflect 15 tokens
        $this->assertEquals(15, $this->gatewayKey->fresh()->tokens_used_today);
        $this->assertEquals(15, EnforceGatewayRateLimits::getDailyUsage($this->gatewayKey));
    }

    /**
     * Test 6: Request payload size ceiling enforces maximum request body size limit (10MB).
     */
    public function test_request_payload_exceeding_body_size_limit_returns_413(): void
    {
        // Configure temporarily to 1KB for fast testing of the ceiling logic
        Config::set('gateway.max_request_size_bytes', 1024);

        $rawKey = $this->gatewayKey->getPlainTextKey();

        // Create a 2KB message content
        $largeContent = str_repeat('A', 2048);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => $largeContent],
            ],
        ]);

        $response->assertStatus(413);
        $response->assertJsonPath('error.code', 'payload_too_large');
        $response->assertJsonPath('error.type', 'invalid_request_error');
    }

    /**
     * Test 7: max_tokens ceiling rejects parameters exceeding maximum ceiling (16,384 tokens).
     */
    public function test_max_tokens_above_ceiling_returns_400_validation_error(): void
    {
        $rawKey = $this->gatewayKey->getPlainTextKey();

        // 20,000 exceeds 16,384 ceiling
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_tokens' => 20000,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error.type', 'invalid_request_error');
        $this->assertStringContainsString('max tokens', strtolower($response->json('error.message')));
    }

    /**
     * Test 8: max_completion_tokens above ceiling returns 400 validation error.
     */
    public function test_max_completion_tokens_above_ceiling_returns_400(): void
    {
        $rawKey = $this->gatewayKey->getPlainTextKey();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_completion_tokens' => 30000,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error.type', 'invalid_request_error');
        $this->assertStringContainsString('max completion tokens', strtolower($response->json('error.message')));
    }

    /**
     * Test 9: Messages array exceeding max_messages_count returns 400 validation error.
     */
    public function test_messages_array_exceeding_count_returns_400(): void
    {
        // Configure temporarily to 5 for test
        Config::set('gateway.max_messages_count', 5);

        $rawKey = $this->gatewayKey->getPlainTextKey();

        $messages = [];
        for ($i = 0; $i < 6; $i++) {
            $messages[] = ['role' => 'user', 'content' => "Message {$i}"];
        }

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error.type', 'invalid_request_error');
        $this->assertStringContainsString('messages', strtolower($response->json('error.message')));
    }

    /**
     * Test 10: VerifyGatewayKey middleware immediately blocks request if daily quota is already exhausted.
     */
    public function test_verify_gateway_key_middleware_blocks_when_daily_quota_is_exhausted(): void
    {
        $key = GatewayKey::create([
            'name' => 'Exhausted Key',
            'key' => 'sk-pw-exhausted-' . bin2hex(random_bytes(8)),
            'daily_token_limit' => 500,
            'tokens_used_today' => 500,
            'usage_reset_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $rawKey = $key->getPlainTextKey();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('error.code', 'daily_limit_exceeded');
        $response->assertJsonPath('error.type', 'insufficient_quota');
    }

    /**
     * Test 11: Embeddings endpoint enforces request body size ceiling.
     */
    public function test_embeddings_enforces_payload_size_ceiling(): void
    {
        Config::set('gateway.max_request_size_bytes', 1024);

        $rawKey = $this->gatewayKey->getPlainTextKey();
        $largeInput = str_repeat('B', 2048);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/embeddings', [
            'model' => 'text-embedding-3-small',
            'input' => $largeInput,
        ]);

        $response->assertStatus(413);
        $response->assertJsonPath('error.code', 'payload_too_large');
        $response->assertJsonPath('error.type', 'invalid_request_error');
    }

    /**
     * Test 12: Embeddings settles daily token quota accurately.
     */
    public function test_embeddings_settles_daily_token_quota_accurately(): void
    {
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [
                    [
                        'object' => 'embedding',
                        'index' => 0,
                        'embedding' => array_fill(0, 1536, 0.01),
                    ]
                ],
                'model' => 'text-embedding-3-small',
                'usage' => [
                    'prompt_tokens' => 8,
                    'total_tokens' => 8,
                ],
            ], 200),
        ]);

        $rawKey = $this->gatewayKey->getPlainTextKey();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/embeddings', [
            'model' => 'text-embedding-3-small',
            'input' => 'Embed this sentence.',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(8, $this->gatewayKey->fresh()->tokens_used_today);
        $this->assertEquals(8, EnforceGatewayRateLimits::getDailyUsage($this->gatewayKey));
    }
}
