<?php use App\Support\Labels; $action = $id ? url('procedures.update', ['id' => $id]) : url('procedures.store', ['person_id' => $person['id']]); ?>
<div class="page-header">
    <h1><i class="bi bi-folder2-open"></i> <?= e($title) ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('persons.show', ['id' => $person['id'], 'tab' => 'procedures'])) ?>">Retour à la fiche</a>
</div>
<p>Fiche : <strong class="text-uppercase"><?= e($person['last_name']) ?></strong> <?= e($person['first_name']) ?> — <?= e($person['file_number']) ?></p>
<div class="alert alert-info small">
    <strong>Règles de qualification.</strong> « Mise en cause » et « poursuivie » ne désignent que des faits <em>allégués</em> :
    la personne est présumée innocente. Le statut « condamnée » ne peut être enregistré qu'avec la référence de la juridiction,
    la date et le dispositif de la décision, ainsi que la référence du document justificatif. Tout changement de statut doit être justifié.
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" action="<?= e($action) ?>" novalidate>
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-header">Procédure</div><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label required" for="procedure_number">Numéro de procédure</label>
        <input class="form-control<?= invalid($errors, 'procedure_number') ?>" id="procedure_number" name="procedure_number" value="<?= old($pr, 'procedure_number') ?>" maxlength="80" required></div>
    <div class="col-md-4"><label class="form-label" for="pv_reference">Référence du procès-verbal</label>
        <input class="form-control" id="pv_reference" name="pv_reference" value="<?= old($pr, 'pv_reference') ?>" maxlength="80"></div>
    <div class="col-md-4"><label class="form-label" for="facts_date">Date des faits allégués</label>
        <input class="form-control<?= invalid($errors, 'facts_date') ?>" type="date" id="facts_date" name="facts_date" value="<?= old($pr, 'facts_date') ?>"></div>
    <div class="col-md-8"><label class="form-label required" for="facts_nature">Nature des faits allégués</label>
        <input class="form-control<?= invalid($errors, 'facts_nature') ?>" id="facts_nature" name="facts_nature" value="<?= old($pr, 'facts_nature') ?>" maxlength="255" required></div>
    <div class="col-md-4"><label class="form-label required" for="investigating_service">Service enquêteur</label>
        <input class="form-control<?= invalid($errors, 'investigating_service') ?>" id="investigating_service" name="investigating_service" value="<?= old($pr, 'investigating_service') ?>" maxlength="150" required></div>
</div></div>

<div class="card mb-3"><div class="card-header">Statut procédural</div><div class="card-body row g-3">
    <div class="col-md-5"><label class="form-label required" for="status">Statut de la personne dans la procédure</label>
        <select class="form-select<?= invalid($errors, 'status') ?>" id="status" name="status" data-status-select>
            <?php foreach (Labels::PROCEDURE_STATUS as $k => [$l, , $hint]): ?><option value="<?= e($k) ?>" data-hint="<?= e($hint) ?>"<?= selected($pr['status'] ?? 'entendue', $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <div class="form-text" id="status-hint"></div></div>
    <div class="col-md-7"><label class="form-label<?= $id ? '' : '' ?>" for="status_justification">Justification du statut <?= $id ? '(obligatoire en cas de changement)' : '' ?></label>
        <input class="form-control<?= invalid($errors, 'status_justification') ?>" id="status_justification" name="status_justification" maxlength="500" value="<?= e($_POST['status_justification'] ?? '') ?>"
               placeholder="ex. : jugement n° … notifié le …, réquisitoire du parquet du …"></div>
    <div class="col-md-4"><label class="form-label" for="court_reference">Référence parquet / juridiction</label>
        <input class="form-control<?= invalid($errors, 'court_reference') ?>" id="court_reference" name="court_reference" value="<?= old($pr, 'court_reference') ?>" maxlength="120"></div>
    <div class="col-md-5"><label class="form-label" for="decision_text">Décision judiciaire documentée</label>
        <input class="form-control<?= invalid($errors, 'decision_text') ?>" id="decision_text" name="decision_text" value="<?= old($pr, 'decision_text') ?>" maxlength="500"></div>
    <div class="col-md-3"><label class="form-label" for="decision_date">Date de la décision</label>
        <input class="form-control<?= invalid($errors, 'decision_date') ?>" type="date" id="decision_date" name="decision_date" value="<?= old($pr, 'decision_date') ?>"></div>
    <div class="col-md-5"><label class="form-label" for="decision_final">Caractère définitif</label>
        <select class="form-select<?= invalid($errors, 'decision_final') ?>" id="decision_final" name="decision_final">
            <?php foreach (Labels::DECISION_FINAL as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($pr['decision_final'] ?? 'inconnu', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <div class="form-text">À renseigner uniquement lorsqu'il est établi par un document.</div></div>
    <div class="col-md-7"><label class="form-label" for="justification_ref">Références des documents justificatifs</label>
        <input class="form-control<?= invalid($errors, 'justification_ref') ?>" id="justification_ref" name="justification_ref" value="<?= old($pr, 'justification_ref') ?>" maxlength="255"
               placeholder="ex. : copie du jugement, extrait du greffe n° …"></div>
    <div class="col-12"><label class="form-label" for="notes">Observations</label>
        <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="4000"><?= old($pr, 'notes') ?></textarea></div>
    <?php if ($id): ?>
    <div class="col-12"><label class="form-label required" for="change_reason">Motif de la modification</label>
        <input class="form-control<?= invalid($errors, 'change_reason') ?>" id="change_reason" name="change_reason" maxlength="300" required value="<?= e($_POST['change_reason'] ?? '') ?>"></div>
    <?php endif; ?>
</div></div>
<button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
</form>
