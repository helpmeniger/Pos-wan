<?php /** Modèles de documents : choix par document, modèles enregistrés, mises en page disponibles. */
use Addons\Modeles\Modeles as M;
$label = fn(array $m) => M::BASES[$m['type']][$m['base']][0] ?? $m['base'];
$usedFor = function (int $id) use ($aff, $autres): array {
    $u = [];
    foreach ($aff as $k => $v) { if ($v === $id) { $u[] = M::DOCUMENTS[$k][1]; } }
    foreach ($autres as $k => $v) { if ($v === $id) { $u[] = M::AUTRES[$k][0]; } }
    return $u;
};
$coreTicket = App\Services\Documents::TICKET_TEMPLATES[(string) setting('ticket_template', '1')] ?? '';
$coreA4 = App\Services\Documents::A4_TEMPLATES[(string) setting('invoice_template', '1')] ?? '';
?>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Choisissez ici la mise en page de chaque document. Un document sans modèle garde celui des <a href="<?= e(url('/settings')) ?>">Paramètres</a>.
  Les modèles changent seulement la <b>présentation</b> : montants, articles et numéros viennent toujours de la vente enregistrée. L'impression directe sur imprimante thermique réseau (ESC/POS) garde son format propre.</div>

<div class="row g-3">
<div class="col-lg-6">
  <form method="post" action="<?= e(url('/modeles/affectations')) ?>" class="card h-100"><?= csrf_field() ?>
    <div class="card-header fw-semibold"><i class="bi bi-diagram-3"></i> Modèle utilisé pour chaque document</div>
    <div class="card-body">
      <?php foreach (M::DOCUMENTS as $k => [$type, $lbl, $icon]): ?>
        <div class="row g-2 align-items-center mb-2">
          <label class="col-sm-5 col-form-label" for="doc_<?= e($k) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($lbl) ?></label>
          <div class="col-sm-7"><select class="form-select form-select-sm" name="doc[<?= e($k) ?>]" id="doc_<?= e($k) ?>">
            <option value="0"><?= e($k === 'credit' ? 'Comme le ticket de caisse' : 'Modèle des Paramètres (' . ($type === 'a4' ? $coreA4 : $coreTicket) . ')') ?></option>
            <?php foreach ($byType[$type] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= ($aff[$k] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nom']) ?></option><?php endforeach; ?>
          </select></div>
        </div>
      <?php endforeach; ?>
      <?php if ($autresDispo): ?>
        <hr><div class="fw-semibold small mb-1">Tickets et reçus des autres pages</div>
        <div class="small text-secondary mb-2">Leur contenu reste celui de chaque module ; le modèle choisi règle la police, la taille du texte, les séparateurs (tickets) et la couleur (A4).</div>
        <?php foreach ($autresDispo as $k => [$lbl]): ?>
          <div class="row g-2 align-items-center mb-2">
            <label class="col-sm-5 col-form-label small" for="aut_<?= e(md5($k)) ?>"><?= e($lbl) ?></label>
            <div class="col-sm-7"><select class="form-select form-select-sm" name="autre[<?= e($k) ?>]" id="aut_<?= e(md5($k)) ?>">
              <option value="0">Présentation habituelle</option>
              <?php foreach (['ticket' => 'Tickets', 'a4' => 'A4'] as $t => $tl): ?><optgroup label="<?= e($tl) ?>"><?php foreach ($byType[$t] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= ($autres[$k] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nom']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
            </select></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer les choix</button></div>
  </form>
</div>

<div class="col-lg-6">
  <div class="card h-100">
    <div class="card-header fw-semibold d-flex align-items-center"><span><i class="bi bi-collection"></i> Vos modèles (<?= count($models) ?>)</span></div>
    <div class="list-group list-group-flush">
      <?php foreach ($models as $m): $u = $usedFor((int) $m['id']); ?>
        <div class="list-group-item">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge <?= $m['type'] === 'a4' ? 'text-bg-primary' : 'text-bg-dark' ?>"><?= $m['type'] === 'a4' ? 'A4' : 'Ticket' ?></span>
            <a class="fw-semibold" href="<?= e(url('/modeles/' . $m['id'])) ?>"><?= e($m['nom']) ?></a>
            <span class="small text-secondary"><?= e($label($m)) ?></span>
            <div class="ms-auto d-flex gap-1">
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/modeles/' . $m['id'])) ?>" title="Personnaliser"><i class="bi bi-sliders"></i></a>
              <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(url('/modeles/apercu/' . $m['id'])) ?>" title="Aperçu"><i class="bi bi-eye"></i></a>
              <form method="post" action="<?= e(url('/modeles/' . $m['id'] . '/dupliquer')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" title="Dupliquer"><i class="bi bi-copy"></i></button></form>
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/modeles/' . $m['id'] . '/exporter')) ?>" title="Exporter (fichier à partager)"><i class="bi bi-download"></i></a>
              <form method="post" action="<?= e(url('/modeles/' . $m['id'] . '/supprimer')) ?>" data-confirm="Supprimer le modèle « <?= e($m['nom']) ?> » ?<?= $u ? ' Les documents qui l\'utilisent reprendront le modèle des Paramètres.' : '' ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button></form>
            </div>
          </div>
          <?php if ($u): ?><div class="small mt-1 text-success"><i class="bi bi-check2-circle"></i> Utilisé pour : <?= e(implode(', ', $u)) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$models): ?><div class="list-group-item text-secondary">Aucun modèle : créez-en un ci-dessous.</div><?php endif; ?>
    </div>
    <div class="card-footer">
      <form method="post" action="<?= e(url('/modeles/importer')) ?>" enctype="multipart/form-data" class="d-flex gap-2 align-items-center"><?= csrf_field() ?>
        <input type="file" name="fichier" accept=".json,application/json" class="form-control form-control-sm" required>
        <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload"></i> Importer un modèle</button>
      </form>
    </div>
  </div>
</div>
</div>

<h2 class="h6 mt-4">Créer un modèle à partir d'une mise en page</h2>
<div class="row g-2">
  <?php foreach (M::BASES as $type => $bases): foreach ($bases as $b => [$bl, $bd]): ?>
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body py-2">
      <div class="d-flex align-items-center gap-2"><span class="badge <?= $type === 'a4' ? 'text-bg-primary' : 'text-bg-dark' ?>"><?= $type === 'a4' ? 'A4' : 'Ticket' ?></span><b><?= e($bl) ?></b>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(url('/modeles/nouveau', ['type' => $type, 'base' => $b])) ?>"><i class="bi bi-plus-lg"></i> Créer</a></div>
      <div class="small text-secondary mt-1"><?= e($bd) ?></div>
    </div></div></div>
  <?php endforeach; endforeach; ?>
</div>
