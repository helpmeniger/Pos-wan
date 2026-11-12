<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Terminals;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;

/**
 * Base des terminaux dialoguant par messages JSON d'une ligne (TCP ou port série via le pont local).
 * La plupart des TPE bancaires utilisent un protocole propriétaire (souvent fourni avec un SDK sous contrat) :
 * ce pilote ne leur est applicable que si le fabricant documente une interface compatible ou fournit un adaptateur.
 *
 * config_json : requests{test|sale|status|cancel|refund: gabarit JSON}, response{status, reference, authorization_code, amount, currency, card_last4},
 *  status_map{valeur: succeeded|pending|processing|declined|cancelled}, amount_unit (major|minor).
 * Seuls les 4 derniers chiffres de la carte peuvent être conservés (jamais le numéro complet).
 */
abstract class LineTerminalDriver extends AbstractDriver
{
    /** Envoie une ligne JSON au terminal et lit sa réponse. @return array{line: ?string, error: ?string, timeout: bool, sent: string} */
    abstract protected function exchange(string $json): array;

    public static function capabilities(): array
    {
        return ['testConnection', 'initiate', 'getStatus', 'cancel', 'refund'];
    }

    private static function at(mixed $d, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        foreach (explode('.', $path) as $k) {
            if (!is_array($d) || !array_key_exists($k, $d)) {
                return null;
            }
            $d = $d[$k];
        }
        return $d;
    }

    private function render(string $tpl, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*(json:)?([a-zA-Z0-9_.]*)\s*\}\}/', function ($m) use ($vars) {
            $v = self::at($vars, $m[2]);
            return $m[1] === 'json:' ? (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) : substr((string) json_encode(is_scalar($v) ? (string) $v : '', JSON_UNESCAPED_UNICODE), 1, -1);
        }, $tpl);
    }

    private function amount(float $a): float|int
    {
        return ($this->config['amount_unit'] ?? 'major') === 'minor' ? (int) round($a * 100) : $a;
    }

    protected function send(string $op, array $vars): GatewayResult
    {
        $tpl = $this->config['requests'][$op] ?? null;
        if (!is_string($tpl) || $tpl === '') {
            return GatewayResult::unsupported($op, static::info()['label'] . ' (requête non configurée)');
        }
        $json = str_replace(["\r", "\n"], ' ', $this->render($tpl, $vars));
        $r = $this->exchange($json);
        if ($r['error'] !== null) {
            return GatewayResult::make($r['timeout'] ? GatewayResult::TIMEOUT : GatewayResult::UNREACHABLE, $r['error'], [], $r['sent']);
        }
        $j = json_decode(trim((string) $r['line']), true);
        if (!is_array($j)) {
            return GatewayResult::make(GatewayResult::INVALID_RESPONSE, 'Réponse du terminal illisible (JSON attendu).', [], $r['sent'], $r['line']);
        }
        if ($op === 'test') {
            return GatewayResult::make(GatewayResult::OK, 'Terminal joignable.', [], $r['sent'], $r['line']);
        }
        $resp = (array) ($this->config['response'] ?? []);
        $raw = self::at($j, (string) ($resp['status'] ?? 'status'));
        $status = is_scalar($raw) ? (((array) ($this->config['status_map'] ?? []))[(string) $raw] ?? null) : null;
        if ($op === 'refund' && $status === 'succeeded') {
            $status = 'refunded';
        }
        if (!in_array($status, ['succeeded', 'pending', 'processing', 'declined', 'cancelled', 'refunded'], true)) {
            return GatewayResult::make(GatewayResult::INVALID_RESPONSE, 'Statut du terminal non reconnu : complétez status_map.', [], $r['sent'], $r['line']);
        }
        $data = [];
        foreach (['provider_reference' => 'reference', 'authorization_code' => 'authorization_code', 'currency' => 'currency'] as $k => $f) {
            $v = self::at($j, (string) ($resp[$f] ?? ''));
            if (is_scalar($v) && (string) $v !== '') {
                $data[$k] = mb_substr((string) $v, 0, 120);
            }
        }
        $amt = self::at($j, (string) ($resp['amount'] ?? ''));
        if (is_numeric($amt)) {
            $data['amount'] = ($this->config['amount_unit'] ?? 'major') === 'minor' ? round((float) $amt / 100, 2) : round((float) $amt, 2);
        }
        $last4 = self::at($j, (string) ($resp['card_last4'] ?? ''));
        if (is_scalar($last4) && preg_match('/^\d{4}$/', (string) $last4)) {
            $data['payer_hint'] = 'Carte •••• ' . $last4; // jamais plus que les 4 derniers chiffres
        }
        return GatewayResult::make($status, 'Réponse du terminal : ' . (string) $raw, $data, $r['sent'], $r['line']);
    }

    private function vars(array $p): array
    {
        return array_merge($p, ['amount' => $this->amount((float) ($p['amount'] ?? 0))]);
    }

    public function testConnection(): GatewayResult
    {
        return $this->send('test', []);
    }

    public function initiate(array $p): GatewayResult
    {
        return $this->send('sale', $this->vars($p));
    }

    public function getStatus(array $tx): GatewayResult
    {
        return $this->send('status', ['number' => $tx['number'], 'reference' => $tx['provider_reference'], 'idempotency_key' => $tx['idempotency_key']]);
    }

    public function cancel(array $tx): GatewayResult
    {
        return $this->send('cancel', ['number' => $tx['number'], 'reference' => $tx['provider_reference']]);
    }

    public function refund(array $tx, array $r): GatewayResult
    {
        return $this->send('refund', $this->vars($r) + ['reference' => $tx['provider_reference'], 'transaction_number' => $tx['number']]);
    }
}
