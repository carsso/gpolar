<?php
declare(strict_types=1);

namespace GPolar;

class ShareStore
{
    private static function path(): string
    {
        return __DIR__ . '/../data/.shares.json';
    }

    public static function all(): array
    {
        $path = self::path();
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private static function save(array $data): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents(self::path(), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** Create (or return existing) share token for a trip+user. */
    public static function create(string $tripId, string $userId, string $psToken): string
    {
        $shares = self::all();
        foreach ($shares as $token => $share) {
            if ($share['trip_id'] === $tripId && $share['user_id'] === $userId) {
                // Update token in case it changed
                if ($share['ps_token'] !== $psToken) {
                    $shares[$token]['ps_token'] = $psToken;
                    self::save($shares);
                }
                return $token;
            }
        }
        $shareToken = bin2hex(random_bytes(16));
        $shares[$shareToken] = [
            'trip_id'    => $tripId,
            'user_id'    => $userId,
            'ps_token'   => $psToken,
            'created_at' => time(),
        ];
        self::save($shares);
        return $shareToken;
    }

    public static function get(string $shareToken): ?array
    {
        return self::all()[$shareToken] ?? null;
    }

    /** Update the Polarsteps token for all shares belonging to a user. */
    public static function updateToken(string $userId, string $newPsToken): void
    {
        $shares  = self::all();
        $changed = false;
        foreach ($shares as &$share) {
            if ($share['user_id'] === $userId && $share['ps_token'] !== $newPsToken) {
                $share['ps_token'] = $newPsToken;
                $changed = true;
            }
        }
        unset($share);
        if ($changed) self::save($shares);
    }

    public static function delete(string $shareToken, string $userId): bool
    {
        $shares = self::all();
        if (!isset($shares[$shareToken])) return false;
        if ($shares[$shareToken]['user_id'] !== $userId) return false;
        unset($shares[$shareToken]);
        self::save($shares);
        return true;
    }

    /** Find share token for a given trip+user (null if none). */
    public static function findToken(string $tripId, string $userId): ?string
    {
        foreach (self::all() as $token => $share) {
            if ($share['trip_id'] === $tripId && $share['user_id'] === $userId) {
                return $token;
            }
        }
        return null;
    }
}
