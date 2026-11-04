<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use App\Services\Gateway\Adapters\GeminiAdapter;
use App\Services\Security\SsrfProtectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SsrfProtectionAndGeminiHeaderTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected SsrfProtectionService $ssrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssrf = new SsrfProtectionService();
        $this->admin = User::first() ?? User::factory()->create([
            'email' => 'admin@passwire.local',
            'is_admin' => true,
        ]);
    }

    public function test_ssrf_service_detects_cloud_metadata_and_private_ips(): void
    {
        // 1. Cloud metadata endpoints (AWS, GCP, Azure, OpenStack)
        $this->assertFalse($this->ssrf->isSafeUrl('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://169.254.169.254:80/computeMetadata/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://metadata.google.internal/computeMetadata/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://instance-data/latest/meta-data'));

        // 2. Loopback and internal private IP networks
        $this->assertFalse($this->ssrf->isSafeUrl('http://127.0.0.1:8000/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://127.0.0.2/api'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://localhost:11434/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://app.localhost/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://internal.service.local/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://database.internal/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://10.0.0.1:8080/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://172.16.0.1:5000/v1'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://192.168.1.1/api'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://0.0.0.0:8000'));
        $this->assertFalse($this->ssrf->isSafeUrl('http://[::1]:8000'));

        // 3. Insecure protocols
        $this->assertFalse($this->ssrf->isSafeUrl('file:///etc/passwd'));
        $this->assertFalse($this->ssrf->isSafeUrl('gopher://127.0.0.1:6379/'));
        $this->assertFalse($this->ssrf->isSafeUrl('ftp://example.com/v1'));

        // 4. Valid public provider endpoints
        $this->assertTrue($this->ssrf->isSafeUrl('https://api.openai.com/v1'));
        $this->assertTrue($this->ssrf->isSafeUrl('https://api.anthropic.com'));
        $this->assertTrue($this->ssrf->isSafeUrl('https://generativelanguage.googleapis.com/v1beta'));
        $this->assertTrue($this->ssrf->isSafeUrl('https://api.groq.com/openai/v1'));
    }

    public function test_admin_cannot_create_provider_pointing_to_metadata_or_private_ip(): void
    {
        $maliciousUrls = [
            'http://169.254.169.254/latest/meta-data',
            'http://127.0.0.1:8000/v1',
            'http://localhost:11434/v1',
            'http://10.0.0.5/api',
            'http://192.168.1.254/v1',
        ];

        foreach ($maliciousUrls as $url) {
            $response = $this->actingAs($this->admin)->post('/dashboard/providers', [
                'name' => 'Exploit Provider',
                'base_url' => $url,
                'format' => 'openai',
            ]);

            $response->assertSessionHasErrors('base_url');
            $this->assertDatabaseMissing('ai_providers', ['base_url' => $url]);
        }
    }

    public function test_admin_cannot_update_provider_to_point_to_private_ip(): void
    {
        $provider = AiProvider::create([
            'name' => 'Safe Provider Initial',
            'slug' => 'safe-init-' . bin2hex(random_bytes(4)),
            'base_url' => 'https://api.groq.com/openai/v1',
            'format' => 'openai',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->put("/dashboard/providers/{$provider->id}", [
            'base_url' => 'http://169.254.169.254/latest/meta-data',
        ]);

        $response->assertSessionHasErrors('base_url');

        $provider->refresh();
        $this->assertEquals('https://api.groq.com/openai/v1', $provider->base_url);
    }

    public function test_admin_can_create_provider_with_safe_public_url(): void
    {
        $response = $this->actingAs($this->admin)->post('/dashboard/providers', [
            'name' => 'Valid Groq Cloud',
            'base_url' => 'https://api.groq.com/openai/v1',
            'format' => 'openai',
            'models_catalog' => "llama-3.3-70b-versatile\nmixtral-8x7b-32768",
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ai_providers', [
            'name' => 'Valid Groq Cloud',
            'base_url' => 'https://api.groq.com/openai/v1',
        ]);
    }

    public function test_gemini_adapter_never_leaks_api_key_in_url_query_parameter(): void
    {
        $adapter = new GeminiAdapter();
        $secretKey = 'AIzaSySecretFakeApiKey1234567890';
        $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

        // 1. Sync generation URL
        $syncUrl = $adapter->buildUrl($baseUrl, 'gemini-1.5-flash', false, $secretKey);
        $this->assertStringNotContainsString('key=', $syncUrl);
        $this->assertStringNotContainsString($secretKey, $syncUrl);
        $this->assertEquals('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent', $syncUrl);

        // 2. Stream generation URL
        $streamUrl = $adapter->buildUrl($baseUrl, 'gemini-1.5-pro', true, $secretKey);
        $this->assertStringNotContainsString('key=', $streamUrl);
        $this->assertStringNotContainsString($secretKey, $streamUrl);
        $this->assertEquals('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro:streamGenerateContent?alt=sse', $streamUrl);

        // 3. API key is securely transmitted via x-goog-api-key header
        $headers = $adapter->formatHeaders($secretKey, false);
        $this->assertArrayHasKey('x-goog-api-key', $headers);
        $this->assertEquals($secretKey, $headers['x-goog-api-key']);

        $curlHeaders = $adapter->formatCurlHeaders($secretKey, true);
        $this->assertContains("x-goog-api-key: {$secretKey}", $curlHeaders);
    }
}

