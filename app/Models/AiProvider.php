<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'base_url',
        'format',
        'description',
        'models_catalog',
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
            'models_catalog' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the accounts associated with the AI provider.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
    }
}
