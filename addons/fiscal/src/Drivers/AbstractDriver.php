<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers;

use Addons\Fiscal\DriverResult;
use Addons\Fiscal\FiscalDriver;
use Addons\Fiscal\Secrets;

/** Base : toute fonction non redéfinie répond explicitement « non prise en charge ». */
abstract class AbstractDriver implements FiscalDriver
{
    protected array $connector;
    protected array $config;

    public function __construct(array $connector)
    {
        $this->connector = $connector;
        $this->config = is_array($connector['config'] ?? null) ? $connector['config'] : (json_decode((string) ($connector['config_json'] ?? ''), true) ?: []);
    }

    public static function capabilities(): array
    {
        return [];
    }

    protected function secret(string $ckey): ?string
    {
        return isset($this->connector['id']) ? Secrets::get((int) $this->connector['id'], $ckey) : null;
    }

    protected function timeout(): int
    {
        return max(1, min(120, (int) ($this->connector['timeout_s'] ?? 15)));
    }

    private function no(string $fn): DriverResult
    {
        return DriverResult::unsupported($fn, static::info()['label'] ?? static::class);
    }

    public function connect(): DriverResult { return $this->no('connect'); }
    public function disconnect(): DriverResult { return DriverResult::ok([], 'Aucune connexion persistante.'); }
    public function testConnection(): DriverResult { return $this->no('testConnection'); }
    public function authenticate(): DriverResult { return $this->no('authenticate'); }
    public function certifyInvoice(array $invoice): DriverResult { return $this->no('certifyInvoice'); }
    public function getInvoiceStatus(string $idempotencyKey): DriverResult { return $this->no('getInvoiceStatus'); }
    public function getCertificate(string $fiscalNumber): DriverResult { return $this->no('getCertificate'); }
    public function getDeviceInfo(): DriverResult { return $this->no('getDeviceInfo'); }
    public function getDailyReport(string $date): DriverResult { return $this->no('getDailyReport'); }
    public function closeDay(string $date): DriverResult { return $this->no('closeDay'); }
    public function cancelInvoice(string $fiscalNumber, string $reason): DriverResult { return $this->no('cancelInvoice'); }
    public function issueCreditNote(array $creditNote): DriverResult { return $this->no('issueCreditNote'); }
}
