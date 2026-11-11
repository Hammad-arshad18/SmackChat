<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

class OfficeNetwork
{
    /**
     * @param  list<string>|null  $entries
     * @return list<string>
     */
    public static function normalize(?array $entries): array
    {
        $normalized = [];

        foreach ($entries ?? [] as $entry) {
            $value = strtolower(trim((string) $entry));
            if ($value === '') {
                continue;
            }

            if (! self::isValidEntry($value)) {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    public static function isValidEntry(string $entry): bool
    {
        $entry = strtolower(trim($entry));

        if ($entry === '') {
            return false;
        }

        if (str_contains($entry, '/')) {
            [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);
            if ($ip === null || $mask === null || ! is_numeric($mask)) {
                return false;
            }

            $mask = (int) $mask;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mask >= 0 && $mask <= 32;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $mask >= 0 && $mask <= 128;
            }

            return false;
        }

        return (bool) filter_var($entry, FILTER_VALIDATE_IP);
    }

    /**
     * @param  list<string>|null  $entries
     */
    public static function allows(?string $ip, ?array $entries): bool
    {
        if (! is_string($ip) || $ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ranges = self::normalize($entries);
        if ($ranges === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $ranges);
    }
}
