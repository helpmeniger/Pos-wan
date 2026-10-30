<?php $this->layout('layouts/app'); use App\Services\Employment\PayslipService; use App\Support\Phone;
$adminView = $adminView ?? false; ?>
<?php if ($adminView): ?><?= $this->partial('partials/admin-nav') ?><?php endif; ?>
<div class="container py-4" style="max-width:900px">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div><h1 class="h4 mb-1">Fiche de paie — <?= e($p['period_label']) ?></h1>
      <div class="small text-muted"><?= e($p['number']) ?> · <?= e($p['position_title']) ?> · <?= e($p['employee_name']) ?> / <?= e($p['business_name'] ?: $p['employer_name']) ?></div></div>
    <div class="d-flex gap-2 align-items-start"><?= badge(PayslipService::LABELS[$p['status']] ?? $p['status'], status_tone($p['status'])) ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/fiches-de-paie/' . $p['uuid'] . '/imprimer')) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Télécharger / imprimer</a></div>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="jn-card p-3 h-100">
        <h2 class="jn-section-title">Montants</h2>
        <table class="table table-sm mb-0">
          <tr><td>Période</td><td class="text-end"><?= e(date_fr($p['period_start'])) ?> → <?= e(date_fr($p['period_end'])) ?> (<?= (int) $p['days_in_period'] ?> j.)</td></tr>
          <tr><td>Salaire de référence</td><td class="text-end jn-amount"><?= e(money((string) $p['reference_salary'])) ?></td></tr>
          <tr><td>Salaire brut de la période</td><td class="text-end jn-amount"><?= e(money((string) $p['gross_amount'])) ?></td></tr>
          <tr><td>Commission JOB NIGER (<?= e(rtrim(rtrim((string) $p['commission_rate'], '0'), '.') ?: '0') ?> %)</td><td class="text-end jn-amount">− <?= e(money((string) $p['commission_amount'])) ?></td></tr>
          <tr class="fw-bold"><td>Net à verser à l'employé</td><td class="text-end jn-amount text-primary"><?= e(money((string) $p['net_amount'])) ?></td></tr>
        </table>
      </div>
    </div>
    <div class="col-md-6">
      <div class="jn-card p-3 h-100">
        <h2 class="jn-section-title">Paiement</h2>
        <?php if ($p['payment_method']): ?><p class="small mb-1">Mode : <strong><?= $p['payment_method'] === 'ESPECES' ? 'en main propre' : 'Mobile Money' ?></strong><?= $p['payout_phone'] ? ' — ' . e(Phone::format($p['payout_phone'])) : '' ?></p><?php endif; ?>
        <?php if ($p['employer_reference']): ?><p class="small mb-1">Référence du versement : <?= e($p['employer_reference']) ?></p><?php endif; ?>
        <?php if ($p['dispute_note']): ?><div class="alert alert-danger small py-2">Contestation : <?= e($p['dispute_note']) ?></div><?php endif; ?>

        <?php if ($isEmployee && in_array($p['status'], ['EMISE', 'PAIEMENT_DEMANDE'], true)): ?>
          <form method="post" action="<?= e(url('/fiches-de-paie/' . $p['uuid'] . '/demander-paiement')) ?>" data-toggle-group="method" novalidate>
            <?= csrf_field() ?>
            <div class="small fw-semibold mb-1"><?= $p['status'] === 'PAIEMENT_DEMANDE' ? 'Modifier ma demande' : 'Demander mon paiement' ?></div>
            <div class="d-flex gap-2 mb-2">
              <label class="account-type-option flex-fill small"><input class="form-check-input me-1" type="radio" name="method" value="MOBILE_MONEY"<?= ($p['payment_method'] ?? 'MOBILE_MONEY') !== 'ESPECES' ? ' checked' : '' ?>> Mobile Money</label>
              <label class="account-type-option flex-fill small"><input class="form-check-input me-1" type="radio" name="method" value="ESPECES"<?= ($p['payment_method'] ?? '') === 'ESPECES' ? ' checked' : '' ?>> En présentiel</label>
            </div>
            <div class="mb-2" data-when="MOBILE_MONEY"><label class="form-label small" for="payout_phone">Numéro Mobile Money</label>
              <input class="<?= e(field_class('payout_phone')) ?>" id="payout_phone" name="payout_phone" type="tel" value="<?= e(old('payout_phone', $p['payout_phone'] ?? ($user['phone'] ?? ''))) ?>">
              <?= $this->partial('partials/field-error', ['name' => 'payout_phone']) ?>
              <div class="form-text">Ne communiquez jamais votre code secret.</div></div>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Envoyer la demande</button>
          </form>
        <?php endif; ?>

        <?php if ($isEmployer && in_array($p['status'], ['EMISE', 'PAIEMENT_DEMANDE', 'CONTESTEE'], true)): ?>
          <form method="post" action="<?= e(url('/fiches-de-paie/' . $p['uuid'] . '/paiement-effectue')) ?>" class="mt-2" data-confirm="Confirmez-vous avoir versé <?= e(money((string) $p['net_amount'])) ?> ?">
            <?= csrf_field() ?>
            <label class="form-label small" for="reference">Référence du versement (ID de transaction Mobile Money, n° de reçu…)</label>
            <input class="form-control form-control-sm mb-2" id="reference" name="reference" maxlength="120">
            <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-cash" aria-hidden="true"></i> J'ai payé <?= e(money((string) $p['net_amount'])) ?></button>
          </form>
        <?php endif; ?>

        <?php if ($isEmployee && $p['status'] === 'PAYEE_DECLAREE'): ?>
          <p class="small">Votre employeur déclare vous avoir payé. Confirmez-vous avoir reçu <strong><?= e(money((string) $p['net_amount'])) ?></strong> ?</p>
          <form method="post" action="<?= e(url('/fiches-de-paie/' . $p['uuid'] . '/confirmer-reception')) ?>" class="mb-2">
            <?= csrf_field() ?><button class="btn btn-success" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Oui, j'ai reçu mon paiement</button>
          </form>
          <details><summary class="small text-danger">Je n'ai pas reçu le paiement</summary>
            <form method="post" action="<?= e(url('/fiches-de-paie/' . $p['uuid'] . '/contester')) ?>" class="mt-2">
              <?= csrf_field() ?><textarea class="<?= e(field_class('note')) ?> mb-2" name="note" rows="2" maxlength="1000" required placeholder="Expliquez : montant non reçu, montant incomplet…"><?= e(old('note')) ?></textarea>
              <?= $this->partial('partials/field-error', ['name' => 'note']) ?>
              <button class="btn btn-outline-danger btn-sm" type="submit">Signaler</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($p['status'] === 'RECUE'): ?><div class="alert alert-success small mb-0"><i class="bi bi-check2-circle" aria-hidden="true"></i> Paiement reçu le <?= e(date_fr($p['employee_confirmed_at'], true)) ?><?= $p['payment_reference'] ? ' (opération ' . e($p['payment_reference']) . ')' : '' ?>.</div><?php endif; ?>

        <?php if ($adminView && !empty($canCancel) && in_array($p['status'], ['EMISE', 'PAIEMENT_DEMANDE', 'CONTESTEE'], true)): ?>
          <form method="post" action="<?= e(url('/admin/fiches-de-paie/' . $p['uuid'] . '/annuler')) ?>" class="mt-3 border-top pt-2" data-confirm="Annuler cette fiche ?">
            <?= csrf_field() ?><label class="form-label small" for="reason">Annuler la fiche (erreur) — motif</label>
            <input class="form-control form-control-sm mb-2" id="reason" name="reason" required minlength="5" maxlength="500">
            <button class="btn btn-sm btn-outline-danger" type="submit">Annuler la fiche</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="jn-card p-3 mt-3">
    <h2 class="jn-section-title">Historique</h2>
    <ul class="list-unstyled small mb-0">
      <?php foreach ($events as $ev): ?>
        <li class="mb-1"><span class="text-muted"><?= e(date_fr($ev['created_at'], true)) ?></span> — <?= e(PayslipService::LABELS[$ev['to_status']] ?? $ev['to_status']) ?>
          <?= $ev['actor_name'] ? '(' . e($ev['actor_name']) . ')' : '(automatique)' ?><?= $ev['note'] ? ' — ' . e($ev['note']) : '' ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php if (!$adminView): ?><a class="small d-inline-block mt-3" href="<?= e(url('/emplois/' . $p['employment_uuid'])) ?>">← Retour au contrat</a><?php endif; ?>
</div>
