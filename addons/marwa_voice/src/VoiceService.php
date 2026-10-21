<?php
declare(strict_types=1);

namespace Addons\MarwaVoice;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * MARWA VOICE AI — réglages, catalogue des événements annoncés et historique.
 * Les phrases sont construites dans le navigateur (voix-core.js) à partir des seules données
 * transmises par POS Marwa ; le serveur décide quels événements sont lus et à quel niveau.
 */
final class VoiceService
{
    public const LEVELS = ['Information', 'Notification', 'Avertissement', 'Erreur', 'Critique'];
    public const STATUSES = ['lu', 'doublon', 'groupe', 'silence', 'expire', 'interrompu', 'file_pleine', 'erreur_voix', 'desactive'];
    public const MODULES = ['caisse' => 'Caisse', 'impression' => 'Impression', 'pages' => 'Pages de gestion', 'systeme' => 'Système'];

    /**
     * Événements connus : code => [module, libellé, niveau par défaut, lu par défaut].
     * Les annonces répétitives (article ajouté, ticket envoyé) sont désactivées par défaut.
     */
    public static function catalogue(): array
    {
        return [
            'pos.sale.saved' => ['caisse', 'Vente enregistrée : total et monnaie à rendre', 1, true],
            'pos.sale.refused' => ['caisse', 'Vente refusée par le serveur (motif lu)', 3, true],
            'pos.sale.unknown' => ['caisse', 'Connexion interrompue pendant la validation : vente non confirmée', 3, true],
            'pos.auth.required' => ['caisse', 'Autorisation d\'un responsable nécessaire', 2, true],
            'pos.payment.insufficient' => ['caisse', 'Montant reçu insuffisant', 2, true],
            'pos.payment.error' => ['caisse', 'Paiement refusé (motif lu)', 3, true],
            'pos.stock.insufficient' => ['caisse', 'Stock insuffisant pour l\'article ajouté', 2, true],
            'pos.product.notfound' => ['caisse', 'Article introuvable (code inconnu)', 2, true],
            'pos.register.closed' => ['caisse', 'Caisse fermée', 2, true],
            'pos.product.added' => ['caisse', 'Article ajouté et total du panier (répétitif)', 0, false],
            'pos.cart.held' => ['caisse', 'Vente mise en attente', 0, false],
            'pos.print.failed' => ['impression', 'Impression du ticket en échec (la vente reste enregistrée)', 3, true],
            'pos.print.sent' => ['impression', 'Ticket envoyé à l\'imprimante du serveur', 0, false],
            'page.error' => ['pages', 'Message d\'erreur affiché sur une page', 3, true],
            'page.warning' => ['pages', 'Message d\'avertissement affiché sur une page', 2, false],
            'voix.test' => ['systeme', 'Essai de la voix', 1, true],
        ];
    }

    /** Réglage des événements : catalogue + choix enregistrés (actif, niveau). */
    public static function events(): array
    {
        $saved = json_decode((string) setting('voix_evenements', '{}'), true);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::catalogue() as $code => [$module, $label, $level, $on]) {
            $s = $saved[$code] ?? [];
            $out[$code] = ['module' => $module, 'label' => $label,
                'level' => isset($s['level']) ? max(0, min(4, (int) $s['level'])) : $level,
                'on' => isset($s['on']) ? (bool) $s['on'] : $on, 'default_level' => $level, 'default_on' => $on];
        }
        return $out;
    }

    /** Configuration envoyée aux postes (aucune donnée confidentielle). */
    public static function config(): array
    {
        $cur = currency();
        return [
            'active' => setting('voix_actif', '1') === '1',
            'engine' => (string) setting('voix_moteur', 'auto'),
            'offlineOnly' => setting('voix_hors_ligne', '1') === '1',
            'volume' => (float) setting('voix_volume', '0.9'),
            'rate' => (float) setting('voix_debit', '1.0'),
            'browserVoice' => (string) setting('voix_navigateur_voix', ''),
            'piper' => PiperService::available(),
            'quiet' => ['start' => (string) setting('voix_silence_debut', ''), 'end' => (string) setting('voix_silence_fin', ''),
                'critical' => setting('voix_critique_en_silence', '1') === '1'],
            'logLevel' => (int) setting('voix_journal_niveau', '2'),
            'currency' => ['code' => (string) ($cur['code'] ?? 'XOF'), 'name' => (string) ($cur['name'] ?? ''), 'decimals' => (int) ($cur['decimals'] ?? 0)],
            'events' => array_map(fn($e) => ['on' => $e['on'], 'level' => $e['level'], 'module' => $e['module']], self::events()),
            'manage' => can('marwa_voice.manage'),
        ];
    }

    public static function saveSettings(array $in): void
    {
        $time = function (string $v): string {
            $v = trim($v);
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '';
        };
        $engine = (string) ($in['voix_moteur'] ?? 'auto');
        if (!in_array($engine, ['auto', 'piper', 'navigateur'], true)) {
            throw new BusinessException('Moteur de voix inconnu.');
        }
        $start = $time((string) ($in['voix_silence_debut'] ?? ''));
        $end = $time((string) ($in['voix_silence_fin'] ?? ''));
        if (($start === '') !== ($end === '')) {
            throw new BusinessException('Indiquez le début et la fin de la plage de silence (format 22:00), ou laissez les deux vides.');
        }
        $piperVoice = (string) ($in['voix_piper_voix'] ?? '');
        if ($piperVoice !== '' && !in_array($piperVoice, array_column(PiperService::voices(), 'file'), true)) {
            throw new BusinessException('Voix Piper introuvable sur le serveur.');
        }
        $events = [];
        $catalogue = self::catalogue();
        foreach ($catalogue as $code => $def) {
            $key = str_replace('.', '_', $code);
            $events[$code] = ['on' => !empty($in['ev_on'][$key]), 'level' => max(0, min(4, (int) ($in['ev_level'][$key] ?? $def[2])))];
        }
        setting_set('voix_actif', !empty($in['voix_actif']) ? '1' : '0');
        setting_set('voix_moteur', $engine);
        setting_set('voix_hors_ligne', !empty($in['voix_hors_ligne']) ? '1' : '0');
        setting_set('voix_volume', (string) max(0.1, min(1, round((float) str_replace(',', '.', (string) ($in['voix_volume'] ?? '0.9')), 2))));
        setting_set('voix_debit', (string) max(0.6, min(1.6, round((float) str_replace(',', '.', (string) ($in['voix_debit'] ?? '1')), 2))));
        setting_set('voix_navigateur_voix', mb_substr(trim((string) ($in['voix_navigateur_voix'] ?? '')), 0, 120));
        setting_set('voix_piper_voix', $piperVoice);
        setting_set('voix_silence_debut', $start);
        setting_set('voix_silence_fin', $end);
        setting_set('voix_critique_en_silence', !empty($in['voix_critique_en_silence']) ? '1' : '0');
        setting_set('voix_journal_niveau', (string) max(0, min(4, (int) ($in['voix_journal_niveau'] ?? 2))));
        setting_set('voix_journal_jours', (string) max(7, min(730, (int) ($in['voix_journal_jours'] ?? 90))));
        setting_set('voix_evenements', json_encode($events, JSON_UNESCAPED_UNICODE));
        Audit::log('settings.marwa_voice', null, null, ['moteur' => $engine, 'actif' => setting('voix_actif'), 'silence' => $start . '-' . $end,
            'evenements_lus' => array_keys(array_filter($events, fn($e) => $e['on']))]);
    }

    /**
     * Historique envoyé par un poste (par lots). Chaque ligne est contrôlée : événement connu,
     * niveau 0-4, statut connu, textes courts. Seuls les niveaux ≥ réglage sont conservés.
     */
    public static function log(array $items): int
    {
        if (count($items) > 50) {
            throw new BusinessException('Trop d\'annonces envoyées en une fois.');
        }
        $min = (int) setting('voix_journal_niveau', '2');
        $known = self::catalogue();
        $n = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $event = (string) ($it['event'] ?? '');
            $status = (string) ($it['status'] ?? '');
            $level = (int) ($it['level'] ?? -1);
            if (!isset($known[$event]) || !in_array($status, self::STATUSES, true) || $level < 0 || $level > 4) {
                throw new BusinessException('Annonce invalide.');
            }
            if ($level < $min && $status !== 'erreur_voix') {
                continue;
            }
            $clean = fn($k, $len) => ($v = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($it[$k] ?? ''))))) === '' ? null : mb_substr($v, 0, $len);
            DB::insert('marwa_voice_log', ['event' => $event, 'level' => $level, 'status' => $status, 'text' => $clean('text', 400),
                'engine' => in_array($it['engine'] ?? '', ['piper', 'navigateur', 'aucun'], true) ? $it['engine'] : null,
                'poste' => $clean('poste', 40), 'page' => $clean('page', 60), 'user_id' => Auth::id()]);
            $n++;
        }
        if ($n && random_int(1, 50) === 1) {
            self::purge();
        }
        return $n;
    }

    /** Suppression de l'historique plus ancien que la durée de conservation (réglage, 90 jours par défaut). */
    public static function purge(): int
    {
        $days = max(7, (int) setting('voix_journal_jours', '90'));
        return DB::run('DELETE FROM marwa_voice_log WHERE created_at < NOW() - INTERVAL ' . $days . ' DAY')->rowCount();
    }

    public static function history(array $f): array
    {
        $where = ['1=1'];
        $p = [];
        if (($f['level'] ?? '') !== '' && is_numeric($f['level'])) {
            $where[] = 'l.level >= ?';
            $p[] = (int) $f['level'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'l.status = ?';
            $p[] = $f['status'];
        }
        return DB::all('SELECT l.*, u.full_name FROM marwa_voice_log l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY l.id DESC LIMIT 200', $p);
    }

    public static function stats(): array
    {
        return array_column(DB::all('SELECT status, COUNT(*) AS n FROM marwa_voice_log WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY status'), 'n', 'status');
    }
}
