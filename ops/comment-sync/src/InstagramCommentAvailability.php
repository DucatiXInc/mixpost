<?php

namespace Inovector\Mixpost\Support;

/** Only our reversible metadata changes; message bodies and unrelated metadata are never removed. */
final class InstagramCommentAvailability
{
    public const KEY = 'peachy_instagram_availability';
    public const GRACE_SECONDS = 600;

    public static function next(?array $data, bool $present, bool $complete, int $now): ?array
    {
        $original = $data;
        $data ??= [];
        $old = $data[self::KEY] ?? [];
        if ($present && $complete) {
            if (! array_key_exists(self::KEY, $data)) {
                return $original;
            }
            unset($data[self::KEY]);
            return $data === [] ? null : $data;
        }
        if (! $complete) {
            // A failed scan breaks pending evidence, but never clears an established marker.
            if (($old['state'] ?? null) === 'pending') {
                unset($data[self::KEY]);
                return $data === [] ? null : $data;
            }
            return $original;
        }
        if (($old['state'] ?? null) === 'unavailable') {
            return $original;
        }
        $first = $old['first_missing_at'] ?? $now;
        $last = $old['last_missing_at'] ?? $now;
        // Repeated invocations at the same time cannot supply a second observation.
        $confirmed = ($old['state'] ?? null) === 'pending'
            && is_int($first) && is_int($last) && $now > $last && $now - $first >= self::GRACE_SECONDS;
        $data[self::KEY] = [
            'state' => $confirmed ? 'unavailable' : 'pending',
            'source' => 'complete_scans',
            'first_missing_at' => $first,
            'last_missing_at' => $now,
        ];
        return $data;
    }
}
