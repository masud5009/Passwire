<?php

namespace App\Http\Middleware;

use App\Models\GatewayKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnforceGatewayRateLimits
{
    /**
     * Standard CORS headers to attach on error responses.
     *
     * @var array<string, string>
     */
    protected array $corsHeaders = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With, x-api-key, X-Passwire-Key, X-OmniRoute-Key',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var GatewayKey|null $gatewayKey */
        $gatewayKey = $request->attributes->get('gateway_key');

        if (!$gatewayKey) {
            return $next($request);
        }

        $headers = [];

        // 1. Enforce Requests-Per-Minute (RPM) limit if configured
        if ($gatewayKey->rpm_limit !== null && $gatewayKey->rpm_limit > 0) {
            $rpmKey = "gateway_key_rpm:{$gatewayKey->id}";

            if (RateLimiter::tooManyAttempts($rpmKey, $gatewayKey->rpm_limit)) {
                $retryAfter = RateLimiter::availableIn($rpmKey);

                app(\App\Services\Notification\AlertNotificationService::class)->notifyGatewayKeyLimitExceeded(
                    $gatewayKey->name,
                    'Requests-Per-Minute (RPM)',
                    "Limit of {$gatewayKey->rpm_limit} RPM exceeded. Retry in {$retryAfter} seconds."
                );

                return response()->json([
                    'error' => [
                        'message' => "Requests-per-minute (RPM) limit of {$gatewayKey->rpm_limit} exceeded for Gateway key '{$gatewayKey->name}'. Retry in {$retryAfter} seconds.",
                        'type' => 'requests_per_minute_exceeded',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], 429, array_merge($this->corsHeaders, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit-Requests' => (string) $gatewayKey->rpm_limit,
                    'X-RateLimit-Remaining-Requests' => '0',
                    'X-RateLimit-Reset-Requests' => (string) $retryAfter,
                ]));
            }

            // Record request hit (60s decay window)
            RateLimiter::hit($rpmKey, 60);

            $remainingRpm = RateLimiter::remaining($rpmKey, $gatewayKey->rpm_limit);
            $headers['X-RateLimit-Limit-Requests'] = (string) $gatewayKey->rpm_limit;
            $headers['X-RateLimit-Remaining-Requests'] = (string) max(0, $remainingRpm);
        }

        // 2. Enforce Tokens-Per-Minute (TPM) limit if configured
        if ($gatewayKey->tpm_limit !== null && $gatewayKey->tpm_limit > 0) {
            $window = (int) floor(time() / 60);
            $tpmKey = "gateway_key_tpm:{$gatewayKey->id}:{$window}";
            $consumedTokens = (int) Cache::get($tpmKey, 0);

            if ($consumedTokens >= $gatewayKey->tpm_limit) {
                $retryAfter = max(1, 60 - (time() % 60));

                app(\App\Services\Notification\AlertNotificationService::class)->notifyGatewayKeyLimitExceeded(
                    $gatewayKey->name,
                    'Tokens-Per-Minute (TPM)',
                    "Limit of " . number_format($gatewayKey->tpm_limit) . " TPM exceeded ({$consumedTokens} tokens consumed). Retry in {$retryAfter} seconds."
                );

                return response()->json([
                    'error' => [
                        'message' => "Tokens-per-minute (TPM) limit of " . number_format($gatewayKey->tpm_limit) . " exceeded for Gateway key '{$gatewayKey->name}'. (" . number_format($consumedTokens) . " tokens consumed in current minute). Retry in {$retryAfter} seconds.",
                        'type' => 'tokens_per_minute_exceeded',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], 429, array_merge($this->corsHeaders, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit-Tokens' => (string) $gatewayKey->tpm_limit,
                    'X-RateLimit-Remaining-Tokens' => '0',
                    'X-RateLimit-Reset-Tokens' => (string) $retryAfter,
                ]));
            }

            $remainingTpm = max(0, $gatewayKey->tpm_limit - $consumedTokens);
            $headers['X-RateLimit-Limit-Tokens'] = (string) $gatewayKey->tpm_limit;
            $headers['X-RateLimit-Remaining-Tokens'] = (string) $remainingTpm;
        }

        $response = $next($request);

        // Attach rate-limit headers to outgoing response
        foreach ($headers as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }

    /**
     * Atomically record tokens consumed by a gateway key within the current 60-second window (TPM).
     */
    public static function recordTokenUsage(GatewayKey $gatewayKey, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $window = (int) floor(time() / 60);
        $tpmKey = "gateway_key_tpm:{$gatewayKey->id}:{$window}";

        // Cache::add is atomic: returns true only if the key was missing and inserted.
        // If false, the key already exists, so we increment atomically.
        if (Cache::add($tpmKey, $tokens, 120)) {
            return;
        }

        Cache::increment($tpmKey, $tokens);
    }

    /**
     * Get the cache key for daily token consumption tracking.
     */
    public static function getDailyCacheKey(GatewayKey $gatewayKey, ?string $date = null): string
    {
        $date = $date ?? now()->toDateString();
        return "gateway_key_daily_tokens:{$gatewayKey->id}:{$date}";
    }

    /**
     * Get real-time daily token usage, synchronized between cache and database.
     */
    public static function getDailyUsage(GatewayKey $gatewayKey): int
    {
        $today = now()->toDateString();
        $cacheKey = self::getDailyCacheKey($gatewayKey, $today);
        $dbUsage = (int) ($gatewayKey->tokens_used_today ?? 0);

        // Atomically initialize cache key if it does not exist yet
        Cache::add($cacheKey, $dbUsage, 86400 * 2);

        return (int) Cache::get($cacheKey, $dbUsage);
    }

    /**
     * Estimate token requirement for an incoming chat completion request.
     */
    public static function estimateRequiredTokens(array $payload, ?GatewayKey $gatewayKey = null): int
    {
        $tokenizer = app(\App\Services\Gateway\TokenizerService::class);
        $promptTokens = $tokenizer->estimatePromptTokens($payload);

        $requestedMax = null;
        if (isset($payload['max_tokens']) && is_numeric($payload['max_tokens'])) {
            $requestedMax = (int) $payload['max_tokens'];
        } elseif (isset($payload['max_completion_tokens']) && is_numeric($payload['max_completion_tokens'])) {
            $requestedMax = (int) $payload['max_completion_tokens'];
        }

        if ($requestedMax !== null) {
            $completionEstimate = min($requestedMax, 1000);
        } else {
            $remaining = 500;
            if ($gatewayKey && $gatewayKey->daily_token_limit !== null) {
                $currentUsage = self::getDailyUsage($gatewayKey);
                $remainingQuota = $gatewayKey->daily_token_limit - $currentUsage;
                if ($remainingQuota > $promptTokens) {
                    $remaining = min(500, max(50, $remainingQuota - $promptTokens));
                }
            }
            $completionEstimate = $remaining;
        }

        return max(1, $promptTokens + $completionEstimate);
    }

    /**
     * Estimate token requirement for an incoming embeddings request.
     */
    public static function estimateEmbeddingTokens(array $payload, ?GatewayKey $gatewayKey = null): int
    {
        $input = $payload['input'] ?? '';
        $chars = 0;
        if (is_string($input)) {
            $chars = strlen($input);
        } elseif (is_array($input)) {
            foreach ($input as $item) {
                $chars += strlen((string) $item);
            }
        }
        return max(1, (int) ceil($chars / 4));
    }

    /**
     * Atomically pre-reserve daily tokens in cache to prevent concurrent overshooting of daily quota.
     *
     * @return bool True if reservation succeeded within limit, false if quota exceeded
     */
    public static function reserveDailyTokens(GatewayKey $gatewayKey, int $estimatedTokens): bool
    {
        if ($gatewayKey->daily_token_limit === null || $gatewayKey->daily_token_limit <= 0) {
            return true;
        }

        if ($estimatedTokens <= 0) {
            $estimatedTokens = 1;
        }

        $today = now()->toDateString();
        $cacheKey = self::getDailyCacheKey($gatewayKey, $today);
        $dbUsage = (int) ($gatewayKey->tokens_used_today ?? 0);

        // Atomically initialize cache key with DB usage if missing
        Cache::add($cacheKey, $dbUsage, 86400 * 2);

        // Atomically increment the daily token consumption counter
        $newUsage = Cache::increment($cacheKey, $estimatedTokens);

        // If limit is exceeded by this reservation, rollback immediately
        if ($newUsage > $gatewayKey->daily_token_limit) {
            Cache::decrement($cacheKey, $estimatedTokens);
            return false;
        }

        return true;
    }

    /**
     * Settle actual tokens consumed: adjust reservation difference in cache and update DB.
     */
    public static function settleDailyTokens(GatewayKey $gatewayKey, int $reservedTokens, int $actualTokens): void
    {
        $today = now()->toDateString();
        $cacheKey = self::getDailyCacheKey($gatewayKey, $today);

        // Settle the difference in the cache counter
        $diff = $actualTokens - $reservedTokens;
        if ($diff > 0) {
            Cache::increment($cacheKey, $diff);
        } elseif ($diff < 0) {
            $val = Cache::decrement($cacheKey, abs($diff));
            if ($val < 0) {
                Cache::put($cacheKey, max(0, (int) $gatewayKey->tokens_used_today + $actualTokens), 86400 * 2);
            }
        }

        // Increment database daily usage
        $gatewayKey->increment('tokens_used_today', $actualTokens);
        $gatewayKey->update(['last_used_at' => now()]);

        // Record for TPM
        self::recordTokenUsage($gatewayKey, $actualTokens);
    }

    /**
     * Rollback reserved daily tokens in cache if request fails or is aborted.
     */
    public static function rollbackDailyTokens(GatewayKey $gatewayKey, int $reservedTokens): void
    {
        if ($reservedTokens <= 0) {
            return;
        }

        $today = now()->toDateString();
        $cacheKey = self::getDailyCacheKey($gatewayKey, $today);

        $val = Cache::decrement($cacheKey, $reservedTokens);
        if ($val < 0) {
            Cache::put($cacheKey, max(0, (int) $gatewayKey->tokens_used_today), 86400 * 2);
        }
    }

    /**
     * Get current real-time rate limit telemetry for a GatewayKey.
     *
     * @return array{rpm_limit: int|null, rpm_used: int, tpm_limit: int|null, tpm_used: int}
     */
    public static function getRateLimitStatus(GatewayKey $gatewayKey): array
    {
        $rpmKey = "gateway_key_rpm:{$gatewayKey->id}";
        $rpmUsed = $gatewayKey->rpm_limit !== null
            ? ($gatewayKey->rpm_limit - RateLimiter::remaining($rpmKey, $gatewayKey->rpm_limit))
            : 0;

        $window = (int) floor(time() / 60);
        $tpmKey = "gateway_key_tpm:{$gatewayKey->id}:{$window}";
        $tpmUsed = (int) Cache::get($tpmKey, 0);

        return [
            'rpm_limit' => $gatewayKey->rpm_limit,
            'rpm_used' => max(0, $rpmUsed),
            'tpm_limit' => $gatewayKey->tpm_limit,
            'tpm_used' => max(0, $tpmUsed),
        ];
    }
}
