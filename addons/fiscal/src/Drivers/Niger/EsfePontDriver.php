<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Niger;

use Addons\Fiscal\DriverResult;
use Addons\Fiscal\Drivers\AbstractDriver;
use Addons\Fiscal\Secef\PontClient;
use Addons\Fiscal\Secef\Secef;
use App\Core\BusinessException;

/**
 * Niger — e-SECeF (application e-SFE de la DGI) par le pont MARWA SECEF CONNECT (Node.js + Playwright).
 *
 * Le pont remplit le formulaire officiel de l'e-SFE comme un utilisateur, d'après les écrans observés
 * (documentation/marwa-secef/), vérifie le total, puis :
 *  - si la certification n'est PAS autorisée dans la configuration : s'arrête avant « CERTIFIER », annule le
 *    projet de facture et répond « préparée » (rien n'est certifié) ;
 *  - sinon : clique « CERTIFIER » et relève les éléments de sécurité réellement affichés par le portail.
 * Une facture n'est « certifiée » dans POS Marwa que si le portail a affiché un Code SECeF/DGI et un NIM.
 *
 * Anti-doublon : chaque facture porte une clé unique ; le pont ne rejoue jamais une facture dont la
 * certification a pu partir (« incertaine ») : elle est signalée pour vérification humaine.
 */
final class EsfePontDriver extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => Secef::DRIVER, 'label' => 'Niger — e-SECeF (e-SFE) via MARWA SECEF CONNECT', 'kind' => 'cloud', 'transports' => ['http'],
            'verified' => false,
            'notes' => 'Automatisation de l\'application e-SFE de la DGI (pont local Node.js + Playwright). Formulaires relevés le 20/11/2026 ; '
                . 'certification réelle non encore essayée. Mode d\'intégration à faire confirmer par la DGI (voie officielle pour un logiciel : e-MCF).'];
    }

    public static function capabilities(): array
    {
        return ['testConnection', 'authenticate', 'certifyInvoice', 'getInvoiceStatus', 'issueCreditNote', 'getDeviceInfo'];
    }

    private function client(): PontClient
    {
        return new PontClient((string) Secef::config()['url_pont'], (string) ($this->secret('jeton_pont') ?? ''));
    }

    /** Identifiants e-SECeF (déchiffrés au moment de l'envoi au pont local, jamais journalisés). */
    private function acces(): ?array
    {
        $id = $this->secret('identifiant');
        $pw = $this->secret('mot_de_passe');
        return $id !== null && $pw !== null ? ['identifiant' => $id, 'mot_de_passe' => $pw] : null;
    }

    private function options(): array
    {
        $c = Secef::config();
        return ['url_portail' => $c['url_portail'], 'fenetre_visible' => (bool) $c['fenetre_visible']];
    }

    public function testConnection(): DriverResult
    {
        if (!$this->secret('jeton_pont')) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Jeton du pont absent : générez-le dans Certification fiscale → MARWA SECEF CONNECT.');
        }
        $acces = $this->acces();
        if (!$acces) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Identifiant ou mot de passe e-SECeF non enregistré.');
        }
        $r = $this->client()->call('POST', '/session/tester', ['acces' => $acces] + $this->options(), 300);
        return $this->plain($r, 'tester');
    }

    public function authenticate(): DriverResult
    {
        return $this->testConnection();
    }

    public function getDeviceInfo(): DriverResult
    {
        $r = $this->client()->call('GET', '/etat', null, 10);
        return $this->plain($r, 'etat');
    }

    public function certifyInvoice(array $invoice): DriverResult
    {
        return $this->submit($invoice);
    }

    public function issueCreditNote(array $creditNote): DriverResult
    {
        if (trim((string) ($creditNote['original']['fiscal_number'] ?? '')) === '') {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Avoir : le Code SECeF/DGI de la facture d\'origine est inconnu.');
        }
        return $this->submit($creditNote);
    }

    private function submit(array $invoice): DriverResult
    {
        $c = Secef::config();
        try {
            $facture = Secef::buildInvoice($invoice, $c);
        } catch (BusinessException $e) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, $e->getMessage());
        }
        $acces = $this->acces();
        if (!$acces || !$this->secret('jeton_pont')) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'MARWA SECEF CONNECT n\'est pas configuré (identifiants e-SECeF ou jeton du pont).');
        }
        $body = ['facture' => $facture, 'certifier' => (bool) $c['certification_autorisee'], 'creer_articles' => (bool) $c['creer_articles'],
            'attente_s' => max(10, $this->timeout() - 5), 'acces' => $acces] + $this->options();
        $r = $this->client()->call('POST', '/factures/soumettre', $body, $this->timeout() + 5);
        return $this->fromJob($r, PontClient::redact($body));
    }

    public function getInvoiceStatus(string $idempotencyKey): DriverResult
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Clé de facture invalide.');
        }
        $r = $this->client()->call('GET', '/factures/' . $idempotencyKey, null, 15);
        if ($r['status'] === 404) {
            return DriverResult::fail(DriverResult::NOT_FOUND, 'Facture inconnue du pont : jamais transmise.');
        }
        // Facture connue du pont mais dont le bouton CERTIFIER n'a jamais été actionné (préparée, erreur avant
        // certification) : rien n'existe sur le portail, un nouvel envoi est sans risque de doublon.
        $etat = (string) ($r['data']['etat'] ?? '');
        if ($r['ok'] && ($r['data']['certification_envoyee'] ?? null) === false && !in_array($etat, ['en_cours', 'recue', 'certifiee'], true)) {
            return DriverResult::fail(DriverResult::NOT_FOUND, 'Facture jamais certifiée sur le portail (état du pont : ' . $etat . ').');
        }
        return $this->fromJob($r, null);
    }

    /** Réponse du pont pour une facture → résultat normalisé. */
    private function fromJob(array $r, ?array $request): DriverResult
    {
        $req = $request !== null ? (string) json_encode($request, JSON_UNESCAPED_UNICODE) : null;
        $resp = $r['data'] ? (string) json_encode($r['data'], JSON_UNESCAPED_UNICODE) : null;
        if (!$r['ok'] && !isset($r['data']['etat'])) {
            $code = $r['error'] === 'timeout' ? DriverResult::TIMEOUT : ($r['status'] === 401 ? DriverResult::CONFIG_ERROR : DriverResult::UNREACHABLE);
            $msg = $r['error'] === 'timeout' ? 'Le pont n\'a pas répondu à temps : la facture est suivie, son état sera redemandé (aucun second envoi).' : (string) $r['error'];
            return DriverResult::fail($code, $msg, [], $req, $resp, $r['status'] ?: null);
        }
        $d = $r['data'];
        $res = is_array($d['resultat'] ?? null) ? $d['resultat'] : [];
        $msg = (string) ($d['message'] ?? '');
        switch ((string) ($d['etat'] ?? '')) {
            case 'certifiee':
                $code = trim((string) ($res['code_secef'] ?? ''));
                $nim = trim((string) ($res['nim'] ?? ''));
                if ($code === '' || $nim === '') {
                    return DriverResult::fail(DriverResult::INVALID_RESPONSE, 'Le pont annonce une certification sans Code SECeF/DGI ni NIM : non retenue, vérification humaine nécessaire.', [], $req, $resp, $r['status']);
                }
                return new DriverResult(DriverResult::CERTIFIED, 'Facture certifiée par l\'e-SECeF.', [
                    'fiscal_number' => $code, 'signature' => $code, 'device_id' => $nim,
                    'counters' => isset($res['compteurs']) ? (string) $res['compteurs'] : null,
                    'qr_payload' => isset($res['qr']) && $res['qr'] !== '' ? (string) $res['qr'] : null,
                    'secef_heure' => $res['heure'] ?? null, 'isf' => $res['isf'] ?? null, 'pdf' => $res['pdf'] ?? null, 'total_portail' => $res['total'] ?? null,
                ], $req, $resp, $r['status']);
            case 'preparee':
                return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Facture préparée et vérifiée sur l\'e-SFE' . (isset($res['total']) ? ' (total ' . $res['total'] . ')' : '')
                    . ', puis abandonnée sans certification : la certification n\'est pas autorisée dans la configuration. Rien n\'a été certifié.', [], $req, $resp, $r['status']);
            case 'en_cours':
            case 'recue':
                return DriverResult::fail(DriverResult::TIMEOUT, 'Facture en cours de traitement par le pont : son état sera redemandé (aucun second envoi).', [], $req, $resp, $r['status']);
            case 'refusee':
                return DriverResult::fail(DriverResult::REJECTED, 'Refusée par l\'e-SFE : ' . $msg, [], $req, $resp, $r['status']);
            case 'incertaine':
                return DriverResult::fail(DriverResult::INVALID_RESPONSE, 'Certification peut-être faite mais résultat non relevé : vérifiez la facture dans le Journal de l\'e-SFE avant toute nouvelle tentative (aucun renvoi automatique). ' . $msg, [], $req, $resp, $r['status']);
            case 'identifiants':
                return DriverResult::fail(DriverResult::AUTH_FAILED, $msg ?: 'Identifiant ou mot de passe e-SECeF refusé par le portail.', [], $req, $resp, $r['status']);
            case 'intervention':
                return DriverResult::fail(DriverResult::AUTH_FAILED, 'Intervention humaine demandée par le portail : ' . $msg, [], $req, $resp, $r['status']);
            default:
                $retry = !empty($d['reessayable']);
                return DriverResult::fail($retry ? DriverResult::UNREACHABLE : DriverResult::CONFIG_ERROR, $msg ?: 'Erreur du pont.', [], $req, $resp, $r['status']);
        }
    }

    private function plain(array $r, string $what): DriverResult
    {
        $resp = $r['data'] ? (string) json_encode($r['data'], JSON_UNESCAPED_UNICODE) : null;
        if ($r['ok']) {
            return DriverResult::ok($r['data'], (string) ($r['data']['message'] ?? 'OK'), null, $resp, $r['status']);
        }
        $etat = (string) ($r['data']['etat'] ?? '');
        $code = $etat === 'identifiants' ? DriverResult::AUTH_FAILED : ($r['error'] === 'timeout' ? DriverResult::TIMEOUT : ($r['status'] === 0 ? DriverResult::UNREACHABLE : DriverResult::CONFIG_ERROR));
        return DriverResult::fail($code, (string) ($r['error'] ?? $r['data']['message'] ?? 'Erreur du pont (' . $what . ').'), [], null, $resp, $r['status'] ?: null);
    }
}
