<?php $proof = json_decode((string) ($c['proof_json'] ?? ''), true); ?>
<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<div class="row g-3"><div class="col-lg-6"><div class="card"><div class="card-body small">
  <h2 class="h6"><?= e(t($c['doc_type'] === 'invoice' ? 'Facture' : 'Avoir')) ?> <?= e($c['local_number']) ?> <?= App\Core\View::partial('fiscal::_status', ['s' => $c['status'], 'sim' => (int) $c['is_simulation']]) ?></h2>
  <table class="table table-sm mb-2">
    <tr><th><?= e(t('Numéro de certification')) ?></th><td><code><?= e($c['fiscal_number'] ?? '—') ?></code></td></tr>
    <tr><th><?= e(t('Identifiant du dispositif')) ?></th><td><?= e($c['device_id'] ?? '—') ?></td></tr>
    <tr><th><?= e(t('Signature')) ?></th><td class="text-break"><code><?= e($c['signature'] ?? '—') ?></code></td></tr>
    <tr><th><?= e(t('Code QR officiel')) ?></th><td class="text-break"><code><?= e($c['qr_payload'] ?? '—') ?></code></td></tr>
    <tr><th><?= e(t('Compteurs')) ?></th><td><?= e($c['counters'] ?? '—') ?></td></tr>
    <tr><th><?= e(t('Certifiée le')) ?></th><td><?= e(fdate($c['certified_at']) ?: '—') ?></td></tr>
    <tr><th><?= e(t('Environnement')) ?></th><td><?= $c['environment'] === 'prod' ? 'PRODUCTION' : 'TEST' ?> · <?= e($c['profile'] ?? '') ?> · <?= e($c['connector'] ?? '') ?></td></tr>
    <tr><th><?= e(t('Clé d\'idempotence')) ?></th><td><code class="small"><?= e($c['idempotency_key']) ?></code></td></tr>
    <tr><th><?= e(t('Empreinte des données envoyées')) ?></th><td><code class="small"><?= e($c['payload_hash'] ?: '—') ?></code></td></tr>
    <tr><th><?= e(t('Empreinte de la réponse')) ?></th><td><code class="small"><?= e($c['response_hash'] ?? '—') ?></code></td></tr>
    <tr><th><?= e(t('Essais')) ?></th><td><?= (int) $c['attempts'] ?> <?= $c['last_error'] ? '· <span class="text-danger">' . e($c['last_error']) . '</span>' : '' ?></td></tr>
  </table>
  <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/sales/' . $c['sale_id'])) ?>"><?= e(t('Voir la vente')) ?></a>
  <?php if (can('fiscal.queue') && in_array($c['status'], ['pending', 'failed'], true) && !($c['doc_type'] === 'invoice' && $c['sale_status'] === 'cancelled')): ?><form method="post" action="<?= e(url('/fiscal/certifications/' . $c['id'] . '/relancer')) ?>"><?= csrf_field() ?><input type="hidden" name="back" value="detail"><button class="btn btn-sm btn-primary"><?= e(t('Relancer')) ?></button></form><?php endif; ?></div>
  <?php if ($c['status'] === 'certified'): ?><p class="text-secondary mt-2 mb-0"><i class="bi bi-lock"></i> <?= e(t('Document certifié : ces données ne sont plus modifiables. Toute correction passe par un avoir certifié.')) ?></p><?php endif; ?>
</div></div></div>
<div class="col-lg-6"><?php if ($proof): ?><div class="card mb-3"><div class="card-header fw-semibold"><?= e(t('Preuve conservée (secrets masqués)')) ?></div><div class="card-body"><pre class="small mb-0" style="max-height:320px;overflow:auto;white-space:pre-wrap"><?= e(json_encode($proof, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre></div></div><?php endif; ?>
<?php if ($logs): ?><div class="card"><div class="card-header fw-semibold"><?= e(t('Échanges avec le dispositif')) ?></div><ul class="list-group list-group-flush small"><?php foreach ($logs as $l): ?><li class="list-group-item"><a href="<?= e(url('/fiscal/journaux/' . $l['id'])) ?>"><?= e(fdate($l['created_at'])) ?></a> · <code><?= e($l['action']) ?></code> → <b><?= e($l['result_code']) ?></b> <?= $l['http_status'] ? 'HTTP ' . (int) $l['http_status'] : '' ?> <?= $l['duration_ms'] !== null ? (int) $l['duration_ms'] . ' ms' : '' ?> <span class="text-secondary"><?= e($l['message'] ?? '') ?></span></li><?php endforeach; ?></ul></div><?php endif; ?></div></div>
