<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_title',
        'version',
        'show_demo_credentials',
        'logo_path',
        'logo_dark_path',
        'logo_light_path',
        'favicon_path',
    ];

    protected $casts = [
        'show_demo_credentials' => 'boolean',
    ];

    /**
     * Default branding values used for fresh installs / fallbacks.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'site_title' => 'Passwire',
            'version' => 'v1.0',
            'show_demo_credentials' => true,
            'logo_path' => null,
            'logo_dark_path' => null,
            'logo_light_path' => null,
            'favicon_path' => null,
        ];
    }

    /**
     * Get the singleton SiteSetting record or create it with defaults.
     */
    public static function getSettings(): self
    {
        return static::firstOrCreate([], static::defaults());
    }

    /**
     * Public URL for the uploaded logo (or null when using the default icon).
     * Falls back to legacy logo_path for backward compatibility.
     */
    public function logoUrl(): ?string
    {
        return $this->logoDarkUrl() ?? $this->legacyLogoUrl();
    }

    /**
     * Public URL for the dark-theme logo.
     */
    public function logoDarkUrl(): ?string
    {
        if ($this->logo_dark_path && Storage::disk('public')->exists($this->logo_dark_path)) {
            return asset('storage/'.$this->logo_dark_path);
        }

        return null;
    }

    /**
     * Public URL for the light-theme logo.
     */
    public function logoLightUrl(): ?string
    {
        if ($this->logo_light_path && Storage::disk('public')->exists($this->logo_light_path)) {
            return asset('storage/'.$this->logo_light_path);
        }

        return null;
    }

    /**
     * Legacy single-logo URL (kept for backward compatibility).
     */
    protected function legacyLogoUrl(): ?string
    {
        if ($this->logo_path && Storage::disk('public')->exists($this->logo_path)) {
            return asset('storage/'.$this->logo_path);
        }

        return null;
    }

    /**
     * Public URL for the uploaded favicon (or null when using /favicon.ico).
     */
    public function faviconUrl(): ?string
    {
        if ($this->favicon_path && Storage::disk('public')->exists($this->favicon_path)) {
            return asset('storage/'.$this->favicon_path);
        }

        return null;
    }
}
