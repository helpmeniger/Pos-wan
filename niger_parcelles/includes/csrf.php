<?php
/**
 * Protection CSRF : un jeton aléatoire par session, exigé pour toute requête
 * qui modifie des données (formulaire : champ _csrf ; API : en-tête X-CSRF-Token).
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($expected) && $expected !== ''
        && hash_equals($expected, $token);
}

/** Interrompt la requête si le jeton CSRF est absent ou invalide. */
function require_csrf(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_valid(is_string($token) ? $token : null)) {
        audit_log('csrf_refuse', null, null, ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
        throw new UserFacingException('Jeton de sécurité (CSRF) invalide ou expiré. Rechargez la page et réessayez.', 403);
    }
}
