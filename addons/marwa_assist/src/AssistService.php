<?php
declare(strict_types=1);

namespace Addons\MarwaAssist;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/**
 * MARWA REMOTE ASSIST — interventions (demande → observation → contrôle limité → fin), postes
 * équipés, kits d'installation, diagnostic et annuaire des boutiques (poste HTN).
 *
 * Le contrôle de Windows est fait par RustDesk (programme séparé) : POS Marwa n'ouvre aucun port,
 * ne bloque aucune requête de caisse et continue de fonctionner si RustDesk s'arrête.
 */
final class AssistService
{
    public const ACTIVE = ['demandee', 'observation', 'controle'];
    public const STATUS = ['demandee' => 'Demandée', 'observation' => 'Observation', 'controle' => 'Contrôle autorisé', 'terminee' => 'Terminée', 'expiree' => 'Expirée'];
    /** Une demande jamais prise en charge expire après ce délai (heures). */
    public const REQUEST_HOURS = 8;

    public static function settings(): array
    {
        $phones = (array) config('licence.author.phones');
        return [
            'role' => setting('assist_role', 'boutique') === 'serveur' ? 'serveur' : 'boutique',
            'host' => (string) setting('assist_serveur_adresse', ''),
            'key' => (string) setting('assist_serveur_cle', ''),
            'minutes' => max(5, min(60, (int) setting('assist_controle_minutes', '15'))),
            'phone' => (string) (setting('assist_tel_htn', '') ?: ($phones[0] ?? '')),
            'kit_days' => max(1, min(30, (int) setting('assist_kit_jours', '7'))),
            'unattended' => setting('assist_sans_surveillance', '0') === '1',
        ];
    }

    public static function event(?int $interventionId, string $action, array $details = []): void
    {
        DB::insert('assist_events', ['intervention_id' => $interventionId, 'action' => $action,
            'details' => $details ? mb_substr(json_encode($details, JSON_UNESCAPED_UNICODE), 0, 500) : null, 'user_id' => Auth::id(), 'ip' => Request::ip()]);
        Audit::log('assist.' . $action, 'assist_intervention', $interventionId, $details ?: null);
    }

    /** Fin automatique : contrôle expiré → retour en observation ; demande trop ancienne → expirée. */
    public static function expire(): void
    {
        foreach (DB::all("SELECT id FROM assist_interventions WHERE status = 'controle' AND control_until < NOW()") as $r) {
            if (DB::update('assist_interventions', ['status' => 'observation', 'control_until' => null], "id = ? AND status = 'controle'", [$r['id']])) {
                self::event((int) $r['id'], 'controle_expire');
                self::localKeyboard(false);
            }
        }
        foreach (DB::all("SELECT id FROM assist_interventions WHERE status IN ('demandee','observation') AND created_at < NOW() - INTERVAL " . self::REQUEST_HOURS . " HOUR") as $r) {
            if (DB::update('assist_interventions', ['status' => 'expiree', 'ended_at' => date('Y-m-d H:i:s')], "id = ? AND status IN ('demandee','observation')", [$r['id']])) {
                self::event((int) $r['id'], 'expiree');
            }
        }
    }

    /** Interventions en cours (toutes, ou celle du poste qui consulte). */
    public static function active(?string $ip = null): array
    {
        self::expire();
        $sql = "SELECT i.*, u.full_name AS requested_name, c.full_name AS control_name FROM assist_interventions i
                LEFT JOIN users u ON u.id = i.requested_by LEFT JOIN users c ON c.id = i.control_by
                WHERE i.status IN ('demandee','observation','controle')";
        return $ip === null ? DB::all($sql . ' ORDER BY i.id DESC') : DB::all($sql . ' AND i.ip = ? ORDER BY i.id DESC', [$ip]);
    }

    public static function find(int $id): array
    {
        $i = DB::one('SELECT * FROM assist_interventions WHERE id = ?', [$id]);
        if (!$i) {
            throw new BusinessException('Intervention introuvable.');
        }
        return $i;
    }

    /** Poste qui consulte la page (reconnu par son adresse sur le réseau de la boutique). */
    public static function currentPoste(): ?array
    {
        return DB::one("SELECT * FROM assist_postes WHERE ip = ? AND kind = 'boutique' AND revoked_at IS NULL AND rustdesk_id IS NOT NULL ORDER BY registered_at DESC LIMIT 1", [Request::ip()]);
    }

    public static function request(string $reason, ?int $posteId): int
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 3) {
            throw new BusinessException('Décrivez le problème en quelques mots.');
        }
        $ip = Request::ip();
        if (self::active($ip)) {
            throw new BusinessException('Une assistance est déjà en cours sur ce poste.');
        }
        $poste = $posteId ? DB::one('SELECT * FROM assist_postes WHERE id = ? AND revoked_at IS NULL', [$posteId]) : self::currentPoste();
        $id = DB::insert('assist_interventions', ['status' => 'demandee', 'reason' => mb_substr($reason, 0, 300), 'poste_id' => $poste['id'] ?? null,
            'poste_label' => $poste ? $poste['label'] : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'poste'), 0, 60), 'ip' => $ip, 'requested_by' => Auth::id()]);
        self::event($id, 'demande', ['motif' => mb_substr($reason, 0, 200), 'poste' => $poste['label'] ?? null]);
        return $id;
    }

    /** « Partager mon écran » : observation seule (le technicien voit, le caissier garde la main). */
    public static function share(int $id): void
    {
        $i = self::find($id);
        if (!in_array($i['status'], ['demandee', 'controle'], true)) {
            throw new BusinessException('Cette intervention n\'est plus en cours.');
        }
        DB::update('assist_interventions', ['status' => 'observation', 'control_until' => null, 'started_at' => $i['started_at'] ?? date('Y-m-d H:i:s')], 'id = ?', [$id]);
        self::event($id, $i['status'] === 'controle' ? 'controle_retire' : 'observation');
        if ($i['status'] === 'controle') {
            self::localKeyboard(false);
        }
    }

    /** Contrôle clavier et souris : identifiants d'un responsable exigés, durée limitée. */
    public static function authorizeControl(int $id, string $username, string $secret, int $minutes): string
    {
        $i = self::find($id);
        if (!in_array($i['status'], ['demandee', 'observation', 'controle'], true)) {
            throw new BusinessException('Cette intervention n\'est plus en cours.');
        }
        $by = Auth::verifySupervisor(trim($username), $secret, 'assist.control');
        $minutes = max(1, min(self::settings()['minutes'], $minutes ?: self::settings()['minutes']));
        $until = date('Y-m-d H:i:s', time() + 60 * $minutes);
        DB::update('assist_interventions', ['status' => 'controle', 'control_by' => $by, 'control_until' => $until, 'started_at' => $i['started_at'] ?? date('Y-m-d H:i:s')], 'id = ?', [$id]);
        self::event($id, 'controle_autorise', ['par' => $by, 'minutes' => $minutes]);
        self::localKeyboard(true);
        return $until;
    }

    public static function end(int $id, string $technician = '', string $summary = ''): void
    {
        $i = self::find($id);
        if (!in_array($i['status'], self::ACTIVE, true)) {
            return; // déjà terminée : rien à faire (double clic, deux onglets)
        }
        DB::update('assist_interventions', ['status' => 'terminee', 'control_until' => null, 'ended_at' => date('Y-m-d H:i:s'), 'ended_by' => Auth::id(),
            'technician' => mb_substr(trim($technician), 0, 60) ?: null, 'summary' => mb_substr(trim($summary), 0, 500) ?: null], 'id = ?', [$id]);
        self::event($id, 'fin', array_filter(['technicien' => $technician, 'resume' => mb_substr($summary, 0, 200)]));
        if ($i['status'] === 'controle') {
            self::localKeyboard(false);
        }
    }

    /**
     * Sur le PC qui héberge POS Marwa (Windows, RustDesk installé) : active ou coupe le clavier et la
     * souris à distance pour les prochaines connexions. Sur les autres postes, le caissier coche ou
     * décoche « Clavier » dans la fenêtre RustDesk. Ne bloque jamais plus de 8 secondes.
     */
    public static function localKeyboard(bool $on): ?bool
    {
        $exe = getenv('ProgramFiles') ? getenv('ProgramFiles') . '\\RustDesk\\rustdesk.exe' : null;
        if (PHP_OS_FAMILY !== 'Windows' || !$exe || !is_file($exe) || !function_exists('proc_open')) {
            return null;
        }
        $p = @proc_open([$exe, '--option', 'enable-keyboard', $on ? 'Y' : 'N'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return false;
        }
        $deadline = microtime(true) + 8;
        do {
            usleep(100000);
            $st = proc_get_status($p);
        } while ($st['running'] && microtime(true) < $deadline);
        if ($st['running']) {
            proc_terminate($p);
            return false;
        }
        proc_close($p);
        return true;
    }

    // ------------------------------------------------------------------ postes et kits

    public static function postes(): array
    {
        return DB::all('SELECT p.*, u.full_name AS created_name FROM assist_postes p LEFT JOIN users u ON u.id = p.created_by ORDER BY p.revoked_at IS NOT NULL, p.label');
    }

    /**
     * Prépare un kit d'installation (ZIP) : RustDesk officiel + script réglé pour le serveur HTN.
     * @return array{path:string, name:string, poste:int}
     */
    public static function createKit(string $kind, string $label, string $baseUrl): array
    {
        $s = self::settings();
        if (!in_array($kind, ['boutique', 'technicien'], true)) {
            throw new BusinessException('Type de kit inconnu.');
        }
        if (!preg_match('/^[A-Za-z0-9.-]{3,100}$/', $s['host'])) {
            throw new BusinessException('Indiquez d\'abord l\'adresse du serveur d\'assistance HTN (Réglages).');
        }
        if (!preg_match('#^[A-Za-z0-9+/]{43}=$#', $s['key'])) {
            throw new BusinessException('Indiquez d\'abord la clé publique du serveur d\'assistance HTN (Réglages).');
        }
        $label = trim(preg_replace('/[^\p{L}\p{N} ._-]/u', '', $label));
        if (mb_strlen($label) < 2) {
            throw new BusinessException('Nommez le poste (ex. Caisse 1).');
        }
        $token = bin2hex(random_bytes(24));
        $posteId = DB::insert('assist_postes', ['label' => mb_substr($label, 0, 60), 'kind' => $kind, 'token_hash' => hash('sha256', $token),
            'token_expires' => date('Y-m-d H:i:s', time() + 86400 * $s['kit_days']), 'created_by' => Auth::id()]);
        $exe = Bundle::file('client/rustdesk.exe');
        $dir = BASE_PATH . '/storage/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $zipPath = $dir . '/kit-assistance-' . $posteId . '-' . bin2hex(random_bytes(4)) . '.zip';
        $z = new \ZipArchive();
        if ($z->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new BusinessException('Kit impossible à préparer (ZIP).');
        }
        $z->addFile($exe, 'rustdesk.exe');
        $z->setCompressionName('rustdesk.exe', \ZipArchive::CM_STORE);
        $z->addFromString($kind === 'boutique' ? 'installer-assistance.bat' : 'demarrer-technicien.bat',
            Kit::script($kind, $s['host'], $s['key'], rtrim($baseUrl, '/'), $token, $s['unattended']));
        $z->addFromString('LISEZMOI.txt', Kit::readme($kind, $label, $s['host']));
        $z->addFile(Bundle::dir() . '/licences/rustdesk-AGPL-3.0.txt', 'LICENCE-RustDesk-AGPL-3.0.txt');
        $z->close();
        self::event(null, 'kit', ['type' => $kind, 'poste' => $label, 'poste_id' => $posteId]);
        return ['path' => $zipPath, 'name' => 'assistance-' . ($kind === 'boutique' ? 'poste' : 'technicien') . '-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $label) . '.zip', 'poste' => $posteId];
    }

    /** Appelé par le script du kit après l'installation : identifiant RustDesk du poste. */
    public static function register(string $token, string $rustdeskId, string $hostname): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new BusinessException('Jeton invalide.');
        }
        $p = DB::one('SELECT * FROM assist_postes WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $token)]);
        if (!$p || strtotime((string) $p['token_expires']) < time()) {
            throw new BusinessException('Kit expiré ou révoqué : préparez un nouveau kit.');
        }
        $rustdeskId = preg_replace('/\D/', '', $rustdeskId);
        if (!preg_match('/^\d{6,12}$/', $rustdeskId)) {
            throw new BusinessException('Identifiant RustDesk invalide.');
        }
        DB::update('assist_postes', ['rustdesk_id' => $rustdeskId, 'hostname' => mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', $hostname), 0, 60) ?: null,
            'ip' => Request::ip(), 'registered_at' => date('Y-m-d H:i:s')], 'id = ?', [$p['id']]);
        DB::insert('assist_events', ['action' => 'poste_enregistre', 'details' => json_encode(['poste' => $p['label'], 'id' => $rustdeskId]), 'ip' => Request::ip()]);
        return ['label' => $p['label'], 'id' => $rustdeskId];
    }

    /** Saisie manuelle de l'identifiant (si le script n'a pas pu le transmettre). */
    public static function setPosteId(int $id, string $rustdeskId, ?string $ip): void
    {
        $rustdeskId = preg_replace('/\D/', '', $rustdeskId);
        if (!preg_match('/^\d{6,12}$/', $rustdeskId)) {
            throw new BusinessException('Identifiant RustDesk invalide (6 à 12 chiffres).');
        }
        $data = ['rustdesk_id' => $rustdeskId, 'registered_at' => date('Y-m-d H:i:s')];
        if ($ip !== null) {
            $data['ip'] = $ip;
        }
        DB::update('assist_postes', $data, 'id = ? AND revoked_at IS NULL', [$id]);
        self::event(null, 'poste_id', ['poste_id' => $id, 'id' => $rustdeskId]);
    }

    public static function revokePoste(int $id): void
    {
        DB::update('assist_postes', ['revoked_at' => date('Y-m-d H:i:s'), 'token_hash' => null], 'id = ? AND revoked_at IS NULL', [$id]);
        self::event(null, 'poste_revoque', ['poste_id' => $id]);
    }

    // ------------------------------------------------------------------ réglages

    public static function saveSettings(array $in): void
    {
        $role = ($in['assist_role'] ?? '') === 'serveur' ? 'serveur' : 'boutique';
        $host = trim((string) ($in['assist_serveur_adresse'] ?? ''));
        if ($host !== '' && !preg_match('/^[A-Za-z0-9.-]{3,100}$/', $host)) {
            throw new BusinessException('Adresse du serveur invalide : adresse IP (ex. 41.203.10.20) ou nom, sans « http:// » ni port.');
        }
        $key = trim((string) ($in['assist_serveur_cle'] ?? ''));
        if ($key !== '' && !preg_match('#^[A-Za-z0-9+/]{43}=$#', $key)) {
            throw new BusinessException('Clé publique invalide : copiez exactement la clé affichée par le serveur HTN (44 caractères finissant par « = »).');
        }
        $unattended = !empty($in['assist_sans_surveillance']);
        if ($unattended && setting('assist_sans_surveillance', '0') !== '1' && trim((string) ($in['confirmation'] ?? '')) !== 'SANS SURVEILLANCE') {
            throw new BusinessException('Pour activer l\'accès sans surveillance, tapez exactement « SANS SURVEILLANCE » dans la case de confirmation.');
        }
        setting_set('assist_role', $role);
        setting_set('assist_serveur_adresse', $host);
        setting_set('assist_serveur_cle', $key);
        setting_set('assist_controle_minutes', (string) max(5, min(60, (int) ($in['assist_controle_minutes'] ?? 15))));
        setting_set('assist_tel_htn', mb_substr(preg_replace('/[^0-9+ ]/', '', (string) ($in['assist_tel_htn'] ?? '')), 0, 30));
        setting_set('assist_kit_jours', (string) max(1, min(30, (int) ($in['assist_kit_jours'] ?? 7))));
        setting_set('assist_sans_surveillance', $unattended ? '1' : '0');
        self::event(null, 'reglages', ['role' => $role, 'serveur' => $host, 'sans_surveillance' => $unattended]);
    }

    // ------------------------------------------------------------------ historique

    public static function history(int $limit = 100): array
    {
        return DB::all('SELECT i.*, u.full_name AS requested_name, c.full_name AS control_name, e.full_name AS ended_name,
                TIMESTAMPDIFF(MINUTE, COALESCE(i.started_at, i.created_at), COALESCE(i.ended_at, NOW())) AS minutes
                FROM assist_interventions i LEFT JOIN users u ON u.id = i.requested_by LEFT JOIN users c ON c.id = i.control_by
                LEFT JOIN users e ON e.id = i.ended_by ORDER BY i.id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    public static function events(int $interventionId): array
    {
        return DB::all('SELECT e.*, u.full_name FROM assist_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.intervention_id = ? ORDER BY e.id', [$interventionId]);
    }

    // ------------------------------------------------------------------ annuaire HTN

    public static function boutiques(): array
    {
        return DB::all('SELECT * FROM assist_boutiques WHERE active = 1 ORDER BY name');
    }

    public static function saveBoutique(array $in, ?int $id = null): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2) {
            throw new BusinessException('Nom de la boutique obligatoire.');
        }
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($code !== '' && !preg_match('/^PW(-[A-F0-9]{4}){4}$/', $code)) {
            throw new BusinessException('Code boutique invalide (format PW-XXXX-XXXX-XXXX-XXXX, affiché dans l\'assistance de la boutique).');
        }
        $data = ['name' => mb_substr($name, 0, 100), 'code' => $code ?: null, 'phone' => mb_substr(preg_replace('/[^0-9+ ]/', '', (string) ($in['phone'] ?? '')), 0, 40) ?: null,
            'postes' => mb_substr(trim((string) ($in['postes'] ?? '')), 0, 500) ?: null, 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null];
        if ($id) {
            DB::update('assist_boutiques', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('assist_boutiques', $data);
        }
        self::event(null, 'boutique', ['id' => $id, 'nom' => $data['name']]);
        return $id;
    }

    public static function archiveBoutique(int $id): void
    {
        DB::update('assist_boutiques', ['active' => 0], 'id = ?', [$id]);
        self::event(null, 'boutique_archivee', ['id' => $id]);
    }
}
