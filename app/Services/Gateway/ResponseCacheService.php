<?php

namespace App\Services\Gateway;

use App\Models\GatewayKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ResponseCacheService
{
    /**
     * Default Cache TTL in seconds for deterministic requests (24 hours).
     */
    public const DEFAULT_TTL = 86400;

    /**
     * Default Cache TTL in seconds for non-deterministic requests (5 minutes).
     */
    public const NON_DETERMINISTIC_TTL = 300;

    /**
     * Cache key prefix.
     */
    protected const KEY_PREFIX = 'omni_resp_cache:';

    /**
     * Determine if a chat completion request payload is eligible for response caching.
     *
     * @param array<string, mixed> $payload
     * @param Request|null $request
     * @return bool
     */
    public function isCacheable(array $payload, ?Request $request = null): bool
    {
        $req = $request ?? (function_exists('request') && app()->bound('request') ? request() : null);

        // Check for client-side explicit bypass headers
        if ($req instanceof Request) {
            if ($req->hasHeader('x-bypass-cache') && filter_var($req->header('x-bypass-cache'), FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }

            $cacheControl = strtolower($req->header('Cache-Control', ''));
            if (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store')) {
                return false;
            }
        }

        // Check for payload bypass flag
        if (!empty($payload['bypass_cache'])) {
            return false;
        }

        // Must have non-empty messages
        if (empty($payload['messages']) || !is_array($payload['messages'])) {
            return false;
        }

        return true;
    }

    /**
     * Determine if a request is deterministic (i.e. greedy decoding or seed specified).
     *
     * @param array<string, mixed> $payload
     * @return bool
     */
    public function isDeterministic(array $payload): bool
    {
        // Explicit seed ensures reproducibility
        if (isset($payload['seed']) && is_numeric($payload['seed'])) {
            return true;
        }

        // Greedy decoding (temperature 0.0) ensures deterministic output
        if (isset($payload['temperature']) && (float) $payload['temperature'] <= 0.01) {
            return true;
        }

        return false;
    }

    /**
     * Calculate appropriate cache TTL based on payload parameters and determinism.
     *
     * @param array<string, mixed> $payload
     * @return int TTL in seconds
     */
    public function calculateTtl(array $payload): int
    {
        // 1. Explicit cache_ttl in payload
        if (isset($payload['cache_ttl']) && is_numeric($payload['cache_ttl'])) {
            return max(1, (int) $payload['cache_ttl']);
        }

        // 2. Deterministic requests get long TTL (24 hours)
        if ($this->isDeterministic($payload)) {
            return self::DEFAULT_TTL;
        }

        // 3. Non-deterministic requests get short TTL (5 minutes) for burst deduplication
        return self::NON_DETERMINISTIC_TTL;
    }

    /**
     * Generate a comprehensive, deterministic SHA-256 cache key from request parameters and tenant isolation.
     *
     * @param array<string, mixed> $payload
     * @param GatewayKey|null $gatewayKey
     * @return string
     */
    public function generateKey(array $payload, ?GatewayKey $gatewayKey = null): string
    {
        // Tenant / GatewayKey isolation: guarantees User A never receives User B's cached responses
        $tenant = $gatewayKey ? ('gwk_' . $gatewayKey->id) : ($payload['tenant'] ?? ($payload['user'] ?? 'global'));

        $normalized = [
            'tenant' => (string) $tenant,
            'model' => strtolower(trim($payload['model'] ?? 'auto')),
            'messages' => $this->normalizeMessages($payload['messages'] ?? []),
            'temperature' => isset($payload['temperature']) ? round((float) $payload['temperature'], 3) : null,
            'top_p' => isset($payload['top_p']) ? round((float) $payload['top_p'], 3) : null,
            'n' => isset($payload['n']) ? (int) $payload['n'] : 1,
            'stop' => $this->normalizeStop($payload['stop'] ?? null),
            'max_tokens' => $payload['max_tokens'] ?? ($payload['max_completion_tokens'] ?? null),
            'presence_penalty' => isset($payload['presence_penalty']) ? round((float) $payload['presence_penalty'], 3) : null,
            'frequency_penalty' => isset($payload['frequency_penalty']) ? round((float) $payload['frequency_penalty'], 3) : null,
            'logit_bias' => $this->normalizeLogitBias($payload['logit_bias'] ?? null),
            'response_format' => $this->normalizeResponseFormat($payload['response_format'] ?? null),
            'seed' => isset($payload['seed']) ? (int) $payload['seed'] : null,
            'tools' => $this->normalizeTools($payload['tools'] ?? null),
            'tool_choice' => $this->normalizeToolChoice($payload['tool_choice'] ?? null),
        ];

        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::KEY_PREFIX . $hash;
    }

    /**
     * Retrieve cached response if available.
     *
     * @param string $cacheKey
     * @return array<string, mixed>|null
     */
    public function get(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);

        if ($cached && is_array($cached)) {
            $this->incrementHits();
            return $cached;
        }

        $this->incrementMisses();
        return null;
    }

    /**
     * Store completed response in cache.
     *
     * @param string $cacheKey
     * @param array<string, mixed> $response
     * @param array<string, mixed> $meta
     * @param int $ttlSeconds
     * @return void
     */
    public function put(string $cacheKey, array $response, array $meta = [], int $ttlSeconds = self::DEFAULT_TTL): void
    {
        $data = [
            'response' => $response,
            'meta' => array_merge($meta, [
                'cached' => true,
                'cached_at' => now()->toIso8601String(),
            ]),
        ];

        Cache::put($cacheKey, $data, now()->addSeconds($ttlSeconds));
    }

    /**
     * Purge all gateway response cache items.
     *
     * @return int Number of purged keys
     */
    public function purgeAll(): int
    {
        $purgedCount = 0;

        try {
            $driver = config('cache.default');

            if ($driver === 'database') {
                $tableName = config('cache.stores.database.table', 'cache');
                $purgedCount = DB::table($tableName)
                    ->where('key', 'like', '%' . self::KEY_PREFIX . '%')
                    ->delete();
            } else {
                // Generic flush or fallback
                Cache::flush();
                $purgedCount = 1;
            }
        } catch (\Throwable $e) {
            // Fallback
            Cache::flush();
            $purgedCount = 1;
        }

        return $purgedCount;
    }

    /**
     * Get real-time cache hit and miss statistics.
     *
     * @return array{hits: int, misses: int, total: int, hit_rate_pct: float}
     */
    public function getHitStats(): array
    {
        $hits = (int) Cache::get('omni_cache_hits', 0);
        $misses = (int) Cache::get('omni_cache_misses', 0);
        $total = $hits + $misses;
        $hitRate = $total > 0 ? round(($hits / $total) * 100, 1) : 0.0;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'total' => $total,
            'hit_rate_pct' => $hitRate,
        ];
    }

    /**
     * Increment the cache hit counter.
     */
    protected function incrementHits(): void
    {
        if (!Cache::has('omni_cache_hits')) {
            Cache::forever('omni_cache_hits', 0);
        }
        Cache::increment('omni_cache_hits');
    }

    /**
     * Increment the cache miss counter.
     */
    protected function incrementMisses(): void
    {
        if (!Cache::has('omni_cache_misses')) {
            Cache::forever('omni_cache_misses', 0);
        }
        Cache::increment('omni_cache_misses');
    }

    /**
     * Normalize messages array for consistent hashing.
     *
     * @param array<int, mixed> $messages
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }

            $role = strtolower(trim($msg['role'] ?? 'user'));
            $content = $msg['content'] ?? '';

            if (is_array($content)) {
                $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $content = (string) $content;
            }

            $item = [
                'role' => $role,
                'content' => $content,
            ];

            if (!empty($msg['name'])) {
                $item['name'] = (string) $msg['name'];
            }
            if (!empty($msg['tool_call_id'])) {
                $item['tool_call_id'] = (string) $msg['tool_call_id'];
            }
            if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $item['tool_calls'] = $msg['tool_calls'];
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Normalize tools array for consistent hashing.
     */
    protected function normalizeTools(?array $tools): ?array
    {
        if (empty($tools) || !is_array($tools)) {
            return null;
        }

        $normalized = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $fn = $tool['function'] ?? [];
            $normalized[] = [
                'type' => $tool['type'] ?? 'function',
                'name' => $fn['name'] ?? '',
                'description' => $fn['description'] ?? '',
                'parameters' => isset($fn['parameters']) ? $this->sortRecursive($fn['parameters']) : null,
            ];
        }

        usort($normalized, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $normalized;
    }

    /**
     * Normalize tool_choice parameter.
     */
    protected function normalizeToolChoice(mixed $toolChoice): mixed
    {
        if (is_array($toolChoice)) {
            return $this->sortRecursive($toolChoice);
        }

        return is_string($toolChoice) ? strtolower(trim($toolChoice)) : null;
    }

    /**
     * Normalize response_format parameter (e.g. json_object vs json_schema).
     */
    protected function normalizeResponseFormat(mixed $responseFormat): mixed
    {
        if (is_array($responseFormat)) {
            return $this->sortRecursive($responseFormat);
        }

        return is_string($responseFormat) ? strtolower(trim($responseFormat)) : null;
    }

    /**
     * Normalize stop sequences (string or array of strings).
     *
     * @return array<int, string>|null
     */
    protected function normalizeStop(mixed $stop): ?array
    {
        if (is_string($stop) && $stop !== '') {
            return [$stop];
        }

        if (is_array($stop)) {
            $filtered = array_values(array_filter(array_map('strval', $stop)));
            sort($filtered);
            return !empty($filtered) ? $filtered : null;
        }

        return null;
    }

    /**
     * Normalize logit_bias map.
     */
    protected function normalizeLogitBias(mixed $logitBias): ?array
    {
        if (!is_array($logitBias) || empty($logitBias)) {
            return null;
        }

        $sorted = $logitBias;
        ksort($sorted);

        return $sorted;
    }

    /**
     * Recursively sort associative arrays by key for consistent JSON serialization.
     */
    protected function sortRecursive(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $isAssoc = array_keys($data) !== range(0, count($data) - 1);
        if ($isAssoc) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }

        return $data;
    }
}
