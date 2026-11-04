<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;

class TwoFactorService
{
    /**
     * Standard Base32 alphabet according to RFC 4648.
     */
    protected const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a new cryptographically secure Base32 secret key.
     */
    public function generateSecretKey(int $length = 16): string
    {
        $secret = '';
        $alphabetLength = strlen(self::BASE32_ALPHABET);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $secret;
    }

    /**
     * Generate standard otpauth URI compatible with Google Authenticator, Authy, Apple, etc.
     */
    public function getQrCodeUri(string $companyName, string $accountName, string $secret): string
    {
        $label = rawurlencode($companyName) . ':' . rawurlencode($accountName);

        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $companyName,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Calculate a 6-digit TOTP code for a given timestamp (RFC 6238).
     */
    public function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $timeSlice = (int) floor($timestamp / 30);

        $binarySecret = $this->base32Decode($secret);
        $timeBytes = pack('N*', 0, $timeSlice);

        $hash = hash_hmac('sha1', $timeBytes, $binarySecret, true);
        $offset = ord($hash[19]) & 0x0f;

        $code = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return sprintf('%06d', $code % 1000000);
    }

    /**
     * Verify a 6-digit TOTP code with time drift window tolerance (default: ±1 slice = ±30s).
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $cleanCode = preg_replace('/\s+/', '', trim($code));
        if (strlen($cleanCode) !== 6 || ! ctype_digit($cleanCode)) {
            return false;
        }

        $currentTime = time();

        for ($slice = -$window; $slice <= $window; $slice++) {
            $calcCode = $this->generateCode($secret, $currentTime + ($slice * 30));
            if (hash_equals($calcCode, $cleanCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an array of secure emergency recovery codes.
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::lower(Str::random(5)) . '-' . Str::lower(Str::random(5));
        }

        return $codes;
    }

    /**
     * Verify and consume an emergency recovery code.
     */
    public function verifyRecoveryCode(array &$recoveryCodes, string $code): bool
    {
        $normalizedInput = strtolower(str_replace(['-', ' '], '', trim($code)));

        foreach ($recoveryCodes as $index => $candidate) {
            $normalizedCandidate = strtolower(str_replace(['-', ' '], '', $candidate));

            if (hash_equals($normalizedCandidate, $normalizedInput)) {
                unset($recoveryCodes[$index]);
                $recoveryCodes = array_values($recoveryCodes);
                return true;
            }
        }

        return false;
    }

    /**
     * Decode a Base32 string into binary bytes.
     */
    protected function base32Decode(string $b32): string
    {
        $b32 = strtoupper(trim($b32));
        $binary = '';

        foreach (str_split($b32) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue; // Ignore invalid characters / padding
            }
            $binary .= sprintf('%05b', $pos);
        }

        $raw = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $raw .= chr(bindec($byte));
            }
        }

        return $raw;
    }
}

