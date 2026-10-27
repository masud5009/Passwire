<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewayKey extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'daily_token_limit',
        'rpm_limit',
        'tpm_limit',
        'tokens_used_today',
        'usage_reset_date',
        'last_used_at',
        'is_active',
    ];

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
