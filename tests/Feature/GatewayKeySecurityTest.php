<?php

namespace Tests\Feature;

use App\Models\GatewayKey;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GatewayKeySecurityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_gateway_key_stores_sha256_hash_and_prefix_never_plaintext(): void
    {
        $rawKey = 'sk-pw-abcd1234567890abcdef1234567890';
        $key = GatewayKey::create([
            'name' => 'Security Audit Key',
            'key' => $rawKey,
            'is_active' => true,
        ]);

        // Directly inspect raw database row
        $rawRow = DB::table('gateway_keys')->where('id', $key->id)->first();
        $this->assertNotNull($rawRow);

        // Raw row must contain key_hash (64 chars SHA-256) and key_prefix (sk-pw-abcd…)
        $expectedHash = hash('sha256', $rawKey);
        $this->assertEquals($expectedHash, $rawRow->key_hash);
        $this->assertEquals('sk-pw-abcd…', $rawRow->key_prefix);

        // Ensure key column does not exist or raw plaintext is not in the DB row
        $this->assertFalse(property_exists($rawRow, 'key'));

        // Query fresh from Eloquent
        $fresh = GatewayKey::find($key->id);
        $this->assertNull($fresh->getPlainTextKey());
        $this->assertEquals('sk-pw-abcd…', $fresh->key);
        $this->assertEquals('sk-pw-abcd…', $fresh->key_prefix);
        $this->assertEquals($expectedHash, $fresh->key_hash);

        // In JSON serialization, key_hash must be hidden
        $json = $fresh->toArray();
        $this->assertArrayNotHasKey('key_hash', $json);
    }

    public function test_incoming_request_is_authenticated_via_sha256_hash(): void
    {
        $rawKey = 'sk-pw-production-safe-token-998877';
        $key = GatewayKey::create([
            'name' => 'Authenticated Client',
            'key' => $rawKey,
            'is_active' => true,
        ]);

        // 1. Valid key in Authorization Bearer
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->getJson('/v1/models');

        $response->assertStatus(200);

        // 2. Valid key in x-api-key header
        $responseApiKey = $this->withHeaders([
            'x-api-key' => $rawKey,
        ])->getJson('/v1/models');

        $responseApiKey->assertStatus(200);

        // 3. Invalid key fails with 401
        $responseBad = $this->withHeaders([
            'Authorization' => 'Bearer sk-pw-wrong-or-tampered-token',
        ])->getJson('/v1/models');

        $responseBad->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');

        // 4. Deactivated key fails with 401
        $key->update(['is_active' => false]);
        $responseInactive = $this->withHeaders([
            'Authorization' => "Bearer {$rawKey}",
        ])->getJson('/v1/models');

        $responseInactive->assertStatus(401);
    }

    public function test_admin_can_generate_key_and_it_is_flashed_only_once(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@passwire.local',
            'is_admin' => true,
        ]);

        $postResponse = $this->actingAs($admin)->post('/dashboard/api-keys', [
            'name' => 'Workstation Secret Key',
            'daily_token_limit' => 50000,
            'rpm_limit' => 60,
            'tpm_limit' => 100000,
        ]);

        $postResponse->assertSessionHas('new_key_generated');
        $sessionData = session('new_key_generated');

        $this->assertEquals('Workstation Secret Key', $sessionData['name']);
        $this->assertStringStartsWith('sk-pw-', $sessionData['key']);
        $this->assertStringStartsWith('sk-pw-', $sessionData['prefix']);
        $this->assertStringEndsWith('…', $sessionData['prefix']);

        // Check database: raw key is NOT stored in DB, only SHA-256 hash
        $created = GatewayKey::where('name', 'Workstation Secret Key')->first();
        $this->assertNotNull($created);
        $this->assertEquals(hash('sha256', $sessionData['key']), $created->key_hash);
        $this->assertEquals($sessionData['prefix'], $created->key_prefix);

        // 1. Initial response following creation shows the one-time secret key modal
        $initialResponse = $this->actingAs($admin)->get('/dashboard/api-keys');
        $initialResponse->assertStatus(200);
        $initialResponse->assertSee($sessionData['key'], false);
        $initialResponse->assertSee('Save Your API Key', false);
        $initialResponse->assertSee('SHA-256', false);

        // 2. Subsequent request (after flash session expires) does NOT show the full key anywhere
        session()->flush();
        $subsequentResponse = $this->actingAs($admin)->get('/dashboard/api-keys');
        $subsequentResponse->assertStatus(200);
        $subsequentResponse->assertSee($sessionData['prefix'], false);
        $subsequentResponse->assertSee('SHA-256', false);
        $subsequentResponse->assertDontSee($sessionData['key'], false);
    }

    public function test_streaming_requests_enforce_tls_ssl_verification_against_mitm(): void
    {
        $controllerFile = file_get_contents(app_path('Http/Controllers/Api/V1/GatewayController.php'));

        // Ensure CURLOPT_SSL_VERIFYPEER is NEVER set to false
        $this->assertStringNotContainsString(
            'CURLOPT_SSL_VERIFYPEER, false',
            $controllerFile,
            'Insecure cURL option CURLOPT_SSL_VERIFYPEER = false must not be present.'
        );

        // Ensure CURLOPT_SSL_VERIFYPEER is explicitly enabled
        $this->assertStringContainsString(
            'CURLOPT_SSL_VERIFYPEER, true',
            $controllerFile,
            'CURLOPT_SSL_VERIFYPEER must be explicitly true to verify peer certificate.'
        );

        // Ensure CURLOPT_SSL_VERIFYHOST is set to 2
        $this->assertStringContainsString(
            'CURLOPT_SSL_VERIFYHOST, 2',
            $controllerFile,
            'CURLOPT_SSL_VERIFYHOST must be set to 2 to verify domain name matches certificate.'
        );
    }
}
