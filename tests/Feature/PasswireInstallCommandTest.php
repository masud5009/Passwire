<?php

namespace Tests\Feature;

use App\Models\GatewayKey;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PasswireInstallCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_passwire_install_command_executes_successfully(): void
    {
        $this->artisan('passwire:install', [
            '--admin-email' => 'ci-admin@passwire.local',
            '--admin-password' => 'secretpass123',
            '--force' => true,
        ])
            ->expectsOutputToContain('Passwire AI Gateway is installed and ready!')
            ->expectsOutputToContain('ci-admin@passwire.local')
            ->assertExitCode(0);

        // Verify the admin user was created with hashed password
        $admin = User::where('email', 'ci-admin@passwire.local')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secretpass123', $admin->password));

        // Verify active master gateway key exists
        $masterKey = GatewayKey::where('is_active', true)->where('key', 'like', 'sk-pw-%')->first();
        $this->assertNotNull($masterKey);
    }
}
