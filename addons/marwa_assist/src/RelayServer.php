<?php
declare(strict_types=1);

namespace Addons\MarwaAssist;

/**
 * Serveur HTN : RustDesk Server (hbbs = présence et identifiants, hbbr = relais), lancé en
 * processus séparés, jamais par une page web. La clé est imposée (-k _) : un poste qui n'a pas la
 * clé publique de ce serveur est refusé. Données du serveur : storage/assist/serveur-donnees.
 */
final class RelayServer
{
    public const PORTS = ['21115' => 'tcp', '21116' => 'tcp+udp', '21117' => 'tcp'];

    public static function dataDir(): string
    {
        return Bundle::target('serveur-donnees');
    }

    public static function publicKey(): ?string
    {
        $f = self::dataDir() . '/id_ed25519.pub';
        $k = is_file($f) ? trim((string) file_get_contents($f)) : '';
        return preg_match('#^[A-Za-z0-9+/]{43}=$#', $k) ? $k : null;
    }

    /** Ports TCP ouverts sur ce poste (le serveur tourne-t-il ?). */
    public static function status(): array
    {
        $ports = [];
        foreach (array_keys(self::PORTS) as $p) {
            $s = @stream_socket_client('tcp://127.0.0.1:' . $p, $e, $m, 0.3);
            $ports[$p] = (bool) $s;
            if ($s) {
                fclose($s);
            }
        }
        return ['running' => $ports['21116'] && $ports['21117'], 'ports' => $ports, 'key' => self::publicKey()];
    }

    /** Lance hbbs et hbbr, les relance s'ils s'arrêtent ; s'arrête avec Ctrl+C (ou la fermeture de la fenêtre). */
    public static function run(callable $log): void
    {
        $os = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
        $ext = $os === 'windows' ? '.exe' : '';
        $hbbs = Bundle::file("serveur/$os/hbbs$ext");
        $hbbr = Bundle::file("serveur/$os/hbbr$ext");
        $dir = self::dataDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (self::status()['running']) {
            throw new \RuntimeException('Le serveur d\'assistance tourne déjà sur ce poste.');
        }
        $logFile = BASE_PATH . '/storage/logs/assist-serveur.log';
        $procs = [];
        $start = function (string $name, string $bin) use (&$procs, $dir, $logFile, $log): void {
            $p = proc_open([$bin, '-k', '_'], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes, $dir);
            if (!is_resource($p)) {
                throw new \RuntimeException("$name n'a pas pu être lancé.");
            }
            $procs[$name] = $p;
            $log("$name démarré");
        };
        $start('hbbr', $hbbr);
        $start('hbbs', $hbbs);
        for ($i = 0; $i < 50 && !self::publicKey(); $i++) {
            usleep(100000);
        }
        foreach (self::guide() as $line) {
            $log($line);
        }
        while (true) {
            sleep(5);
            foreach (['hbbr' => $hbbr, 'hbbs' => $hbbs] as $name => $bin) {
                if (!proc_get_status($procs[$name])['running']) {
                    proc_close($procs[$name]);
                    $log("$name s'est arrêté : relance");
                    sleep(2);
                    $start($name, $bin);
                }
            }
        }
    }

    /** Marche à suivre affichée au démarrage du serveur (adresses de ce PC, clé, ports, réglages des boutiques). */
    public static function guide(): array
    {
        $key = self::publicKey() ?? '(pas encore créée : attendez quelques secondes puis tapez « php bin/assist.php cle »)';
        $ips = \App\Services\NetworkService::localIps();
        $lan = $ips ? implode(' ou ', $ips) : '(voir « ipconfig », ligne « Adresse IPv4 »)';
        return [
            '==================================================================',
            ' SERVEUR D\'ASSISTANCE HTN DÉMARRÉ — laissez cette fenêtre ouverte',
            '==================================================================',
            ' CLÉ PUBLIQUE (à copier) : ' . $key,
            ' Adresse de ce PC sur le réseau local : ' . $lan,
            '',
            ' ÉTAPE 1 — Box Internet de ce PC : rediriger vers ' . ($ips[0] ?? 'ce PC') . ' les ports',
            '           TCP 21115, 21116, 21117 et UDP 21116 (rien d\'autre).',
            '           (Sur un VPS : ouvrir ces ports dans son pare-feu.)',
            ' ÉTAPE 2 — Noter l\'adresse IP PUBLIQUE de ce PC : ouvrez https://api.ipify.org',
            '           dans un navigateur de ce PC. Elle doit être FIXE (VPS ou abonnement',
            '           avec IP fixe) ; une connexion 4G (CGNAT) ne convient pas.',
            ' ÉTAPE 3 — Dans chaque boutique : POS Marwa → Configuration → Assistance à',
            '           distance → Réglages : « Adresse du serveur » = IP publique de l\'étape 2,',
            '           « Clé publique » = la clé ci-dessus. Enregistrer.',
            '           (Ou une seule fois pour toutes les boutiques : config/licence.php,',
            '           assist.host et assist.key, avant de distribuer POS Marwa.)',
            ' ÉTAPE 4 — Dans la boutique, onglet « Postes équipés » : « Kit du poste » sur',
            '           chaque PC à assister, puis installer-assistance.bat (administrateur).',
            ' ÉTAPE 5 — Sur votre PC de technicien : « Kit technicien », puis',
            '           demarrer-technicien.bat. Saisir l\'identifiant et le code d\'accès',
            '           affichés par la boutique après « Partager mon écran ».',
            ' Vérifier : POS Marwa de la boutique → Assistance à distance → Diagnostic',
            '           doit afficher « Connexion au serveur d\'assistance HTN : joignable ».',
            '==================================================================',
        ];
    }
}
