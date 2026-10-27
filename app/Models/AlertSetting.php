<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertSetting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'is_enabled',
        'webhook_type',
        'webhook_url',
        'telegram_bot_token',
        'telegram_chat_id',
        'alert_circuit_breaker',
        'alert_account_cooldown',
        'alert_key_limit_exceeded',
        'last_alert_sent_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'alert_circuit_breaker' => 'boolean',
            'alert_account_cooldown' => 'boolean',
            'alert_key_limit_exceeded' => 'boolean',
            'last_alert_sent_at' => 'datetime',
        ];
    }

    /**
     * Get singleton AlertSetting record or create default.
     */
    public static function getSettings(): self
    {
        return static::firstOrCreate([], [
            'is_enabled' => false,
            'webhook_type' => 'discord',
            'webhook_url' => null,
            'telegram_bot_token' => null,
            'telegram_chat_id' => null,
            'alert_circuit_breaker' => true,
            'alert_account_cooldown' => true,
            'alert_key_limit_exceeded' => true,
        ]);
    }
}
