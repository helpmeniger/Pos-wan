<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Generic;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * Prestataire sans API de création ni de consultation, qui confirme les paiements uniquement par notification signée.
 * initiate() prépare un lien de paiement à partir de config.payment_url_template (page hébergée par le prestataire) ;
 * la transaction reste « en attente » jusqu'à la notification signée (montant et devise vérifiés par le moteur).
 * config_json : payment_url_template, webhook{signature_header, prefix, encoding, reference, status, amount, currency, event_id}, status_map, amount_unit.
 */
final class WebhookGateway extends RestApiGateway
{
    public static function info(): array
    {
        return ['code' => 'generic_webhook', 'label' => 'Page de paiement + notification signée', 'kind' => 'gateway', 'transports' => ['https'], 'verified' => false,
            'notes' => 'Lien vers la page hébergée du prestataire ; confirmation uniquement par webhook signé. La redirection du client ne vaut jamais paiement.'];
    }

    public static function capabilities(): array
    {
        return ['initiate', 'webhook'];
    }

    public function initiate(array $p): GatewayResult
    {
        $tpl = (string) ($this->config['payment_url_template'] ?? '');
        $vars = $this->vars($p);
        $url = self::render($tpl, $vars, 'url');
        if (!preg_match($this->prod() ? '#^https://#i' : '#^https?://#i', $url)) {
            return GatewayResult::make(GatewayResult::CONFIG_ERROR, 'Modèle de lien de paiement absent ou non HTTPS.');
        }
        return GatewayResult::make(GatewayResult::PENDING, 'Lien de paiement prêt : en attente de la notification du prestataire.', ['redirect_url' => $url, 'provider_reference' => $p['number']]);
    }

    public function getStatus(array $tx): GatewayResult
    {
        return GatewayResult::unsupported('getStatus', self::info()['label']);
    }
}
