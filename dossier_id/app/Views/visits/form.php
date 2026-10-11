<?php use App\Support\Labels; $action = $id ? url('visits.update', ['id' => $id]) : url('visits.store', ['person_id' => $person['id']]); ?>
<div class="page-header">
    <h1><i class="bi bi-calendar-check"></i> <?= e($title) ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('persons.show', ['id' => $person['id'], 'tab' => 'visits'])) ?>">Retour à la fiche</a>
</div>
<p>Fiche : <strong class="text-uppercase"><?= e($person['last_name']) ?></strong> <?= e($person['first_name']) ?> — <?= e($person['file_number']) ?></p>
<?php if ($errors): ?><div class="alert alert-danger">Le formulaire contient des erreurs.</div><?php endif; ?>
<form method="post" action="<?= e($action) ?>" novalidate>
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-3"><label class="form-label required" for="visit_date">Date et heure</label>
        <input class="form-control<?= invalid($errors, 'visit_date') ?>" type="datetime-local" id="visit_date" name="visit_date" value="<?= e(str_replace(' ', 'T', substr((string) ($v['visit_date'] ?? ''), 0, 16))) ?>" required><?= field_error($errors, 'visit_date') ?></div>
    <div class="col-md-4"><label class="form-label required" for="location">Lieu</label>
        <input class="form-control<?= invalid($errors, 'location') ?>" id="location" name="location" value="<?= old($v, 'location') ?>" maxlength="150" required><?= field_error($errors, 'location') ?></div>
    <div class="col-md-5"><label class="form-label required" for="service">Service concerné</label>
        <input class="form-control<?= invalid($errors, 'service') ?>" id="service" name="service" value="<?= old($v, 'service') ?>" maxlength="120" required><?= field_error($errors, 'service') ?></div>
    <div class="col-md-6"><label class="form-label required" for="reason">Motif administratif</label>
        <input class="form-control<?= invalid($errors, 'reason') ?>" id="reason" name="reason" value="<?= old($v, 'reason') ?>" maxlength="255" required list="reasons"><?= field_error($errors, 'reason') ?>
        <datalist id="reasons"><option value="Demande de document administratif"><option value="Convocation"><option value="Déclaration de perte"><option value="Dépôt de plainte"><option value="Audition"><option value="Vérification d'identité"></datalist></div>
    <div class="col-md-3"><label class="form-label required" for="reference">Référence du dossier</label>
        <input class="form-control<?= invalid($errors, 'reference') ?>" id="reference" name="reference" value="<?= old($v, 'reference') ?>" maxlength="80" required><?= field_error($errors, 'reference') ?></div>
    <div class="col-md-3"><label class="form-label" for="closure_status">Statut</label>
        <select class="form-select" id="closure_status" name="closure_status">
            <?php foreach (Labels::VISIT_CLOSURE as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($v['closure_status'] ?? 'ouvert', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label required" for="responsible_agent">Agent responsable</label>
        <input class="form-control<?= invalid($errors, 'responsible_agent') ?>" id="responsible_agent" name="responsible_agent" value="<?= old($v, 'responsible_agent') ?>" maxlength="150" required><?= field_error($errors, 'responsible_agent') ?></div>
    <div class="col-12"><label class="form-label" for="observations">Observations professionnelles</label>
        <textarea class="form-control<?= invalid($errors, 'observations') ?>" id="observations" name="observations" rows="4" maxlength="4000"><?= old($v, 'observations') ?></textarea>
        <div class="form-text">Faits objectifs et utiles au dossier uniquement. Pas d'appréciation personnelle.</div><?= field_error($errors, 'observations') ?></div>
    <?php if ($id): ?>
    <div class="col-12"><label class="form-label required" for="change_reason">Motif de la modification</label>
        <input class="form-control<?= invalid($errors, 'change_reason') ?>" id="change_reason" name="change_reason" maxlength="300" required value="<?= e($_POST['change_reason'] ?? '') ?>"><?= field_error($errors, 'change_reason') ?></div>
    <?php endif; ?>
</div></div>
<button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
</form>
