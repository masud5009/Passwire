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
}
