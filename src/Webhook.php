<?php

declare(strict_types=1);

namespace SpeedyIndex;

/** Verify Rank Tracker webhook signatures (X-SpeedyIndex-Signature: sha256=<hex HMAC-SHA256 of the raw body>). */
final class Webhook
{
    public static function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /** True if any comma-separated signature in the header matches (two are sent during secret rotation). */
    public static function verify(string $body, ?string $header, ?string $secret): bool
    {
        if (!$header || !$secret) {
            return false;
        }
        $expected = self::sign($body, $secret);
        foreach (explode(',', $header) as $sig) {
            if (hash_equals($expected, trim($sig))) {
                return true;
            }
        }
        return false;
    }

    /** Verify, then decode. Process each event_id once: deliveries may repeat. */
    public static function parse(string $body, ?string $header, ?string $secret): array
    {
        if (!self::verify($body, $header, $secret)) {
            throw new SpeedyIndexException('invalid SpeedyIndex webhook signature');
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
