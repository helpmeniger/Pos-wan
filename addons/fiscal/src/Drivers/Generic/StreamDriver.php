<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Generic;

use Addons\Fiscal\DriverResult;
use Addons\Fiscal\Drivers\AbstractDriver;

/**
 * Base des pilotes à flux (TCP, port série) avec un protocole « une ligne JSON par message ».
 * Les MCF utilisent en général des trames propriétaires (octets de début/fin, sommes de contrôle) :
 * pour ceux-là, ce pilote n'est qu'un point de départ et un pilote dédié doit être écrit d'après la
 * spécification officielle du constructeur. Il n'est jamais déclaré compatible par défaut.
 *
 * config_json : framing (json_line), endpoints {test|certify|status|device|daily_report|close_day|credit_note: {template}},
 *  response_map, success (comme le pilote REST).
 */
abstract class StreamDriver extends AbstractDriver
{
    use Mapping;

    private const OPS = ['testConnection' => 'test', 'certifyInvoice' => 'certify', 'getInvoiceStatus' => 'status', 'getDeviceInfo' => 'device',
        'getDailyReport' => 'daily_report', 'closeDay' => 'close_day', 'issueCreditNote' => 'credit_note'];

    /** @var resource|null */
    protected $stream = null;

    /** Ouvre le flux ou renvoie un échec explicite. @return resource|DriverResult */
    abstract protected function open(): mixed;

    public static function capabilities(): array
    {
        return ['connect', 'disconnect', 'testConnection', 'certifyInvoice', 'getInvoiceStatus', 'getDeviceInfo', 'getDailyReport', 'closeDay', 'issueCreditNote'];
    }

    public function connect(): DriverResult
    {
        if (($this->config['framing'] ?? 'json_line') !== 'json_line') {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Format de trame non pris en charge : un pilote dédié au dispositif est nécessaire.');
        }
        $s = $this->open();
        if ($s instanceof DriverResult) {
            return $s;
        }
        $this->stream = $s;
        stream_set_timeout($this->stream, $this->timeout());
        return DriverResult::ok([], 'Connecté.');
    }

    public function disconnect(): DriverResult
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        return DriverResult::ok([], 'Déconnecté.');
    }

    protected function exchange(string $fn, array $vars, bool $expectCertification = false): DriverResult
    {
        $op = self::OPS[$fn] ?? null;
        $ep = $op ? ($this->config['endpoints'][$op] ?? null) : null;
        if (!$op || !is_array($ep)) {
            return DriverResult::unsupported($fn, static::info()['label'] . ' (opération non configurée)');
        }
        if (!is_resource($this->stream)) {
            $c = $this->connect();
            if (!$c->isOk()) {
                return $c;
            }
        }
        $msg = !empty($ep['template']) ? self::render((string) $ep['template'], $vars, 'json') : (string) json_encode(['op' => $op, 'data' => $vars], JSON_UNESCAPED_UNICODE);
        $msg = str_replace(["\r", "\n"], ' ', $msg);
        if (@fwrite($this->stream, $msg . "\n") === false) {
            $this->disconnect();
            return DriverResult::fail(DriverResult::UNREACHABLE, 'Écriture impossible vers le dispositif.', [], $msg);
        }
        $line = @fgets($this->stream, 1048576);
        $meta = stream_get_meta_data($this->stream);
        if ($line === false || $line === '') {
            $timedOut = !empty($meta['timed_out']);
            $this->disconnect();
            return DriverResult::fail($timedOut ? DriverResult::TIMEOUT : DriverResult::UNREACHABLE, $timedOut ? 'Délai de réponse dépassé.' : 'Aucune réponse du dispositif.', [], $msg);
        }
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) {
            return DriverResult::fail(DriverResult::INVALID_RESPONSE, 'Réponse illisible (JSON attendu).', [], $msg, $line);
        }
        if (($expectCertification || $fn === 'getInvoiceStatus') && $this->businessSuccess($decoded) === false) {
            return DriverResult::fail($expectCertification ? DriverResult::REJECTED : ($fn === 'getInvoiceStatus' ? DriverResult::NOT_FOUND : DriverResult::INVALID_RESPONSE), 'Le dispositif indique un échec.', ['body' => $decoded], $msg, $line);
        }
        if ($expectCertification || $fn === 'getInvoiceStatus') {
            $data = $this->mapResponse($decoded);
            if ($fn === 'getInvoiceStatus' && empty($data['fiscal_number'])) {
                return DriverResult::fail(DriverResult::NOT_FOUND, 'Aucune certification trouvée.', [], $msg, $line);
            }
            return new DriverResult(DriverResult::CERTIFIED, 'Réponse positive du dispositif.', $data, $msg, $line);
        }
        return DriverResult::ok($decoded, 'OK', $msg, $line);
    }

    public function testConnection(): DriverResult
    {
        $r = $this->exchange('testConnection', []);
        $this->disconnect();
        return $r;
    }

    public function certifyInvoice(array $invoice): DriverResult
    {
        unset($invoice['unmapped_rates']);
        return $this->exchange('certifyInvoice', $invoice, true);
    }

    public function issueCreditNote(array $creditNote): DriverResult
    {
        unset($creditNote['unmapped_rates']);
        return $this->exchange('issueCreditNote', $creditNote, true);
    }

    public function getInvoiceStatus(string $idempotencyKey): DriverResult
    {
        return $this->exchange('getInvoiceStatus', ['idempotency_key' => $idempotencyKey]);
    }

    public function getDeviceInfo(): DriverResult
    {
        return $this->exchange('getDeviceInfo', []);
    }

    public function getDailyReport(string $date): DriverResult
    {
        return $this->exchange('getDailyReport', ['date' => $date]);
    }

    public function closeDay(string $date): DriverResult
    {
        return $this->exchange('closeDay', ['date' => $date]);
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
