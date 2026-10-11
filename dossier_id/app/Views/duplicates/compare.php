<?php use App\Support\Labels; ?>
<div class="page-header"><h1><i class="bi bi-layout-split"></i> Comparaison visuelle</h1>
    <?php if (can('duplicates.review')): ?><a class="btn btn-outline-secondary" href="<?= e(url('duplicates.index')) ?>">Doublons potentiels</a><?php endif; ?></div>

<div class="card mb-3"><div class="card-body">
<form method="get" action="<?= e(url('duplicates.compare')) ?>" class="row g-2 align-items-end">
    <input type="hidden" name="r" value="duplicates.compare">
    <div class="col-md-4"><label class="form-label small" for="a_number">N° de dossier A</label>
        <input class="form-control" id="a_number" name="a_number" value="<?= e($a['file_number'] ?? '') ?>" placeholder="DID-2026-000001" required></div>
    <div class="col-md-4"><label class="form-label small" for="b_number">N° de dossier B</label>
        <input class="form-control" id="b_number" name="b_number" value="<?= e($b['file_number'] ?? '') ?>" placeholder="DID-2026-000002" required></div>
    <div class="col-md-4"><button class="btn btn-primary"><i class="bi bi-layout-split"></i> Comparer</button></div>
</form>
</div></div>

<?php if ($a && $b): ?>
<div class="alert alert-warning small">
    <i class="bi bi-eye"></i> <strong>Comparaison effectuée par l'agent.</strong> Le système n'analyse pas les visages et ne propose aucun score de ressemblance.
    Une ressemblance visuelle ne suffit pas à établir l'identité : confrontez les pièces d'identité, les dates et les informations déclarées.
</div>

<div class="compare-tools card mb-3 d-print-none"><div class="card-body d-flex flex-wrap gap-4 align-items-center">
    <label class="d-flex align-items-center gap-2 small">Zoom synchronisé
        <input type="range" class="form-range compare-zoom" min="1" max="3" step="0.1" value="1" data-compare-zoom></label>
    <div class="form-check form-switch small"><input class="form-check-input" type="checkbox" id="gray" data-compare-gray>
        <label class="form-check-label" for="gray">Niveaux de gris</label></div>
    <div class="form-check form-switch small"><input class="form-check-input" type="checkbox" id="grid" data-compare-grid>
        <label class="form-check-label" for="grid">Grille de repères</label></div>
    <span class="small text-muted">Faites glisser une image zoomée pour la déplacer ; les deux images suivent le même mouvement.</span>
</div></div>

<div class="row g-3 compare-grid">
<?php foreach ([['A', $a, $a_photos], ['B', $b, $b_photos]] as [$side, $p, $photos]): ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between">
                <span><strong><?= $side ?></strong> — <a href="<?= e(url('persons.show', ['id' => $p['id']])) ?>"><?= e($p['file_number']) ?></a></span>
                <?php if ($p['status'] === 'archive'): ?><span class="badge text-bg-secondary">Archivée</span><?php endif; ?>
            </div>
            <div class="compare-frame" data-compare-frame>
                <?php if ($photos): ?>
                    <img src="<?= e(url('photos.show', ['id' => $photos[0]['id']])) ?>" alt="Photographie fiche <?= $side ?>" data-compare-img draggable="false">
                    <div class="compare-overlay" aria-hidden="true"></div>
                <?php else: ?>
                    <div class="text-muted text-center py-5"><i class="bi bi-person fs-1"></i><br>Aucune photographie</div>
                <?php endif; ?>
            </div>
            <?php if (count($photos) > 1): ?>
            <div class="d-flex gap-1 p-2 flex-wrap">
                <?php foreach ($photos as $ph): ?>
                    <button type="button" class="btn p-0 border compare-thumb" data-compare-select="<?= e(url('photos.show', ['id' => $ph['id']])) ?>">
                        <img src="<?= e(url('photos.show', ['id' => $ph['id']])) ?>" alt="Miniature"></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="card-body small">
                <?php if ($photos): ?><div class="text-muted mb-2">Photographie du <?= e(fmt_date($photos[0]['taken_at'] ?: $photos[0]['uploaded_at'])) ?></div><?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="card my-3">
    <div class="card-header">Informations administratives</div>
    <div class="table-responsive"><table class="table table-sm mb-0 compare-table">
        <thead><tr><th>Champ</th><th>Fiche A</th><th>Fiche B</th><th></th></tr></thead>
        <tbody>
        <?php foreach ([
            'Nom' => 'last_name', 'Prénom(s)' => 'first_name', 'Autres noms' => 'other_names', 'Date de naissance' => 'birth_date',
            'Lieu de naissance' => 'birth_place', 'Nationalité' => 'nationality', 'Identifiant' => 'admin_id_number',
            'Service créateur' => 'registering_service', "Date d'enregistrement" => 'registration_date',
        ] as $label => $k):
            $va = (string) ($a[$k] ?? ''); $vb = (string) ($b[$k] ?? '');
            $same = $va !== '' && mb_strtolower($va) === mb_strtolower($vb);
            $fmt = fn ($v) => in_array($k, ['birth_date', 'registration_date'], true) ? fmt_date($v) : ($v !== '' ? $v : '—'); ?>
            <tr class="<?= $same ? 'table-success' : ($va !== '' && $vb !== '' ? 'table-light' : '') ?>">
                <th class="fw-normal text-muted"><?= e($label) ?></th><td><?= e($fmt($va)) ?></td><td><?= e($fmt($vb)) ?></td>
                <td class="small"><?= $same ? 'identique' : ($va !== '' && $vb !== '' ? 'différent' : '') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="card-footer small">
        Indice administratif : <strong><?= e(ucfirst($match['level'])) ?></strong>
        <?= $match['reasons'] ? '(' . e(implode(', ', $match['reasons'])) . ')' : '(aucune concordance notable)' ?>
    </div>
</div>

<?php if ($review): ?>
    <div class="alert alert-secondary">Décision enregistrée le <?= e(fmt_date($review['reviewed_at'], true)) ?> par <?= e($review['full_name'] ?? '—') ?> :
        <strong><?= $review['decision'] === 'distinctes' ? 'personnes distinctes' : 'doublon confirmé' ?></strong> — <?= e($review['comment']) ?></div>
<?php endif; ?>

<?php if (can('duplicates.review')): ?>
<div class="card mb-4"><div class="card-header">Décision de l'agent</div><div class="card-body">
<form method="post" action="<?= e(url('duplicates.review', ['a' => $a['id'], 'b' => $b['id']])) ?>" class="row g-2">
    <?= csrf_field() ?>
    <div class="col-md-4">
        <div class="form-check"><input class="form-check-input" type="radio" name="decision" id="d1" value="distinctes" required>
            <label class="form-check-label" for="d1">Personnes distinctes</label></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="decision" id="d2" value="doublon_confirme">
            <label class="form-check-label" for="d2">Même personne (doublon confirmé)</label></div>
    </div>
    <div class="col-md-8"><label class="form-label small required" for="comment">Vérifications effectuées</label>
        <textarea class="form-control" id="comment" name="comment" rows="2" maxlength="500" required
                  placeholder="ex. : pièces d'identité comparées (CNI n° …), dates de naissance différentes…"></textarea></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer la décision</button></div>
</form>
</div></div>
<?php endif; ?>
<?php endif; ?>
