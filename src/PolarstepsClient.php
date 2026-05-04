<?php
declare(strict_types=1);

namespace GPolar;

class PolarstepsClient
{
    private const API_URL = 'https://api.polarsteps.com';
    private const WEB_URL = 'https://www.polarsteps.com';

    public function __construct(private readonly string $rememberToken) {}

    // ── Auth ──────────────────────────────────────────────────────────────────

    /**
     * Authenticate with username/password, return the remember_token.
     * Throws on bad credentials or missing cookie.
     */
    public static function loginWithCredentials(string $username, string $password): string
    {
        $rememberToken = null;

        $ch = curl_init(self::WEB_URL . '/api/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['username' => $username, 'password' => $password]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: */*',
                'Accept-Language: fr,fr-FR;q=0.9,en;q=0.8',
                'Origin: https://www.polarsteps.com',
                'Referer: https://www.polarsteps.com/login?locale=fr',
                'polarsteps-api-version: 69',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:150.0) Gecko/20100101 Firefox/150.0',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$rememberToken) {
                if (stripos($header, 'Set-Cookie:') === 0) {
                    if (preg_match('/remember_token=([^;]+)/i', $header, $m)) {
                        $rememberToken = $m[1];
                    }
                }
                return strlen($header);
            },
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Erreur réseau : {$err}");
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException("Identifiants incorrects. Vérifie ton email/mot de passe.");
        }
        if ($code === 405) {
            throw new \RuntimeException("Endpoint de connexion inaccessible (HTTP 405). Utilise le token direct (onglet avancé).");
        }
        if ($code !== 200) {
            $preview = $body ? substr(strip_tags((string)$body), 0, 120) : '';
            throw new \RuntimeException("Erreur de connexion Polarsteps (HTTP {$code})" . ($preview ? " : {$preview}" : '.'));
        }
        if (!$rememberToken) {
            throw new \RuntimeException("Token introuvable dans la réponse — essaie avec le token direct.");
        }

        return $rememberToken;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Get the authenticated user's profile, including followers/followees.
     * Uses https://www.polarsteps.com/currentuser
     */
    public function getMe(): array
    {
        return $this->request('/currentuser', self::WEB_URL);
    }

    /**
     * Get the social activity feed (active trips from followees).
     * Uses https://www.polarsteps.com/api/social/activity-feed
     */
    public function getActivityFeed(): array
    {
        return $this->request('/api/social/activity-feed', self::WEB_URL);
    }

    /**
     * Get a user's public profile by their Polarsteps user ID.
     */
    public function getUser(int $userId): array
    {
        return $this->request("/users/{$userId}");
    }

    /**
     * Get a user's public profile by their Polarsteps username.
     */
    public function getUserByUsername(string $username): array
    {
        return $this->request("/users/username/{$username}");
    }

    /**
     * Get full trip data (steps, photos, etc.) by trip ID.
     */
    public function getTrip(string|int $tripId): array
    {
        return $this->request("/trips/{$tripId}");
    }

    /**
     * Get comments for a step.
     */
    public function getStepComments(int $stepId): array
    {
        return $this->request("/social/steps/{$stepId}/comments");
    }

    /**
     * Fetch multiple users in parallel to reduce total wait time.
     * Returns array keyed by user ID.
     */
    public function getUsersParallel(array $userIds): array
    {
        if (empty($userIds)) return [];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($userIds as $id) {
            $ch = $this->buildHandle("/users/{$id}");
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh);
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($code === 200 && $body) {
                $data = json_decode($body, true);
                if ($data && !empty($data['id'])) {
                    $results[$id] = $data;
                }
            }
        }

        curl_multi_close($mh);
        return $results;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function request(string $path, string $baseUrl = self::API_URL): array
    {
        $ch   = $this->buildHandle($path, $baseUrl);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Erreur réseau : {$err}");
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException(
                "Accès refusé (HTTP {$code}). " .
                "Vérifie que ton token est correct et non expiré."
            );
        }
        if ($code === 404) {
            throw new \RuntimeException("Ressource introuvable (HTTP 404) : {$path}");
        }
        if ($code !== 200) {
            throw new \RuntimeException("L'API a retourné HTTP {$code}");
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Réponse JSON invalide de l'API Polarsteps");
        }

        return $data;
    }

    private function buildHandle(string $path, string $baseUrl = self::API_URL): \CurlHandle
    {
        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_COOKIE         => 'remember_token=' . $this->rememberToken,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'polarsteps-api-version: ' . ($baseUrl === self::WEB_URL ? '69' : '61'),
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:150.0) Gecko/20100101 Firefox/150.0',
            ],
        ]);
        return $ch;
    }
}
