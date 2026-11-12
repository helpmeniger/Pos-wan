<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Wallets;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * SQUELETTE — NON COMPATIBLE en l'état. Aucune connexion n'est simulée : toutes les fonctions répondent
 * « non prise en charge » et rien n'est débité ni enregistré comme payé.
 * Chaque opérateur a sa propre API et son contrat marchand : documentation officielle requise.
 * En attendant : si le prestataire publie une API documentée, configurer le connecteur générique REST ou SOAP.
 * Pour le développer : addons/pay/docs/AJOUTER_UN_PRESTATAIRE.md
 */
final class MobileMoneyGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'wallet_mobile_money', 'label' => 'Mobile Money (opérateur à préciser)', 'kind' => 'gateway', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette non développé : Chaque opérateur a sa propre API et son contrat marchand : documentation officielle requise.'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::UNSUPPORTED, 'Connecteur « Mobile Money (opérateur à préciser) » non développé : documentation officielle et accès de test requis.');
    }
}
