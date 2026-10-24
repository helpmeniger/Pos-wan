<?php use Addons\Comptabilite\Compta; use Addons\Comptabilite\Plans; require __DIR__ . '/_nav.php'; $new = (bool) App\Core\Request::query('nouveau'); $o = fn($k, $d = '') => App\Core\Session::old($k, (string) ($edit[$k] ?? $d)); ?>
<div class="row g-3">
<div class="col-xl-8">
  <form method="get" class="d-flex flex-wrap gap-2 mb-2 align-items-end small">
    <div><label class="form-label mb-0">Classe</label><select name="classe" class="form-select form-select-sm" data-autosubmit><option value="">Toutes</option><?php foreach (Compta::CLASSES as $k => $l): ?><option value="<?= $k ?>" <?= $f['classe'] === (string) $k ? 'selected' : '' ?>><?= $k ?> — <?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label mb-0">Recherche</label><input name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm" placeholder="Numéro ou libellé"></div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
    <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a><a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a><?php endif; ?>
    <?php if (can('compta.admin')): ?><a class="btn btn-sm btn-success ms-auto" href="<?= e(url('/compta/comptes', ['nouveau' => 1])) ?>"><i class="bi bi-plus-lg"></i> Nouveau compte</a><?php endif; ?>
  </form>
  <div class="card"><div class="table-responsive" style="max-height:70vh"><table class="table table-sm table-hover mb-0 small cpt-table">
    <thead class="sticky-top bg-body"><tr><th>Numéro</th><th>Libellé</th><th>Tiers</th><th>Lettrable</th><th class="num">Lignes</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $c): $group = strlen($c['numero']) <= 2; $imp = isset($imputables[$c['numero']]); ?>
      <tr class="<?= $group ? 'cpt-sub' : '' ?> <?= (int) $c['actif'] ? '' : 'text-decoration-line-through text-secondary' ?>">
        <td class="cpt-mono"><?= e($c['numero']) ?></td><td><?= e($c['libelle']) ?><?= !$group && !$imp && (int) $c['actif'] ? ' <span class="badge bg-light text-secondary border" title="A des sous-comptes : imputez plutôt un sous-compte">regroupement</span>' : '' ?></td>
        <td><?= $c['tiers_type'] ? e(Compta::TIERS_TYPES[$c['tiers_type']]) : '' ?></td><td><?= (int) $c['lettrable'] ? '<i class="bi bi-check2"></i>' : '' ?></td><td class="num"><?= (int) $c['nb'] ?: '' ?></td>
        <td class="text-end text-nowrap"><?php if ((int) $c['nb']): ?><a class="btn btn-sm btn-link p-0 me-2" href="<?= e(url('/compta/lettrage', ['compte' => $c['numero']])) ?>" title="Consulter"><i class="bi bi-eye"></i></a><?php endif; ?>
          <?php if (can('compta.admin')): ?><a class="btn btn-sm btn-link p-0" href="<?= e(url('/compta/comptes', ['edit' => $c['id'], 'q' => $f['q'], 'classe' => $f['classe']])) ?>" title="Modifier"><i class="bi bi-pencil"></i></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun compte.</td></tr><?php endif; ?>
    </tbody></table></div><div class="card-footer small text-secondary"><?= count($rows) ?> compte(s). Les comptes à 2 chiffres regroupent leurs sous-comptes (balance par groupe) ; on impute les comptes de détail.</div></div>
</div>
<div class="col-xl-4">
  <?php if (can('compta.admin') && ($edit || $new)): ?>
  <form method="post" action="<?= e(url($edit ? '/compta/comptes/' . $edit['id'] : '/compta/comptes')) ?>" class="card mb-3"><div class="card-body"><?= csrf_field() ?>
    <h3 class="h6"><?= $edit ? 'Modifier le compte ' . e($edit['numero']) : 'Nouveau compte' ?></h3>
    <div class="mb-2"><label class="form-label small mb-0">Numéro</label><input name="numero" class="form-control form-control-sm cpt-mono" required pattern="[1-9][0-9]{1,19}" value="<?= e($o('numero')) ?>" placeholder="ex. 5211 (sous-compte de 521)"></div>
    <div class="mb-2"><label class="form-label small mb-0">Libellé</label><input name="libelle" class="form-control form-control-sm" required maxlength="150" value="<?= e($o('libelle')) ?>" placeholder="ex. Banque BIA Niger"></div>
    <div class="mb-2"><label class="form-label small mb-0">Compte collectif de tiers</label><select name="tiers_type" class="form-select form-select-sm"><option value="">Non</option><?php foreach (Compta::TIERS_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $o('tiers_type') === $k ? 'selected' : '' ?>><?= e($l) ?>s</option><?php endforeach; ?></select><div class="form-text">Un compte collectif demande le tiers à chaque ligne (comptes clients, fournisseurs…).</div></div>
    <div class="form-check small"><input type="hidden" name="lettrable" value="0"><input class="form-check-input" type="checkbox" name="lettrable" value="1" id="cl" <?= $o('lettrable', $edit ? '0' : '0') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="cl">Lettrable (rapprochement factures / règlements)</label></div>
    <div class="form-check small mb-2"><input type="hidden" name="actif" value="0"><input class="form-check-input" type="checkbox" name="actif" value="1" id="ca" <?= $o('actif', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="ca">Actif</label></div>
    <div class="d-flex gap-2"><button class="btn btn-sm btn-primary">Enregistrer</button><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/comptes')) ?>">Fermer</a></div>
  </div></form>
  <?php if ($edit && !(int) App\Core\DB::value('SELECT COUNT(*) FROM cpt_lignes WHERE compte_id = ?', [$edit['id']])): ?>
  <form method="post" action="<?= e(url('/compta/comptes/' . $edit['id'] . '/supprimer')) ?>" class="mb-3" data-confirm="Supprimer le compte <?= e($edit['numero']) ?> ? (possible seulement s'il n'a jamais servi)"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Supprimer ce compte</button></form>
  <?php endif; ?>
  <?php endif; ?>
  <?php if (can('compta.admin')): ?>
  <form method="post" action="<?= e(url('/compta/comptes/plan')) ?>" class="card mb-3" data-confirm="Ajouter au plan les comptes manquants du plan choisi ? Les comptes existants ne sont ni modifiés ni supprimés."><div class="card-body"><?= csrf_field() ?>
    <h3 class="h6">Compléter avec un plan proposé</h3>
    <select name="plan" class="form-select form-select-sm mb-2"><?php foreach (Plans::PLANS as $k => [$l]): if ($k === 'vide') continue; ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-outline-primary">Ajouter les comptes manquants</button>
  </div></form>
  <form method="post" action="<?= e(url('/compta/comptes/import')) ?>" enctype="multipart/form-data" class="card"><div class="card-body"><?= csrf_field() ?>
    <h3 class="h6">Importer un plan (CSV)</h3>
    <p class="small text-secondary mb-2">Une ligne par compte : <code>numéro;libellé</code> (séparateur point-virgule, virgule ou tabulation ; une ligne d'en-tête est ignorée). Les comptes existants sont conservés.</p>
    <input type="file" name="fichier" accept=".csv,.txt,text/csv,text/plain" class="form-control form-control-sm mb-2" required>
    <div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="maj" value="1" id="pm"><label class="form-check-label" for="pm">Mettre à jour le libellé des comptes existants</label></div>
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload"></i> Importer</button>
  </div></form>
  <?php endif; ?>
</div>
</div>
