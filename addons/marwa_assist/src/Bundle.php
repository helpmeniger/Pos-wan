<?php
declare(strict_types=1);

namespace Addons\MarwaAssist;

use App\Core\BusinessException;

/**
 * Programmes livrés dans le module (addons/marwa_assist/paquet) : RustDesk (client Windows) et
 * RustDesk Server (Windows et Linux), non modifiés, licence AGPL-3.0.
 *
 * Ils sont livrés en fragments neutres « .partNNN » de 1 Mo au plus (limites d'envoi de WampServer,
 * aucun .exe en clair que l'antivirus bloquerait pendant la mise à jour), puis reconstitués dans
 * storage/assist/ au premier usage. Chaque fichier est vérifié par son empreinte SHA-256.
 */
final class Bundle
{
    public static function dir(): string
    {
        return dirname(__DIR__) . '/paquet';
    }

    public static function target(string $sub = ''): string
    {
        return BASE_PATH . '/storage/assist' . ($sub !== '' ? '/' . $sub : '');
    }

    public static function manifest(): ?array
    {
        $f = self::dir() . '/paquet.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($d) && isset($d['files']) && is_array($d['files']) ? $d : null;
    }

    /**
     * Reconstitue les fichiers (tous, ou seulement ceux dont le chemin commence par $prefix).
     * @return array{deployed:int, skipped:int, done:bool}
     */
    public static function deploy(string $prefix = '', ?string $base = null, bool $force = false): array
    {
        $m = self::manifest();
        $out = ['deployed' => 0, 'skipped' => 0, 'done' => false];
        if (!$m) {
            throw new BusinessException('Paquet d\'assistance absent (dossier addons/marwa_assist/paquet incomplet).');
        }
        $base ??= self::target();
        $marker = $base . '/.paquet-' . substr(hash('sha256', json_encode($m['files']) . $prefix), 0, 16);
        if (!$force && is_file($marker)) {
            return ['done' => true] + $out;
        }
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            throw new BusinessException('Dossier ' . $base . ' impossible à créer.');
        }
        $lock = fopen($base . '/paquet.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $out;
        }
        try {
            foreach ($m['files'] as $rel => $f) {
                if (!preg_match('#^[a-z0-9_-]+(/[A-Za-z0-9_.-]+)+$#', $rel) || str_contains($rel, '..')) {
                    throw new BusinessException('Paquet d\'assistance invalide : ' . $rel);
                }
                if ($prefix !== '' && !str_starts_with($rel, $prefix)) {
                    continue;
                }
                $target = $base . '/' . $rel;
                if (!$force && is_file($target) && filesize($target) === (int) $f['size'] && hash_file('sha256', $target) === $f['sha256']) {
                    $out['skipped']++;
                    continue;
                }
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                $tmp = $target . '.tmp';
                $w = fopen($tmp, 'wb');
                for ($i = 0; $i < max(1, (int) $f['parts']); $i++) {
                    $src = sprintf('%s/%s.part%03d', self::dir(), $rel, $i);
                    $r = @fopen($src, 'rb');
                    if (!$r) {
                        fclose($w);
                        @unlink($tmp);
                        throw new BusinessException('Fragment manquant : ' . basename($src) . ' (recopiez le dossier addons/marwa_assist).');
                    }
                    stream_copy_to_stream($r, $w);
                    fclose($r);
                }
                fclose($w);
                if (hash_file('sha256', $tmp) !== $f['sha256']) {
                    @unlink($tmp);
                    throw new BusinessException('Fichier altéré (empreinte SHA-256 différente) : ' . $rel . '. Recopiez le dossier addons/marwa_assist.');
                }
                if (!@rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new BusinessException('Impossible de remplacer ' . $rel . ' (programme en cours d\'utilisation ?).');
                }
                if (!str_ends_with($rel, '.exe') && str_contains($rel, '/linux/')) {
                    @chmod($target, 0755);
                }
                $out['deployed']++;
            }
            file_put_contents($marker, date('c'));
            $out['done'] = true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $out;
    }

    /** Chemin d'un programme livré, reconstitué au besoin. */
    public static function file(string $rel): string
    {
        $m = self::manifest();
        if (!$m || !isset($m['files'][$rel])) {
            throw new BusinessException('Programme absent du paquet : ' . $rel);
        }
        $p = self::target($rel);
        if (!is_file($p) || filesize($p) !== (int) $m['files'][$rel]['size']) {
            self::deploy(explode('/', $rel)[0] . '/');
        }
        if (!is_file($p)) {
            throw new BusinessException('Programme non disponible : ' . $rel);
        }
        return $p;
    }
}
