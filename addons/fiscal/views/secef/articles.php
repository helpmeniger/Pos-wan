<?php
use Addons\Fiscal\Secef\Secef;
/** @var array $rows @var array $categories @var array $portail @var array $cfg @var array $stats */
$groupes = ['' => '— règle par défaut —'] + array_combine(array_keys(Secef::GROUPES), array_map(fn($k, $v) => "[$k] $v", array_keys(Secef::GROUPES), Secef::GROUPES));
$opt = fn(array $o, ?string $cur) => implode('', array_map(fn($k, $v) => '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $cur ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($o), $o));
$statuts = ['a_programmer' => 'à programmer', 'programme' => 'programmé sur l\'e-SFE', 'ignore' => 'ne jamais certifier'];
?>
<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= e(url('/fiscal/secef')) ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <h2 class="h5 mb-0">Articles POS Marwa ↔ articles programmés sur l'e-SFE</h2>
  <span class="small text-secondary"><?= (int) ($stats['programmes'] ?? 0) ?> / <?= (int) $stats['articles'] ?> programmés · <?= (int) ($stats['portail'] ?? 0) ?> relevés sur le portail<?= !empty($stats['releve']) ? ' le ' . e(fdate($stats['releve'])) : '' ?></span>
  <form method="post" action="<?= e(url('/fiscal/secef/articles/releve')) ?>" class="ms-auto"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-arrow-repeat"></i> Relever les articles de l'e-SFE</button></form>
</div>
<div class="alert alert-info small">Sur l'e-SFE, une facture ne peut contenir que des <b>articles programmés</b> (Articles → Programmer un article). Le relevé (lecture seule)
  rapproche automatiquement les articles dont la désignation est identique. Un article absent peut être programmé d'ici (bouton <i class="bi bi-cloud-upload"></i> :
  désignation, code = référence POS, groupe, prix) — c'est une écriture sur le portail, faite seulement à votre demande, ou automatiquement en caisse si l'option est activée.
  Le stock reste géré par POS Marwa (l'option « Stock » de l'e-SFE n'est pas activée par le pont).</div>

<form class="row g-2 mb-2" method="get"><div class="col-md-5"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Rechercher un article"></div>
  <div class="col-md-3"><select class="form-select form-select-sm" name="filtre"><?= $opt(['' => 'Tous', 'a_programmer' => 'Non programmés', 'programme' => 'Programmés'], $filtre) ?></select></div>
  <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Filtrer</button></div></form>

<div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<thead><tr><th>Article POS Marwa</th><th class="text-end">Prix</th><th>Désignation sur l'e-SFE</th><th>Groupe</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><div class="fw-semibold"><?= e($r['name']) ?></div><div class="small text-secondary"><?= e($r['reference']) ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?> · TVA <?= e(rtrim(rtrim(number_format((float) $r['tax_rate'], 2, '.', ''), '0'), '.')) ?> %</div></td>
  <td class="text-end"><?= e(money((float) $r['sale_price'])) ?><?= $r['prix_portail'] !== null && abs((float) $r['prix_portail'] - (float) $r['sale_price']) > 0.009 ? '<div class="small text-warning">e-SFE : ' . e(money((float) $r['prix_portail'])) . '</div>' : '' ?></td>
  <td colspan="3">
    <form method="post" action="<?= e(url('/fiscal/secef/articles')) ?>" class="row g-1 align-items-center"><?= csrf_field() ?>
      <input type="hidden" name="product_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="filtre" value="<?= e($filtre) ?>">
      <div class="col-md-5"><input class="form-control form-control-sm" name="designation" value="<?= e($r['designation'] ?? $r['name']) ?>" maxlength="200" list="portailList"></div>
      <div class="col-md-3"><select class="form-select form-select-sm" name="groupe"><?= $opt($groupes, $r['groupe']) ?></select>
        <div class="small <?= $r['groupe_effectif'] ? 'text-secondary' : 'text-danger' ?>">appliqué : <?= $r['groupe_effectif'] ? e($r['groupe_effectif']) . ' (' . e($r['source']) . ')' : 'aucun groupe' ?></div>
        <?php if ($r['alerte']): ?><div class="small text-warning"><i class="bi bi-exclamation-triangle"></i> <?= e($r['alerte']) ?></div><?php endif; ?></div>
      <div class="col-md-3"><select class="form-select form-select-sm" name="statut"><?= $opt($statuts, $r['statut'] ?? 'a_programmer') ?></select>
        <?php if ($r['derniere_erreur']): ?><div class="small text-danger"><?= e($r['derniere_erreur']) ?></div><?php endif; ?></div>
      <div class="col-md-1"><button class="btn btn-sm btn-outline-primary" title="Enregistrer"><i class="bi bi-check2"></i></button></div>
    </form>
  </td>
  <td class="text-end"><?php if (($r['statut'] ?? '') !== 'programme' && ($r['statut'] ?? '') !== 'ignore'): ?>
    <form method="post" action="<?= e(url('/fiscal/secef/articles/' . (int) $r['id'] . '/programmer')) ?>" data-confirm="Programmer « <?= e($r['designation'] ?? $r['name']) ?> » sur l'e-SFE (groupe <?= e((string) $r['groupe_effectif']) ?>, prix <?= e(money((float) $r['sale_price'])) ?>) ? C'est une écriture sur le portail de la DGI."><?= csrf_field() ?>
      <button class="btn btn-sm btn-outline-success" title="Programmer sur l'e-SFE" <?= $r['groupe_effectif'] ? '' : 'disabled' ?>><i class="bi bi-cloud-upload"></i></button></form><?php endif; ?></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-secondary small">Aucun article.</td></tr><?php endif; ?>
</tbody></table></div></div>
<datalist id="portailList"><?php foreach ($portail as $p): ?><option value="<?= e($p['designation']) ?>"><?= e(($p['groupe'] ? '[' . $p['groupe'] . '] ' : '') . ($p['prix_ttc'] !== null ? money((float) $p['prix_ttc']) : '')) ?></option><?php endforeach; ?></datalist>

<div class="row g-3">
<div class="col-lg-6"><form method="post" action="<?= e(url('/fiscal/secef/categories')) ?>" class="card"><?= csrf_field() ?>
  <div class="card-header fw-semibold">Groupe de taxation par catégorie</div>
  <div class="card-body" style="max-height:420px;overflow:auto"><table class="table table-sm mb-0"><tbody>
  <?php foreach ($categories as $c): ?><tr><td><?= e($c['name']) ?></td><td style="width:55%"><select class="form-select form-select-sm" name="groupes[<?= (int) $c['id'] ?>]"><?= $opt($groupes, $c['groupe']) ?></select></td></tr><?php endforeach; ?>
  </tbody></table></div><div class="card-footer text-end"><button class="btn btn-sm btn-primary">Enregistrer</button></div></form></div>
<div class="col-lg-6"><div class="card"><div class="card-header fw-semibold">Articles relevés sur l'e-SFE</div>
  <div class="card-body p-0" style="max-height:470px;overflow:auto"><table class="table table-sm mb-0"><thead><tr><th>Désignation</th><th>Groupe</th><th class="text-end">Prix TTC</th></tr></thead><tbody>
  <?php foreach ($portail as $p): ?><tr><td class="small"><?= e($p['designation']) ?></td><td><?= e((string) $p['groupe']) ?></td><td class="text-end small"><?= $p['prix_ttc'] !== null ? e(money((float) $p['prix_ttc'])) : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$portail): ?><tr><td colspan="3" class="small text-secondary p-3">Aucun relevé : cliquez sur « Relever les articles de l'e-SFE ».</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
</div>
