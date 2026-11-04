<?php

namespace Tests\Feature;

use App\Jobs\RecordGatewayLogJob;
use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\GatewayLog;
use App\Models\ProviderAccount;
use App\Services\Gateway\OmniRouterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncLoggingAndSchedulerResetTest extends TestCase
{
    use DatabaseTransactions;

    protected AiProvider $provider;
    protected ProviderAccount $account;
    protected GatewayKey $gatewayKey;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->provider = AiProvider::create([
            'name' => 'Async Test Provider',
            'slug' => 'async-openai',
            'base_url' => 'https://api.openai.com/v1',
            'format' => 'openai',
            'models_catalog' => ['gpt-4o-mini'],
            'is_active' => true,
        ]);

        $this->account = ProviderAccount::create([
            'ai_provider_id' => $this->provider->id,
            'label' => 'Primary Key',
            'api_key' => 'sk-test-async-key',
            'priority' => 1,
            'tokens_used_today' => 500,
            'usage_reset_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->gatewayKey = GatewayKey::create([
            'name' => 'Async Test Gateway Key',
            'key' => 'sk-pw-async-' . bin2hex(random_bytes(8)),
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: getHealthyAccounts does NOT execute UPDATE statements on every request.
     */
    public function test_get_healthy_accounts_does_not_execute_update_queries_per_request(): void
    {
        /** @var OmniRouterService $router */
        $router = app(OmniRouterService::class);

        // First call sets up the cache flag for today
        $router->getHealthyAccounts('async-openai');

        // Track all DB queries during subsequent getHealthyAccounts calls
        $executedQueries = [];
        DB::listen(function ($query) use (&$executedQueries) {
            $executedQueries[] = $query->sql;
        });

        // Execute multiple consecutive requests
        for ($i = 0; $i < 5; $i++) {
            $accounts = $router->getHealthyAccounts('async-openai');
            $this->assertNotEmpty($accounts);
        }

        // Verify none of the executed queries during the 5 requests were UPDATE statements
        $updateQueries = array_filter($executedQueries, function ($sql) {
            return stripos($sql, 'update') === 0;
        });

        $this->assertCount(0, $updateQueries, 'getHealthyAccounts must not execute UPDATE queries on subsequent requests.');
    }

    /**
     * Test 2: passwire:reset-daily-usage Artisan command resets usage at midnight.
     */
    public function test_reset_daily_usage_command_resets_counters_and_sets_cache_flag(): void
    {
        // Account with yesterday's usage
        $accountYesterday = ProviderAccount::create([
            'ai_provider_id' => $this->provider->id,
            'label' => 'Yesterday Account',
            'api_key' => 'sk-test-yesterday-key',
            'priority' => 2,
            'tokens_used_today' => 2500,
            'usage_reset_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $exitCode = Artisan::call('passwire:reset-daily-usage');
        $this->assertEquals(0, $exitCode);

        $accountYesterday->refresh();
        $this->assertEquals(0, $accountYesterday->tokens_used_today);
        $this->assertEquals(now()->toDateString(), $accountYesterday->usage_reset_date->toDateString());

        // Cache flag is marked for today
        $this->assertTrue((bool) Cache::get('provider_accounts_reset_done:' . now()->toDateString()));
    }

    /**
     * Test 3: Chat completions dispatches RecordGatewayLogJob to the queue asynchronously.
     */
    public function test_chat_completions_dispatches_gateway_log_to_queue(): void
    {
        Queue::fake([RecordGatewayLogJob::class]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-async-job-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Hello from queue test!'],
                        'finish_reason' => 'stop',
                    ]
                ],
                'usage' => [
                    'prompt_tokens' => 8,
                    'completion_tokens' => 12,
                    'total_tokens' => 20,
                ],
            ], 200),
        ]);

        $rawKey = $this->gatewayKey->getPlainTextKey();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->postJson('/v1/chat/completions', [
            'model' => 'async-openai/gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi queue'],
            ],
        ]);

        $response->assertStatus(200);

        // Assert job was pushed to queue asynchronously
        Queue::assertPushed(RecordGatewayLogJob::class, function (RecordGatewayLogJob $job) {
            return $job->logData['requested_model'] === 'async-openai/gpt-4o-mini'
                && $job->logData['routed_provider'] === 'async-openai'
                && $job->logData['total_tokens'] === 20;
        });
    }

    /**
     * Test 4: RecordGatewayLogJob handle method creates the GatewayLog record in database.
     */
    public function test_record_gateway_log_job_creates_database_record(): void
    {
        $logPayload = [
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'gpt-4o',
            'routed_provider' => 'async-openai',
            'routed_model' => 'gpt-4o',
            'account_label' => 'Primary Key',
            'prompt_tokens' => 15,
            'completion_tokens' => 25,
            'total_tokens' => 40,
            'saved_tokens' => 0,
            'cost_usd' => 0.0004,
            'cost_saved_usd' => 0,
            'latency_ms' => 140,
            'status_code' => 200,
            'is_cached' => false,
            'redacted_pii_count' => 0,
            'error_message' => null,
        ];

        $job = new RecordGatewayLogJob($logPayload);
        $job->handle();

        $this->assertDatabaseHas('gateway_logs', [
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'gpt-4o',
            'total_tokens' => 40,
            'status_code' => 200,
        ]);
    }
}

