<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardUiTest extends TestCase
{
    use DatabaseTransactions;
    public function test_login_page_renders_with_redesigned_ui(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Sign In', false);
        $response->assertSee('https://unpkg.com/lucide@latest', false);
        $response->assertSee('https://fonts.googleapis.com/css2?family=Inter', false);
        $response->assertSee('bg-[#09090b]', false);
        $response->assertSee('bg-[#121215]', false);
        $response->assertSee('Admin Email', false);
    }

    public function test_all_dedicated_dashboard_routes_render_flawlessly(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@passwire.local',
            'is_admin' => true,
        ]);

        // 1. Overview Route
        $resOverview = $this->actingAs($admin)->get('/dashboard/overview');
        $resOverview->assertStatus(200);
        $resOverview->assertSee('Overview', false);
        $resOverview->assertSee('Active Provider Health Matrix', false);
        $resOverview->assertSee('Quick Integration', false);

        // 2. Providers Route
        $resProviders = $this->actingAs($admin)->get('/dashboard/providers');
        $resProviders->assertStatus(200);
        $resProviders->assertSee('Add Custom Provider', false);
        $resProviders->assertSee('Free Tier', false);

        // 2b. Dedicated Provider Detail Route
        $provider = \App\Models\AiProvider::firstOrCreate(
            ['slug' => 'gemini'],
            [
                'name' => 'Google Gemini',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'format' => 'openai',
                'models_catalog' => ['gemini-2.5-flash'],
                'is_active' => true,
            ]
        );
        $resProviderDetail = $this->actingAs($admin)->get('/dashboard/providers/gemini');
        $resProviderDetail->assertStatus(200);
        $resProviderDetail->assertSee('Connections', false);
        $resProviderDetail->assertSee('Available Models', false);
        $resProviderDetail->assertSee('Import from /models', false);

        // 3. Combos Route
        $resCombos = $this->actingAs($admin)->get('/dashboard/combos');
        $resCombos->assertStatus(200);
        $resCombos->assertSee('Smart Combos', false);
        $resCombos->assertSee('Fallback Pipeline Sequence', false);

        // 4. API Keys Route (Primary + legacy redirect)
        $resKeys = $this->actingAs($admin)->get('/dashboard/api-keys');
        $resKeys->assertStatus(200);
        $resKeys->assertSee('API Keys', false);
        $resKeys->assertSee('Daily Token Quota', false);

        $resLegacyKeys = $this->actingAs($admin)->get('/dashboard/gateway-keys');
        $resLegacyKeys->assertRedirect('/dashboard/api-keys');

        // 5. Playground Route
        $resPlayground = $this->actingAs($admin)->get('/dashboard/playground');
        $resPlayground->assertStatus(200);
        $resPlayground->assertSee('Live AI Studio Playground', false);
        $resPlayground->assertSee('playTemperature', false);

        // 6. Logs Route
        $resLogs = $this->actingAs($admin)->get('/dashboard/logs');
        $resLogs->assertStatus(200);
        $resLogs->assertSee('Request Logs', false);

        // 7. Alerts Route
        $resAlerts = $this->actingAs($admin)->get('/dashboard/alerts');
        $resAlerts->assertStatus(200);
        $resAlerts->assertSee('Real-Time Failover Alerts & Webhooks', false);
    }
}
