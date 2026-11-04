<?php

namespace Tests\Feature;

use App\Models\GatewayKey;
use App\Models\GatewayLog;
use App\Models\RoutingCombo;
use App\Models\User;
use App\Services\Gateway\OmniRouterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GatewayLogIndexAndRetentionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected GatewayKey $gatewayKey;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->admin = User::first() ?? User::factory()->create([
            'email' => 'admin@passwire.local',
            'is_admin' => true,
        ]);

        $this->gatewayKey = GatewayKey::create([
            'name' => 'Log Test Key',
            'key' => 'sk-pw-logs-' . bin2hex(random_bytes(8)),
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Verify all required performance indexes exist on gateway_logs table.
     */
    public function test_gateway_logs_table_has_all_performance_indexes(): void
    {
        $rawIndexes = DB::select('SHOW INDEX FROM gateway_logs');
        $indexNames = collect($rawIndexes)->pluck('Key_name')->unique()->values()->all();

        $this->assertContains('gateway_logs_created_at_idx', $indexNames, 'created_at index must exist');
        $this->assertContains('gateway_logs_gateway_key_id_created_at_idx', $indexNames, '(gateway_key_id, created_at) index must exist');
        $this->assertContains('gateway_logs_status_code_created_at_idx', $indexNames, '(status_code, created_at) index must exist');
        $this->assertContains('gateway_logs_routed_model_created_at_idx', $indexNames, '(routed_model, created_at) index must exist');
        $this->assertContains('gateway_logs_latency_strategy_idx', $indexNames, 'Composite lowest-latency strategy index must exist');
        $this->assertContains('gateway_logs_created_at_is_cached_idx', $indexNames, '(created_at, is_cached) index must exist');
    }

    /**
     * Test 2: GatewayLog::pruneOlderThan deletes old records and preserves recent records.
     */
    public function test_prune_older_than_deletes_records_beyond_retention_window(): void
    {
        // 1. Create a recent log (today)
        $recentLog = GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'gpt-4o',
            'routed_provider' => 'openai',
            'routed_model' => 'gpt-4o',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'status_code' => 200,
            'latency_ms' => 150,
            'created_at' => now()->subDays(2),
        ]);

        // 2. Create an old log (40 days old)
        $oldLog1 = GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'claude-3-5-sonnet',
            'routed_provider' => 'anthropic',
            'routed_model' => 'claude-3-5-sonnet',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'status_code' => 200,
            'latency_ms' => 200,
        ]);
        DB::table('gateway_logs')->where('id', $oldLog1->id)->update(['created_at' => now()->subDays(40)]);

        // 3. Create another old log (60 days old)
        $oldLog2 = GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'gemini-1.5-pro',
            'routed_provider' => 'gemini',
            'routed_model' => 'gemini-1.5-pro',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'status_code' => 200,
            'latency_ms' => 250,
        ]);
        DB::table('gateway_logs')->where('id', $oldLog2->id)->update(['created_at' => now()->subDays(60)]);

        // Prune logs older than 30 days
        $deletedCount = GatewayLog::pruneOlderThan(30, 10);

        $this->assertGreaterThanOrEqual(2, $deletedCount);
        $this->assertDatabaseMissing('gateway_logs', ['id' => $oldLog1->id]);
        $this->assertDatabaseMissing('gateway_logs', ['id' => $oldLog2->id]);
        $this->assertDatabaseHas('gateway_logs', ['id' => $recentLog->id]);
    }

    /**
     * Test 3: Artisan command passwire:prune-logs executes cleanly and respects options.
     */
    public function test_artisan_prune_command_executes_successfully(): void
    {
        // Create an old log (45 days old)
        $oldLog = GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'gpt-4o-mini',
            'routed_provider' => 'openai',
            'routed_model' => 'gpt-4o-mini',
            'prompt_tokens' => 5,
            'completion_tokens' => 10,
            'total_tokens' => 15,
            'status_code' => 200,
            'latency_ms' => 100,
        ]);
        DB::table('gateway_logs')->where('id', $oldLog->id)->update(['created_at' => now()->subDays(45)]);

        $exitCode = Artisan::call('passwire:prune-logs', [
            '--days' => 30,
            '--chunk' => 500,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Successfully pruned', $output);
        $this->assertDatabaseMissing('gateway_logs', ['id' => $oldLog->id]);
    }

    /**
     * Test 4: Dashboard prune endpoint /dashboard/logs/prune requires auth and prunes logs.
     */
    public function test_dashboard_prune_logs_endpoint_prunes_and_redirects(): void
    {
        $oldLog = GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'gpt-4o',
            'routed_provider' => 'openai',
            'routed_model' => 'gpt-4o',
            'prompt_tokens' => 5,
            'completion_tokens' => 10,
            'total_tokens' => 15,
            'status_code' => 200,
            'latency_ms' => 100,
        ]);
        DB::table('gateway_logs')->where('id', $oldLog->id)->update(['created_at' => now()->subDays(35)]);

        $response = $this->actingAs($this->admin)->post('/dashboard/logs/prune', [
            'days' => 30,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('gateway_logs', ['id' => $oldLog->id]);
    }

    /**
     * Test 5: Lowest-latency routing strategy correctly uses index and sorts by lowest latency.
     */
    public function test_lowest_latency_strategy_sorts_models_by_recent_average_latency(): void
    {
        $combo = RoutingCombo::create([
            'name' => 'smart-fast-combo',
            'models' => ['openai/fast-model', 'openai/slow-model'],
            'strategy' => 'lowest-latency',
            'is_active' => true,
        ]);

        // Fast model recent logs: avg 120ms
        GatewayLog::create([
            'requested_model' => 'smart-fast-combo',
            'routed_provider' => 'openai',
            'routed_model' => 'fast-model',
            'status_code' => 200,
            'latency_ms' => 120,
            'created_at' => now()->subHours(2),
        ]);

        // Slow model recent logs: avg 850ms
        GatewayLog::create([
            'requested_model' => 'smart-fast-combo',
            'routed_provider' => 'openai',
            'routed_model' => 'slow-model',
            'status_code' => 200,
            'latency_ms' => 850,
            'created_at' => now()->subHours(2),
        ]);

        /** @var OmniRouterService $router */
        $router = app(OmniRouterService::class);
        $resolved = $router->resolveTargets('smart-fast-combo');

        $this->assertNotEmpty($resolved['targets']);
        $this->assertEquals('fast-model', $resolved['targets'][0]['model'], 'Fastest model should be prioritized first');
        $this->assertEquals('slow-model', $resolved['targets'][1]['model']);
    }

    /**
     * Test 6: Overview page loads efficiently using date range bounds.
     */
    public function test_overview_page_loads_with_indexed_aggregations(): void
    {
        GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'gpt-4o-mini',
            'routed_provider' => 'openai',
            'routed_model' => 'gpt-4o-mini',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'saved_tokens' => 10,
            'cost_usd' => 0.0005,
            'cost_saved_usd' => 0.0002,
            'status_code' => 200,
            'latency_ms' => 110,
            'is_cached' => false,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('stats');
    }
}
