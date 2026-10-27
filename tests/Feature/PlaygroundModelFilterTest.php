<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\ProviderAccount;
use App\Models\RoutingCombo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaygroundModelFilterTest extends TestCase
{
    use DatabaseTransactions;
    public function test_playground_only_shows_configured_providers_and_ready_combos(): void
    {
        $admin = User::first() ?? User::factory()->create(['email' => 'admin@omniroute.local']);

        // Check playground dedicated view rendering
        $response = $this->actingAs($admin)->get('/dashboard/playground');

        $response->assertStatus(200);
        $response->assertSee('Live AI Studio Playground', false);
        $response->assertSee('Target Model / Smart Combo', false);
        $response->assertSee('+ Custom Model', false);
    }

    public function test_sync_provider_models_fetches_from_openai_endpoint(): void
    {
        $provider = AiProvider::create([
            'name' => 'Custom Test Gateway',
            'slug' => 'custom-gw-' . uniqid(),
            'base_url' => 'https://api.custom-gateway.test/v1',
            'format' => 'openai',
            'models_catalog' => ['old-model'],
            'is_active' => true,
        ]);

        $account = ProviderAccount::create([
            'ai_provider_id' => $provider->id,
            'label' => 'Primary Test Key',
            'api_key' => 'sk-test-secret',
            'priority' => 1,
            'is_active' => true,
        ]);

        // Mock upstream /models call and /chat/completions ping
        Http::fake([
            'https://api.custom-gateway.test/v1/models' => Http::response([
                'data' => [
                    ['id' => 'qwen-2.5-coder-32b'],
                    ['id' => 'deepseek-r1-distill-llama-70b'],
                ],
            ], 200),
            'https://api.custom-gateway.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'OK']],
                ],
            ], 200),
        ]);

        $admin = User::first() ?? User::factory()->create(['email' => 'admin@omniroute.local']);

        // Test the account
        $res = $this->actingAs($admin)->postJson(route('dashboard.accounts.test', $account));

        $res->assertStatus(200);
        $res->assertJsonFragment(['success' => true]);

        // Verify models_catalog was automatically updated in DB
        $provider->refresh();
        $this->assertContains('qwen-2.5-coder-32b', $provider->models_catalog);
        $this->assertContains('deepseek-r1-distill-llama-70b', $provider->models_catalog);
        $this->assertContains('old-model', $provider->models_catalog);
    }

    public function test_playground_chat_returns_normalized_choices_and_meta(): void
    {
        $provider = AiProvider::create([
            'name' => 'Mock Chat Provider',
            'slug' => 'mock-chat-' . uniqid(),
            'base_url' => 'https://api.mock-chat.test/v1',
            'format' => 'openai',
            'models_catalog' => ['mock-gpt-4o'],
            'is_active' => true,
        ]);

        $account = ProviderAccount::create([
            'ai_provider_id' => $provider->id,
            'label' => 'Mock Key',
            'api_key' => 'sk-mock-key',
            'priority' => 1,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.mock-chat.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-mock-123',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'mock-gpt-4o',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hello from mock provider!'
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15,
                ],
            ], 200),
        ]);

        $admin = User::first() ?? User::factory()->create(['email' => 'admin@omniroute.local']);

        $res = $this->actingAs($admin)->postJson(route('dashboard.playground.chat'), [
            'model' => "{$provider->slug}/mock-gpt-4o",
            'messages' => [
                ['role' => 'user', 'content' => 'hello']
            ],
            'temperature' => 0.7,
        ]);

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'choices' => [
                [
                    'message' => ['role', 'content'],
                ]
            ],
            '_omniroute_meta' => ['provider', 'model', 'account', 'saved_tokens', 'latency_ms'],
        ]);
        $this->assertEquals('Hello from mock provider!', $res->json('choices.0.message.content'));
        $this->assertEquals($provider->slug, $res->json('_omniroute_meta.provider'));
    }

    public function test_clear_cooldown_json_response(): void
    {
        $provider = AiProvider::first();
        $account = ProviderAccount::create([
            'ai_provider_id' => $provider->id,
            'label' => 'Cooldown Test Key',
            'api_key' => 'sk-test',
            'is_active' => true,
            'cooldown_until' => now()->addMinutes(10),
            'fail_count' => 3,
        ]);

        $admin = User::first() ?? User::factory()->create(['email' => 'admin@omniroute.local']);

        $res = $this->actingAs($admin)->postJson(route('dashboard.accounts.clear-cooldown', $account));

        $res->assertStatus(200);
        $res->assertJsonFragment(['success' => true]);

        $account->refresh();
        $this->assertNull($account->cooldown_until);
        $this->assertEquals(0, $account->fail_count);
    }
}
