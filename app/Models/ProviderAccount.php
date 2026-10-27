<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderAccount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ai_provider_id',
        'label',
        'api_key',
        'priority',
        'daily_token_limit',
        'tokens_used_today',
        'usage_reset_date',
        'cooldown_until',
        'fail_count',
        'last_error',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'cooldown_until' => 'datetime',
            'usage_reset_date' => 'date',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'daily_token_limit' => 'integer',
            'tokens_used_today' => 'integer',
            'fail_count' => 'integer',
        ];
    }

    /**
     * Get the provider that owns the account.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Determine if the account is currently in cooldown.
     */
    public function isInCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /**
     * Mark the account into cooldown for the specified number of minutes.
     */
    public function markCooldown(int $minutes, ?string $error = null): void
    {
        $this->cooldown_until = now()->addMinutes($minutes);
        $this->fail_count = ($this->fail_count ?? 0) + 1;

        if ($error !== null) {
            $this->last_error = $error;
        }

        $this->save();
    }
}
