<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Banking;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * SQUELETTE — NON COMPATIBLE en l'état. Aucune connexion n'est simulée : toutes les fonctions répondent
 * « non prise en charge » et rien n'est débité ni enregistré comme payé.
 * API propre à chaque banque : documentation, contrat et environnement de test requis.
 * En attendant : si le prestataire publie une API documentée, configurer le connecteur générique REST ou SOAP.
 * Pour le développer : addons/pay/docs/AJOUTER_UN_PRESTATAIRE.md
 */
final class BankApiGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'bank_api', 'label' => 'Banque (API) — à préciser', 'kind' => 'gateway', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette non développé : API propre à chaque banque : documentation, contrat et environnement de test requis.'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::UNSUPPORTED, 'Connecteur « Banque (API) — à préciser » non développé : documentation officielle et accès de test requis.');
    }
}
