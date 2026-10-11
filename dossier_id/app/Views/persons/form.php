<?php use App\Support\Labels; $action = $id ? url('persons.update', ['id' => $id]) : url('persons.store'); ?>
<div class="page-header">
    <h1><i class="bi bi-person-<?= $id ? 'gear' : 'plus' ?>"></i> <?= e($title) ?>
        <?php if ($id): ?><small class="text-muted fs-6"><?= e($p['file_number'] ?? '') ?></small><?php endif; ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e($id ? url('persons.show', ['id' => $id]) : url('persons.index')) ?>">Annuler</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">Le formulaire contient des erreurs. Vérifiez les champs signalés.</div>
<?php endif; ?>

<?php if ($duplicates): ?>
<div class="card border-warning mb-4">
    <div class="card-header bg-warning-subtle"><i class="bi bi-exclamation-triangle"></i> <strong>Doublons potentiels détectés</strong> — vérifiez avant de créer une nouvelle fiche</div>
    <div class="card-body">
        <p class="small">Ces fiches présentent des informations administratives proches. Il s'agit d'une suggestion : comparez les informations
            et les photographies, et vérifiez les pièces présentées avant toute décision.</p>
        <?php if ($duplicates['visible']): ?>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>N° dossier</th><th>Nom et prénom</th><th>Naissance</th><th>Identifiant</th><th>Concordance</th><th>Motifs</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($duplicates['visible'] as $d): $dp = $d['person']; ?>
                <tr><td><?= e($dp['file_number']) ?></td>
                    <td><span class="text-uppercase"><?= e($dp['last_name']) ?></span> <?= e($dp['first_name']) ?></td>
                    <td><?= e(fmt_date($dp['birth_date'])) ?></td>
                    <td><?= e($dp['admin_id_number'] ?? '—') ?></td>
                    <td><span class="badge dup-<?= e($d['level'] === 'élevé' ? 'high' : ($d['level'] === 'moyen' ? 'mid' : 'low')) ?>"><?= e(ucfirst($d['level'])) ?></span></td>
                    <td class="small"><?= e(implode(', ', $d['reasons'])) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('persons.show', ['id' => $dp['id']])) ?>">Ouvrir</a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        <?php if ($duplicates['hidden']): ?>
            <div class="alert alert-secondary small"><?= (int) $duplicates['hidden'] ?> fiche(s) à accès restreint correspondent également. Contactez un agent habilité avant de poursuivre.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" novalidate autocomplete="off">
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-header">Identification</div><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label required" for="last_name">Nom</label>
        <input class="form-control text-uppercase<?= invalid($errors, 'last_name') ?>" id="last_name" name="last_name" value="<?= old($p, 'last_name') ?>" required maxlength="100"><?= field_error($errors, 'last_name') ?></div>
    <div class="col-md-4"><label class="form-label required" for="first_name">Prénom(s)</label>
        <input class="form-control<?= invalid($errors, 'first_name') ?>" id="first_name" name="first_name" value="<?= old($p, 'first_name') ?>" required maxlength="100"><?= field_error($errors, 'first_name') ?></div>
    <div class="col-md-4"><label class="form-label" for="other_names">Autres noms déclarés</label>
        <input class="form-control<?= invalid($errors, 'other_names') ?>" id="other_names" name="other_names" value="<?= old($p, 'other_names') ?>" maxlength="255"><?= field_error($errors, 'other_names') ?></div>
    <div class="col-md-3"><label class="form-label" for="birth_date">Date de naissance</label>
        <input class="form-control<?= invalid($errors, 'birth_date') ?>" type="date" id="birth_date" name="birth_date" value="<?= old($p, 'birth_date') ?>"><?= field_error($errors, 'birth_date') ?></div>
    <div class="col-md-2"><label class="form-label" for="declared_age">ou âge déclaré</label>
        <input class="form-control<?= invalid($errors, 'declared_age') ?>" type="number" min="0" max="120" id="declared_age" name="declared_age" value="<?= old($p, 'declared_age') ?>"><?= field_error($errors, 'declared_age') ?></div>
    <div class="col-md-3"><label class="form-label" for="birth_place">Lieu de naissance</label>
        <input class="form-control<?= invalid($errors, 'birth_place') ?>" id="birth_place" name="birth_place" value="<?= old($p, 'birth_place') ?>" maxlength="120"><?= field_error($errors, 'birth_place') ?></div>
    <div class="col-md-2"><label class="form-label" for="sex">Sexe</label>
        <select class="form-select" id="sex" name="sex"><option value="">Non renseigné</option>
            <?php foreach (Labels::SEX as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($p['sex'] ?? '', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <div class="form-text">Uniquement si nécessaire.</div></div>
    <div class="col-md-2"><label class="form-label" for="nationality">Nationalité</label>
        <input class="form-control" id="nationality" name="nationality" value="<?= old($p, 'nationality') ?>" maxlength="80"></div>
    <div class="col-md-4"><label class="form-label" for="profession">Profession</label>
        <input class="form-control" id="profession" name="profession" value="<?= old($p, 'profession') ?>" maxlength="120">
        <div class="form-text">Uniquement si nécessaire au traitement du dossier.</div></div>
</div></div>

<div class="card mb-3"><div class="card-header">Identifiant administratif (si disponible et autorisé)</div><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label" for="admin_id_type">Type de pièce</label>
        <input class="form-control<?= invalid($errors, 'admin_id_type') ?>" id="admin_id_type" name="admin_id_type" value="<?= old($p, 'admin_id_type') ?>" maxlength="60" list="id-types">
        <datalist id="id-types"><option value="Carte nationale d'identité"><option value="Passeport"><option value="Acte de naissance"><option value="Carte consulaire"><option value="Permis de conduire"></datalist>
        <?= field_error($errors, 'admin_id_type') ?></div>
    <div class="col-md-4"><label class="form-label" for="admin_id_number">Numéro</label>
        <input class="form-control<?= invalid($errors, 'admin_id_number') ?>" id="admin_id_number" name="admin_id_number" value="<?= old($p, 'admin_id_number') ?>" maxlength="80"><?= field_error($errors, 'admin_id_number') ?></div>
    <div class="col-md-4 d-flex align-items-end"><div class="form-check">
        <input class="form-check-input<?= invalid($errors, 'admin_id_verified') ?>" type="checkbox" id="admin_id_verified" name="admin_id_verified" value="1"<?= checked($p['admin_id_verified'] ?? 0) ?>>
        <label class="form-check-label" for="admin_id_verified">Pièce originale vérifiée par l'agent</label><?= field_error($errors, 'admin_id_verified') ?></div></div>
</div></div>

<div class="card mb-3"><div class="card-header">Enregistrement</div><div class="card-body row g-3">
    <div class="col-md-3"><label class="form-label required" for="registration_date">Date d'enregistrement</label>
        <input class="form-control<?= invalid($errors, 'registration_date') ?>" type="date" id="registration_date" name="registration_date" value="<?= old($p, 'registration_date') ?>" required><?= field_error($errors, 'registration_date') ?></div>
    <div class="col-md-4"><label class="form-label required" for="registration_place">Lieu d'enregistrement</label>
        <input class="form-control<?= invalid($errors, 'registration_place') ?>" id="registration_place" name="registration_place" value="<?= old($p, 'registration_place') ?>" required maxlength="120"><?= field_error($errors, 'registration_place') ?></div>
    <div class="col-md-5"><label class="form-label required" for="registering_service">Service ayant créé la fiche</label>
        <input class="form-control<?= invalid($errors, 'registering_service') ?>" id="registering_service" name="registering_service" value="<?= old($p, 'registering_service') ?>" required maxlength="120"><?= field_error($errors, 'registering_service') ?></div>
    <?php if (can('persons.view_restricted')): ?>
    <div class="col-md-4"><label class="form-label" for="access_level">Niveau d'accès</label>
        <select class="form-select" id="access_level" name="access_level">
            <?php foreach (Labels::ACCESS_LEVEL as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($p['access_level'] ?? 'standard', $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select><div class="form-text">« Accès restreint » : visible uniquement des comptes habilités.</div></div>
    <?php else: ?>
        <input type="hidden" name="access_level" value="<?= old($p, 'access_level', 'standard') ?>">
    <?php endif; ?>
</div></div>

<?php if ($id): ?>
<div class="card mb-3 border-primary"><div class="card-body">
    <label class="form-label required" for="change_reason">Motif de la rectification</label>
    <input class="form-control<?= invalid($errors, 'change_reason') ?>" id="change_reason" name="change_reason" maxlength="300" required
           placeholder="ex. : correction de l'orthographe sur présentation de la carte d'identité" value="<?= e($_POST['change_reason'] ?? '') ?>">
    <?= field_error($errors, 'change_reason') ?>
    <div class="form-text">Le motif est conservé dans le journal d'audit avec la liste des champs modifiés.</div>
</div></div>
<?php endif; ?>

<?php if ($duplicates): ?>
<div class="card mb-3 border-warning"><div class="card-body">
    <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="confirm_distinct" name="confirm_distinct" value="1" required>
        <label class="form-check-label" for="confirm_distinct">J'ai vérifié les fiches ci-dessus : il s'agit d'une personne différente.</label>
    </div>
    <label class="form-label required" for="distinct_justification">Justification</label>
    <input class="form-control<?= invalid($errors, 'distinct_justification') ?>" id="distinct_justification" name="distinct_justification" maxlength="300"
           placeholder="ex. : dates de naissance et pièces d'identité différentes" value="<?= e($_POST['distinct_justification'] ?? '') ?>">
    <?= field_error($errors, 'distinct_justification') ?>
</div></div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $id ? 'Enregistrer la rectification' : 'Créer la fiche' ?></button>
    <a class="btn btn-outline-secondary" href="<?= e($id ? url('persons.show', ['id' => $id]) : url('persons.index')) ?>">Annuler</a>
</div>
</form>
