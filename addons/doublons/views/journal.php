<?php /** Journal des fusions de doublons. */ ?>
<div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/doublons')) ?>"><i class="bi bi-arrow-left"></i> Doublons</a></div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Date</th><th>Article conservé</th><th>Doublon fusionné</th><th class="text-end">Lignes transférées</th><th>Résultat</th><th>Par</th></tr></thead><tbody>
  <?php foreach ($p['rows'] as $r): ?>
    <tr><td class="small text-nowrap"><?= e(fdate($r['created_at'])) ?></td>
      <td><a href="<?= e(url('/products/' . $r['kept_id'])) ?>"><?= e($r['kept_name']) ?></a> <span class="small text-secondary">#<?= (int) $r['kept_id'] ?></span></td>
      <td><?= e($r['removed_name']) ?><div class="small text-secondary">#<?= (int) $r['removed_id'] ?> · <?= e((string) ($r['removed_ref'] ?? '')) ?><?= $r['removed_barcode'] ? ' · ' . e($r['removed_barcode']) : '' ?></div></td>
      <td class="text-end"><?= (int) $r['moved'] ?></td>
      <td><?= (int) $r['deleted'] ? '<span class="badge text-bg-success">supprimé</span>' : '<span class="badge text-bg-warning">archivé</span>' ?></td>
      <td class="small"><?= e((string) ($r['full_name'] ?? '')) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucune fusion pour le moment.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
<p class="small text-secondary mt-2">Chaque ligne garde une copie complète de la fiche supprimée (prix, code-barres, référence…) et la liste des lignes regroupées : en cas d'erreur, l'article peut être recréé à l'identique, ou la sauvegarde faite avant la fusion restaurée.</p>
