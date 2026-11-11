<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Generic;

use Addons\Fiscal\DriverResult;

/**
 * Dispositif branché en USB (port série virtuel) ou RS232 **sur la machine qui exécute POS Marwa**.
 * PHP ne peut pas ouvrir le port du poste d'un caissier distant, ni depuis un hébergement en ligne.
 * config_json : port (COM3 | /dev/ttyUSB0 | /dev/ttyACM0 | /dev/ttyS0), baud (9600), data_bits (8), parity (none|even|odd), stop_bits (1|2).
 */
final class SerialDriver extends StreamDriver
{
    public static function info(): array
    {
        return ['code' => 'generic_serial', 'label' => 'Dispositif USB / série générique (JSON par ligne)', 'kind' => 'mcf', 'transports' => ['usb', 'serial'], 'verified' => false,
            'notes' => 'Le dispositif doit être branché sur le serveur POS Marwa. Non testé sur matériel. Trames propriétaires : pilote dédié nécessaire.'];
    }

    protected function open(): mixed
    {
        $port = (string) ($this->config['port'] ?? '');
        $win = PHP_OS_FAMILY === 'Windows';
        if (!($win ? preg_match('/^COM[1-9][0-9]?$/i', $port) : preg_match('#^/dev/tty(USB|ACM|S)[0-9]{1,2}$#', $port))) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, $win ? 'Port invalide (ex. COM3).' : 'Port invalide (ex. /dev/ttyUSB0).');
        }
        $baud = (int) ($this->config['baud'] ?? 9600);
        $bits = (int) ($this->config['data_bits'] ?? 8);
        $parity = (string) ($this->config['parity'] ?? 'none');
        $stop = (int) ($this->config['stop_bits'] ?? 1);
        if (!in_array($baud, [1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200], true) || !in_array($bits, [7, 8], true) || !in_array($parity, ['none', 'even', 'odd'], true) || !in_array($stop, [1, 2], true)) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Paramètres du port série invalides.');
        }
        if (!function_exists('exec')) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'La fonction exec est désactivée : impossible de régler le port série.');
        }
        // Valeurs strictement validées ci-dessus : aucune donnée libre dans la commande
        $cmd = $win ? sprintf('mode %s BAUD=%d PARITY=%s DATA=%d STOP=%d', $port, $baud, $parity[0], $bits, $stop)
            : sprintf('stty -F %s %d cs%d %s %s raw -echo 2>&1', escapeshellarg($port), $baud, $bits, $parity === 'none' ? '-parenb' : ($parity === 'odd' ? 'parenb parodd' : 'parenb -parodd'), $stop === 2 ? 'cstopb' : '-cstopb');
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            return DriverResult::fail(DriverResult::UNREACHABLE, 'Port série inaccessible : ' . mb_substr(implode(' ', $out), 0, 150));
        }
        $s = @fopen($win ? '\\\\.\\' . strtoupper($port) : $port, 'r+b');
        if (!$s) {
            return DriverResult::fail(DriverResult::UNREACHABLE, "Ouverture du port $port impossible (droits, câble, port occupé).");
        }
        return $s;
    }
}
