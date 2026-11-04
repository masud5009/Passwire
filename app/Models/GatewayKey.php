<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewayKey extends Model
{
    use HasFactory;

    /**
     * Temporary in-memory holder for the unhashed secret key.
     * Only populated immediately after generation or creation.
     */
    protected ?string $plainTextKey = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'key_hash',
        'key_prefix',
        'daily_token_limit',
        'rpm_limit',
        'tpm_limit',
        'tokens_used_today',
        'usage_reset_date',
        'last_used_at',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
    ];

    /**
     * Bootstrap model events.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->attributes['key_hash'])) {
                $rawKey = self::generateSecretKey();
                $model->plainTextKey = $rawKey;
                $model->attributes['key_hash'] = self::hashKey($rawKey);
                $model->attributes['key_prefix'] = self::formatPrefix($rawKey);
            }
        });
    }

    /**
     * Mutator for 'key': hashes raw secret key using SHA-256 and records the prefix.
     */
    public function setKeyAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->plainTextKey = $value;
        $this->attributes['key_hash'] = self::hashKey($value);
        $this->attributes['key_prefix'] = self::formatPrefix($value);
    }

    /**
     * Accessor for 'key': returns raw plaintext key if in memory, or the masked key_prefix.
     */
    public function getKeyAttribute(): ?string
    {
        return $this->plainTextKey ?? $this->attributes['key_prefix'] ?? null;
    }

    /**
     * Retrieve the in-memory plain text key if available.
     */
    public function getPlainTextKey(): ?string
    {
        return $this->plainTextKey;
    }

    /**
     * Compute SHA-256 hash of an API key.
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Format display prefix for an API key (e.g. sk-pw-abcd…).
     */
    public static function formatPrefix(string $key): string
    {
        if (str_starts_with($key, 'sk-pw-')) {
            $tail = substr($key, 6, 4);
            return 'sk-pw-' . $tail . '…';
        }

        if (strlen($key) > 8) {
            return substr($key, 0, 8) . '…';
        }

        return $key . '…';
    }

    /**
     * Generate a new cryptographically secure Gateway API key.
     */
    public static function generateSecretKey(): string
    {
        return 'sk-pw-' . bin2hex(random_bytes(16));
    }

    /**
     * Look up an active GatewayKey model by its plain text API key.
     */
    public static function findActiveByKey(string $plainKey): ?self
    {
        return static::where('key_hash', self::hashKey($plainKey))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_token_limit' => 'integer',
            'rpm_limit' => 'integer',
            'tpm_limit' => 'integer',
            'tokens_used_today' => 'integer',
            'usage_reset_date' => 'date',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the logs associated with this gateway key.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(GatewayLog::class);
    }
}
