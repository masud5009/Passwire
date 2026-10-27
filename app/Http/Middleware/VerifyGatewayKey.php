<?php

namespace App\Http\Middleware;

use App\Models\GatewayKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGatewayKey
{
    /**
     * Standard CORS headers for gateway responses.
     *
     * @var array<string, string>
     */
    protected array $corsHeaders = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With, x-api-key, X-OmniRoute-Key',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Expose-Headers' => 'X-OmniRoute-Provider, X-OmniRoute-Model, X-OmniRoute-Account, X-OmniRoute-Saved-Tokens, X-OmniRoute-Latency-Ms',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Handle CORS preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $this->corsHeaders);
        }

        // 2. Extract API key from Authorization header or x-api-key
        $key = null;
        $authHeader = $request->header('Authorization');

        if ($authHeader) {
            if (str_starts_with($authHeader, 'Bearer ')) {
                $key = trim(substr($authHeader, 7));
            } else {
                $key = trim($authHeader);
            }
        }

        if (empty($key)) {
            $key = trim($request->header('x-api-key') ?? '');
        }

        // 3. Look up active GatewayKey
        $gatewayKey = null;
        if (!empty($key)) {
            $gatewayKey = GatewayKey::where('key', $key)
                ->where('is_active', true)
                ->first();
        }

        if (!$gatewayKey) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid or missing OmniRoute Gateway API key.',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401, $this->corsHeaders);
        }

        // 4. Auto-reset daily usage if the date has changed
        $today = now()->toDateString();
        if (!$gatewayKey->usage_reset_date || $gatewayKey->usage_reset_date->toDateString() < $today) {
            $gatewayKey->tokens_used_today = 0;
            $gatewayKey->usage_reset_date = $today;
            $gatewayKey->save();
        }

        // 5. Check daily token limit
        if ($gatewayKey->daily_token_limit !== null && $gatewayKey->tokens_used_today >= $gatewayKey->daily_token_limit) {
            app(\App\Services\Notification\AlertNotificationService::class)->notifyGatewayKeyLimitExceeded(
                $gatewayKey->name,
                'Daily Token Quota',
                "Consumed {$gatewayKey->tokens_used_today} / {$gatewayKey->daily_token_limit} tokens today."
            );

            return response()->json([
                'error' => [
                    'message' => 'Daily token limit exceeded for this Gateway key.',
                    'type' => 'insufficient_quota',
                    'code' => 'daily_limit_exceeded',
                ],
            ], 429, $this->corsHeaders);
        }

        // 6. Update last_used_at and bind to request attributes
        $gatewayKey->update(['last_used_at' => now()]);
        $request->attributes->set('gateway_key', $gatewayKey);

        // 7. Proceed and attach CORS headers
        $response = $next($request);

        foreach ($this->corsHeaders as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }
}
