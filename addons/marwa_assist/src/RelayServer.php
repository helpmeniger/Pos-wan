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
        $log('Clé publique à donner aux boutiques : ' . (self::publicKey() ?? '?'));
        $log('Ports à ouvrir vers ce poste : TCP 21115, 21116, 21117 et UDP 21116.');
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
}
