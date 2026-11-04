<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminLoginThrottleAndTwoFactorTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        RateLimiter::clear('admin-test@passwire.local|127.0.0.1');
        RateLimiter::clear('admin2fa-test@passwire.local|127.0.0.1');
        parent::tearDown();
    }

    public function test_login_attempts_are_throttled_by_email_and_ip(): void
    {
        $user = User::factory()->create([
            'email' => 'admin-test@passwire.local',
            'password' => Hash::make('correct-password'),
        ]);

        $throttleKey = 'admin-test@passwire.local|127.0.0.1';
        RateLimiter::clear($throttleKey);

        // Attempt 1 to 5 with wrong password
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->from('/login')->post('/login', [
                'email' => 'admin-test@passwire.local',
                'password' => 'wrong-password',
            ]);

            $response->assertRedirect('/login');
            $response->assertSessionHasErrors('email');
            $this->assertEquals($i, RateLimiter::attempts($throttleKey));
        }

        // 6th attempt should be blocked by RateLimiter
        $responseLockout = $this->from('/login')->post('/login', [
            'email' => 'admin-test@passwire.local',
            'password' => 'even-correct-password-now',
        ]);

        $responseLockout->assertRedirect('/login');
        $responseLockout->assertSessionHasErrors('email');

        $errorMessage = session('errors')->first('email');
        $this->assertStringContainsString('Too many login attempts', $errorMessage);

        // Clear rate limiter and login should succeed
        RateLimiter::clear($throttleKey);

        $responseSuccess = $this->post('/login', [
            'email' => 'admin-test@passwire.local',
            'password' => 'correct-password',
        ]);

        $responseSuccess->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_with_2fa_is_redirected_to_two_factor_challenge(): void
    {
        /** @var TwoFactorService $twoFactorService */
        $twoFactorService = app(TwoFactorService::class);
        $secret = $twoFactorService->generateSecretKey();
        $recoveryCodes = $twoFactorService->generateRecoveryCodes();

        $user = User::factory()->create([
            'email' => 'admin2fa-test@passwire.local',
            'password' => Hash::make('secretpassword123'),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ]);

        // 1. Initial login with valid credentials
        $response = $this->post('/login', [
            'email' => 'admin2fa-test@passwire.local',
            'password' => 'secretpassword123',
        ]);

        // User is NOT yet logged in
        $this->assertGuest();
        $response->assertRedirect('/login/two-factor');
        $this->assertTrue(session()->has('login.2fa_user_id'));
        $this->assertEquals($user->id, session('login.2fa_user_id'));

        // 2. Accessing 2FA challenge page
        $challengePage = $this->get('/login/two-factor');
        $challengePage->assertStatus(200);
        $challengePage->assertSee('Two-Factor Authentication');

        // 3. Submitting invalid TOTP code
        $badCodeResponse = $this->from('/login/two-factor')->post('/login/two-factor', [
            'code' => '000000',
        ]);

        $badCodeResponse->assertRedirect('/login/two-factor');
        $badCodeResponse->assertSessionHasErrors('code');
        $this->assertGuest();

        // 4. Submitting valid TOTP code
        $validCode = $twoFactorService->generateCode($secret);
        $validCodeResponse = $this->post('/login/two-factor', [
            'code' => $validCode,
        ]);

        $validCodeResponse->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_using_emergency_recovery_code(): void
    {
        /** @var TwoFactorService $twoFactorService */
        $twoFactorService = app(TwoFactorService::class);
        $secret = $twoFactorService->generateSecretKey();
        $recoveryCodes = $twoFactorService->generateRecoveryCodes(4);
        $firstRecoveryCode = $recoveryCodes[0];

        $user = User::factory()->create([
            'email' => 'admin-recovery@passwire.local',
            'password' => Hash::make('secretpassword123'),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ]);

        // Trigger 2FA
        $this->post('/login', [
            'email' => 'admin-recovery@passwire.local',
            'password' => 'secretpassword123',
        ]);

        // Submit recovery code
        $response = $this->post('/login/two-factor', [
            'recovery_code' => $firstRecoveryCode,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // Recovery code should be consumed
        $user->refresh();
        $this->assertCount(3, $user->two_factor_recovery_codes);
        $this->assertNotContains($firstRecoveryCode, $user->two_factor_recovery_codes);
    }

    public function test_guest_without_pending_2fa_is_redirected_away_from_challenge(): void
    {
        $response = $this->get('/login/two-factor');
        $response->assertRedirect('/login');

        $postResponse = $this->post('/login/two-factor', [
            'code' => '123456',
        ]);
        $postResponse->assertRedirect('/login');
    }

    public function test_admin_can_setup_confirm_and_disable_2fa_in_dashboard(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin-settings@passwire.local',
            'password' => Hash::make('mypassword123'),
        ]);

        // 1. Enable 2FA setup
        $enableResponse = $this->actingAs($admin)->post('/dashboard/two-factor/enable');
        $enableResponse->assertRedirect('/dashboard/password');

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);

        // 2. Confirm 2FA with valid TOTP code
        /** @var TwoFactorService $twoFactorService */
        $twoFactorService = app(TwoFactorService::class);
        $code = $twoFactorService->generateCode($admin->two_factor_secret);

        $confirmResponse = $this->actingAs($admin)->post('/dashboard/two-factor/confirm', [
            'code' => $code,
        ]);

        $confirmResponse->assertRedirect('/dashboard/password');
        $confirmResponse->assertSessionHas('success');
        $confirmResponse->assertSessionHas('recovery_codes_generated');

        $admin->refresh();
        $this->assertTrue($admin->hasEnabledTwoFactorAuthentication());
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertCount(8, $admin->two_factor_recovery_codes);

        // 3. Disable 2FA
        $disableResponse = $this->actingAs($admin)->delete('/dashboard/two-factor/disable', [
            'current_password' => 'mypassword123',
        ]);

        $disableResponse->assertRedirect('/dashboard/password');
        $admin->refresh();
        $this->assertFalse($admin->hasEnabledTwoFactorAuthentication());
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
    }
}

