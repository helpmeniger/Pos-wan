<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Generic;

use Addons\Pay\GatewayResult;

/**
 * Connecteur générique SOAP / XML : enveloppes en gabarits ({{chemin}} échappé XML), réponse lue par XPath
 * (les chemins de « response » sont des expressions XPath). Compatible seulement après écriture des gabarits
 * d'après le WSDL officiel du prestataire. Les entités externes XML sont refusées.
 */
final class SoapGateway extends RestApiGateway
{
    public static function info(): array
    {
        return ['code' => 'generic_soap', 'label' => 'Service SOAP / XML générique', 'kind' => 'gateway', 'transports' => ['https'], 'verified' => false,
            'notes' => 'Enveloppes XML et XPath configurables, même authentification que le connecteur REST. À établir d\'après le WSDL officiel.'];
    }

    public static function capabilities(): array
    {
        return ['testConnection', 'initiate', 'getStatus', 'cancel', 'refund', 'getRefundStatus'];
    }

    protected function request(string $name, array $vars, bool $withBody): array
    {
        $ep = $this->config['endpoints'][$name] ?? null;
        if (!is_array($ep) || empty($ep['template'])) {
            return [null, GatewayResult::unsupported($name, self::info()['label'] . ' (opération non configurée)'), []];
        }
        $url = $this->url((string) ($ep['path'] ?? ''), $vars);
        if (!$url) {
            return [null, GatewayResult::make(GatewayResult::CONFIG_ERROR, 'URL invalide (HTTPS obligatoire en production).'), []];
        }
        $auth = $this->authHeaders();
        if ($auth instanceof GatewayResult) {
            return [null, $auth, []];
        }
        $action = preg_replace('/[\r\n"]/', '', (string) ($ep['action'] ?? ''));
        $v12 = (string) ($this->config['soap_version'] ?? '1.1') === '1.2';
        $headers = $v12 ? ['Content-Type: application/soap+xml; charset=utf-8' . ($action ? '; action="' . $action . '"' : '')] : ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . $action . '"'];
        $r = $this->http('POST', $url, array_merge($headers, $auth), self::render((string) $ep['template'], $vars, 'xml'));
        $meta = ['sent' => $r['sent'], 'body' => $r['body'], 'http' => $r['status']];
        if ($r['error'] !== null) {
            return [null, GatewayResult::make($r['timeout'] ? GatewayResult::TIMEOUT : GatewayResult::UNREACHABLE, 'Connexion impossible : ' . $r['error'], [], $r['sent']), $meta];
        }
        if (in_array($r['status'], [401, 403], true)) {
            return [null, GatewayResult::make(GatewayResult::AUTH_FAILED, 'Authentification refusée.', [], $r['sent'], $r['body'], $r['status']), $meta];
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = trim($r['body']) !== '' && $doc->loadXML($r['body'], LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->doctype !== null) {
            return [null, GatewayResult::make($r['status'] >= 500 ? GatewayResult::TIMEOUT : GatewayResult::INVALID_RESPONSE, 'Réponse XML illisible.', [], $r['sent'], $r['body'], $r['status']), $meta];
        }
        $x = new \DOMXPath($doc);
        if ($x->query("//*[local-name()='Fault']")->length) {
            $f = $x->query("//*[local-name()='faultstring' or local-name()='Text']");
            return [null, GatewayResult::make(in_array($name, ['initiate', 'refund'], true) ? GatewayResult::DECLINED : GatewayResult::INVALID_RESPONSE,
                'Erreur SOAP : ' . mb_substr($f->length ? $f->item(0)->textContent : '', 0, 200), [], $r['sent'], $r['body'], $r['status']), $meta];
        }
        // Les chemins de « response » sont des XPath : on construit une structure à plat pour interpret()
        $flat = [];
        foreach ((array) ($this->config['response'] ?? []) as $k => $expr) {
            $n = @$x->evaluate((string) $expr);
            $flat[$k] = $n instanceof \DOMNodeList ? ($n->length ? trim($n->item(0)->textContent) : null) : (is_scalar($n) ? trim((string) $n) : null);
        }
        return [$flat, null, $meta];
    }

    protected function interpret(array $j, array $meta, string $mapKey = 'status_map', string $statusPath = 'status'): GatewayResult
    {
        // $j est déjà indexé par nom de champ
        $saved = $this->config['response'] ?? [];
        $this->config['response'] = array_combine(array_keys($j), array_keys($j));
        $r = parent::interpret($j, $meta, $mapKey, $statusPath);
        $this->config['response'] = $saved;
        return $r;
    }
}
