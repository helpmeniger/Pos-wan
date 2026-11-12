<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Wallets;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * SQUELETTE — NON COMPATIBLE en l'état. Aucune connexion n'est simulée : toutes les fonctions répondent
 * « non prise en charge » et rien n'est débité ni enregistré comme payé.
 * Identifiants marchands et documentation de l'API PayPal à obtenir ; à développer et tester en sandbox avant toute utilisation.
 * En attendant : si le prestataire publie une API documentée, configurer le connecteur générique REST ou SOAP.
 * Pour le développer : addons/pay/docs/AJOUTER_UN_PRESTATAIRE.md
 */
final class PaypalGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'wallet_paypal', 'label' => 'PayPal', 'kind' => 'gateway', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette non développé : Identifiants marchands et documentation de l\'API PayPal à obtenir ; à développer et tester en sandbox avant toute utilisation.'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::UNSUPPORTED, 'Connecteur « PayPal » non développé : documentation officielle et accès de test requis.');
    }
}
