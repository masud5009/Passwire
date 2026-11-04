<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'gateway_key_id',
        'requested_model',
        'routed_provider',
        'routed_model',
        'account_label',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'saved_tokens',
        'cost_usd',
        'cost_saved_usd',
        'latency_ms',
        'status_code',
        'is_cached',
        'redacted_pii_count',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'saved_tokens' => 'integer',
            'cost_usd' => 'float',
            'cost_saved_usd' => 'float',
            'latency_ms' => 'integer',
            'status_code' => 'integer',
            'is_cached' => 'boolean',
            'redacted_pii_count' => 'integer',
        ];
    }

    /**
     * Get the gateway key that owns the log.
     */
    public function gatewayKey(): BelongsTo
    {
        return $this->belongsTo(GatewayKey::class);
    }

    /**
     * Prune logs older than the specified number of days in safe chunks to avoid locking table.
     *
     * @param int $days Number of days of logs to retain
     * @param int $chunkSize Number of records to delete per chunk
     * @return int Total number of records deleted
     */
    public static function pruneOlderThan(int $days, int $chunkSize = 1000): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $totalDeleted = 0;
        $batch = max(50, $chunkSize);

        do {
            // Retrieve IDs in chunks to delete without table-locking range locks
            $ids = static::where('created_at', '<', $cutoff)
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = static::whereIn('id', $ids)->delete();
            $totalDeleted += $deleted;
        } while ($deleted >= $batch);

        return $totalDeleted;
    }

    /**
     * Record a gateway log entry asynchronously via queue (or synchronously if async is disabled).
     *
     * @param array<string, mixed> $logData
     */
    public static function record(array $logData): void
    {
        if (config('gateway.async_logging', true)) {
            try {
                \App\Jobs\RecordGatewayLogJob::dispatch($logData);
                return;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to dispatch gateway log job: ' . $e->getMessage());
            }
        }

        try {
            static::create($logData);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create gateway log: ' . $e->getMessage());
        }
    }
}
