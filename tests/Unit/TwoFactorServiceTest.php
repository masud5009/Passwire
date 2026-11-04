<?php

namespace Tests\Unit;

use App\Services\Auth\TwoFactorService;
use PHPUnit\Framework\TestCase;

class TwoFactorServiceTest extends TestCase
{
    public function test_secret_key_generation(): void
    {
        $service = new TwoFactorService();
        $secret = $service->generateSecretKey(16);

        $this->assertEquals(16, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16}$/', $secret);
    }

    public function test_totp_code_generation_and_verification(): void
    {
        $service = new TwoFactorService();
        $secret = $service->generateSecretKey(16);
        $code = $service->generateCode($secret);

        $this->assertEquals(6, strlen($code));
        $this->assertTrue(ctype_digit($code));
        $this->assertTrue($service->verify($secret, $code));

        // Invalid code should fail
        $this->assertFalse($service->verify($secret, '999999'));
        $this->assertFalse($service->verify($secret, 'invalid'));
    }

    public function test_totp_window_tolerance(): void
    {
        $service = new TwoFactorService();
        $secret = $service->generateSecretKey(16);

        // Code generated 25 seconds ago should still be valid with default window = 1
        $pastCode = $service->generateCode($secret, time() - 25);
        $this->assertTrue($service->verify($secret, $pastCode));

        // Code generated 2 minutes ago should fail with window = 1
        $oldCode = $service->generateCode($secret, time() - 120);
        $this->assertFalse($service->verify($secret, $oldCode));
    }

    public function test_recovery_codes_generation_and_consumption(): void
    {
        $service = new TwoFactorService();
        $codes = $service->generateRecoveryCodes(4);

        $this->assertCount(4, $codes);
        $firstCode = $codes[0];

        // Verifying with formatted code (with or without dashes/spaces) should consume it
        $this->assertTrue($service->verifyRecoveryCode($codes, $firstCode));
        $this->assertCount(3, $codes);
        $this->assertNotContains($firstCode, $codes);

        // Using the same code again should fail
        $this->assertFalse($service->verifyRecoveryCode($codes, $firstCode));
    }

    public function test_qr_code_uri_format(): void
    {
        $service = new TwoFactorService();
        $uri = $service->getQrCodeUri('Passwire', 'admin@passwire.local', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/Passwire:admin%40passwire.local?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Passwire', $uri);
    }
}

