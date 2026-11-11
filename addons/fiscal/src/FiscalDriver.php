<?php
declare(strict_types=1);

namespace Addons\Fiscal;

/**
 * Interface commune des pilotes (MCF physiques, API, plateformes en ligne).
 * Une fonction que le dispositif ne propose pas renvoie DriverResult::unsupported(), jamais un succès simulé.
 */
interface FiscalDriver
{
    /** @param array $connector ligne fiscal_connectors (config_json décodé dans 'config') */
    public function __construct(array $connector);

    /** Métadonnées : label, kind, transports, verified (protocole vérifié sur documentation officielle), notes. */
    public static function info(): array;

    /** @return string[] fonctions réellement prises en charge */
    public static function capabilities(): array;

    public function connect(): DriverResult;
    public function disconnect(): DriverResult;
    public function testConnection(): DriverResult;
    public function authenticate(): DriverResult;
    /** @param array $invoice facture normalisée (InvoiceNormalizer) */
    public function certifyInvoice(array $invoice): DriverResult;
    /** @param string $idempotencyKey clé unique transmise lors de certifyInvoice */
    public function getInvoiceStatus(string $idempotencyKey): DriverResult;
    public function getCertificate(string $fiscalNumber): DriverResult;
    public function getDeviceInfo(): DriverResult;
    public function getDailyReport(string $date): DriverResult;
    public function closeDay(string $date): DriverResult;
    public function cancelInvoice(string $fiscalNumber, string $reason): DriverResult;
    /** @param array $creditNote avoir normalisé (référence à la facture certifiée d'origine) */
    public function issueCreditNote(array $creditNote): DriverResult;
}
