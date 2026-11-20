<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/**
 * Licence multiposte : ordinateurs du réseau local qui utilisent POS Marwa.
 *  - Poste principal (l'ordinateur où POS Marwa est installé) : toujours autorisé, compté dans la licence.
 *  - Téléphones, tablettes et téléviseurs (écrans d'affichage) : libres, non comptés.
 *  - Autres ordinateurs : chacun occupe une place de la licence (« p » dans la clé : nombre d'ordinateurs,
 *    poste principal compris). Licence monoposte : aucun autre ordinateur. Essai : un autre ordinateur.
 *    Places toutes prises : l'ordinateur reçoit une page « licence multiposte requise ».
 *  - L'administrateur peut libérer la place d'un ordinateur qui n'est plus utilisé (Licence et activation).
 * Chaque navigateur est reconnu par son cookie « pm_device » (DeviceService::key).
 *
 * Limite honnête : le type d'appareil est déduit de ce que le navigateur annonce (User-Agent). Un ordinateur
 * réglé pour se faire passer pour un téléphone (outils de développement) n'est pas compté.
 */
final class LicencePostes
{
    /** Tests : contrôle appliqué aussi en ligne de commande. */
    public static bool $test = false;

    public static function isMobile(string $ua): bool
    {
        return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile|Tablet|Silk|Kindle|PlayBook|Windows Phone|Opera Mini|IEMobile|SMART-TV|SmartTV|Tizen|Web0S|webOS|HbbTV|CrKey|BRAVIA/i', $ua);
    }

    /** Places pour les AUTRES ordinateurs du réseau (le poste principal occupe la première). */
    public static function remoteSeats(): int
    {
        return max(0, (int) (LicenceService::status()['postes'] ?? 1) - 1);
    }

    /**
     * Contrôle d'une requête : renvoie true si l'appareil peut utiliser POS Marwa.
     * Appelé par le contrôleur frontal, avant toute page (connexion comprise).
     */
    public static function allowed(): bool
    {
        if ((PHP_SAPI === 'cli' && !self::$test) || \App\Saas\Saas::isTenant() || !InstallService::isInstalled() || DeviceService::isLocal()) {
            return true;
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (self::isMobile($ua)) {
            return true;
        }
        try {
            $hash = hash('sha256', DeviceService::key());
            $row = DB::one('SELECT id, status, last_seen_at FROM licence_postes WHERE device_hash = ?', [$hash]);
        } catch (\Throwable) {
            return true; // table pas encore créée (migration en attente) : pas de blocage
        }
        $now = date('Y-m-d H:i:s');
        if ($row && $row['status'] === 'actif') {
            if (strtotime((string) $row['last_seen_at']) < time() - 300) {
                DB::update('licence_postes', ['last_seen_at' => $now, 'ip' => Request::ip()], 'id = ?', [$row['id']]);
            }
            return true;
        }
        // Nouvel ordinateur (ou place libérée) : une place est-elle disponible ? Verrou : deux ordinateurs qui
        // arrivent en même temps ne peuvent pas prendre la même dernière place.
        if ((int) DB::value("SELECT GET_LOCK(CONCAT('poswan_postes:', DATABASE()), 10)") !== 1) {
            return false;
        }
        try {
            $used = (int) DB::value("SELECT COUNT(*) FROM licence_postes WHERE status = 'actif'");
            if ($used >= self::remoteSeats()) {
                return false;
            }
            $label = mb_substr(DeviceService::label($ua), 0, 150);
            if ($row) {
                DB::update('licence_postes', ['status' => 'actif', 'label' => $label, 'ip' => Request::ip(), 'last_seen_at' => $now, 'freed_at' => null], 'id = ?', [$row['id']]);
                $id = (int) $row['id'];
            } else {
                $id = DB::insert('licence_postes', ['device_hash' => $hash, 'label' => $label, 'ip' => Request::ip(), 'status' => 'actif', 'first_seen_at' => $now, 'last_seen_at' => $now]);
            }
            Audit::log('licence.poste_ajoute', 'licence_poste', $id, ['ip' => Request::ip(), 'label' => $label]);
            return true;
        } finally {
            DB::value("SELECT RELEASE_LOCK(CONCAT('poswan_postes:', DATABASE()))");
        }
    }

    public static function list(): array
    {
        try {
            return DB::all("SELECT * FROM licence_postes ORDER BY status = 'actif' DESC, last_seen_at DESC LIMIT 200");
        } catch (\Throwable) {
            return [];
        }
    }

    public static function free(int $id): void
    {
        $r = DB::one('SELECT * FROM licence_postes WHERE id = ?', [$id]) ?? throw new BusinessException('Ordinateur introuvable.');
        DB::update('licence_postes', ['status' => 'libere', 'freed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        Audit::log('licence.poste_libere', 'licence_poste', $id, ['label' => $r['label'], 'ip' => $r['ip']]);
    }

    /** Page affichée à un ordinateur sans place de licence. */
    public static function refuse(): never
    {
        http_response_code(403);
        header('Cache-Control: no-store');
        $s = LicenceService::status();
        $total = (int) ($s['postes'] ?? 1);
        $phones = e(implode(' / ', (array) config('licence.author.phones')));
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Licence multiposte requise — POS Marwa</title>'
            . '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f4f6fb;margin:0;padding:40px 16px;color:#1d2433}.c{max-width:620px;margin:auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.08)}h1{font-size:1.35rem;margin-top:0}b{color:#14213d}</style></head>'
            . '<body><div class="c"><h1>Licence multiposte requise</h1>'
            . '<p>Cet ordinateur n\'a pas de place dans la licence de POS Marwa. La licence actuelle autorise <b>' . $total . ' ordinateur' . ($total > 1 ? 's' : '') . '</b> (poste principal compris) ; toutes les places sont occupées.</p>'
            . '<p>Les <b>téléphones et tablettes</b> peuvent utiliser POS Marwa librement. Pour un ordinateur supplémentaire :</p>'
            . '<ul><li>demandez une <b>licence multiposte</b> à l\'auteur : ' . $phones . ' ;</li>'
            . '<li>ou, sur le poste principal, l\'administrateur peut libérer la place d\'un ordinateur qui n\'est plus utilisé (Configuration → Licence et activation).</li></ul></div></body></html>';
        exit;
    }
}
