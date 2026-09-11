<?php

declare(strict_types=1);

namespace Cms\Security;

/**
 * RFC 6238 TOTP (SHA1, 30s, 6 digits) without external deps.
 */
final class Totp
{
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('TOTP secret byte length must be >= 1');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $timestamp ??= time();
        $period = 30;
        $counter = intdiv($timestamp, $period);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    public static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = (
            ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF)
        ) % 1_000_000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(\ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (\strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[(int) bindec($chunk)];
        }

        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $map = array_flip(str_split($alphabet));
        $bits = '';
        foreach (str_split($secret) as $char) {
            if (!isset($map[$char])) {
                continue;
            }
            $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (\strlen($chunk) === 8) {
                $out .= \chr((int) bindec($chunk));
            }
        }

        return $out;
    }
}
