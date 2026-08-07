<?php

declare(strict_types=1);

namespace NoteMy\Http;

/**
 * Address maths for rate limiting and proxy trust.
 *
 * Raw IPs are never stored anywhere. This class exists so that the only thing
 * that ever reaches Redis is an HMAC of a *network*, not of a host.
 */
final class ClientIp
{
    /**
     * Collapse an address to its rate-limiting network: IPv4 to /24, IPv6 to
     * /64. Returns the packed network address, or null if unparseable.
     *
     * /64 for IPv6 is not arbitrary — it is the smallest block a residential
     * customer is normally assigned, so anything finer would let one user
     * mint unlimited quota by walking their own prefix.
     */
    public static function normalize(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        return match (strlen($packed)) {
            4       => self::mask($packed, 24),
            16      => self::mask($packed, 64),
            default => null,
        };
    }

    /** @param list<string> $cidrs */
    public static function inAny(string $ip, array $cidrs): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            $netPacked = @inet_pton((string) $net);
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
            if (self::mask($packed, $bits) === self::mask($netPacked, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function mask(string $packed, int $bits): string
    {
        $len = strlen($packed);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $remaining = $bits - $i * 8;
            $byteMask = $remaining >= 8 ? 0xFF : ($remaining <= 0 ? 0x00 : (0xFF << (8 - $remaining)) & 0xFF);
            $out .= chr(ord($packed[$i]) & $byteMask);
        }

        return $out;
    }
}
