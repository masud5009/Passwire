<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\GatewayLog;
use App\Models\RoutingCombo;
use App\Services\Gateway\CostCalculatorService;
use App\Services\Gateway\FormatTranslator;
use App\Services\Gateway\OmniRouterService;
use App\Services\Gateway\PromptCompressor;
use App\Services\Gateway\ResponseCacheService;
use App\Services\Gateway\TokenizerService;
use App\Services\Notification\AlertNotificationService;
use App\Services\Security\SsrfProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GatewayController extends Controller
{
    protected AlertNotificationService $alertService;
    protected TokenizerService $tokenizer;
    protected SsrfProtectionService $ssrf;

    /**
     * Create a new GatewayController instance.
     */
    public function __construct(
        protected OmniRouterService $router,
        protected PromptCompressor $compressor,
        protected FormatTranslator $translator,
        protected ResponseCacheService $cacheService,
        protected CostCalculatorService $costCalculator,
        ?AlertNotificationService $alertService = null,
        ?TokenizerService $tokenizer = null,
        ?SsrfProtectionService $ssrf = null
    ) {
        $this->alertService = $alertService ?? new AlertNotificationService();
        $this->tokenizer = $tokenizer ?? new TokenizerService();
        $this->ssrf = $ssrf ?? new SsrfProtectionService();
    }

    /**
     * Return OpenAI-compatible model list.
     *
     * GET /v1/models
     */
    public function models(Request $request): JsonResponse
    {
        $models = [];

        // 1. Include active RoutingCombos
        $combos = RoutingCombo::where('is_active', true)->get();
        foreach ($combos as $combo) {
            $models[] = [
                'id' => $combo->name,
                'object' => 'model',
                'created' => $combo->created_at?->timestamp ?? time(),
                'owned_by' => 'passwire',
                'permission' => [],
                'root' => $combo->name,
                'parent' => null,
            ];
        }

        // 2. Include provider_slug/model_name for active providers with at least one active account
        $providers = AiProvider::where('is_active', true)
            ->whereHas('accounts', function ($q) {
                $q->where('is_active', true);
            })
            ->get();

        foreach ($providers as $provider) {
            $catalog = $provider->models_catalog ?? [];
            if (is_array($catalog)) {
                foreach ($catalog as $modelName) {
                    $fullId = "{$provider->slug}/{$modelName}";
                    $models[] = [
                        'id' => $fullId,
                        'object' => 'model',
                        'created' => $provider->created_at?->timestamp ?? time(),
                        'owned_by' => $provider->slug,
                        'permission' => [],
                        'root' => $fullId,
                        'parent' => null,
                    ];
                }
            }
        }

        return response()->json([
            'object' => 'list',
            'data' => $models,
        ]);
    }

    /**
     * Handle OpenAI Chat Completions requests (sync or streaming).
     *
     * POST /v1/chat/completions
     */
    public function chatCompletions(Request $request): JsonResponse|StreamedResponse
    {
        // 1. Enforce request body size limit (10MB default)
        $maxSizeBytes = (int) config('gateway.max_request_size_bytes', 10 * 1024 * 1024);
        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength > $maxSizeBytes || strlen($request->getContent()) > $maxSizeBytes) {
            return response()->json([
                'error' => [
                    'message' => 'Request payload exceeds the maximum allowed size of 10MB.',
                    'type' => 'invalid_request_error',
                    'code' => 'payload_too_large',
                ],
            ], 413);
        }

        $maxCeiling = (int) config('gateway.max_tokens_ceiling', 16384);
        $maxMessages = (int) config('gateway.max_messages_count', 500);

        $validator = Validator::make($request->all(), [
            'model' => 'required|string',
            'messages' => "required|array|min:1|max:{$maxMessages}",
            'max_tokens' => "nullable|integer|min:1|max:{$maxCeiling}",
            'max_completion_tokens' => "nullable|integer|min:1|max:{$maxCeiling}",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => [
                    'message' => $validator->errors()->first(),
                    'type' => 'invalid_request_error',
                    'code' => 'missing_required_parameter',
                ],
            ], 400);
        }

        /** @var GatewayKey|null $gatewayKey */
        $gatewayKey = $request->attributes->get('gateway_key');
        $payload = $request->all();

        // 2. Daily token quota pre-reservation (prevents concurrency race conditions)
        $reservedTokens = 0;
        if ($gatewayKey && $gatewayKey->daily_token_limit !== null) {
            $estimatedTokens = \App\Http\Middleware\EnforceGatewayRateLimits::estimateRequiredTokens($payload, $gatewayKey);
            if (!\App\Http\Middleware\EnforceGatewayRateLimits::reserveDailyTokens($gatewayKey, $estimatedTokens)) {
                app(\App\Services\Notification\AlertNotificationService::class)->notifyGatewayKeyLimitExceeded(
                    $gatewayKey->name,
                    'Daily Token Quota',
                    "Daily token limit of " . number_format($gatewayKey->daily_token_limit) . " exceeded."
                );

                return response()->json([
                    'error' => [
                        'message' => 'Daily token limit exceeded for this Gateway key.',
                        'type' => 'insufficient_quota',
                        'code' => 'daily_limit_exceeded',
                    ],
                ], 429);
            }
            $reservedTokens = $estimatedTokens;
        }

        // If client requested SSE streaming
        if ($request->boolean('stream')) {
            return $this->streamCompletions($payload, $gatewayKey, $reservedTokens);
        }

        // Synchronous dispatch
        try {
            $result = $this->router->dispatchSync($payload, $gatewayKey, $reservedTokens);

            return response()->json($result['response'], 200, [
                // Passwire Gateway Standard Headers
                'X-Passwire-Cache' => !empty($result['meta']['cached']) ? 'HIT' : 'MISS',
                'X-Passwire-Provider' => $result['meta']['provider'] ?? '',
                'X-Passwire-Model' => $result['meta']['model'] ?? '',
                'X-Passwire-Account' => $result['meta']['account'] ?? '',
                'X-Passwire-Saved-Tokens' => (string) ($result['meta']['saved_tokens'] ?? 0),
                'X-Passwire-Cost-USD' => (string) ($result['meta']['cost_usd'] ?? 0),
                'X-Passwire-Cost-Saved-USD' => (string) ($result['meta']['cost_saved_usd'] ?? 0),
                'X-Passwire-Redacted-PII' => (string) ($result['meta']['redacted_pii_count'] ?? 0),
                'X-Passwire-Latency-Ms' => (string) ($result['meta']['latency_ms'] ?? 0),
                // Legacy compatibility headers
                'X-OmniRoute-Cache' => !empty($result['meta']['cached']) ? 'HIT' : 'MISS',
                'X-OmniRoute-Provider' => $result['meta']['provider'] ?? '',
                'X-OmniRoute-Model' => $result['meta']['model'] ?? '',
                'X-OmniRoute-Account' => $result['meta']['account'] ?? '',
                'X-OmniRoute-Saved-Tokens' => (string) ($result['meta']['saved_tokens'] ?? 0),
                'X-OmniRoute-Cost-USD' => (string) ($result['meta']['cost_usd'] ?? 0),
                'X-OmniRoute-Cost-Saved-USD' => (string) ($result['meta']['cost_saved_usd'] ?? 0),
                'X-OmniRoute-Redacted-PII' => (string) ($result['meta']['redacted_pii_count'] ?? 0),
                'X-OmniRoute-Latency-Ms' => (string) ($result['meta']['latency_ms'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            if ($gatewayKey && $reservedTokens > 0) {
                \App\Http\Middleware\EnforceGatewayRateLimits::rollbackDailyTokens($gatewayKey, $reservedTokens);
            }

            return response()->json([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'api_error',
                    'code' => 'gateway_dispatch_error',
                ],
            ], 502);
        }
    }

    /**
     * Handle Embeddings creation (POST /v1/embeddings).
     */
    public function embeddings(Request $request): JsonResponse
    {
        // 1. Enforce request body size limit (10MB default)
        $maxSizeBytes = (int) config('gateway.max_request_size_bytes', 10 * 1024 * 1024);
        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength > $maxSizeBytes || strlen($request->getContent()) > $maxSizeBytes) {
            return response()->json([
                'error' => [
                    'message' => 'Request payload exceeds the maximum allowed size of 10MB.',
                    'type' => 'invalid_request_error',
                    'code' => 'payload_too_large',
                ],
            ], 413);
        }

        /** @var GatewayKey|null $gatewayKey */
        $gatewayKey = $request->attributes->get('gateway_key');
        $payload = $request->all();

        if (empty($payload['input'])) {
            return response()->json([
                'error' => [
                    'message' => 'Missing required parameter: input.',
                    'type' => 'invalid_request_error',
                    'code' => 'missing_parameter',
                ],
            ], 400);
        }

        $reservedTokens = 0;
        if ($gatewayKey && $gatewayKey->daily_token_limit !== null) {
            $estimatedTokens = \App\Http\Middleware\EnforceGatewayRateLimits::estimateEmbeddingTokens($payload, $gatewayKey);
            if (!\App\Http\Middleware\EnforceGatewayRateLimits::reserveDailyTokens($gatewayKey, $estimatedTokens)) {
                return response()->json([
                    'error' => [
                        'message' => 'Daily token limit exceeded for this Gateway key.',
                        'type' => 'insufficient_quota',
                        'code' => 'daily_limit_exceeded',
                    ],
                ], 429);
            }
            $reservedTokens = $estimatedTokens;
        }

        try {
            $result = $this->router->dispatchEmbeddingSync($payload, $gatewayKey, $reservedTokens);

            return response()->json($result['response'], 200, [
                // Passwire Standard Headers
                'X-Passwire-Cache' => !empty($result['meta']['cached']) ? 'HIT' : 'MISS',
                'X-Passwire-Provider' => $result['meta']['provider'] ?? '',
                'X-Passwire-Model' => $result['meta']['model'] ?? '',
                'X-Passwire-Account' => $result['meta']['account'] ?? '',
                'X-Passwire-Saved-Tokens' => (string) ($result['meta']['saved_tokens'] ?? 0),
                'X-Passwire-Cost-USD' => (string) ($result['meta']['cost_usd'] ?? 0),
                'X-Passwire-Cost-Saved-USD' => (string) ($result['meta']['cost_saved_usd'] ?? 0),
                'X-Passwire-Redacted-PII' => (string) ($result['meta']['redacted_pii_count'] ?? 0),
                'X-Passwire-Latency-Ms' => (string) ($result['meta']['latency_ms'] ?? 0),
                // Legacy OmniRoute Headers
                'X-OmniRoute-Cache' => !empty($result['meta']['cached']) ? 'HIT' : 'MISS',
                'X-OmniRoute-Provider' => $result['meta']['provider'] ?? '',
                'X-OmniRoute-Model' => $result['meta']['model'] ?? '',
                'X-OmniRoute-Account' => $result['meta']['account'] ?? '',
                'X-OmniRoute-Saved-Tokens' => (string) ($result['meta']['saved_tokens'] ?? 0),
                'X-OmniRoute-Cost-USD' => (string) ($result['meta']['cost_usd'] ?? 0),
                'X-OmniRoute-Cost-Saved-USD' => (string) ($result['meta']['cost_saved_usd'] ?? 0),
                'X-OmniRoute-Redacted-PII' => (string) ($result['meta']['redacted_pii_count'] ?? 0),
                'X-OmniRoute-Latency-Ms' => (string) ($result['meta']['latency_ms'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            if ($gatewayKey && $reservedTokens > 0) {
                \App\Http\Middleware\EnforceGatewayRateLimits::rollbackDailyTokens($gatewayKey, $reservedTokens);
            }

            return response()->json([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'api_error',
                    'code' => 'gateway_embedding_dispatch_error',
                ],
            ], 502);
        }
    }

    /**
     * Stream completions using shared-hosting compatible SSE via native cURL.
     */
    public function streamCompletions(array $payload, ?GatewayKey $gatewayKey, int $reservedTokens = 0): StreamedResponse
    {
        return new StreamedResponse(function () use ($payload, $gatewayKey, $reservedTokens) {
            // Disable all PHP output buffering for shared hosting compatibility
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', 'Off');
            @set_time_limit(0);

            if (!app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
            }
            @ob_implicit_flush(true);

            $streamStartTime = microtime(true);
            $requestedModel = $payload['model'] ?? 'auto';

            // 1. Resolve target sequence and compression modes
            $resolved = $this->router->resolveTargets($requestedModel);
            $targets = $resolved['targets'];
            $compressionModes = $resolved['compression_modes'] ?? ['dedup', 'lite'];

            // 2. Compress prompts & apply PII redaction guardrails
            $savedTokens = 0;
            $redactedPiiCount = 0;
            if (!empty($payload['messages']) && is_array($payload['messages'])) {
                $compressed = $this->compressor->compress($payload['messages'], $compressionModes);
                $payload['messages'] = $compressed['messages'];
                $savedTokens = $compressed['saved_tokens'];
                $redactedPiiCount = $compressed['redacted_pii_count'] ?? 0;
            }

            // 3. Check Response Cache for instantaneous SSE replay (matches sync post-compression key)
            $isCacheable = $this->cacheService->isCacheable($payload);
            $cacheKey = $isCacheable ? $this->cacheService->generateKey($payload, $gatewayKey) : null;

            if ($cacheKey) {
                $cachedEntry = $this->cacheService->get($cacheKey);
                if ($cachedEntry !== null) {
                    $cachedResponse = $cachedEntry['response'] ?? [];
                    $cachedMeta = $cachedEntry['meta'] ?? [];
                    $cachedChoice = $cachedResponse['choices'][0] ?? [];
                    $cachedMessage = $cachedChoice['message'] ?? [];
                    $cachedContent = $cachedMessage['content'] ?? null;
                    $cachedToolCalls = $cachedMessage['tool_calls'] ?? null;
                    $finishReason = $cachedChoice['finish_reason'] ?? (!empty($cachedToolCalls) ? 'tool_calls' : 'stop');

                    $chunkId = 'chatcmpl-cache-' . bin2hex(random_bytes(6));
                    $created = time();

                    // Emit cached content chunk if content is present
                    if ($cachedContent !== null && $cachedContent !== '') {
                        $sseChunk = [
                            'id' => $chunkId,
                            'object' => 'chat.completion.chunk',
                            'created' => $created,
                            'model' => $cachedMeta['model'] ?? $requestedModel,
                            'choices' => [
                                [
                                    'index' => 0,
                                    'delta' => [
                                        'role' => 'assistant',
                                        'content' => $cachedContent,
                                    ],
                                    'finish_reason' => null,
                                ]
                            ],
                        ];
                        echo 'data: ' . json_encode($sseChunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                    }

                    // Emit cached tool calls chunk if tool calls are present
                    if (!empty($cachedToolCalls) && is_array($cachedToolCalls)) {
                        $sseToolChunk = [
                            'id' => $chunkId,
                            'object' => 'chat.completion.chunk',
                            'created' => $created,
                            'model' => $cachedMeta['model'] ?? $requestedModel,
                            'choices' => [
                                [
                                    'index' => 0,
                                    'delta' => [
                                        'role' => 'assistant',
                                        'tool_calls' => $cachedToolCalls,
                                    ],
                                    'finish_reason' => null,
                                ]
                            ],
                        ];
                        echo 'data: ' . json_encode($sseToolChunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                    }

                    // Emit finish chunk with real finish_reason ('stop' or 'tool_calls')
                    $sseFinish = [
                        'id' => $chunkId,
                        'object' => 'chat.completion.chunk',
                        'created' => $created,
                        'model' => $cachedMeta['model'] ?? $requestedModel,
                        'choices' => [
                            [
                                'index' => 0,
                                'delta' => (object) [],
                                'finish_reason' => $finishReason,
                            ]
                        ],
                    ];
                    echo 'data: ' . json_encode($sseFinish, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

                    // Emit usage chunk if client requested stream_options.include_usage
                    if (!empty($payload['stream_options']['include_usage'])) {
                        $pTokens = (int) ($cachedResponse['usage']['prompt_tokens'] ?? 0);
                        $cTokens = (int) ($cachedResponse['usage']['completion_tokens'] ?? 0);
                        $tTokens = (int) ($cachedResponse['usage']['total_tokens'] ?? ($pTokens + $cTokens));
                        echo $this->tokenizer->formatStreamUsageChunk($cachedMeta['model'] ?? $requestedModel, $pTokens, $cTokens, $tTokens);
                    }

                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) @ob_flush();
                    @flush();

                    $latencyMs = max(1, (int) round((microtime(true) - $streamStartTime) * 1000));
                    $promptTokens = (int) ($cachedResponse['usage']['prompt_tokens'] ?? 0);
                    $completionTokens = (int) ($cachedResponse['usage']['completion_tokens'] ?? (strlen($cachedContent) / 4));
                    $totalTokens = (int) ($cachedResponse['usage']['total_tokens'] ?? ($promptTokens + $completionTokens));

                    $costData = $this->costCalculator->calculate(
                        $cachedMeta['model'] ?? $requestedModel,
                        $promptTokens,
                        $completionTokens,
                        $totalTokens,
                        true
                    );

                    // Record cached streaming log entry asynchronously via queue
                    GatewayLog::record([
                        'gateway_key_id' => $gatewayKey?->id,
                        'requested_model' => $requestedModel,
                        'routed_provider' => $cachedMeta['provider'] ?? 'cache',
                        'routed_model' => $cachedMeta['model'] ?? $requestedModel,
                        'account_label' => 'cache-hit',
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'total_tokens' => $totalTokens,
                        'saved_tokens' => $totalTokens,
                        'cost_usd' => $costData['cost_usd'],
                        'cost_saved_usd' => $costData['cost_saved_usd'],
                        'latency_ms' => $latencyMs,
                        'status_code' => 200,
                        'is_cached' => true,
                        'redacted_pii_count' => $redactedPiiCount,
                        'error_message' => null,
                    ]);

                    if ($gatewayKey && $reservedTokens > 0) {
                        \App\Http\Middleware\EnforceGatewayRateLimits::settleDailyTokens($gatewayKey, $reservedTokens, 0);
                    }

                    return;
                }
            }

            $attemptErrors = [];
            $streamSuccess = false;

            foreach ($targets as $target) {
                if ($streamSuccess) {
                    break;
                }

                $providerSlug = $target['provider'];
                $modelName = $target['model'];

                if (Cache::has("circuit_open:{$providerSlug}")) {
                    $attemptErrors[] = "Provider '{$providerSlug}' circuit open";
                    continue;
                }

                $provider = AiProvider::where('slug', $providerSlug)
                    ->where('is_active', true)
                    ->first();

                if (!$provider) {
                    $attemptErrors[] = "Provider '{$providerSlug}' inactive or not found";
                    continue;
                }

                $accounts = $this->router->getHealthyAccounts($providerSlug);
                if ($accounts->isEmpty()) {
                    $attemptErrors[] = "Provider '{$providerSlug}' has no healthy accounts";
                    continue;
                }

                foreach ($accounts as $account) {
                    if ($streamSuccess) {
                        break;
                    }

                    $targetPayload = $payload;
                    $targetPayload['model'] = $modelName;
                    $targetPayload['stream'] = true;

                    // Request upstream provider usage chunk (stream_options.include_usage)
                    if (!isset($targetPayload['stream_options']) || !is_array($targetPayload['stream_options'])) {
                        $targetPayload['stream_options'] = [];
                    }
                    $targetPayload['stream_options']['include_usage'] = true;

                    $format = $provider->format ?? 'openai';
                    $adapter = $this->translator->getAdapter($format);

                    $url = $adapter->buildUrl($provider->base_url, $modelName, true, $account->api_key);
                    $this->ssrf->assertSafeUrl($url);
                    $curlHeaders = $adapter->formatCurlHeaders($account->api_key, true);
                    $bodyJson = json_encode($adapter->formatPayload($modelName, $targetPayload));

                    // Native cURL handle for precise streaming control
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

                    $httpStatusCode = 0;
                    $errorBuffer = '';
                    $streamStartedForThisAttempt = false;
                    $sseBuffer = '';
                    $streamedChunksCount = 0;
                    $accumulatedResponseText = '';
                    $accumulatedToolCalls = [];
                    $streamFinishReason = null;
                    $usageFromStream = [
                        'prompt_tokens' => null,
                        'completion_tokens' => null,
                        'total_tokens' => null,
                    ];
                    $clientRequestedUsage = !empty($payload['stream_options']['include_usage']);
                    $usageChunkEmittedToClient = false;

                    // Inspect HTTP headers as they arrive
                    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$httpStatusCode) {
                        if (preg_match('/^HTTP\/[\d\.]+\s+(\d+)/i', $headerLine, $matches)) {
                            $httpStatusCode = (int) $matches[1];
                        }
                        return strlen($headerLine);
                    });

                    // Stream response body chunks directly if 200, buffer if error
                    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (
                        &$httpStatusCode,
                        &$errorBuffer,
                        &$streamStartedForThisAttempt,
                        &$sseBuffer,
                        &$streamedChunksCount,
                        &$accumulatedResponseText,
                        &$accumulatedToolCalls,
                        &$streamFinishReason,
                        &$usageFromStream,
                        &$usageChunkEmittedToClient,
                        $format,
                        $adapter,
                        $modelName
                    ) {
                        // If status is not 200, collect error body without emitting to client
                        if ($httpStatusCode !== 200) {
                            $errorBuffer .= $chunk;
                            return strlen($chunk);
                        }

                        $streamStartedForThisAttempt = true;

                        if ($format === 'openai') {
                            echo $chunk;
                            $streamedChunksCount++;

                            // Parse SSE lines inside the chunk to track text, tool calls, and provider usage chunks
                            $lines = explode("\n", $chunk);
                            foreach ($lines as $line) {
                                $this->tokenizer->parseStreamChunk(
                                    $line,
                                    'openai',
                                    $usageFromStream,
                                    $accumulatedResponseText,
                                    $usageChunkEmittedToClient,
                                    $accumulatedToolCalls,
                                    $streamFinishReason
                                );
                            }

                            if (ob_get_level() > 0) @ob_flush();
                            @flush();
                        } else {
                            // Non-OpenAI SSE formats (Anthropic, Gemini) stream line by line
                            $sseBuffer .= $chunk;
                            while (($pos = strpos($sseBuffer, "\n")) !== false) {
                                $line = substr($sseBuffer, 0, $pos);
                                $sseBuffer = substr($sseBuffer, $pos + 1);

                                $this->tokenizer->parseStreamChunk(
                                    $line,
                                    $format,
                                    $usageFromStream,
                                    $accumulatedResponseText,
                                    $usageChunkEmittedToClient,
                                    $accumulatedToolCalls,
                                    $streamFinishReason
                                );

                                $translated = $adapter->transformStreamChunk($line, $modelName);
                                if ($translated !== null) {
                                    echo $translated;
                                    $streamedChunksCount++;
                                    if (ob_get_level() > 0) @ob_flush();
                                    @flush();
                                }
                            }
                        }

                        return strlen($chunk);
                    });

                    $reqStartTime = microtime(true);
                    curl_exec($ch);
                    $curlError = curl_error($ch);
                    curl_close($ch);

                    // Flush any leftover buffer for non-OpenAI streams
                    if ($format !== 'openai' && $streamStartedForThisAttempt && trim($sseBuffer) !== '') {
                        $this->tokenizer->parseStreamChunk(
                            $sseBuffer,
                            $format,
                            $usageFromStream,
                            $accumulatedResponseText,
                            $usageChunkEmittedToClient,
                            $accumulatedToolCalls,
                            $streamFinishReason
                        );

                        $translated = $adapter->transformStreamChunk($sseBuffer, $modelName);
                        if ($translated !== null) {
                            echo $translated;
                            if (ob_get_level() > 0) @ob_flush();
                            @flush();
                        }
                    }

                    $latencyMs = (int) round((microtime(true) - $reqStartTime) * 1000);

                    // If stream succeeded, finalize and record success
                    if ($streamStartedForThisAttempt) {
                        $streamSuccess = true;

                        // Resolve accurate token counts (provider-reported or tokenizer estimation)
                        $resolvedTokens = $this->tokenizer->resolveStreamTokens(
                            $usageFromStream,
                            $targetPayload,
                            $accumulatedResponseText,
                            $accumulatedToolCalls
                        );

                        $promptTokens = $resolvedTokens['prompt_tokens'];
                        $completionTokens = $resolvedTokens['completion_tokens'];
                        $totalTokens = $resolvedTokens['total_tokens'];
                        $cachedPromptTokens = $resolvedTokens['cached_prompt_tokens'] ?? 0;

                        // Emit usage chunk to client if requested via stream_options.include_usage and not yet emitted
                        if ($clientRequestedUsage && !$usageChunkEmittedToClient) {
                            echo $this->tokenizer->formatStreamUsageChunk($modelName, $promptTokens, $completionTokens, $totalTokens, $cachedPromptTokens);
                            if (ob_get_level() > 0) @ob_flush();
                            @flush();
                        }

                        // Increment usage tokens
                        $account->increment('tokens_used_today', max(1, $totalTokens));
                        $account->update(['fail_count' => 0, 'last_error' => null]);

                        if ($gatewayKey) {
                            \App\Http\Middleware\EnforceGatewayRateLimits::settleDailyTokens(
                                $gatewayKey,
                                $reservedTokens,
                                max(1, $totalTokens)
                            );
                        }

                        $costData = $this->costCalculator->calculate(
                            $modelName,
                            $promptTokens,
                            $completionTokens,
                            $savedTokens,
                            false,
                            $cachedPromptTokens,
                            $providerSlug
                        );

                        // Record successful streaming log asynchronously via queue
                        GatewayLog::record([
                            'gateway_key_id' => $gatewayKey?->id,
                            'requested_model' => $requestedModel,
                            'routed_provider' => $providerSlug,
                            'routed_model' => $modelName,
                            'account_label' => $account->label,
                            'prompt_tokens' => $promptTokens,
                            'completion_tokens' => $completionTokens,
                            'total_tokens' => $totalTokens,
                            'saved_tokens' => $savedTokens,
                            'cost_usd' => $costData['cost_usd'],
                            'cost_saved_usd' => $costData['cost_saved_usd'],
                            'latency_ms' => $latencyMs,
                            'status_code' => 200,
                            'is_cached' => false,
                            'redacted_pii_count' => $redactedPiiCount,
                            'error_message' => null,
                        ]);

                        // Store in Response Cache if cacheable
                        if ($cacheKey && ($accumulatedResponseText !== '' || !empty($accumulatedToolCalls))) {
                            $assembledMessage = [
                                'role' => 'assistant',
                                'content' => $accumulatedResponseText !== '' ? $accumulatedResponseText : null,
                            ];
                            if (!empty($accumulatedToolCalls)) {
                                $assembledMessage['tool_calls'] = array_values($accumulatedToolCalls);
                            }

                            $assembledUsage = [
                                'prompt_tokens' => $promptTokens,
                                'completion_tokens' => $completionTokens,
                                'total_tokens' => $totalTokens,
                            ];
                            if ($cachedPromptTokens > 0) {
                                $assembledUsage['prompt_tokens_details'] = [
                                    'cached_tokens' => $cachedPromptTokens,
                                ];
                            }

                            $assembled = [
                                'id' => 'chatcmpl-cached-' . bin2hex(random_bytes(6)),
                                'object' => 'chat.completion',
                                'created' => time(),
                                'model' => $modelName,
                                'choices' => [
                                    [
                                        'index' => 0,
                                        'message' => $assembledMessage,
                                        'finish_reason' => $streamFinishReason ?? (!empty($accumulatedToolCalls) ? 'tool_calls' : 'stop'),
                                    ]
                                ],
                                'usage' => $assembledUsage,
                            ];
                            $ttl = $this->cacheService->calculateTtl($payload);
                            $this->cacheService->put($cacheKey, $assembled, [
                                'provider' => $providerSlug,
                                'model' => $modelName,
                                'account' => $account->label,
                                'saved_tokens' => $savedTokens,
                                'cost_usd' => $costData['cost_usd'],
                                'cost_saved_usd' => $costData['cost_saved_usd'],
                                'redacted_pii_count' => $redactedPiiCount,
                                'latency_ms' => $latencyMs,
                            ], $ttl);
                        }

                        return;
                    }

                    // Otherwise request failed before stream started: put in cooldown and try next key/provider
                    $errText = !empty($errorBuffer) ? $errorBuffer : ($curlError ?: 'Unknown error');
                    $errSnippet = substr($errText, 0, 500);

                    if ($httpStatusCode === 429) {
                        $account->markCooldown(2, "HTTP 429: {$errSnippet}");
                        $this->alertService->notifyAccountCooldown($account->label, $providerSlug, 429, $errSnippet);
                    } elseif (in_array($httpStatusCode, [401, 402, 403], true)) {
                        $account->markCooldown(60, "HTTP {$httpStatusCode}: {$errSnippet}");
                        $this->alertService->notifyAccountCooldown($account->label, $providerSlug, $httpStatusCode, $errSnippet);
                    } elseif ($httpStatusCode >= 500) {
                        $account->markCooldown(1, "HTTP {$httpStatusCode}: {$errSnippet}");
                        $this->alertService->notifyAccountCooldown($account->label, $providerSlug, $httpStatusCode, $errSnippet);
                    } else {
                        $account->update(['last_error' => "HTTP {$httpStatusCode}: {$errSnippet}"]);
                    }

                    $attemptErrors[] = "[{$providerSlug}/{$modelName} - {$account->label}] HTTP {$httpStatusCode}: " . substr($errText, 0, 200);

                    if ($account->fresh()->fail_count >= 5) {
                        Cache::put("circuit_open:{$providerSlug}", true, now()->addMinutes(1));
                        $this->alertService->notifyCircuitBreakerTripped($providerSlug, "5 consecutive failures in streaming on '{$account->label}' ({$errSnippet})");
                    }
                }
            }

            // If all fallback targets failed
            if (!$streamSuccess) {
                if ($gatewayKey && $reservedTokens > 0) {
                    \App\Http\Middleware\EnforceGatewayRateLimits::rollbackDailyTokens($gatewayKey, $reservedTokens);
                }

                $summary = implode(' | ', $attemptErrors);
                $errPayload = [
                    'error' => [
                        'message' => 'Passwire streaming fallback exhausted: ' . ($summary ?: 'No healthy accounts available.'),
                        'type' => 'gateway_error',
                        'code' => 'stream_fallback_failed',
                    ],
                ];

                echo "data: " . json_encode($errPayload) . "\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) @ob_flush();
                @flush();

                // Record streaming failure log asynchronously via queue
                GatewayLog::record([
                    'gateway_key_id' => $gatewayKey?->id,
                    'requested_model' => $requestedModel,
                    'routed_provider' => null,
                    'routed_model' => null,
                    'account_label' => null,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'saved_tokens' => $savedTokens,
                    'latency_ms' => (int) round((microtime(true) - $streamStartTime) * 1000),
                    'status_code' => 502,
                    'redacted_pii_count' => $redactedPiiCount,
                    'error_message' => $summary ?: 'All providers failed in streaming mode.',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With, x-api-key',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        ]);
    }
}
