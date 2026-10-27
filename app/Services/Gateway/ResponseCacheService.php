<?php

namespace App\Services\Gateway;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ResponseCacheService
{
    /**
     * Default Cache TTL in seconds (24 hours).
     */
    protected const DEFAULT_TTL = 86400;

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
        // Check for client-side explicit bypass headers
        if ($request) {
            if ($request->hasHeader('x-bypass-cache') && filter_var($request->header('x-bypass-cache'), FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }

            $cacheControl = strtolower($request->header('Cache-Control', ''));
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
     * Generate a deterministic SHA-256 cache key from request parameters.
     *
     * @param array<string, mixed> $payload
     * @return string
     */
    public function generateKey(array $payload): string
    {
        $normalized = [
            'model' => strtolower(trim($payload['model'] ?? 'auto')),
            'messages' => $this->normalizeMessages($payload['messages'] ?? []),
            'temperature' => isset($payload['temperature']) ? round((float) $payload['temperature'], 2) : 0.7,
            'max_tokens' => $payload['max_tokens'] ?? ($payload['max_completion_tokens'] ?? null),
            'tools' => $payload['tools'] ?? null,
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
     * @return array<int, array{role: string, content: string}>
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

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $normalized;
    }
}
