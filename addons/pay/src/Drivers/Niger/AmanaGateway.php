<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Niger;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * SQUELETTE — NON COMPATIBLE en l'état. Aucune connexion n'est simulée : toutes les fonctions répondent
 * « non prise en charge » et rien n'est débité ni enregistré comme payé.
 * Disponibilité d'une API marchande, documentation et accès de test à obtenir auprès du fournisseur.
 * En attendant : si le prestataire publie une API documentée, configurer le connecteur générique REST ou SOAP.
 * Pour le développer : addons/pay/docs/AJOUTER_UN_PRESTATAIRE.md
 */
final class AmanaGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'ne_amana', 'label' => 'AMANA', 'kind' => 'gateway', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette non développé : Disponibilité d\'une API marchande, documentation et accès de test à obtenir auprès du fournisseur.'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::UNSUPPORTED, 'Connecteur « AMANA » non développé : documentation officielle et accès de test requis.');
    }
}
