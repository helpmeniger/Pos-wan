<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Terminals;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * TPE bancaire AUTONOME (non relié à POS Marwa) : le montant est saisi sur le terminal, le client y présente
 * sa carte et son code. Le caissier recopie le n° d'autorisation imprimé sur le ticket du TPE.
 * POS Marwa ne reçoit aucune confirmation électronique : la transaction reste « À vérifier » jusqu'au
 * rapprochement avec le relevé de la banque (menu Rapprochement).
 */
final class PosTerminalDriver extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'terminal_standalone', 'label' => 'TPE autonome (saisie du n° d\'autorisation)', 'kind' => 'terminal', 'transports' => ['standalone'], 'verified' => false,
            'notes' => 'Aucune liaison avec le terminal : confirmation par rapprochement bancaire uniquement.'];
    }

    public static function capabilities(): array
    {
        return ['testConnection', 'initiate'];
    }

    public function testConnection(): GatewayResult
    {
        return GatewayResult::make(GatewayResult::OK, 'TPE autonome : aucune liaison à tester. Les transactions seront « À vérifier » jusqu\'au rapprochement.');
    }

    public function initiate(array $p): GatewayResult
    {
        $code = strtoupper(trim((string) ($p['authorization_code'] ?? '')));
        if (!preg_match('/^[A-Z0-9-]{4,20}$/', $code)) {
            return GatewayResult::make(GatewayResult::CONFIG_ERROR, 'Saisissez le n° d\'autorisation imprimé par le TPE (4 à 20 lettres ou chiffres).');
        }
        return GatewayResult::make(GatewayResult::TO_VERIFY, 'Paiement déclaré sur TPE autonome : à confirmer par le relevé bancaire.', ['authorization_code' => $code]);
    }
}
