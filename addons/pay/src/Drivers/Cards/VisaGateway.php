<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Cards;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * SQUELETTE — NON COMPATIBLE en l'état. Aucune connexion n'est simulée : toutes les fonctions répondent
 * « non prise en charge » et rien n'est débité ni enregistré comme payé.
 * Visa est un réseau de cartes : POS Marwa ne s'y connecte pas directement. Il faut un acquéreur (banque) ou un prestataire certifié PCI DSS, avec page de paiement hébergée, tokenisation ou TPE certifié.
 * En attendant : si le prestataire publie une API documentée, configurer le connecteur générique REST ou SOAP.
 * Pour le développer : addons/pay/docs/AJOUTER_UN_PRESTATAIRE.md
 */
final class VisaGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'card_visa', 'label' => 'Visa (via acquéreur ou prestataire certifié)', 'kind' => 'gateway', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette non développé : Visa est un réseau de cartes : POS Marwa ne s\'y connecte pas directement. Il faut un acquéreur (banque) ou un prestataire certifié PCI DSS, avec page de paiement hébergée, tokenisation ou TPE certifié.'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::UNSUPPORTED, 'Connecteur « Visa (via acquéreur ou prestataire certifié) » non développé : documentation officielle et accès de test requis.');
    }
}
