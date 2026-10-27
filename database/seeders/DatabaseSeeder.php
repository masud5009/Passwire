<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\RoutingCombo;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Default Admin User
        User::updateOrCreate(
            ['email' => 'admin@omniroute.local'],
            [
                'name' => 'OmniRoute Admin',
                'password' => 'password',
                'email_verified_at' => now(),
            ]
        );

        // 2. Pre-configured AI Providers (Native adapters starter providers)
        $providers = [
            [
                'slug' => 'gemini',
                'name' => 'Google Gemini (Native)',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'format' => 'gemini',
                'description' => 'Direct connection to Google Gemini REST API with native SSE streaming support.',
                'models_catalog' => ['gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-1.5-flash-8b'],
                'is_active' => true,
            ],
            [
                'slug' => 'anthropic',
                'name' => 'Anthropic Claude (Native)',
                'base_url' => 'https://api.anthropic.com/v1',
                'format' => 'anthropic',
                'description' => 'Direct connection to Anthropic Messages API with native SSE stream translation.',
                'models_catalog' => ['claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022', 'claude-3-opus-20240229'],
                'is_active' => true,
            ],
        ];

        foreach ($providers as $providerData) {
            AiProvider::firstOrCreate(
                ['slug' => $providerData['slug']],
                $providerData
            );
        }

        // 3. Default Routing Combos
        $combos = [
            [
                'name' => 'auto',
                'description' => 'Default general purpose routing combo',
                'strategy' => 'priority',
                'models' => [
                    'gemini/gemini-2.5-flash',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'auto/free',
                'description' => 'Free-tier optimized routing combo',
                'strategy' => 'priority',
                'models' => [
                    'gemini/gemini-2.5-flash',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'auto/coding',
                'description' => 'Coding and reasoning models routing combo',
                'strategy' => 'priority',
                'models' => [
                    'gemini/gemini-2.5-pro',
                    'gemini/gemini-2.5-flash',
                ],
                'is_active' => true,
            ],
        ];

        foreach ($combos as $comboData) {
            RoutingCombo::updateOrCreate(
                ['name' => $comboData['name']],
                $comboData
            );
        }

        // 4. Default Gateway Key
        GatewayKey::updateOrCreate(
            ['key' => 'sk-omni-master-key-1234567890abcdef'],
            [
                'name' => 'Default Master Key',
                'is_active' => true,
            ]
        );
    }
}
