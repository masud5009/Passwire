<?php

namespace App\Console\Commands;

use App\Models\GatewayKey;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class PasswireInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'passwire:install 
                            {--force : Force rerun of migrations and seeder}
                            {--admin-email= : Pre-defined administrator email}
                            {--admin-password= : Pre-defined administrator password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up and initialize Passwire AI Gateway environment, database, and admin user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayBanner();

        // 1. Environment configuration
        $this->info('⚙️  Step 1/5: Checking Environment Configuration...');
        $envPath = base_path('.env');
        $envExamplePath = base_path('.env.example');

        if (!File::exists($envPath)) {
            if (File::exists($envExamplePath)) {
                File::copy($envExamplePath, $envPath);
                $this->line('   <fg=green>✓</> Created .env file from .env.example');
            } else {
                $this->warn('   ⚠️  .env.example not found. Please ensure .env is configured.');
            }
        } else {
            $this->line('   <fg=green>✓</> .env file already exists');
        }

        // Generate application key if not set
        if (empty(config('app.key'))) {
            $this->call('key:generate', ['--force' => true]);
            $this->line('   <fg=green>✓</> Generated application encryption key');
        }

        // 2. Database Migrations
        $this->newLine();
        $this->info('📦 Step 2/5: Running Database Migrations...');
        $this->call('migrate', ['--force' => true]);

        // 3. Database Seeders
        $this->newLine();
        $this->info('🌱 Step 3/5: Seeding AI Providers and Routing Combos...');
        $this->call('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ]);

        // 4. Storage Linking
        $this->newLine();
        $this->info('🔗 Step 4/5: Linking Public Storage...');
        if (!File::exists(public_path('storage'))) {
            $this->call('storage:link');
        } else {
            $this->line('   <fg=green>✓</> Public storage link already active');
        }

        // 5. Admin User Configuration
        $this->newLine();
        $this->info('👤 Step 5/5: Configuring Administrator Account...');

        $adminEmail = $this->option('admin-email');
        if (empty($adminEmail)) {
            if ($this->input->isInteractive()) {
                $adminEmail = $this->ask('Enter Administrator Email', 'admin@passwire.local');
            } else {
                $adminEmail = 'admin@passwire.local';
            }
        }

        $adminPassword = $this->option('admin-password');
        if (empty($adminPassword)) {
            if ($this->input->isInteractive()) {
                $adminPassword = $this->secret('Enter Administrator Password (leave empty for "password")');
                if (empty($adminPassword)) {
                    $adminPassword = 'password';
                }
            } else {
                $adminPassword = 'password';
            }
        }

        $admin = User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Passwire Administrator',
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
            ]
        );

        $this->line("   <fg=green>✓</> Administrator ready: <fg=cyan>{$admin->email}</>");

        // 6. Master Gateway Key
        $masterKey = GatewayKey::where('is_active', true)
            ->where('key', 'like', 'sk-pw-%')
            ->latest()
            ->first() ?? GatewayKey::where('is_active', true)->latest()->first();

        if (!$masterKey) {
            $masterKey = GatewayKey::create([
                'name' => 'Default Master Key',
                'key' => 'sk-pw-' . bin2hex(random_bytes(16)),
                'is_active' => true,
            ]);
        }

        // 7. Success Banner & Instructions
        $this->displaySuccessSummary($adminEmail, $adminPassword, $masterKey->key);

        return self::SUCCESS;
    }

    /**
     * Display the Passwire ASCII art banner.
     */
    protected function displayBanner(): void
    {
        $this->newLine();
        $this->line('<fg=green;options=bold>  ____                               _           </>');
        $this->line('<fg=green;options=bold> |  _ \ __ _ ___ _____      _____ __(_)___ _ __  </>');
        $this->line('<fg=green;options=bold> | |_) / _` / __/ __\ \ /\ / / _ \ \'__| / _ \ \'_ \ </>');
        $this->line('<fg=green;options=bold> |  __/ (_| \__ \__ \\ V  V /  __/ |  | |  __/ | | |</>');
        $this->line('<fg=green;options=bold> |_|   \__,_|___/___/ \_/\_/ \___|_|  |_|\___|_| |_|</>');
        $this->line('<fg=gray>        ⚡ Production-Ready Open Source AI Gateway</>');
        $this->newLine();
    }

    /**
     * Display a clean installation summary with credentials and quickstart snippet.
     */
    protected function displaySuccessSummary(string $email, string $password, string $apiKey): void
    {
        $appUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');

        $this->newLine();
        $this->line('<bg=green;fg=black;options=bold> SUCCESS </> <fg=green;options=bold>Passwire AI Gateway is installed and ready!</>');
        $this->newLine();

        $this->table(
            ['Key', 'Value'],
            [
                ['Console URL', "{$appUrl}/login"],
                ['Admin Email', $email],
                ['Admin Password', $password],
                ['Master Gateway Key', $apiKey],
                ['API Endpoint', "{$appUrl}/v1/chat/completions"],
            ]
        );

        $this->newLine();
        $this->line('<fg=yellow;options=bold>Quick Test with cURL:</>');
        $this->line("<fg=gray>curl {$appUrl}/v1/chat/completions \\</>");
        $this->line("  <fg=gray>-H \"Authorization: Bearer {$apiKey}\" \\</>");
        $this->line("  <fg=gray>-H \"Content-Type: application/json\" \\</>");
        $this->line("  <fg=gray>-d '{\"model\":\"auto\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello Passwire!\"}]}'</>");
        $this->newLine();
    }
}
