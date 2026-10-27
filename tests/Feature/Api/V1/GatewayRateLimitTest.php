<?php

namespace Tests\Feature\Api\V1;

use App\Http\Middleware\EnforceGatewayRateLimits;
use App\Models\GatewayKey;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class GatewayRateLimitTest extends TestCase
{
    use DatabaseTransactions;
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('gateway_key_rpm:*');
        Cache::flush();
    }

    public function test_gateway_key_enforces_rpm_limit_and_returns_429(): void
    {
        $key = GatewayKey::create([
            'name' => 'RPM Test Key',
            'key' => 'gw-rpm-test-' . uniqid(),
            'rpm_limit' => 2,
            'tpm_limit' => null,
            'is_active' => true,
        ]);

        RateLimiter::clear("gateway_key_rpm:{$key->id}");

        // Request 1: Should succeed with 1 remaining
        $res1 = $this->withHeaders([
            'Authorization' => "Bearer {$key->key}",
        ])->getJson('/v1/models');

        $res1->assertStatus(200);
        $res1->assertHeader('X-RateLimit-Limit-Requests', '2');
        $res1->assertHeader('X-RateLimit-Remaining-Requests', '1');

        // Request 2: Should succeed with 0 remaining
        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$key->key}",
        ])->getJson('/v1/models');

        $res2->assertStatus(200);
        $res2->assertHeader('X-RateLimit-Limit-Requests', '2');
        $res2->assertHeader('X-RateLimit-Remaining-Requests', '0');

        // Request 3: Exceeds limit -> 429 Too Many Requests
        $res3 = $this->withHeaders([
            'Authorization' => "Bearer {$key->key}",
        ])->getJson('/v1/models');

        $res3->assertStatus(429);
        $res3->assertHeader('X-RateLimit-Limit-Requests', '2');
        $res3->assertHeader('X-RateLimit-Remaining-Requests', '0');
        $this->assertTrue($res3->headers->has('Retry-After'));
        $res3->assertJsonPath('error.type', 'requests_per_minute_exceeded');
        $res3->assertJsonPath('error.code', 'rate_limit_exceeded');
    }

    public function test_gateway_key_enforces_tpm_limit_and_returns_429(): void
    {
        $key = GatewayKey::create([
            'name' => 'TPM Test Key',
            'key' => 'gw-tpm-test-' . uniqid(),
            'rpm_limit' => null,
            'tpm_limit' => 500,
            'is_active' => true,
        ]);

        // Consume 600 tokens (exceeds 500 TPM limit)
        EnforceGatewayRateLimits::recordTokenUsage($key, 600);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$key->key}",
        ])->getJson('/v1/models');

        $response->assertStatus(429);
        $response->assertHeader('X-RateLimit-Limit-Tokens', '500');
        $response->assertHeader('X-RateLimit-Remaining-Tokens', '0');
        $this->assertTrue($response->headers->has('Retry-After'));
        $response->assertJsonPath('error.type', 'tokens_per_minute_exceeded');
        $response->assertJsonPath('error.code', 'rate_limit_exceeded');
    }

    public function test_unlimited_gateway_key_allows_requests_without_rate_limit_headers(): void
    {
        $key = GatewayKey::create([
            'name' => 'Unlimited Key',
            'key' => 'gw-unlimited-test-' . uniqid(),
            'rpm_limit' => null,
            'tpm_limit' => null,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$key->key}",
        ])->getJson('/v1/models');

        $response->assertStatus(200);
        $this->assertFalse($response->headers->has('X-RateLimit-Limit-Requests'));
        $this->assertFalse($response->headers->has('X-RateLimit-Limit-Tokens'));
    }

    public function test_admin_can_update_gateway_key_rate_limits(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@omniroute.local',
            'is_admin' => true,
        ]);

        $key = GatewayKey::create([
            'name' => 'Original Key',
            'key' => 'gw-update-test-' . uniqid(),
            'daily_token_limit' => 100000,
            'rpm_limit' => 60,
            'tpm_limit' => 200000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->from('/dashboard/gateway-keys')->put("/dashboard/gateway-keys/{$key->id}", [
            'name' => 'Updated Production Key',
            'daily_token_limit' => 500000,
            'rpm_limit' => 120,
            'tpm_limit' => 400000,
        ]);

        $response->assertRedirect('/dashboard/gateway-keys');

        $key->refresh();
        $this->assertEquals('Updated Production Key', $key->name);
        $this->assertEquals(500000, $key->daily_token_limit);
        $this->assertEquals(120, $key->rpm_limit);
        $this->assertEquals(400000, $key->tpm_limit);
        $this->assertTrue((bool) $key->is_active);
    }
}
