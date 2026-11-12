<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Core\BusinessException;

/** Connecteurs et pilotes disponibles. « verified » reste false tant qu'un essai officiel n'a pas été mené. */
final class DriverRegistry
{
    public const GATEWAYS = [
        'simulation' => Drivers\SimulationDriver::class,
        'generic_rest' => Drivers\Generic\RestApiGateway::class,
        'generic_soap' => Drivers\Generic\SoapGateway::class,
        'generic_webhook' => Drivers\Generic\WebhookGateway::class,
        'card_visa' => Drivers\Cards\VisaGateway::class,
        'card_mastercard' => Drivers\Cards\MastercardGateway::class,
        'card_generic' => Drivers\Cards\GenericCardGateway::class,
        'wallet_paypal' => Drivers\Wallets\PaypalGateway::class,
        'wallet_mobile_money' => Drivers\Wallets\MobileMoneyGateway::class,
        'ne_mynita' => Drivers\Niger\MyNitaGateway::class,
        'ne_amana' => Drivers\Niger\AmanaGateway::class,
        'bank_api' => Drivers\Banking\BankApiGateway::class,
    ];

    public const TERMINALS = [
        'terminal_simulation' => Drivers\Terminals\SimulatedTerminalDriver::class,
        'terminal_standalone' => Drivers\Terminals\PosTerminalDriver::class,
        'terminal_tcp' => Drivers\Terminals\TcpTerminalDriver::class,
        'terminal_usb' => Drivers\Terminals\UsbTerminalDriver::class,
        'terminal_android' => Drivers\Terminals\AndroidTerminalDriver::class,
    ];

    private static function filter(array $list): array
    {
        $out = [];
        foreach ($list as $code => $class) {
            if (class_exists($class)) {
                $out[$code] = $class::info() + ['capabilities' => $class::capabilities()];
            }
        }
        return $out;
    }

    public static function gateways(): array
    {
        return self::filter(self::GATEWAYS);
    }

    public static function terminals(): array
    {
        return self::filter(self::TERMINALS);
    }

    public static function info(string $code): ?array
    {
        $class = self::GATEWAYS[$code] ?? self::TERMINALS[$code] ?? null;
        return $class && class_exists($class) ? $class::info() + ['capabilities' => $class::capabilities()] : null;
    }

    public static function make(array $target): PaymentDriver
    {
        $code = (string) ($target['driver'] ?? '');
        $class = self::GATEWAYS[$code] ?? self::TERMINALS[$code] ?? null;
        if (!$class || !class_exists($class)) {
            throw new BusinessException('Pilote de paiement inconnu : ' . $code);
        }
        $target['config'] ??= json_decode((string) ($target['config_json'] ?? ''), true) ?: [];
        return new $class($target);
    }

    public static function supports(array $target, string $fn): bool
    {
        $i = self::info((string) ($target['driver'] ?? ''));
        return $i !== null && in_array($fn, $i['capabilities'], true);
    }

    public static function isSimulation(string $code): bool
    {
        return in_array($code, ['simulation', 'terminal_simulation'], true);
    }
}
