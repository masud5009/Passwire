<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Auth\TwoFactorService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
        ];
    }

    /**
     * Determine if two-factor authentication is active for this user.
     */
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return ! empty($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Verify a 6-digit TOTP code against the user's secret.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        if (empty($this->two_factor_secret)) {
            return false;
        }

        /** @var TwoFactorService $service */
        $service = app(TwoFactorService::class);

        return $service->verify($this->two_factor_secret, $code);
    }

    /**
     * Verify and consume an emergency recovery code.
     */
    public function verifyRecoveryCode(string $code): bool
    {
        $recoveryCodes = $this->two_factor_recovery_codes ?? [];
        if (empty($recoveryCodes)) {
            return false;
        }

        /** @var TwoFactorService $service */
        $service = app(TwoFactorService::class);

        if ($service->verifyRecoveryCode($recoveryCodes, $code)) {
            $this->two_factor_recovery_codes = $recoveryCodes;
            $this->save();

            return true;
        }

        return false;
    }
}
