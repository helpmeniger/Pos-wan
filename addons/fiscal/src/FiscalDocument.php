<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\DB;

/**
 * Bloc fiscal imprimé sur le ticket et la facture A4.
 * Les données de certification (numéro, dispositif, signature, QR) ne sont imprimées
 * QUE si la certification a été reçue et validée ; sinon le document porte « NON CERTIFIÉE ».
 */
final class FiscalDocument
{
    public static function block(int $saleId): ?array
    {
        if (!FiscalEngine::enabled()) {
            return null;
        }
        $c = FiscalEngine::forSale($saleId);
        if (!$c) {
            return null; // vente non soumise (antérieure au module ou hors périmètre)
        }
        $p = $c['profile_id'] ? DB::one('SELECT p.*, co.legal_mentions AS country_mentions, co.tax_id_label FROM fiscal_profiles p JOIN fiscal_countries co ON co.id = p.country_id WHERE p.id = ?', [$c['profile_id']]) : null;
        $nifLabel = ($p['tax_id_label'] ?? '') ?: 'NIF';
        $nif = ($p['seller_nif'] ?? '') ?: (string) setting('company_nif', '');
        $lines = $nif !== '' ? [[$nifLabel . ' ' . t('vendeur'), $nif]] : [];
        $mentions = array_values(array_filter([trim((string) ($p['country_mentions'] ?? '')), trim((string) ($p['legal_mentions'] ?? ''))]));
        $secef = $c['connector_id'] && DB::value('SELECT driver FROM fiscal_connectors WHERE id = ?', [$c['connector_id']]) === Secef\Secef::DRIVER;
        if ($c['status'] === 'certified' && $secef) {
            // e-SECeF (Niger) : libellés et valeurs exactement tels qu'affichés par le portail de la DGI
            $proof = json_decode((string) $c['proof_json'], true)['data'] ?? [];
            $lines[] = ['Code SECeF/DGI', (string) $c['fiscal_number']];
            $lines[] = ['SECeF NIM', (string) $c['device_id']];
            if ($c['counters']) {
                $lines[] = ['SECeF Compteurs', (string) $c['counters']];
            }
            $lines[] = ['SECeF Heure', !empty($proof['secef_heure']) ? (string) $proof['secef_heure'] : fdate($c['certified_at'])];
            if (!empty($proof['isf'])) {
                $lines[] = ['ISF', (string) $proof['isf']];
            }
            $mentions[] = 'Vérification : sygmef.impots.gouv.ne/Verification (Code SECeF/DGI + NIM).';
            return ['status' => 'certified', 'simulation' => false, 'title' => 'FACTURE CERTIFIÉE e-SECeF', 'banner' => null,
                'lines' => $lines, 'mentions' => $mentions, 'note' => null, 'qr' => $c['qr_payload'] ?: null];
        }
        if ($c['status'] === 'certified') {
            $sim = (int) $c['is_simulation'] === 1;
            $lines[] = [t('N° de certification'), (string) $c['fiscal_number']];
            if ($c['device_id']) {
                $lines[] = [t('Dispositif (MCF)'), (string) $c['device_id']];
            }
            if ($c['counters']) {
                $lines[] = [t('Compteurs'), (string) $c['counters']];
            }
            if ($c['signature']) {
                $lines[] = [t('Signature'), (string) $c['signature']];
            }
            $lines[] = [t('Certifiée le'), fdate($c['certified_at'])];
            return ['status' => 'certified', 'simulation' => $sim,
                'title' => $sim ? 'NON CERTIFIÉ – TEST' : t('FACTURE CERTIFIÉE'),
                'banner' => $sim ? 'NON CERTIFIÉ – TEST' : null,
                'lines' => $lines, 'mentions' => $sim ? [] : $mentions,
                'note' => $sim ? t('Document de simulation sans valeur fiscale.') : null,
                'qr' => $sim ? null : ($c['qr_payload'] ?: null)];
        }
        $lines[] = [t('Statut fiscal'), t(FiscalAdmin::STATUSES[$c['status']] ?? $c['status'])];
        return ['status' => $c['status'], 'simulation' => false, 'title' => t('FACTURE NON CERTIFIÉE'), 'banner' => t('NON CERTIFIÉE'),
            'lines' => $lines, 'mentions' => [], 'qr' => null,
            'note' => $c['status'] === 'rejected' ? t('Refusée par le dispositif fiscal : document correctif nécessaire.') : t('Certification en attente : ce document n\'a pas de valeur de facture certifiée.')];
    }

    /** Message de refus si le profil interdit l'impression d'une facture non certifiée. */
    public static function printBlocker(int $saleId): ?string
    {
        if (!FiscalEngine::enabled()) {
            return null;
        }
        $c = FiscalEngine::forSale($saleId);
        if (!$c || $c['status'] === 'certified') {
            return null;
        }
        $mode = $c['profile_id'] ? DB::value('SELECT print_uncertified FROM fiscal_profiles WHERE id = ?', [$c['profile_id']]) : null;
        return $mode === 'block' ? 'Impression bloquée : la facture ' . $c['local_number'] . ' n\'est pas encore certifiée (état : ' . (FiscalAdmin::STATUSES[$c['status']] ?? $c['status']) . '). Voir « Factures en attente ».' : null;
    }
}
