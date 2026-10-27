<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayEmbeddingsTest extends TestCase
{
    use DatabaseTransactions;

    protected GatewayKey $gatewayKey;
    protected AiProvider $openAiProvider;
    protected ProviderAccount $openAiAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gatewayKey = GatewayKey::create([
            'name' => 'Embeddings Test Key',
            'key' => 'sk-pw-embeddings-' . bin2hex(random_bytes(8)),
            'is_active' => true,
        ]);

        $this->openAiProvider = AiProvider::create([
            'name' => 'OpenAI Embeddings Provider',
            'slug' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'format' => 'openai',
            'models_catalog' => ['text-embedding-3-small', 'text-embedding-3-large'],
            'is_active' => true,
        ]);

        $this->openAiAccount = ProviderAccount::create([
            'ai_provider_id' => $this->openAiProvider->id,
            'label' => 'OpenAI Primary Embeddings Key',
            'api_key' => 'sk-test-openai-embeddings-key',
            'priority' => 1,
            'is_active' => true,
        ]);
    }

    public function test_embeddings_requires_authentication(): void
    {
        $response = $this->postJson('/v1/embeddings', [
            'model' => 'text-embedding-3-small',
            'input' => 'Hello embeddings',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_embeddings_validates_input_parameter(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->gatewayKey->key}",
        ])->postJson('/v1/embeddings', [
            'model' => 'text-embedding-3-small',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'missing_parameter');
    }

    public function test_openai_embeddings_dispatches_successfully(): void
    {
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [
                    [
                        'object' => 'embedding',
                        'index' => 0,
                        'embedding' => [0.0123, -0.0456, 0.0789],
                    ],
                ],
                'model' => 'text-embedding-3-small',
                'usage' => [
                    'prompt_tokens' => 5,
                    'total_tokens' => 5,
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->gatewayKey->key}",
        ])->postJson('/v1/embeddings', [
            'model' => 'text-embedding-3-small',
            'input' => 'Antigravity AI Gateway',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Passwire-Provider', 'openai');
        $response->assertHeader('X-Passwire-Model', 'text-embedding-3-small');
        $response->assertJsonPath('object', 'list');
        $response->assertJsonPath('data.0.object', 'embedding');
        $response->assertJsonPath('data.0.embedding.0', 0.0123);
        $response->assertJsonPath('usage.prompt_tokens', 5);
    }

    public function test_gemini_embeddings_translates_and_normalizes_successfully(): void
    {
        $geminiProvider = AiProvider::create([
            'name' => 'Gemini Embeddings Provider',
            'slug' => 'gemini-embed',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'format' => 'gemini',
            'models_catalog' => ['text-embedding-004'],
            'is_active' => true,
        ]);

        ProviderAccount::create([
            'ai_provider_id' => $geminiProvider->id,
            'label' => 'Gemini Embed Account',
            'api_key' => 'AIzaSyFakeGeminiEmbeddingsKey123',
            'priority' => 1,
            'is_active' => true,
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:batchEmbedContents*' => Http::response([
                'embeddings' => [
                    ['values' => [0.111, 0.222, 0.333]],
                    ['values' => [0.444, 0.555, 0.666]],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->gatewayKey->key}",
        ])->postJson('/v1/embeddings', [
            'model' => 'gemini-embed/text-embedding-004',
            'input' => ['First chunk', 'Second chunk'],
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Passwire-Provider', 'gemini-embed');
        $response->assertJsonPath('object', 'list');
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.embedding.0', 0.111);
        $response->assertJsonPath('data.1.embedding.0', 0.444);
    }

    public function test_embeddings_redacts_pii_in_input(): void
    {
        $capturedPayload = null;

        Http::fake([
            'https://api.openai.com/v1/embeddings' => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response([
                    'object' => 'list',
                    'data' => [
                        ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]],
                    ],
                    'model' => 'text-embedding-3-small',
                    'usage' => ['prompt_tokens' => 6, 'total_tokens' => 6],
                ], 200);
            },
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->gatewayKey->key}",
        ])->postJson('/v1/embeddings', [
            'model' => 'text-embedding-3-small',
            'input' => 'My secret email is founder@stealth.io and key is sk-1234567890abcdef1234567890.',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Passwire-Redacted-PII', '2');

        $this->assertNotNull($capturedPayload);
        $this->assertStringNotContainsString('founder@stealth.io', $capturedPayload['input']);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $capturedPayload['input']);
        $this->assertStringContainsString('[REDACTED_API_KEY]', $capturedPayload['input']);
    }
}
