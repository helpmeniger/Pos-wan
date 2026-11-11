<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Generic;

use Addons\Fiscal\DriverResult;

/**
 * Pilote générique SOAP / XML. Les enveloppes sont des gabarits XML ({{chemin}} échappé XML)
 * et la réponse est lue par expressions XPath. Même authentification, SSL et mTLS que le pilote REST.
 * Compatible avec un service seulement si les gabarits ont été écrits d'après son WSDL officiel.
 *
 * config_json : base_url (adresse du service), soap_version (1.1|1.2),
 *  endpoints {certify|status|…: {path ('' = base_url), action (SOAPAction), template (enveloppe XML)}},
 *  response_map {fiscal_number: XPath, …}, success {xpath, values[]}.
 */
final class SoapDriver extends RestApiDriver
{
    public static function info(): array
    {
        return ['code' => 'generic_soap', 'label' => 'Service SOAP / XML générique', 'kind' => 'api', 'transports' => ['soap', 'http'], 'verified' => false,
            'notes' => 'Enveloppes XML et XPath configurables. Compatibilité à établir d\'après le WSDL officiel du service.'];
    }

    protected function endpoint(string $fn): ?array
    {
        $e = $this->config['endpoints'][self::ENDPOINT_OF[$fn] ?? ''] ?? null;
        return is_array($e) && !empty($e['template']) ? $e + ['path' => ''] : null;
    }

    protected function request(string $fn, array $vars, ?string $body = null, bool $expectCertification = false): DriverResult
    {
        $ep = $this->endpoint($fn);
        if (!$ep) {
            return DriverResult::unsupported($fn, self::info()['label'] . ' (opération non configurée)');
        }
        $url = (string) $ep['path'] === '' ? $this->url('', []) : $this->url((string) $ep['path'], $vars);
        if (!$url) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'URL invalide (HTTPS obligatoire en production).');
        }
        $auth = $this->authHeaders();
        if ($auth instanceof DriverResult) {
            return $auth;
        }
        $envelope = self::render((string) $ep['template'], $vars, 'xml');
        $v12 = (string) ($this->config['soap_version'] ?? '1.1') === '1.2';
        $action = preg_replace('/[\r\n"]/', '', (string) ($ep['action'] ?? ''));
        $headers = $v12 ? ['Content-Type: application/soap+xml; charset=utf-8' . ($action ? '; action="' . $action . '"' : '')]
            : ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . $action . '"'];
        $r = $this->http('POST', $url, array_merge($headers, $auth), $envelope);
        return $this->interpretXml($fn, $r, $expectCertification);
    }

    private function xpath(string $xml): ?\DOMXPath
    {
        if (trim($xml) === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET : aucune ressource externe ; pas de DTD → pas d'entités externes (XXE)
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->doctype !== null) {
            return null;
        }
        return new \DOMXPath($doc);
    }

    private static function text(\DOMXPath $x, string $expr): ?string
    {
        $n = @$x->evaluate($expr);
        if ($n instanceof \DOMNodeList) {
            return $n->length ? trim((string) $n->item(0)->textContent) : null;
        }
        return is_scalar($n) && (string) $n !== '' ? trim((string) $n) : null;
    }

    private function interpretXml(string $fn, array $r, bool $expectCertification): DriverResult
    {
        $sent = $r['sent'];
        if ($r['error'] !== null) {
            return DriverResult::fail($r['timeout'] ? DriverResult::TIMEOUT : DriverResult::UNREACHABLE, 'Connexion impossible : ' . $r['error'], [], $sent);
        }
        $code = $r['status'];
        if ($code === 401 || $code === 403) {
            return DriverResult::fail(DriverResult::AUTH_FAILED, 'Authentification refusée (HTTP ' . $code . ').', [], $sent, $r['body'], $code);
        }
        if ($code >= 500 && !str_contains($r['body'], 'Fault')) {
            return DriverResult::fail(DriverResult::UNREACHABLE, 'Service indisponible (HTTP ' . $code . ').', [], $sent, $r['body'], $code);
        }
        $x = $this->xpath($r['body']);
        if (!$x) {
            return DriverResult::fail(DriverResult::INVALID_RESPONSE, 'Réponse XML illisible.', [], $sent, $r['body'], $code);
        }
        $fault = self::text($x, "//*[local-name()='Fault']//*[local-name()='faultstring' or local-name()='Text']");
        if ($fault !== null || self::text($x, "//*[local-name()='Fault']") !== null) {
            return DriverResult::fail($expectCertification ? DriverResult::REJECTED : DriverResult::INVALID_RESPONSE, 'Erreur SOAP : ' . mb_substr((string) $fault, 0, 200), [], $sent, $r['body'], $code);
        }
        $s = $this->config['success'] ?? null;
        if (is_array($s) && !empty($s['xpath']) && !in_array((string) self::text($x, (string) $s['xpath']), array_map('strval', (array) ($s['values'] ?? [])), true)) {
            return DriverResult::fail($expectCertification ? DriverResult::REJECTED : DriverResult::INVALID_RESPONSE, 'Le service indique un échec.', [], $sent, $r['body'], $code);
        }
        $data = [];
        foreach ((array) ($this->config['response_map'] ?? []) as $field => $expr) {
            $v = self::text($x, (string) $expr);
            if ($v !== null && $v !== '') {
                $data[$field] = $v;
            }
        }
        if ($fn === 'getInvoiceStatus') {
            return empty($data['fiscal_number']) ? DriverResult::fail(DriverResult::NOT_FOUND, 'Aucune certification trouvée.', [], $sent, $r['body'], $code)
                : new DriverResult(DriverResult::CERTIFIED, 'Certification trouvée sur le service.', $data, $sent, $r['body'], $code);
        }
        return $expectCertification ? new DriverResult(DriverResult::CERTIFIED, 'Réponse positive du service.', $data, $sent, $r['body'], $code)
            : DriverResult::ok($data, 'OK', $sent, $r['body'], $code);
    }

    public function getInvoiceStatus(string $idempotencyKey): DriverResult
    {
        return $this->request('getInvoiceStatus', ['idempotency_key' => $idempotencyKey]);
    }

    public function closeDay(string $date): DriverResult
    {
        return $this->request('closeDay', ['date' => $date]);
    }

    public function cancelInvoice(string $fiscalNumber, string $reason): DriverResult
    {
        return $this->request('cancelInvoice', ['fiscal_number' => $fiscalNumber, 'reason' => $reason]);
    }

    public function certifyInvoice(array $invoice): DriverResult
    {
        return $this->request('certifyInvoice', $invoice, '', true);
    }

    public function issueCreditNote(array $creditNote): DriverResult
    {
        return $this->request('issueCreditNote', $creditNote, '', true);
    }
}
