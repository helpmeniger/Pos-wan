<?php $this->layout('layouts/app'); $u = $d['user']; use App\Support\Phone; use App\Services\Verification\VerificationService; ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <a class="small" href="<?= e(url('/admin/utilisateurs')) ?>">← Utilisateurs</a>
  <div class="d-flex justify-content-between flex-wrap gap-2 my-2">
    <div><h1 class="h4 mb-0"><?= e($u['first_name'] . ' ' . $u['last_name']) ?> <?= badge($u['status'], status_tone($u['status'])) ?></h1>
      <div class="small text-muted"><?= e(Phone::format($u['phone'])) ?><?= $u['phone_verified_at'] ? ' ✔' : '' ?> · <?= e($u['email'] ?? 'sans e-mail') ?> · <?= e($u['city'] ?? '') ?> · rôles : <?= e(implode(', ', $d['roles'])) ?>
        · niveau de vérification <?= (int) $u['verification_level'] ?> · inscrit le <?= e(date_fr($u['created_at'])) ?> · dernière connexion <?= e(date_fr($u['last_login_at'], true)) ?></div>
      <?php if ($u['status_reason']): ?><div class="small text-danger">Motif : <?= e($u['status_reason']) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="row g-3">
    <div class="col-lg-8">
      <?php if ($d['businesses']): ?><div class="jn-card p-3 mb-3"><h2 class="jn-section-title">Organisation</h2>
        <?php foreach ($d['businesses'] as $b): ?><div class="small"><strong><?= e($b['name']) ?></strong> (<?= e($b['kind']) ?>, <?= e($b['member_role']) ?>) — <?= badge($b['verification_status'], $b['verification_status'] === 'VERIFIEE' ? 'success' : 'warning') ?></div><?php endforeach; ?></div><?php endif; ?>
      <div class="jn-card p-3 mb-3"><h2 class="jn-section-title">Documents</h2>
        <?php if (!$d['documents']): ?><p class="small text-muted mb-0">Aucun document.</p><?php endif; ?>
        <?php foreach ($d['documents'] as $doc): ?><div class="small d-flex justify-content-between border-bottom py-1"><span><?= e(VerificationService::DOC_TYPES[$doc['doc_type']] ?? $doc['doc_type']) ?> — <?= e(date_fr($doc['submitted_at'])) ?> <?= badge($doc['status'], status_tone($doc['status'])) ?></span>
          <?php if (can('users.view_identity_docs')): ?><a href="<?= e(url('/fichiers/' . $doc['file_uuid'])) ?>" target="_blank" rel="noopener">Ouvrir</a><?php endif; ?></div><?php endforeach; ?>
        <?php if (can('users.verify')): ?><a class="small" href="<?= e(url('/admin/verifications')) ?>">File de vérification →</a><?php endif; ?></div>
      <div class="jn-card p-3 mb-3"><h2 class="jn-section-title">Contrats</h2>
        <?php if (!$d['employments']): ?><p class="small text-muted mb-0">Aucun.</p><?php endif; ?>
        <?php foreach ($d['employments'] as $e): ?><div class="small border-bottom py-1"><a href="<?= e(url('/admin/emplois/' . $e['uuid'])) ?>"><?= e($e['reference']) ?></a> — <?= e($e['position_title']) ?> (<?= $e['as_employer'] ? 'employeur de ' : 'employé de ' ?><?= e($e['other_party']) ?>) — <?= e(money((string) $e['salary_amount'])) ?> <?= badge($e['status'], status_tone($e['status'])) ?></div><?php endforeach; ?></div>
      <div class="jn-card p-3 mb-3"><h2 class="jn-section-title">Fiches de paie</h2>
        <?php if (!$d['payslips']): ?><p class="small text-muted mb-0">Aucune.</p><?php endif; ?>
        <?php foreach ($d['payslips'] as $p): ?><div class="small border-bottom py-1"><?= can('finance.dashboard.view') ? '<a href="' . e(url('/admin/fiches-de-paie/' . $p['uuid'])) . '">' . e($p['number']) . '</a>' : e($p['number']) ?> — <?= e($p['period_label']) ?> — <?= e(money((string) $p['net_amount'])) ?> <?= badge($p['status'], status_tone($p['status'])) ?></div><?php endforeach; ?>
        <?php if ($d['commission_due'] > 0): ?><div class="small mt-2 text-warning-emphasis">Commission due à JOB NIGER : <?= e(money($d['commission_due'])) ?></div><?php endif; ?></div>
      <div class="row g-3">
        <div class="col-md-6"><div class="jn-card p-3 h-100"><h2 class="jn-section-title">Offres publiées</h2><?php foreach ($d['posts'] as $p): ?><div class="small"><a href="<?= e(url('/offres/' . $p['uuid'])) ?>"><?= e($p['title']) ?></a> <?= badge($p['status'], status_tone($p['status'])) ?></div><?php endforeach; ?><?= $d['posts'] ? '' : '<p class="small text-muted mb-0">Aucune.</p>' ?></div></div>
        <div class="col-md-6"><div class="jn-card p-3 h-100"><h2 class="jn-section-title">Candidatures</h2><?php foreach ($d['applications'] as $a): ?><div class="small"><?= e($a['title']) ?> <?= badge($a['status'], status_tone($a['status'])) ?></div><?php endforeach; ?><?= $d['applications'] ? '' : '<p class="small text-muted mb-0">Aucune.</p>' ?></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <?php if (can('users.manage')): ?>
      <div class="jn-card p-3 mb-3"><h2 class="jn-section-title">Statut du compte</h2>
        <form method="post" action="<?= e(url('/admin/utilisateurs/' . $u['uuid'] . '/statut')) ?>" data-confirm="Confirmer le changement de statut ?">
          <?= csrf_field() ?>
          <select class="form-select form-select-sm mb-2" name="status" aria-label="Nouveau statut"><option value="SUSPENDED">Suspendre</option><option value="ACTIVE">Réactiver</option><option value="BANNED">Fermer définitivement</option></select>
          <input class="<?= e(field_class('reason', 'form-control form-control-sm')) ?> mb-2" name="reason" required minlength="5" maxlength="500" placeholder="Motif (communiqué à l'utilisateur)" aria-label="Motif">
          <?= $this->partial('partials/field-error', ['name' => 'reason']) ?>
          <button class="btn btn-sm btn-outline-danger w-100" type="submit">Appliquer</button>
        </form></div>
      <?php endif; ?>
      <?php if (can('users.verify') && !$u['phone_verified_at']): ?>
        <form method="post" action="<?= e(url('/admin/utilisateurs/' . $u['uuid'] . '/telephone')) ?>" class="jn-card p-3 mb-3"><?= csrf_field() ?>
          <p class="small mb-2">Téléphone non vérifié. Après appel de contrôle :</p><button class="btn btn-sm btn-outline-primary w-100" type="submit">Marquer le téléphone vérifié</button></form>
      <?php endif; ?>
      <div class="jn-card p-3"><h2 class="jn-section-title">Sécurité</h2>
        <?php foreach ($d['security'] as $s): ?><div class="small"><span class="text-muted"><?= e(date_fr($s['created_at'], true)) ?></span> <?= e($s['event_type']) ?> <?= $s['ip_address'] ? '· ' . e($s['ip_address']) : '' ?></div><?php endforeach; ?></div>
    </div>
  </div>
</div>
