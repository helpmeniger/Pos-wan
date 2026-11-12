<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Cards;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * SQUELETTE — NON COMPATIBLE en l'état. Aucune connexion n'est simulée : toutes les fonctions répondent
 * « non prise en charge » et rien n'est débité ni enregistré comme payé.
 * Base pour un prestataire de cartes : page hébergée ou tokenisation uniquement ; jamais de numéro de carte, CVV ni PIN dans POS Marwa.
 * En attendant : si le prestataire publie une API documentée, configurer le connecteur générique REST ou SOAP.
 * Pour le développer : addons/pay/docs/AJOUTER_UN_PRESTATAIRE.md
 */
final class GenericCardGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'card_generic', 'label' => 'Cartes EMV (prestataire à préciser)', 'kind' => 'gateway', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette non développé : Base pour un prestataire de cartes : page hébergée ou tokenisation uniquement ; jamais de numéro de carte, CVV ni PIN dans POS Marwa.'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::UNSUPPORTED, 'Connecteur « Cartes EMV (prestataire à préciser) » non développé : documentation officielle et accès de test requis.');
    }
}
