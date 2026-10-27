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
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With, x-api-key, X-OmniRoute-Key',
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
     * Atomically record tokens consumed by a gateway key within the current 60-second window.
     */
    public static function recordTokenUsage(GatewayKey $gatewayKey, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $window = (int) floor(time() / 60);
        $tpmKey = "gateway_key_tpm:{$gatewayKey->id}:{$window}";

        if (!Cache::has($tpmKey)) {
            Cache::put($tpmKey, $tokens, 120);
        } else {
            Cache::increment($tpmKey, $tokens);
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
