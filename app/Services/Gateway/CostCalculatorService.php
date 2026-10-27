<?php

namespace App\Services\Gateway;

use App\Models\ModelPricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CostCalculatorService
{
    /**
     * Cache key for active model pricing rules.
     */
    protected const CACHE_KEY = 'omni_model_pricings';

    /**
     * Cache TTL in seconds (1 hour).
     */
    protected const CACHE_TTL = 3600;

    /**
     * Fallback rates if database pricing table is empty.
     */
    protected const DEFAULT_INPUT_RATE = 0.2000;
    protected const DEFAULT_OUTPUT_RATE = 0.6000;

    /**
     * Find pricing rates for a given model string (e.g. "deepseek-ai/DeepSeek-V4.1-Flash" or "gpt-4o").
     *
     * @param string $model
     * @return array{input: float, output: float, currency: string}
     */
    public function getPricingForModel(string $model): array
    {
        $pricings = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return ModelPricing::where('is_active', true)
                ->orderByRaw("CASE WHEN model_pattern = '*' THEN 1 ELSE 0 END") // Specific first, '*' last
                ->get()
                ->toArray();
        });

        $modelLower = strtolower(trim($model));
        $modelWithoutProvider = str_contains($modelLower, '/') 
            ? explode('/', $modelLower, 2)[1] 
            : $modelLower;

        $fallbackPricing = null;

        if (is_iterable($pricings)) {
            foreach ($pricings as $pricing) {
                if (is_array($pricing)) {
                    $rawPattern = $pricing['model_pattern'] ?? '*';
                    $inputRate = (float) ($pricing['input_cost_per_million'] ?? self::DEFAULT_INPUT_RATE);
                    $outputRate = (float) ($pricing['output_cost_per_million'] ?? self::DEFAULT_OUTPUT_RATE);
                    $currency = $pricing['currency'] ?? 'USD';
                } elseif (is_object($pricing)) {
                    $rawPattern = $pricing->model_pattern ?? '*';
                    $inputRate = (float) ($pricing->input_cost_per_million ?? self::DEFAULT_INPUT_RATE);
                    $outputRate = (float) ($pricing->output_cost_per_million ?? self::DEFAULT_OUTPUT_RATE);
                    $currency = $pricing->currency ?? 'USD';
                } else {
                    continue;
                }

                $pattern = strtolower($rawPattern);

                if ($pattern === '*') {
                    $fallbackPricing = [
                        'input' => $inputRate,
                        'output' => $outputRate,
                        'currency' => $currency,
                    ];
                    continue;
                }

                if (Str::is($pattern, $modelLower) 
                    || Str::is($pattern, $modelWithoutProvider)
                    || Str::is('*' . $pattern . '*', $modelLower)) {
                    return [
                        'input' => $inputRate,
                        'output' => $outputRate,
                        'currency' => $currency,
                    ];
                }
            }
        }

        if ($fallbackPricing !== null) {
            return $fallbackPricing;
        }

        return [
            'input' => self::DEFAULT_INPUT_RATE,
            'output' => self::DEFAULT_OUTPUT_RATE,
            'currency' => 'USD',
        ];
    }

    /**
     * Calculate cost and savings for a request trace.
     *
     * @param string $model
     * @param int $promptTokens
     * @param int $completionTokens
     * @param int $savedTokens
     * @param bool $isCached
     * @return array{cost_usd: float, cost_saved_usd: float, input_rate: float, output_rate: float}
     */
    public function calculate(string $model, int $promptTokens, int $completionTokens, int $savedTokens = 0, bool $isCached = false): array
    {
        $pricing = $this->getPricingForModel($model);
        $inputRate = $pricing['input'];
        $outputRate = $pricing['output'];

        if ($isCached) {
            // Cache Hit: actual cost incurred is $0.00, savings is 100% of theoretical cost
            $potentialCost = ($promptTokens * $inputRate / 1_000_000) + ($completionTokens * $outputRate / 1_000_000);
            return [
                'cost_usd' => 0.000000,
                'cost_saved_usd' => round(max(0.000001, $potentialCost), 6),
                'input_rate' => $inputRate,
                'output_rate' => $outputRate,
            ];
        }

        // Live API call: calculate actual money spent
        $actualCost = ($promptTokens * $inputRate / 1_000_000) + ($completionTokens * $outputRate / 1_000_000);

        // Savings from prompt compression (input tokens that were eliminated before hitting upstream)
        $compressionSavedCost = ($savedTokens * $inputRate / 1_000_000);

        return [
            'cost_usd' => round($actualCost, 6),
            'cost_saved_usd' => round($compressionSavedCost, 6),
            'input_rate' => $inputRate,
            'output_rate' => $outputRate,
        ];
    }

    /**
     * Purge in-memory/cache pricing manifest.
     */
    public function clearPricingCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
