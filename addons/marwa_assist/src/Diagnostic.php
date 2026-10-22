<?php
declare(strict_types=1);

namespace Addons\MarwaAssist;

use App\Core\DB;

/**
 * Diagnostic pour le technicien : uniquement l'état technique (aucune vente, aucun client,
 * aucun mot de passe). Calculé à la demande, jamais en arrière-plan de la caisse.
 * Chaque ligne : [libellé, état ok|warn|bad|info, détail].
 */
final class Diagnostic
{
    public static function run(): array
    {
        $rows = [];
        $rows[] = ['Version de POS Marwa', 'info', (string) config('app.version')];
        $rows[] = ['PHP', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn', PHP_VERSION . ' · ' . PHP_SAPI . ' · ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'serveur web')];
        try {
            $t = microtime(true);
            $v = (string) DB::value('SELECT VERSION()');
            $ms = (int) round((microtime(true) - $t) * 1000);
            $rows[] = ['Base de données (MySQL / MariaDB)', $ms < 200 ? 'ok' : 'warn', $v . ' · réponse ' . $ms . ' ms'];
        } catch (\Throwable $e) {
            $rows[] = ['Base de données (MySQL / MariaDB)', 'bad', 'injoignable'];
        }
        try {
            $open = (int) DB::value('SELECT COUNT(*) FROM cash_sessions WHERE closed_at IS NULL');
            $last = DB::value('SELECT MAX(created_at) FROM sales');
            $rows[] = ['Caisse', 'ok', $open . ' caisse(s) ouverte(s) · dernière vente : ' . ($last ?: 'aucune')];
        } catch (\Throwable $e) {
            $rows[] = ['Caisse', 'warn', 'état non lisible'];
        }
        foreach (DB::all("SELECT name, driver, address FROM printers WHERE active = 1 ORDER BY id") as $p) {
            if ($p['driver'] === 'network' && preg_match('/^([A-Za-z0-9.-]+):(\d{1,5})$/', (string) $p['address'], $m)) {
                $s = @stream_socket_client("tcp://{$m[1]}:{$m[2]}", $en, $es, 1.0);
                if ($s) {
                    fclose($s);
                }
                $rows[] = ['Imprimante « ' . $p['name'] . ' »', $s ? 'ok' : 'bad', ($s ? 'joignable' : 'injoignable') . ' (' . $p['address'] . ')'];
            } else {
                $rows[] = ['Imprimante « ' . $p['name'] . ' »', 'info', $p['driver'] === 'browser' ? 'impression par le navigateur' : 'imprimante Windows (non vérifiable d\'ici)'];
            }
        }
        $free = @disk_free_space(BASE_PATH);
        if ($free !== false) {
            $gb = $free / 1073741824;
            $rows[] = ['Espace disque libre', $gb > 5 ? 'ok' : ($gb > 1 ? 'warn' : 'bad'), number_format($gb, 1, ',', ' ') . ' Go'];
        }
        $s = AssistService::settings();
        if ($s['host'] !== '') {
            $t = microtime(true);
            $c = @stream_socket_client('tcp://' . $s['host'] . ':21116', $en, $es, 3.0);
            $ms = (int) round((microtime(true) - $t) * 1000);
            if ($c) {
                fclose($c);
            }
            $rows[] = ['Connexion au serveur d\'assistance HTN', $c ? 'ok' : 'bad', $s['host'] . ($c ? ' · joignable en ' . $ms . ' ms' : ' · injoignable (Internet coupé, serveur arrêté ou ports fermés)')];
        } else {
            $rows[] = ['Connexion au serveur d\'assistance HTN', 'warn', 'serveur non réglé'];
        }
        $postes = DB::all("SELECT label, rustdesk_id FROM assist_postes WHERE kind = 'boutique' AND revoked_at IS NULL");
        $ready = array_filter($postes, fn($p) => $p['rustdesk_id']);
        $rows[] = ['Postes équipés pour l\'assistance', $ready ? 'ok' : 'warn', count($ready) . ' / ' . count($postes) . ($ready ? ' : ' . implode(', ', array_map(fn($p) => $p['label'] . ' (' . $p['rustdesk_id'] . ')', $ready)) : '')];
        $lic = \App\Services\LicenceService::status();
        $rows[] = ['Licence POS Marwa', $lic['state'] === 'expired' ? 'bad' : 'ok', $lic['state'] . ($lic['days_left'] !== null ? ' · ' . $lic['days_left'] . ' jour(s)' : '')];
        return $rows;
    }

    /** Dernières erreurs du journal technique (lignes ERROR / CRITICAL du mois, tronquées). */
    public static function recentErrors(int $max = 15): array
    {
        $files = glob(BASE_PATH . '/storage/logs/app-*.log') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, 2) as $f) {
            $lines = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
            foreach (array_reverse(array_slice($lines, -2000)) as $l) {
                if (preg_match('/\] (ERROR|CRITICAL):/', $l)) {
                    // Masque d'éventuels secrets dans le texte (mot de passe, jeton, clé)
                    $out[] = mb_substr(preg_replace('/(pass(word)?|mot de passe|token|jeton|secret|key|cl[ée])(["\']?\s*[:=]\s*)\S+/iu', '$1$3***', $l), 0, 300);
                    if (count($out) >= $max) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    /** Texte à transmettre au technicien (copier / WhatsApp), sans donnée commerciale. */
    public static function text(array $rows): string
    {
        $t = 'Diagnostic POS Marwa ' . date('d/m/Y H:i') . ' — code boutique ' . \App\Services\LicenceService::installId() . "\n";
        foreach ($rows as [$label, $state, $detail]) {
            $t .= ['ok' => '✔', 'warn' => '⚠', 'bad' => '✘', 'info' => '•'][$state] . ' ' . $label . ' : ' . $detail . "\n";
        }
        return $t;
    }
}
