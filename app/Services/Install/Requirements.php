<?php

declare(strict_types=1);

namespace App\Services\Install;

/** Vérification de l'environnement avant installation (PHP, extensions, droits d'écriture, Apache). */
final class Requirements
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return list<array{label: string, ok: bool, required: bool, detail: string}> */
    public function check(): array
    {
        $items = [];
        $items[] = [
            'label' => 'PHP 8.2 ou plus récent',
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'required' => true,
            'detail' => 'Version détectée : ' . PHP_VERSION . ' (WampServer : icône → PHP → Version)',
        ];
        foreach (['pdo_mysql' => true, 'mbstring' => true, 'fileinfo' => true, 'openssl' => true, 'session' => true, 'json' => true,
                  'gd' => false, 'intl' => false, 'curl' => false] as $ext => $required) {
            $items[] = [
                'label' => "Extension PHP « {$ext} »",
                'ok' => extension_loaded($ext),
                'required' => $required,
                'detail' => extension_loaded($ext) ? 'Active' : 'Inactive — WampServer : icône → PHP → Extensions PHP → cocher ' . $ext,
            ];
        }
        $items[] = [
            'label' => 'Hachage Argon2id des mots de passe',
            'ok' => defined('PASSWORD_ARGON2ID'),
            'required' => false,
            'detail' => defined('PASSWORD_ARGON2ID') ? 'Disponible' : 'Indisponible : bcrypt sera utilisé (sûr)',
        ];
        foreach (['', 'storage/logs', 'storage/sessions', 'storage/cache', 'storage/private', 'public/uploads'] as $dir) {
            $path = rtrim($this->root . '/' . $dir, '/');
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
            $items[] = [
                'label' => 'Écriture dans ' . ($dir === '' ? 'le dossier du projet (fichier .env)' : $dir),
                'ok' => is_writable($path),
                'required' => true,
                'detail' => is_writable($path) ? 'OK' : 'Donnez le droit d\'écriture au serveur web sur ' . $path,
            ];
        }
        if (function_exists('apache_get_modules')) {
            $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
            $items[] = [
                'label' => 'Module Apache « rewrite »',
                'ok' => $rewrite,
                'required' => true,
                'detail' => $rewrite ? 'Actif' : 'Inactif — WampServer : icône → Apache → Modules Apache → rewrite_module',
            ];
        }
        return $items;
    }

    /** @param list<array{ok: bool, required: bool}> $items */
    public static function allRequiredOk(array $items): bool
    {
        foreach ($items as $i) {
            if ($i['required'] && !$i['ok']) {
                return false;
            }
        }
        return true;
    }
}
