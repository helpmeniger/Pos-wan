<?php
declare(strict_types=1);

namespace App\Saas;

use App\Core\BusinessException;
use App\Services\Pdf;

/**
 * Paiements des abonnements. Encaissement MANUEL : le client paie par mobile money,
 * virement, espèces… puis l'exploitant enregistre (ou valide la déclaration du client) avec
 * la référence de la transaction. Aucun prélèvement automatique : une passerelle de paiement
 * demande un contrat et des clés d'API auprès d'un opérateur (voir SAAS.md).
 */
final class Billing
{
    public static function fcfa(mixed $v): string
    {
        return number_format((float) $v, 0, ',', ' ') . ' FCFA';
    }

    /** Déclaration d'un paiement par le client depuis son espace (en attente de validation). */
    public static function declare(int $tenantId, array $d, string $by): int
    {
        $p = self::checkInput($d);
        if ($p['reference'] === null) {
            throw new BusinessException('Indiquez la référence de la transaction (reçue par SMS ou sur le reçu).');
        }
        if ((int) Central::value("SELECT COUNT(*) FROM saas_payments WHERE tenant_id = ? AND status = 'pending'", [$tenantId]) >= 3) {
            throw new BusinessException('Vous avez déjà 3 déclarations en attente : patientez jusqu\'à leur vérification.');
        }
        $id = Central::insert('saas_payments', $p + ['tenant_id' => $tenantId, 'status' => 'pending', 'declared_by' => 'client', 'declared_by_name' => mb_substr($by, 0, 120)]);
        Central::audit('payment.declared', $tenantId, ['payment' => $id, 'amount' => $p['amount'], 'ref' => $p['reference']], 0);
        return $id;
    }

    /** Paiement saisi par l'exploitant : validé immédiatement. */
    public static function record(int $tenantId, array $d, int $adminId): array
    {
        $p = self::checkInput($d);
        $id = Central::insert('saas_payments', $p + ['tenant_id' => $tenantId, 'status' => 'pending', 'declared_by' => 'console']);
        return self::validate($id, $adminId);
    }

    private static function checkInput(array $d): array
    {
        $plan = Central::one('SELECT * FROM saas_plans WHERE id = ? AND active = 1', [(int) ($d['plan_id'] ?? 0)]);
        $months = (int) ($d['months'] ?? 0);
        $method = (string) ($d['method'] ?? '');
        $amount = round((float) ($d['amount'] ?? 0), 2);
        $paidOn = (string) ($d['paid_on'] ?? date('Y-m-d'));
        $ref = trim((string) ($d['reference'] ?? ''));
        if (!$plan) {
            throw new BusinessException('Offre invalide.');
        }
        if ($months < 1 || $months > 24) {
            throw new BusinessException('Durée invalide (1 à 24 mois).');
        }
        if (!isset(Subscription::METHODS[$method])) {
            throw new BusinessException('Mode de paiement invalide.');
        }
        if ($amount <= 0) {
            throw new BusinessException('Montant invalide.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || $paidOn > date('Y-m-d') || $paidOn < date('Y-m-d', strtotime('-1 year'))) {
            throw new BusinessException('Date de paiement invalide.');
        }
        if ($ref !== '' && Central::value("SELECT 1 FROM saas_payments WHERE method = ? AND reference = ? AND status <> 'rejected'", [$method, $ref])) {
            throw new BusinessException('Cette référence de transaction a déjà été enregistrée.');
        }
        return [
            'plan_id' => (int) $plan['id'], 'months' => $months, 'amount' => $amount, 'method' => $method,
            'reference' => $ref !== '' ? mb_substr($ref, 0, 80) : null, 'payer_phone' => mb_substr(trim((string) ($d['payer_phone'] ?? '')), 0, 40) ?: null,
            'paid_on' => $paidOn, 'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null,
        ];
    }

    /** Validation : prolonge l'abonnement, applique l'offre payée, numérote la facture. */
    public static function validate(int $paymentId, int $adminId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        return Central::transaction(function () use ($paymentId, $adminId, $today) {
            $p = Central::one("SELECT * FROM saas_payments WHERE id = ? AND status = 'pending' FOR UPDATE", [$paymentId]);
            if (!$p) {
                throw new BusinessException('Paiement introuvable ou déjà traité.');
            }
            $t = Central::one('SELECT * FROM saas_tenants WHERE id = ? FOR UPDATE', [$p['tenant_id']]);
            // La période payée commence aujourd'hui, ou au lendemain de la période déjà payée, ou au
            // lendemain de l'essai en cours (payer pendant l'essai ne fait perdre aucun jour gratuit)
            $from = $today;
            foreach ([$t['paid_until'], $t['trial_ends']] as $end) {
                if (!empty($end) && $end >= $from) {
                    $from = date('Y-m-d', strtotime($end . ' +1 day'));
                }
            }
            $to = self::addMonths($from, (int) $p['months']);
            $year = (int) date('Y', strtotime($today));
            Central::run("INSERT INTO saas_settings (skey, svalue) VALUES (?, '0') ON DUPLICATE KEY UPDATE skey = skey", ['invoice_seq_' . $year]);
            $n = (int) Central::value('SELECT svalue FROM saas_settings WHERE skey = ? FOR UPDATE', ['invoice_seq_' . $year]) + 1;
            Central::settingSet('invoice_seq_' . $year, (string) $n);
            $invoice = sprintf('AB%d-%05d', $year, $n);
            Central::update('saas_payments', ['status' => 'validated', 'period_from' => $from, 'period_to' => $to, 'invoice_no' => $invoice, 'validated_by' => $adminId ?: null, 'validated_at' => date('Y-m-d H:i:s')], 'id = ?', [$paymentId]);
            Central::update('saas_tenants', ['paid_until' => $to, 'plan_id' => $p['plan_id']], 'id = ?', [$t['id']]);
            Central::audit('payment.validated', (int) $t['id'], ['payment' => $paymentId, 'invoice' => $invoice, 'until' => $to], $adminId);
            return Central::one('SELECT * FROM saas_payments WHERE id = ?', [$paymentId]);
        });
    }

    /** Fin de période : du 15 janvier pour 1 mois → 14 février (fin de mois respectée). */
    public static function addMonths(string $from, int $months): string
    {
        $d = new \DateTimeImmutable($from);
        $day = (int) $d->format('j');
        $first = $d->modify('first day of this month')->modify('+' . $months . ' months');
        if ($day > (int) $first->format('t')) {
            return $first->modify('last day of this month')->format('Y-m-d'); // 31 janvier + 1 mois → 28 février
        }
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->modify('-1 day')->format('Y-m-d');
    }

    public static function reject(int $paymentId, string $reason, int $adminId): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Indiquez le motif du refus (le client le verra).');
        }
        $p = Central::one("SELECT * FROM saas_payments WHERE id = ? AND status = 'pending'", [$paymentId]);
        if (!$p) {
            throw new BusinessException('Paiement introuvable ou déjà traité.');
        }
        Central::update('saas_payments', ['status' => 'rejected', 'reject_reason' => mb_substr(trim($reason), 0, 255), 'validated_by' => $adminId, 'validated_at' => date('Y-m-d H:i:s')], 'id = ?', [$paymentId]);
        Central::audit('payment.rejected', (int) $p['tenant_id'], ['payment' => $paymentId, 'reason' => $reason], $adminId);
    }

    /** Geste commercial : jours ajoutés à l'essai (ou à la période payée si elle est en cours). */
    public static function extend(int $tenantId, int $days, string $reason, int $adminId): void
    {
        if ($days < 1 || $days > 365) {
            throw new BusinessException('Nombre de jours invalide (1 à 365).');
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Indiquez le motif de la prolongation.');
        }
        $t = Central::one('SELECT * FROM saas_tenants WHERE id = ?', [$tenantId]) ?? throw new BusinessException('Client introuvable.');
        $today = date('Y-m-d');
        if (!empty($t['paid_until']) && $t['paid_until'] >= $today) {
            Central::update('saas_tenants', ['paid_until' => date('Y-m-d', strtotime($t['paid_until'] . " +$days days"))], 'id = ?', [$tenantId]);
        } else {
            $base = (!empty($t['trial_ends']) && $t['trial_ends'] >= $today) ? $t['trial_ends'] : date('Y-m-d', strtotime('-1 day'));
            Central::update('saas_tenants', ['trial_ends' => date('Y-m-d', strtotime($base . " +$days days"))], 'id = ?', [$tenantId]);
        }
        Central::audit('tenant.extended', $tenantId, ['days' => $days, 'reason' => $reason], $adminId);
    }

    /** Revenu mensuel récurrent : prix mensuel des offres des clients à jour de paiement. */
    public static function mrr(): float
    {
        return (float) Central::value('SELECT COALESCE(SUM(p.price_month),0) FROM saas_tenants t JOIN saas_plans p ON p.id = t.plan_id WHERE t.suspended_at IS NULL AND t.paid_until >= CURDATE()');
    }

    public static function invoicePdf(array $pay): string
    {
        $t = Central::one('SELECT * FROM saas_tenants WHERE id = ?', [$pay['tenant_id']]);
        $plan = Central::one('SELECT * FROM saas_plans WHERE id = ?', [$pay['plan_id']]);
        $op = fn(string $k, string $d = '') => (string) (Central::setting($k) ?: $d);
        $pdf = new Pdf();
        $pdf->addPage();
        $W = $pdf->width();
        $navy = [0.078, 0.129, 0.239];
        $violet = [0.435, 0.259, 0.757];
        $grey = [0.42, 0.45, 0.53];
        $pdf->rect(0, 0, $W, 110, $navy);
        $pdf->text(50, 50, $op('operator_name', 'POS Marwa en ligne'), 18, true, 'left', 0, [1, 1, 1]);
        $pdf->text(50, 72, $op('operator_address'), 9, false, 'left', 0, [0.85, 0.87, 0.95]);
        $pdf->text(50, 86, trim(($op('operator_phone') ? 'Tél. ' . $op('operator_phone') : '') . ($op('operator_nif') ? '   NIF ' . $op('operator_nif') : '') . ($op('operator_rccm') ? '   RCCM ' . $op('operator_rccm') : '')), 9, false, 'left', 0, [0.85, 0.87, 0.95]);
        $pdf->text(50, 50, 'FACTURE', 20, true, 'right', $W - 100, [0.96, 0.76, 0.42]);
        $pdf->text(50, 72, 'N° ' . $pay['invoice_no'], 11, true, 'right', $W - 100, [1, 1, 1]);
        $pdf->text(50, 86, 'Date : ' . date('d/m/Y', strtotime((string) $pay['validated_at'])), 9, false, 'right', $W - 100, [0.85, 0.87, 0.95]);
        $y = 150;
        $pdf->text(50, $y, 'FACTURÉ À', 8.5, true, 'left', 0, $violet);
        $pdf->text(50, $y + 18, $t['company'], 12, true, 'left', 0, $navy);
        $pdf->text(50, $y + 34, $t['contact_name'] . ' · ' . $t['phone'] . ($t['email'] ? ' · ' . $t['email'] : ''), 9.5, false, 'left', 0, $grey);
        $pdf->text(50, $y + 48, 'Espace : ' . $t['slug'] . '.' . config('saas.domain'), 9.5, false, 'left', 0, $grey);
        $y = 240;
        $pdf->rect(50, $y - 13, $W - 100, 20, $navy);
        $pdf->text(58, $y, 'Désignation', 9, true, 'left', 0, [1, 1, 1]);
        $pdf->text(58, $y, 'Montant', 9, true, 'right', $W - 116, [1, 1, 1]);
        $y += 26;
        $pdf->text(58, $y, 'Abonnement POS Marwa en ligne — offre ' . $plan['name'] . ' (' . $pay['months'] . ' mois)', 10, true, 'left', $W - 220, $navy);
        $pdf->text(58, $y, self::fcfa($pay['amount']), 10, true, 'right', $W - 116, $navy);
        $pdf->text(58, $y + 15, 'Période du ' . date('d/m/Y', strtotime((string) $pay['period_from'])) . ' au ' . date('d/m/Y', strtotime((string) $pay['period_to'])), 9, false, 'left', 0, $grey);
        $pdf->line(50, $y + 30, $W - 50, $y + 30, 0.5, [0.85, 0.85, 0.9]);
        $y += 54;
        $pdf->rect($W - 250, $y - 16, 200, 28, [0.957, 0.949, 1.0]);
        $pdf->text($W - 240, $y + 2, 'TOTAL', 10, true, 'left', 0, $violet);
        $pdf->text($W - 240, $y + 2, self::fcfa($pay['amount']), 12, true, 'right', 180, $navy);
        $y += 50;
        $pdf->text(50, $y, 'Réglé le ' . date('d/m/Y', strtotime((string) $pay['paid_on'])) . ' par ' . Subscription::METHODS[$pay['method']] . ($pay['reference'] ? ' — référence ' . $pay['reference'] : '') . '.', 9.5, false, 'left', $W - 100, [0.15, 0.17, 0.22]);
        if ($op('invoice_note') !== '') {
            $pdf->text(50, $y + 18, $op('invoice_note'), 9, false, 'left', $W - 100, $grey);
        }
        $pdf->text(50, $pdf->height() - 40, 'Facture acquittée · ' . $op('operator_name', 'POS Marwa en ligne') . ' · POS Marwa © ' . config('licence.author.name'), 7.5, false, 'center', $W - 100, $grey);
        return $pdf->output();
    }
}
