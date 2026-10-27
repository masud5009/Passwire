<?php

namespace Database\Seeders;

use App\Models\ModelPricing;
use Illuminate\Database\Seeder;

class ModelPricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pricings = [
            [
                'model_pattern' => 'deepseek*',
                'display_name' => 'DeepSeek V3 / V4 / Flash',
                'input_cost_per_million' => 0.1400,
                'output_cost_per_million' => 0.2800,
            ],
            [
                'model_pattern' => 'gpt-4o-mini*',
                'display_name' => 'OpenAI GPT-4o Mini',
                'input_cost_per_million' => 0.1500,
                'output_cost_per_million' => 0.6000,
            ],
            [
                'model_pattern' => 'gpt-4o*',
                'display_name' => 'OpenAI GPT-4o (Omni)',
                'input_cost_per_million' => 2.5000,
                'output_cost_per_million' => 10.0000,
            ],
            [
                'model_pattern' => 'claude-3-5-sonnet*',
                'display_name' => 'Anthropic Claude 3.5 Sonnet',
                'input_cost_per_million' => 3.0000,
                'output_cost_per_million' => 15.0000,
            ],
            [
                'model_pattern' => 'claude-3-5-haiku*',
                'display_name' => 'Anthropic Claude 3.5 Haiku',
                'input_cost_per_million' => 0.8000,
                'output_cost_per_million' => 4.0000,
            ],
            [
                'model_pattern' => 'gemini-1.5-flash*',
                'display_name' => 'Google Gemini 1.5 Flash',
                'input_cost_per_million' => 0.0750,
                'output_cost_per_million' => 0.3000,
            ],
            [
                'model_pattern' => 'gemini-1.5-pro*',
                'display_name' => 'Google Gemini 1.5 Pro',
                'input_cost_per_million' => 1.2500,
                'output_cost_per_million' => 5.0000,
            ],
            [
                'model_pattern' => 'qwen*',
                'display_name' => 'Alibaba Qwen 2.5',
                'input_cost_per_million' => 0.2000,
                'output_cost_per_million' => 0.6000,
            ],
            [
                'model_pattern' => 'glm*',
                'display_name' => 'Zhipu GLM-4 / Flash',
                'input_cost_per_million' => 0.1000,
                'output_cost_per_million' => 0.2000,
            ],
            [
                'model_pattern' => '*',
                'display_name' => 'Default Fallback Pricing',
                'input_cost_per_million' => 0.2000,
                'output_cost_per_million' => 0.6000,
            ],
        ];

        foreach ($pricings as $p) {
            ModelPricing::updateOrCreate(
                ['model_pattern' => $p['model_pattern']],
                array_merge($p, ['currency' => 'USD', 'is_active' => true])
            );
        }
    }
}
