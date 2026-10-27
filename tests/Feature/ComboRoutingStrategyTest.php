<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\GatewayLog;
use App\Models\ModelPricing;
use App\Models\ProviderAccount;
use App\Models\RoutingCombo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComboRoutingStrategyTest extends TestCase
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
            'name' => 'Strategy Test Key',
            'key' => 'sk-pw-combo-test-' . bin2hex(random_bytes(6)),
            'is_active' => true,
        ]);

        $this->provider = AiProvider::create([
            'name' => 'Mock Strategy Provider',
            'slug' => 'mock-strat',
            'base_url' => 'https://api.mockstrat.com/v1',
            'format' => 'openai',
            'models_catalog' => ['expensive-model', 'cheap-model', 'fast-model', 'slow-model'],
            'is_active' => true,
        ]);

        $this->account = ProviderAccount::create([
            'ai_provider_id' => $this->provider->id,
            'label' => 'Primary Mock Account',
            'api_key' => 'sk-mock-key-123',
            'priority' => 1,
            'is_active' => true,
        ]);
    }

    public function test_lowest_cost_strategy_routes_to_cheaper_model_first(): void
    {
        // Define pricing: expensive-model ($10/M) vs cheap-model ($0.50/M)
        ModelPricing::create([
            'model_pattern' => 'expensive-model',
            'input_cost_per_million' => 10.00,
            'output_cost_per_million' => 30.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        ModelPricing::create([
            'model_pattern' => 'cheap-model',
            'input_cost_per_million' => 0.20,
            'output_cost_per_million' => 0.60,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        // Combo lists expensive-model first in models array, but strategy is lowest-cost
        $combo = RoutingCombo::create([
            'name' => 'cost-smart-combo',
            'strategy' => 'lowest-cost',
            'models' => ['mock-strat/expensive-model', 'mock-strat/cheap-model'],
            'is_active' => true,
        ]);

        $requestedModels = [];

        Http::fake([
            'https://api.mockstrat.com/v1/chat/completions' => function ($request) use (&$requestedModels) {
                $body = $request->data();
                $requestedModels[] = $body['model'];

                return Http::response([
                    'id' => 'chatcmpl-mock-cheap',
                    'object' => 'chat.completion',
                    'created' => time(),
                    'model' => $body['model'],
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => ['role' => 'assistant', 'content' => 'Hello from cheapest!'],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                ], 200);
            },
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', [
            'model' => 'cost-smart-combo',
            'messages' => [
                ['role' => 'user', 'content' => 'Test cost routing'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($requestedModels);
        // The first model attempted must be cheap-model despite expensive-model being first in the combo definition
        $this->assertEquals('cheap-model', $requestedModels[0]);
    }

    public function test_lowest_latency_strategy_routes_to_historically_faster_model_first(): void
    {
        // slow-model average latency 800ms
        GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'latency-smart-combo',
            'status_code' => 200,
            'routed_model' => 'slow-model',
            'latency_ms' => 800,
            'created_at' => now()->subMinutes(10),
        ]);

        // fast-model average latency 75ms
        GatewayLog::create([
            'gateway_key_id' => $this->gatewayKey->id,
            'requested_model' => 'latency-smart-combo',
            'status_code' => 200,
            'routed_model' => 'fast-model',
            'latency_ms' => 75,
            'created_at' => now()->subMinutes(10),
        ]);

        // Combo lists slow-model first in models array, but strategy is lowest-latency
        $combo = RoutingCombo::create([
            'name' => 'latency-smart-combo',
            'strategy' => 'lowest-latency',
            'models' => ['mock-strat/slow-model', 'mock-strat/fast-model'],
            'is_active' => true,
        ]);

        $requestedModels = [];

        Http::fake([
            'https://api.mockstrat.com/v1/chat/completions' => function ($request) use (&$requestedModels) {
                $body = $request->data();
                $requestedModels[] = $body['model'];

                return Http::response([
                    'id' => 'chatcmpl-mock-fast',
                    'object' => 'chat.completion',
                    'created' => time(),
                    'model' => $body['model'],
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => ['role' => 'assistant', 'content' => 'Hello from fastest!'],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                ], 200);
            },
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->gatewayKey->key,
        ])->postJson('/v1/chat/completions', [
            'model' => 'latency-smart-combo',
            'messages' => [
                ['role' => 'user', 'content' => 'Test latency routing'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($requestedModels);
        // The first model attempted must be fast-model
        $this->assertEquals('fast-model', $requestedModels[0]);
    }

    public function test_dashboard_combo_creation_accepts_new_strategies(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin-combo-' . uniqid() . '@passwire.local',
        ]);

        $response = $this->actingAs($admin)
            ->from('/dashboard/combos')
            ->post('/dashboard/combos', [
                'name' => 'ultra-combo',
                'strategy' => 'lowest-cost',
                'models' => "mock-strat/cheap-model\nmock-strat/expensive-model",
                'description' => 'Cheapest first virtual combo',
            ]);

        $response->assertRedirect('/dashboard/combos');

        $this->assertDatabaseHas('routing_combos', [
            'name' => 'ultra-combo',
            'strategy' => 'lowest-cost',
        ]);
    }
}
