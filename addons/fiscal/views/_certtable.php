<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
<thead><tr><th><?= e(t('Document')) ?></th><th><?= e(t('Statut')) ?></th><th><?= e(t('Numéro fiscal')) ?></th><th><?= e(t('Connecteur')) ?></th><th class="text-end"><?= e(t('Montant')) ?></th><th><?= e(t('Créée')) ?></th><th><?= e(t('Essais')) ?></th><th><?= e(t('Information')) ?></th><?php if ($actions): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($p['rows'] as $r): ?><tr>
  <td><a href="<?= e(url('/fiscal/certifications/' . $r['id'])) ?>"><?= e($r['local_number']) ?></a> <span class="small text-secondary"><?= e(t($r['doc_type'] === 'invoice' ? 'facture' : 'avoir')) ?></span></td>
  <td><?= App\Core\View::partial('fiscal::_status', ['s' => $r['status'], 'sim' => (int) $r['is_simulation']]) ?></td><td><code><?= e($r['fiscal_number'] ?? '') ?></code></td>
  <td class="small"><?= e($r['connector'] ?? '') ?></td><td class="text-end"><?= $r['doc_type'] === 'invoice' ? e(money($r['total'] ?? 0)) : '' ?></td><td class="small"><?= e(fdate($r['created_at'])) ?></td><td><?= (int) $r['attempts'] ?></td>
  <td class="small text-secondary"><?= e($r['last_error'] ?? '') ?><?= $r['next_attempt_at'] ? ' · ' . e(t('prochain essai')) . ' ' . e(fdate($r['next_attempt_at'])) : '' ?></td>
  <?php if ($actions): ?><td><?php if (can('fiscal.queue') && in_array($r['status'], ['pending', 'failed'], true) && !($r['doc_type'] === 'invoice' && $r['sale_status'] === 'cancelled')): ?><form method="post" action="<?= e(url('/fiscal/certifications/' . $r['id'] . '/relancer')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><?= e(t('Relancer')) ?></button></form><?php endif; ?></td><?php endif; ?></tr>
<?php endforeach; ?><?php if (!$p['rows']): ?><tr><td colspan="9" class="small text-secondary"><?= e(t('Aucun document.')) ?></td></tr><?php endif; ?></tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
