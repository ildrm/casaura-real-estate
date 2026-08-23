<?php

namespace App\Domain\Identity;

use InvalidArgumentException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function currentCode(string $secret): string
    {
        return $this->codeForTimestep($secret, $this->currentTimestep());
    }

    public function matchingTimestep(string $secret, string $code, ?int $now = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $current = $this->currentTimestep($now);
        foreach ([-1, 0, 1] as $offset) {
            $timestep = $current + $offset;
            if (hash_equals($this->codeForTimestep($secret, $timestep), $code)) {
                return $timestep;
            }
        }

        return null;
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $issuer = (string) config('identity.mfa.issuer', 'Casaura');
        $label = rawurlencode($issuer.':'.$email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    private function currentTimestep(?int $now = null): int
    {
        return intdiv($now ?? time(), 30);
    }

    private function codeForTimestep(string $secret, int $timestep): string
    {
        $counter = pack('N2', 0, $timestep);
        $hash = hash_hmac('sha1', $counter, $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = unpack('N', substr($hash, $offset, 4));
        $value = ((int) $binary[1] & 0x7FFFFFFF) % 1_000_000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[\s-]+/', '', $encoded) ?? '');
        $buffer = 0;
        $bits = 0;
        $decoded = '';
        foreach (str_split($encoded) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new InvalidArgumentException('Invalid Base32 secret.');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
            }
        }

        return $decoded;
    }
}
