<?php

namespace App\Usage\Infrastructure\Security;

/**
 * The secret the usage series identity is keyed with, derived from the app key under its own context
 * label so it never equals the key Laravel encrypts and signs with, nor any other key derived from it.
 */
final class UsageSeriesKey
{
    private const CONTEXT = 'memry-usage-series-v1';

    /** @param  string  $appKey  config('app.key'): raw bytes, or `base64:` followed by the encoded bytes */
    public static function fromAppKey(string $appKey): string
    {
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        return hash_hmac('sha256', self::CONTEXT, $appKey, true);
    }
}
