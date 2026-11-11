<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\BusinessException;

/** Liste des pilotes disponibles. « verified » reste false tant que le protocole officiel n'a pas été testé. */
final class DriverRegistry
{
    public const DRIVERS = [
        'simulation' => Drivers\SimulationDriver::class,
        'generic_rest' => Drivers\Generic\RestApiDriver::class,
        'generic_soap' => Drivers\Generic\SoapDriver::class,
        'generic_tcp' => Drivers\Generic\TcpDriver::class,
        'generic_serial' => Drivers\Generic\SerialDriver::class,
        'ne_secef' => Drivers\Niger\SecefDriver::class,
        'bj_skeleton' => Drivers\Benin\BeninDriver::class,
        'tg_skeleton' => Drivers\Togo\TogoDriver::class,
    ];

    /** @return array<string, array> code => info() + capabilities */
    public static function all(): array
    {
        $out = [];
        foreach (self::DRIVERS as $code => $class) {
            if (class_exists($class)) {
                $out[$code] = $class::info() + ['capabilities' => $class::capabilities()];
            }
        }
        return $out;
    }

    public static function exists(string $code): bool
    {
        return isset(self::DRIVERS[$code]) && class_exists(self::DRIVERS[$code]);
    }

    public static function make(array $connector): FiscalDriver
    {
        $code = (string) ($connector['driver'] ?? '');
        if (!self::exists($code)) {
            throw new BusinessException('Pilote fiscal inconnu : ' . $code);
        }
        if (!isset($connector['config'])) {
            $connector['config'] = json_decode((string) ($connector['config_json'] ?? ''), true) ?: [];
        }
        $class = self::DRIVERS[$code];
        return new $class($connector);
    }

    public static function supports(array $connector, string $fn): bool
    {
        $class = self::DRIVERS[(string) ($connector['driver'] ?? '')] ?? null;
        return $class !== null && in_array($fn, $class::capabilities(), true);
    }
}
