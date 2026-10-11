<?php
use App\Support\Labels;
$tab = in_array($_GET['tab'] ?? '', ['photos', 'visits', 'procedures', 'documents', 'trace'], true) ? $_GET['tab'] : 'identity';
$archived = $p['status'] === 'archive';
$editable = !$archived;
$primary = $photos[0] ?? null;
?>
<div class="page-header">
    <div class="d-flex align-items-center gap-3">
        <div class="avatar-lg">
            <?php if ($primary): ?>
                <img src="<?= e(url('photos.show', ['id' => $primary['id']])) ?>" alt="Photographie de référence">
            <?php else: ?><i class="bi bi-person"></i><?php endif; ?>
        </div>
        <div>
            <h1 class="mb-0"><span class="text-uppercase"><?= e($p['last_name']) ?></span> <?= e($p['first_name']) ?></h1>
            <div class="text-muted">Dossier <strong><?= e($p['file_number']) ?></strong>
                <?php if ($archived): ?><span class="badge text-bg-secondary ms-1">Archivée</span><?php endif; ?>
                <?php if ($p['access_level'] === 'restreint'): ?><span class="badge text-bg-warning ms-1">Accès restreint</span><?php endif; ?>
                <?php if ($p['is_demo']): ?><span class="badge badge-demo ms-1">Données fictives</span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('persons.print', ['id' => $p['id']])) ?>"><i class="bi bi-printer"></i> Imprimer</a>
        <?php if (can('persons.edit') && $editable): ?>
            <a class="btn btn-outline-primary" href="<?= e(url('persons.edit', ['id' => $p['id']])) ?>"><i class="bi bi-pencil"></i> Rectifier</a>
        <?php endif; ?>
        <?php if (can('persons.archive') && !$archived): ?>
            <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#archiveModal"><i class="bi bi-archive"></i> Archiver</button>
        <?php elseif (can('persons.archive') && $archived): ?>
            <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#archiveModal"><i class="bi bi-arrow-counterclockwise"></i> Désarchiver</button>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info small notice-presumption">
    <i class="bi bi-info-circle"></i> Une mention dans ce dossier ne constitue pas une preuve de culpabilité. Seule une décision judiciaire documentée
    et définitive établit une condamnation. Les informations doivent être vérifiées avant toute décision.
</div>
<?php if ($archived): ?>
    <div class="alert alert-secondary">Fiche archivée le <?= e(fmt_date($p['archived_at'], true)) ?> — motif : <?= e($p['archive_reason']) ?>. Consultation exceptionnelle uniquement ; aucune modification possible.</div>
<?php endif; ?>
<?php if ($duplicates): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people"></i> <?= count($duplicates) ?> fiche(s) présentent des informations proches (doublon potentiel).</span>
        <a class="btn btn-sm btn-warning" href="<?= e(url('duplicates.compare', ['a' => $p['id'], 'b' => $duplicates[0]['person']['id']])) ?>">Comparer</a>
    </div>
<?php endif; ?>

<ul class="nav nav-tabs" role="tablist">
    <?php foreach ([
        'identity' => ['Identité', true, null],
        'photos' => ['Photographies', can('photos.view'), count($photos)],
        'visits' => ['Passages', can('visits.view'), count($visits)],
        'procedures' => ['Procédures', can('procedures.view'), count($procedures)],
        'documents' => ['Documents', can('documents.view'), count($documents)],
        'trace' => ['Traçabilité', true, null],
    ] as $k => [$label, $show, $count]): if (!$show) continue; ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $tab === $k ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $k ?>" type="button" role="tab">
                <?= e($label) ?><?php if ($count !== null): ?> <span class="badge text-bg-light"><?= (int) $count ?></span><?php endif; ?>
            </button>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card card-tabbed mb-4"><div class="card-body tab-content">

<!-- Identité -->
<div class="tab-pane fade<?= $tab === 'identity' ? ' show active' : '' ?>" id="tab-identity" role="tabpanel">
    <div class="row">
        <div class="col-lg-6"><dl class="row dl-fiche">
            <dt class="col-sm-5">Nom</dt><dd class="col-sm-7 text-uppercase"><?= e($p['last_name']) ?></dd>
            <dt class="col-sm-5">Prénom(s)</dt><dd class="col-sm-7"><?= e($p['first_name']) ?></dd>
            <dt class="col-sm-5">Autres noms déclarés</dt><dd class="col-sm-7"><?= e($p['other_names'] ?: '—') ?></dd>
            <dt class="col-sm-5">Date de naissance</dt><dd class="col-sm-7"><?= e(fmt_date($p['birth_date'])) ?> <span class="text-muted">(<?= e(person_age($p)) ?>)</span></dd>
            <dt class="col-sm-5">Lieu de naissance</dt><dd class="col-sm-7"><?= e($p['birth_place'] ?: '—') ?></dd>
            <dt class="col-sm-5">Sexe</dt><dd class="col-sm-7"><?= e(Labels::SEX[$p['sex'] ?? ''] ?? '—') ?></dd>
            <dt class="col-sm-5">Nationalité</dt><dd class="col-sm-7"><?= e($p['nationality'] ?: '—') ?></dd>
            <dt class="col-sm-5">Profession</dt><dd class="col-sm-7"><?= e($p['profession'] ?: '—') ?></dd>
        </dl></div>
        <div class="col-lg-6"><dl class="row dl-fiche">
            <dt class="col-sm-5">Identifiant administratif</dt>
            <dd class="col-sm-7"><?php if ($p['admin_id_number']): ?><?= e($p['admin_id_type']) ?> n° <?= e($p['admin_id_number']) ?>
                <?= $p['admin_id_verified'] ? '<span class="badge text-bg-success">vérifié</span>' : '<span class="badge text-bg-light">non vérifié</span>' ?><?php else: ?>—<?php endif; ?></dd>
            <dt class="col-sm-5">Enregistrement</dt><dd class="col-sm-7"><?= e(fmt_date($p['registration_date'])) ?> — <?= e($p['registration_place']) ?></dd>
            <dt class="col-sm-5">Service créateur</dt><dd class="col-sm-7"><?= e($p['registering_service']) ?></dd>
            <dt class="col-sm-5">Niveau d'accès</dt><dd class="col-sm-7"><?= e(Labels::ACCESS_LEVEL[$p['access_level']]) ?></dd>
            <dt class="col-sm-5">Créée par</dt><dd class="col-sm-7"><?= e($creator ?: '—') ?>, le <?= e(fmt_date($p['created_at'], true)) ?></dd>
            <dt class="col-sm-5">Dernière mise à jour</dt><dd class="col-sm-7"><?= e(fmt_date($p['updated_at'], true)) ?> (<?= e($updater ?: '—') ?>)</dd>
        </dl></div>
    </div>
</div>

<!-- Photographies -->
<?php if (can('photos.view')): ?>
<div class="tab-pane fade<?= $tab === 'photos' ? ' show active' : '' ?>" id="tab-photos" role="tabpanel">
    <div class="row g-3">
        <?php if (!$photos): ?><p class="text-muted">Aucune photographie de référence.</p><?php endif; ?>
        <?php foreach ($photos as $ph): ?>
            <div class="col-sm-6 col-md-4 col-xl-3">
                <div class="card photo-card h-100">
                    <a href="<?= e(url('photos.show', ['id' => $ph['id']])) ?>" target="_blank" rel="noopener">
                        <img class="card-img-top" src="<?= e(url('photos.show', ['id' => $ph['id']])) ?>" alt="Photographie <?= (int) $ph['id'] ?>" loading="lazy"></a>
                    <div class="card-body small">
                        <?php if ($ph['is_primary']): ?><span class="badge text-bg-primary mb-1">Référence</span><?php endif; ?>
                        <div>Prise de vue : <?= e(fmt_date($ph['taken_at'])) ?></div>
                        <div>Importée le <?= e(fmt_date($ph['uploaded_at'], true)) ?></div>
                        <div>Autorisation : <?= e($ph['authorization_ref']) ?></div>
                        <div class="text-muted"><?= (int) $ph['width'] ?>×<?= (int) $ph['height'] ?> px</div>
                    </div>
                    <?php if (can('photos.manage') && $editable): ?>
                    <div class="card-footer d-flex gap-1">
                        <?php if (!$ph['is_primary']): ?>
                        <form method="post" action="<?= e(url('photos.primary', ['id' => $ph['id']])) ?>"><?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Définir comme référence</button></form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('photos.retire', ['id' => $ph['id']])) ?>" data-confirm="Retirer cette photographie de la fiche ? Elle restera conservée pour la traçabilité." data-prompt="Motif du retrait" data-prompt-field="reason">
                            <?= csrf_field() ?><input type="hidden" name="reason" value="">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Retirer</button></form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($all_photos_count > count($photos)): ?>
        <p class="small text-muted mt-2"><?= $all_photos_count - count($photos) ?> photographie(s) retirée(s), conservée(s) pour la traçabilité.</p>
    <?php endif; ?>
    <?php if (can('photos.manage') && $editable): ?>
    <hr>
    <h2 class="h6">Importer une photographie</h2>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('photos.store', ['person_id' => $p['id']])) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small required" for="photo">Fichier (JPEG ou PNG, 5 Mo max., 200 px min.)</label>
            <input class="form-control" type="file" id="photo" name="photo" accept="image/jpeg,image/png" required data-preview="#photo-preview"></div>
        <div class="col-md-3"><label class="form-label small required" for="authorization_ref">Référence de l'autorisation</label>
            <input class="form-control" id="authorization_ref" name="authorization_ref" maxlength="120" required placeholder="ex. : formulaire n° …"></div>
        <div class="col-md-2"><label class="form-label small" for="taken_at">Date de prise de vue</label>
            <input class="form-control" type="date" id="taken_at" name="taken_at"></div>
        <div class="col-md-3 d-flex align-items-end"><div class="form-check">
            <input class="form-check-input" type="checkbox" id="consent_confirmed" name="consent_confirmed" value="1" required>
            <label class="form-check-label small" for="consent_confirmed">Photographie fournie avec autorisation, conformément aux règles du service.</label></div></div>
        <div class="col-12"><img id="photo-preview" class="photo-preview d-none" alt="Aperçu"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Importer</button>
            <span class="small text-muted ms-2">L'image est ré-encodée (métadonnées supprimées) et stockée chiffrée hors du répertoire web.</span></div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Passages -->
<?php if (can('visits.view')): ?>
<div class="tab-pane fade<?= $tab === 'visits' ? ' show active' : '' ?>" id="tab-visits" role="tabpanel">
    <?php if (can('visits.manage') && $editable): ?>
        <a class="btn btn-sm btn-primary mb-3" href="<?= e(url('visits.create', ['person_id' => $p['id']])) ?>"><i class="bi bi-plus"></i> Enregistrer un passage</a>
    <?php endif; ?>
    <?php if (!$visits): ?><p class="text-muted">Aucun passage enregistré.</p><?php else: ?>
    <div class="timeline">
        <?php foreach ($visits as $v): ?>
            <div class="timeline-item">
                <div class="d-flex justify-content-between flex-wrap">
                    <strong><?= e(fmt_date($v['visit_date'], true)) ?> — <?= e($v['service']) ?></strong>
                    <span><span class="badge <?= $v['closure_status'] === 'cloture' ? 'text-bg-secondary' : 'text-bg-info' ?>"><?= e(Labels::VISIT_CLOSURE[$v['closure_status']]) ?></span>
                    <?php if (can('visits.manage') && $editable): ?><a class="small ms-2" href="<?= e(url('visits.edit', ['id' => $v['id']])) ?>">Modifier</a><?php endif; ?></span>
                </div>
                <div><?= e($v['reason']) ?></div>
                <div class="small text-muted">Lieu : <?= e($v['location']) ?> · Réf. <?= e($v['reference']) ?> · Agent responsable : <?= e($v['responsible_agent']) ?></div>
                <?php if ($v['observations']): ?><div class="small mt-1 observations"><?= nl2br(e($v['observations'])) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Procédures -->
<?php if (can('procedures.view')): ?>
<div class="tab-pane fade<?= $tab === 'procedures' ? ' show active' : '' ?>" id="tab-procedures" role="tabpanel">
    <?php if (can('procedures.manage') && $editable): ?>
        <a class="btn btn-sm btn-primary mb-3" href="<?= e(url('procedures.create', ['person_id' => $p['id']])) ?>"><i class="bi bi-plus"></i> Enregistrer une procédure</a>
    <?php endif; ?>
    <?php if (!$procedures): ?><p class="text-muted">Aucune procédure enregistrée.</p><?php endif; ?>
    <?php foreach ($procedures as $pr): ?>
        <div class="card mb-3 procedure-card status-<?= e($pr['status']) ?>">
            <div class="card-header d-flex justify-content-between flex-wrap gap-2">
                <span><strong>Procédure <?= e($pr['procedure_number']) ?></strong><?php if ($pr['pv_reference']): ?> · PV <?= e($pr['pv_reference']) ?><?php endif; ?></span>
                <span><?= status_badge($pr['status']) ?>
                <?php if (can('procedures.manage') && $editable): ?><a class="small ms-2" href="<?= e(url('procedures.edit', ['id' => $pr['id']])) ?>">Modifier</a><?php endif; ?></span>
            </div>
            <div class="card-body small">
                <p class="text-muted fst-italic mb-2"><?= e(Labels::PROCEDURE_STATUS[$pr['status']][2]) ?></p>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Faits allégués</dt><dd class="col-sm-8"><?= e($pr['facts_nature']) ?> (<?= e(fmt_date($pr['facts_date'])) ?>)</dd>
                    <dt class="col-sm-4">Service enquêteur</dt><dd class="col-sm-8"><?= e($pr['investigating_service']) ?></dd>
                    <dt class="col-sm-4">Parquet / juridiction</dt><dd class="col-sm-8"><?= e($pr['court_reference'] ?: '—') ?></dd>
                    <?php if ($pr['decision_text']): ?>
                    <dt class="col-sm-4">Décision documentée</dt><dd class="col-sm-8"><?= e($pr['decision_text']) ?> — <?= e(fmt_date($pr['decision_date'])) ?>
                        <br><span class="badge <?= $pr['decision_final'] === 'definitive' ? 'text-bg-dark' : 'text-bg-light' ?>"><?= e(Labels::DECISION_FINAL[$pr['decision_final']]) ?></span></dd>
                    <?php endif; ?>
                    <dt class="col-sm-4">Justificatifs</dt><dd class="col-sm-8"><?= e($pr['justification_ref'] ?: '—') ?></dd>
                    <?php if ($pr['notes']): ?><dt class="col-sm-4">Observations</dt><dd class="col-sm-8"><?= nl2br(e($pr['notes'])) ?></dd><?php endif; ?>
                    <dt class="col-sm-4">Mise à jour</dt><dd class="col-sm-8"><?= e(fmt_date($pr['updated_at'], true)) ?></dd>
                </dl>
                <details class="mt-2"><summary>Historique des statuts (<?= count($pr['history']) ?>)</summary>
                    <ul class="mb-0 mt-1"><?php foreach ($pr['history'] as $h): ?>
                        <li><?= e(fmt_date($h['changed_at'], true)) ?> : <?= $h['old_status'] ? e(Labels::procedureStatus($h['old_status'])) . ' → ' : '' ?><strong><?= e(Labels::procedureStatus($h['new_status'])) ?></strong>
                            — <?= e($h['justification']) ?> <span class="text-muted">(<?= e($h['full_name'] ?? '—') ?>)</span></li>
                    <?php endforeach; ?></ul></details>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Documents -->
<?php if (can('documents.view')): ?>
<div class="tab-pane fade<?= $tab === 'documents' ? ' show active' : '' ?>" id="tab-documents" role="tabpanel">
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Intitulé</th><th>Référence</th><th>Rattachement</th><th>Type</th><th>Taille</th><th>Importé</th><th></th></tr></thead>
        <tbody>
        <?php if (!$documents): ?><tr><td colspan="7" class="text-muted">Aucun document.</td></tr><?php endif; ?>
        <?php foreach ($documents as $d): ?>
            <tr><td><?= e($d['title']) ?></td><td><?= e($d['doc_reference'] ?: '—') ?></td>
                <td class="small"><?= $d['procedure_id'] ? 'Procédure' : ($d['visit_id'] ? 'Passage' : 'Fiche') ?></td>
                <td><?= $d['mime_type'] === 'application/pdf' ? 'PDF' : 'Image' ?></td><td><?= e(human_size((int) $d['size_bytes'])) ?></td>
                <td class="small"><?= e(fmt_date($d['uploaded_at'], true)) ?><br><?= e($d['uploader'] ?? '') ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('documents.download', ['id' => $d['id']])) ?>"><i class="bi bi-download"></i></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php if (can('documents.manage') && $editable): ?>
    <hr><h2 class="h6">Ajouter un document</h2>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('documents.store', ['person_id' => $p['id']])) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label small required" for="doc_title">Intitulé</label><input class="form-control" id="doc_title" name="title" required maxlength="200"></div>
        <div class="col-md-2"><label class="form-label small" for="doc_reference">Référence</label><input class="form-control" id="doc_reference" name="doc_reference" maxlength="120"></div>
        <div class="col-md-3"><label class="form-label small" for="doc_link">Rattacher à</label>
            <select class="form-select" id="doc_link" data-doc-link>
                <option value="">La fiche</option>
                <?php foreach ($visits as $v): ?><option value="visit:<?= (int) $v['id'] ?>">Passage du <?= e(fmt_date($v['visit_date'])) ?> (<?= e($v['reference']) ?>)</option><?php endforeach; ?>
                <?php foreach ($procedures as $pr): ?><option value="procedure:<?= (int) $pr['id'] ?>">Procédure <?= e($pr['procedure_number']) ?></option><?php endforeach; ?>
            </select>
            <input type="hidden" name="visit_id" value=""><input type="hidden" name="procedure_id" value=""></div>
        <div class="col-md-4"><label class="form-label small required" for="document">Fichier (PDF, JPEG, PNG — 10 Mo max.)</label>
            <input class="form-control" type="file" id="document" name="document" accept="application/pdf,image/jpeg,image/png" required></div>
        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Ajouter</button></div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Traçabilité -->
<div class="tab-pane fade<?= $tab === 'trace' ? ' show active' : '' ?>" id="tab-trace" role="tabpanel">
    <p class="small">Toutes les consultations et modifications de cette fiche sont enregistrées dans le journal d'audit.</p>
    <?php if ($access_log): ?>
        <table class="table table-sm"><thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($access_log as $l): ?><tr><td><?= e(fmt_date($l['created_at'], true)) ?></td><td><?= e($l['username']) ?></td><td><?= e(Labels::AUDIT_ACTIONS[$l['action']] ?? $l['action']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <a class="small" href="<?= e(url('audit.index', ['entity_id' => $p['id']])) ?>">Voir tout l'historique</a>
    <?php else: ?>
        <p class="small text-muted">Le détail des accès est consultable par le responsable de contrôle.</p>
    <?php endif; ?>
</div>
</div></div>

<?php if (can('persons.archive')): ?>
<div class="modal fade" id="archiveModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form method="post" action="<?= e(url($archived ? 'persons.restore' : 'persons.archive', ['id' => $p['id']])) ?>">
        <?= csrf_field() ?>
        <div class="modal-header"><h5 class="modal-title"><?= $archived ? 'Désarchiver la fiche' : 'Archiver la fiche' ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
        <div class="modal-body">
            <p class="small"><?= $archived ? 'La fiche redeviendra consultable de manière courante.' : 'La fiche ne sera plus visible dans les consultations courantes et ne pourra plus être modifiée. Elle reste conservée et tracée.' ?></p>
            <label class="form-label required" for="archive_reason">Motif</label>
            <input class="form-control" id="archive_reason" name="reason" required minlength="5" maxlength="255"
                   placeholder="<?= $archived ? 'ex. : nouveau passage administratif' : 'ex. : doublon de DID-2026-000012 / fin de la durée de conservation' ?>">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
            <button class="btn <?= $archived ? 'btn-success' : 'btn-danger' ?>" type="submit">Confirmer</button></div>
    </form>
</div></div></div>
<?php endif; ?>
