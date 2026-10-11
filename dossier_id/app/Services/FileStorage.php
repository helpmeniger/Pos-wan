<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;

/**
 * Stockage chiffré des photographies et documents, hors du répertoire public.
 * Les noms de fichiers sont aléatoires : aucune donnée personnelle dans les chemins.
 */
final class FileStorage
{
    public const CATEGORIES = ['photos', 'documents'];

    public static function root(): string
    {
        return rtrim((string) Config::get('storage.path'), '/\\');
    }

    public static function put(string $category, string $contents): string
    {
        self::assertCategory($category);
        $name = bin2hex(random_bytes(2)) . '/' . bin2hex(random_bytes(16)) . '.bin';
        $path = self::path($category, $name);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0750, true)) {
            throw new \RuntimeException('Impossible de créer le répertoire de stockage.');
        }
        if (file_put_contents($path, Crypto::encrypt($contents), LOCK_EX) === false) {
            throw new \RuntimeException("Échec de l'écriture du fichier.");
        }
        return $name;
    }

    public static function get(string $category, string $name): string
    {
        $path = self::path($category, $name);
        if (!is_file($path)) {
            throw new \RuntimeException('Fichier introuvable dans le stockage.');
        }
        return Crypto::decrypt((string) file_get_contents($path));
    }

    public static function path(string $category, string $name): string
    {
        self::assertCategory($category);
        if (!preg_match('#^[a-f0-9]{4}/[a-f0-9]{32}\.bin$#', $name)) {
            throw new \InvalidArgumentException('Nom de fichier de stockage invalide.');
        }
        return self::root() . '/' . $category . '/' . $name;
    }

    private static function assertCategory(string $category): void
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Catégorie de stockage invalide.');
        }
    }
}
