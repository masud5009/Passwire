<?php

namespace Tests\Feature;

use App\Models\GatewayLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VisualAnalyticsTest extends TestCase
{
    use DatabaseTransactions;
    public function test_guest_cannot_access_analytics_endpoint(): void
    {
        $response = $this->getJson('/dashboard/analytics/charts');
        $response->assertStatus(401);
    }

    public function test_admin_can_fetch_today_chart_analytics(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@omniroute.local',
            'is_admin' => true,
        ]);

        // Seed a test log for today
        GatewayLog::create([
            'requested_model' => 'openai/gpt-4o',
            'routed_provider' => 'openai',
            'routed_model' => 'gpt-4o',
            'account_label' => 'Test Key',
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
            'total_tokens' => 200,
            'saved_tokens' => 40,
            'cost_usd' => 0.0015,
            'cost_saved_usd' => 0.0003,
            'latency_ms' => 250,
            'status_code' => 200,
            'is_cached' => false,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/dashboard/analytics/charts?period=today');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'period',
            'token_chart' => [
                'categories',
                'prompt_tokens',
                'completion_tokens',
                'saved_tokens',
            ],
            'provider_chart' => [
                'labels',
                'series',
                'total_requests',
            ],
            'latency_chart' => [
                'categories',
                'latencies',
                'errors',
            ],
        ]);

        $this->assertEquals('today', $response->json('period'));
        $this->assertCount(24, $response->json('token_chart.categories'));
        $this->assertGreaterThanOrEqual(1, $response->json('provider_chart.total_requests'));
        $this->assertContains('openai', $response->json('provider_chart.labels'));
    }

    public function test_admin_can_fetch_7d_and_30d_analytics(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@omniroute.local',
            'is_admin' => true,
        ]);

        // 7 days
        $res7d = $this->actingAs($admin)->getJson('/dashboard/analytics/charts?period=7d');
        $res7d->assertStatus(200);
        $this->assertEquals('7d', $res7d->json('period'));
        $this->assertCount(7, $res7d->json('token_chart.categories'));

        // 30 days
        $res30d = $this->actingAs($admin)->getJson('/dashboard/analytics/charts?period=30d');
        $res30d->assertStatus(200);
        $this->assertEquals('30d', $res30d->json('period'));
        $this->assertCount(30, $res30d->json('token_chart.categories'));
    }

    public function test_overview_page_renders_with_visual_analytics_containers(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@omniroute.local',
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)->get('/dashboard/overview');

        $response->assertStatus(200);
        $response->assertSee('Gateway Visual Analytics & Workload Distribution', false);
        $response->assertSee('chart-token-usage', false);
        $response->assertSee('chart-provider-donut', false);
        $response->assertSee('chart-latency-trend', false);
        $response->assertSee('apexcharts', false);
    }
}
