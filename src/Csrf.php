<?php
declare(strict_types=1);

namespace Verifia;

/** Jeton CSRF lié à la session + vérification de l'origine des requêtes modifiant l'état. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    public static function isValid(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function originAllowed(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null || $origin === '') {
            // Pas d'en-tête Origin (anciens navigateurs, formulaires même origine) : on s'appuie sur le jeton.
            return true;
        }
        $allowed = (array) Config::get('security.allowed_origins', []);
        $host = $_SERVER['HTTP_HOST'] ?? '';
        foreach ($allowed as $a) {
            if (rtrim($a, '/') === rtrim($origin, '/')) {
                return true;
            }
        }
        // Même hôte que celui servi
        return parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') === $host;
    }

    /** À appeler au début de tout traitement POST. */
    public static function verifyRequest(bool $api = false): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::originAllowed() || !self::isValid($token)) {
            EventLog::log('warning', 'web', 'Requête rejetée : jeton CSRF ou origine invalide', null, ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
            if ($api) {
                Http::json(['error' => 'Jeton CSRF invalide'], 419);
            }
            http_response_code(419);
            exit('Session expirée ou requête non autorisée. Rechargez la page.');
        }
    }
}
