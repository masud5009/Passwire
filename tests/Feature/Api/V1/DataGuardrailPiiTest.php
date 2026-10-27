<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\GatewayLog;
use App\Models\ProviderAccount;
use App\Models\RoutingCombo;
use App\Models\User;
use App\Services\Gateway\DataGuardrailService;
use App\Services\Gateway\PromptCompressor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataGuardrailPiiTest extends TestCase
{
    use DatabaseTransactions;
    public function test_guardrail_redacts_emails_cards_keys_phones_passwords_and_ssn(): void
    {
        $service = new DataGuardrailService();

        $input = "Hello! My email is masud@example.com, my phone is +1-555-839-2019, my card is 4111-2222-3333-4444, my SSN is 123-45-6789. Also my secret password: \"UltraSecretP@ss1\" and OpenAI key is sk-1234567890abcdef1234567890123456.";

        $result = $service->redact($input);

        $this->assertStringNotContainsString('masud@example.com', $result['text']);
        $this->assertStringNotContainsString('+1-555-839-2019', $result['text']);
        $this->assertStringNotContainsString('4111-2222-3333-4444', $result['text']);
        $this->assertStringNotContainsString('123-45-6789', $result['text']);
        $this->assertStringNotContainsString('UltraSecretP@ss1', $result['text']);
        $this->assertStringNotContainsString('sk-1234567890abcdef1234567890123456', $result['text']);

        $this->assertStringContainsString('[REDACTED_EMAIL]', $result['text']);
        $this->assertStringContainsString('[REDACTED_PHONE]', $result['text']);
        $this->assertStringContainsString('[REDACTED_CREDIT_CARD]', $result['text']);
        $this->assertStringContainsString('[REDACTED_SSN]', $result['text']);
        $this->assertStringContainsString('[REDACTED_PASSWORD]', $result['text']);
        $this->assertStringContainsString('[REDACTED_API_KEY]', $result['text']);

        $this->assertEquals(6, $result['count']);
    }

    public function test_guardrail_preserves_code_blocks_intact(): void
    {
        $service = new DataGuardrailService();

        $input = "Contact masud@company.com immediately.\n\n```python\n# Inside code snippet\ndef send():\n    return 'masud@company.com'\n```\nThanks!";

        $result = $service->redact($input);

        // Outside code block is redacted
        $this->assertStringContainsString('Contact [REDACTED_EMAIL] immediately.', $result['text']);
        // Inside markdown code block is preserved intact
        $this->assertStringContainsString("return 'masud@company.com'", $result['text']);
        $this->assertEquals(1, $result['count']);
    }

    public function test_prompt_compressor_applies_pii_mode(): void
    {
        $compressor = new PromptCompressor();

        $messages = [
            ['role' => 'system', 'content' => 'You are an assistant.'],
            ['role' => 'user', 'content' => 'Please reach me at ceo@startup.io or call 555-123-4567.'],
        ];

        $res = $compressor->compress($messages, ['dedup', 'lite', 'pii']);

        $this->assertGreaterThan(0, $res['redacted_pii_count']);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $res['messages'][1]['content']);
        $this->assertStringNotContainsString('ceo@startup.io', $res['messages'][1]['content']);
    }

    public function test_gateway_combo_with_pii_redacts_before_upstream_and_records_telemetry(): void
    {
        $provider = AiProvider::firstOrCreate(
            ['slug' => 'test-pii-gw'],
            [
                'name' => 'Test PII Provider',
                'base_url' => 'https://api.test-pii.com/v1',
                'format' => 'openai',
                'models_catalog' => ['gpt-4o-secure'],
                'is_active' => true,
            ]
        );

        $account = ProviderAccount::firstOrCreate(
            [
                'ai_provider_id' => $provider->id,
                'label' => 'Secure Key Pool',
            ],
            [
                'api_key' => 'sk-test-pii-secret',
                'priority' => 1,
                'is_active' => true,
            ]
        );

        $combo = RoutingCombo::create([
            'name' => 'secure-shield-' . uniqid(),
            'description' => 'Combo with PII Redaction Guardrail',
            'strategy' => 'priority',
            'models' => ['test-pii-gw/gpt-4o-secure'],
            'compression_modes' => ['dedup', 'lite', 'pii'],
            'is_active' => true,
        ]);

        $gatewayKey = GatewayKey::create([
            'name' => 'Client Key',
            'key' => 'gw-key-' . uniqid(),
            'is_active' => true,
        ]);

        $capturedPrompt = null;

        Http::fake([
            'https://api.test-pii.com/v1/chat/completions' => function ($request) use (&$capturedPrompt) {
                $capturedPrompt = $request->data();
                return Http::response([
                    'id' => 'chatcmpl-pii-mock',
                    'object' => 'chat.completion',
                    'created' => time(),
                    'model' => 'gpt-4o-secure',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Data secured and processed.',
                            ],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => [
                        'prompt_tokens' => 25,
                        'completion_tokens' => 10,
                        'total_tokens' => 35,
                    ],
                ], 200);
            },
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$gatewayKey->key}",
        ])->postJson('/v1/chat/completions', [
            'model' => $combo->name,
            'messages' => [
                ['role' => 'user', 'content' => 'My secret email is secret-founder@stealth.co and my API key is sk-1234567890abcdef1234567890.'],
            ],
            'temperature' => 0.7,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-OmniRoute-Redacted-PII', '2');

        // Verify upstream received REDACTED payload
        $this->assertNotNull($capturedPrompt);
        $upstreamMessages = $capturedPrompt['messages'];
        $this->assertStringNotContainsString('secret-founder@stealth.co', $upstreamMessages[0]['content']);
        $this->assertStringNotContainsString('sk-1234567890abcdef1234567890', $upstreamMessages[0]['content']);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $upstreamMessages[0]['content']);
        $this->assertStringContainsString('[REDACTED_API_KEY]', $upstreamMessages[0]['content']);

        // Verify telemetry log recorded redacted_pii_count
        $log = GatewayLog::where('gateway_key_id', $gatewayKey->id)->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals(2, $log->redacted_pii_count);
    }

    public function test_admin_can_save_combo_with_pii_guardrail(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@omniroute.local',
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)->from('/dashboard/combos')->post('/dashboard/combos', [
            'name' => 'Production Shield Combo ' . uniqid(),
            'description' => 'Combos with PII enabled',
            'strategy' => 'priority',
            'models' => 'openai/gpt-4o, anthropic/claude-3-5-sonnet',
            'compression_modes' => ['dedup', 'lite', 'pii'],
        ]);

        $response->assertRedirect('/dashboard/combos');

        $combo = RoutingCombo::latest()->first();
        $this->assertNotNull($combo);
        $this->assertContains('pii', $combo->compression_modes);
    }
}
