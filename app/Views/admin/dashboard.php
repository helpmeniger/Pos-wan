<?php $this->layout('layouts/app');
$u = $data['users']; $f = $data['finance']; $p = $data['posts']; $m = $data['missions'];
$sum = fn (array $set, array $keys) => array_sum(array_intersect_key($set, array_flip($keys)));
$envLabels = ['INDISPONIBLE' => 'Indisponible', 'A_CONFIGURER' => 'En attente de configuration', 'TEST' => 'Mode test', 'PRODUCTION' => 'Activé en production'];
?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">Tableau de bord d'administration</h1>
    <span class="small text-muted">Rôle(s) : <?= e(implode(', ', $roles)) ?> · données réelles (comptes de démonstration exclus)</span>
  </div>

  <h2 class="jn-section-title">Utilisateurs</h2>
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Utilisateurs', 'value' => (string) (int) $u['total'], 'icon' => 'people', 'hint' => '+' . (int) $u['new_7d'] . ' sur 7 jours']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Prestataires', 'value' => (string) (int) $u['workers'], 'icon' => 'person-workspace', 'hint' => (int) $u['active_workers'] . ' actifs (30 j)']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Demandeurs', 'value' => (string) (int) $u['clients'], 'icon' => 'briefcase', 'hint' => (int) $u['active_clients'] . ' actifs (30 j)']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Organisations inscrites', 'value' => (string) $data['businesses'], 'icon' => 'building', 'hint' => (int) $u['suspended'] . ' compte(s) suspendu(s)']) ?></div>
  </div>

  <h2 class="jn-section-title">Recrutement et emploi</h2>
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/admin/offres')) ?>"><?= $this->partial('partials/kpi', ['label' => 'Offres ouvertes', 'value' => (string) (int) $kpi['open_posts'], 'icon' => 'megaphone', 'hint' => (int) $kpi['moderation_posts'] . ' à modérer']) ?></a></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/admin/candidatures')) ?>"><?= $this->partial('partials/kpi', ['label' => 'Candidatures', 'value' => (string) (int) $kpi['applications'], 'icon' => 'person-lines-fill', 'hint' => '+' . (int) $kpi['applications_7d'] . ' sur 7 jours · ' . (int) $kpi['upcoming_interviews'] . ' entretien(s) à venir']) ?></a></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/admin/emplois')) ?>"><?= $this->partial('partials/kpi', ['label' => 'Contrats actifs', 'value' => (string) (int) $kpi['active_employments'], 'icon' => 'briefcase', 'hint' => (int) $kpi['pending_employments'] . ' en attente d\'acceptation']) ?></a></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/admin/verifications')) ?>"><?= $this->partial('partials/kpi', ['label' => 'Documents à vérifier', 'value' => (string) (int) $kpi['pending_verifications'], 'icon' => 'patch-check']) ?></a></div>
  </div>
  <?php if ($canFinance): ?>
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-4"><?= $this->partial('partials/kpi', ['label' => 'Salaires confirmés reçus', 'value' => money((string) $kpi['salaries_confirmed']), 'icon' => 'cash-stack']) ?></div>
    <div class="col-6 col-lg-4"><?= $this->partial('partials/kpi', ['label' => 'Fiches de paie en cours', 'value' => (string) (int) $kpi['open_payslips'], 'icon' => 'receipt']) ?></div>
    <div class="col-6 col-lg-4"><a class="text-decoration-none text-reset" href="<?= e(url('/admin/finances?status=CONTESTEE')) ?>"><?= $this->partial('partials/kpi', ['label' => 'Paiements contestés', 'value' => (string) (int) $kpi['disputed_payslips'], 'icon' => 'exclamation-triangle', 'hint' => 'À suivre en priorité']) ?></a></div>
  </div>
  <?php endif; ?>

  <h2 class="jn-section-title">Activité (missions)</h2>
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions publiées', 'value' => (string) $sum($p, ['PUBLIEE', 'CANDIDATURES_OUVERTES', 'EN_NEGOCIATION', 'ATTRIBUEE', 'EN_COURS', 'EN_ATTENTE_DE_VALIDATION', 'TERMINEE', 'EN_LITIGE']), 'icon' => 'megaphone', 'hint' => (int) ($p['EN_MODERATION'] ?? 0) . ' en modération']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions en cours', 'value' => (string) $sum($m, ['ATTRIBUEE', 'EN_COURS', 'EN_ATTENTE_DE_VALIDATION']), 'icon' => 'hourglass-split']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions terminées', 'value' => (string) ($m['TERMINEE'] ?? 0), 'icon' => 'check2-circle', 'hint' => (int) ($m['ANNULEE'] ?? 0) . ' annulée(s)']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Litiges ouverts', 'value' => (string) $data['openDisputes'], 'icon' => 'exclamation-diamond', 'hint' => $data['openReports'] . ' signalement(s), ' . $data['pendingVerifications'] . ' vérification(s), ' . $data['openTickets'] . ' ticket(s)']) ?></div>
  </div>

  <?php if ($canFinance): ?>
    <h2 class="jn-section-title">Finances</h2>
    <div class="row g-3 mb-2">
      <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Paiements clients confirmés', 'value' => money($f['confirmed_payments']), 'icon' => 'cash-coin', 'hint' => 'Appartient majoritairement aux prestataires']) ?></div>
      <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Commissions acquises', 'value' => money($f['commissions_earned']), 'icon' => 'graph-up-arrow', 'hint' => 'En attente : ' . money($f['commissions_deferred']) . ' · à recevoir : ' . money($f['commissions_receivable'])]) ?></div>
      <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Revenu net estimé', 'value' => money($f['net_revenue_estimate']), 'icon' => 'piggy-bank', 'hint' => 'Après frais de paiement (' . money($f['gateway_fees']) . ')']) ?></div>
      <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Dû aux prestataires', 'value' => money($f['due_to_workers']), 'icon' => 'people', 'hint' => 'Déjà reversé : ' . money($f['paid_out'])]) ?></div>
    </div>
    <p class="small text-muted mb-4">Transactions en attente : <?= (int) $f['pending_count'] ?> · échouées : <?= (int) $f['failed_count'] ?> · contestées : <?= (int) $f['disputed_count'] ?>.
      Une somme en attente n'est jamais comptée comme revenu.</p>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="jn-card p-3 h-100">
        <h2 class="jn-section-title">Moyens de paiement</h2>
        <ul class="list-group list-group-flush">
          <?php foreach ($data['gateways'] as $g): ?>
            <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
              <span class="small"><?= e($g['name']) ?></span>
              <span class="badge badge-env-<?= e($g['environment']) ?>"><?= e($envLabels[$g['environment']] ?? $g['environment']) ?><?= $g['is_active'] ? '' : ' · inactif' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php if ($canAudit): ?>
    <div class="col-lg-6">
      <div class="jn-card p-3 h-100">
        <h2 class="jn-section-title">Derniers événements de sécurité</h2>
        <?php if (!$data['recentSecurity']): ?>
          <p class="small text-muted mb-0">Aucun avertissement récent.</p>
        <?php else: ?>
          <ul class="list-group list-group-flush small">
            <?php foreach ($data['recentSecurity'] as $s): ?>
              <li class="list-group-item px-0 d-flex justify-content-between gap-2">
                <span><span class="badge <?= $s['severity'] === 'CRITIQUE' ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= e($s['severity']) ?></span> <?= e($s['event_type']) ?></span>
                <span class="text-muted"><?= e($s['ip_address'] ?? '') ?> · <?= e(date_fr($s['created_at'], true)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
