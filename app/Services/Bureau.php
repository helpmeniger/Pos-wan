<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Liens avec le lanceur de l'édition Bureau (POS Marwa.exe), joignable seulement depuis ce PC avec le jeton secret
 * de son démarrage. Hors édition Bureau, ces fonctions ne font rien.
 */
final class Bureau
{
    public static function active(): bool
    {
        return getenv('POS_BUREAU') === '1' && (int) getenv('POS_BUREAU_PORT') > 0 && (string) getenv('POS_BUREAU_JETON') !== '';
    }

    /**
     * Transmet le réglage « impression automatique » : activé, la fenêtre imprime les tickets sans boîte de dialogue.
     * @return array{rouvrir: bool, sans_fenetre: bool}|null null si le lanceur n'a pas répondu
     */
    public static function impression(bool $auto): ?array
    {
        if (!self::active()) {
            return null;
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 5, 'ignore_errors' => true,
            'header' => "X-Bureau-Jeton: " . getenv('POS_BUREAU_JETON') . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['auto' => $auto ? '1' : '0'])]]);
        $r = @file_get_contents('http://127.0.0.1:' . (int) getenv('POS_BUREAU_PORT') . '/__bureau/impression', false, $ctx);
        $d = is_string($r) ? json_decode($r, true) : null;
        return is_array($d) ? ['rouvrir' => !empty($d['rouvrir']), 'sans_fenetre' => !empty($d['sans_fenetre'])] : null;
    }
}
