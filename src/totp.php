<?php
declare(strict_types=1);

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const TOTP_PERIOD = 30;

function base32_encode(string $binary): string
{
    $bits = '';
    foreach (str_split($binary) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/**
 * @throws InvalidArgumentException on characters outside the RFC 4648 alphabet.
 */
function base32_decode(string $encoded): string
{
    $bits = '';
    foreach (str_split(strtoupper($encoded)) as $char) {
        $pos = strpos(BASE32_ALPHABET, $char);
        if ($pos === false) {
            throw new InvalidArgumentException('Invalid base32 input');
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_generate_secret(): string
{
    return base32_encode(random_bytes(20));
}

/**
 * RFC 6238 code. HMAC-SHA1 is the RFC default and the only variant every authenticator
 * app supports; HMAC-SHA1 is not affected by SHA-1 collision attacks.
 */
function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Returns the matching time step (±1 step clock drift) only if it is newer than $lastStep,
 * which blocks replay of an already used code.
 */
function totp_match(string $secret, string $code, int $lastStep, int $now): ?int
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $current = intdiv($now, TOTP_PERIOD);
    for ($delta = -1; $delta <= 1; $delta++) {
        $step = $current + $delta;
        if ($step > $lastStep && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secret, string $username): string
{
    return 'otpauth://totp/' . rawurlencode('Ledencontact:' . $username)
        . '?secret=' . $secret . '&issuer=Ledencontact&algorithm=SHA1&digits=6&period=' . TOTP_PERIOD;
}
