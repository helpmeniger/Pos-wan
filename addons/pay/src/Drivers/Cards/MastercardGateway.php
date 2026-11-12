<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Cards;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * SQUELETTE — NON COMPATIBLE en l'état. Aucune connexion n'est simulée : toutes les fonctions répondent
 * « non prise en charge » et rien n'est débité ni enregistré comme payé.
 * Mastercard est un réseau de cartes : POS Marwa ne s'y connecte pas directement. Il faut un acquéreur (banque) ou un prestataire certifié PCI DSS.
 * En attendant : si le prestataire publie une API documentée, configurer le connecteur générique REST ou SOAP.
 * Pour le développer : addons/pay/docs/AJOUTER_UN_PRESTATAIRE.md
 */
final class MastercardGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'card_mastercard', 'label' => 'Mastercard (via acquéreur ou prestataire certifié)', 'kind' => 'gateway', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette non développé : Mastercard est un réseau de cartes : POS Marwa ne s\'y connecte pas directement. Il faut un acquéreur (banque) ou un prestataire certifié PCI DSS.'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::UNSUPPORTED, 'Connecteur « Mastercard (via acquéreur ou prestataire certifié) » non développé : documentation officielle et accès de test requis.');
    }
}
