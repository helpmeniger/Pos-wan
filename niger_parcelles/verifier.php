<?php
/**
 * Vérification publique de l'authenticité d'une fiche (QR code ou code court).
 * Accessible sans connexion. N'affiche AUCUNE donnée personnelle.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$input = trim((string) ($_GET['t'] ?? $_POST['code'] ?? ''));
$result = null;
$limited = false;
if ($input !== '') {
    // Limitation : 30 vérifications par adresse IP et par 10 minutes
    $recent = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE action = 'verification_publique' AND ip_address = ? AND created_at >= ?",
        [client_ip(), date('Y-m-d H:i:s', time() - 600)]);
    if ($recent >= 30) {
        $limited = true;
    } else {
        $result = IssuedDocumentService::verify($input);
        audit_log('verification_publique', 'issued_document', $result['doc'] ? (int) $result['doc']['id'] : null, ['resultat' => $result['state']]);
    }
}
header('X-Robots-Tag: noindex, nofollow');

$pageTitle = 'Vérification d\'une fiche';
require __DIR__ . '/includes/header.php';
$states = [
    'authentique' => ['success', 'patch-check-fill', 'Fiche authentique et à jour', 'Cette fiche a bien été émise par l\'application et la situation enregistrée n\'a pas changé depuis.'],
    'perimee' => ['warning', 'exclamation-triangle-fill', 'Fiche authentique mais PÉRIMÉE', 'Cette fiche a bien été émise par l\'application, mais la situation a changé depuis : demandez une fiche à jour.'],
    'revoquee' => ['danger', 'x-octagon-fill', 'Fiche RÉVOQUÉE', 'Cette fiche a été annulée par l\'administration et ne doit plus être utilisée.'],
    'falsifiee' => ['danger', 'shield-x', 'Fiche NON VALIDE', 'Les données de cette fiche ne correspondent pas à sa signature : elle a pu être altérée. Signalez-la à l\'administration.'],
    'inconnue' => ['danger', 'question-octagon-fill', 'Fiche INCONNUE', 'Aucune fiche ne correspond à ce code. Il peut s\'agir d\'une fausse fiche ou d\'une erreur de saisie : vérifiez le code.'],
];
?>
<div class="container py-4" style="max-width: 760px">
    <div class="d-flex align-items-center gap-3 mb-3">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="Drapeau du Niger" width="58" height="50" class="np-flag">
        <div><h1 class="h4 mb-0">Vérification d'une fiche</h1>
            <div class="text-muted small"><?= e(setting('organisation_name', 'Niger Parcelles')) ?> — registre de gestion foncière</div></div>
    </div>

    <?php if ($limited): ?>
        <div class="alert alert-warning">Trop de vérifications depuis votre adresse. Réessayez dans quelques minutes.</div>
    <?php elseif ($result): [$cls, $icon, $title, $text] = $states[$result['state']]; $f = $result['facts']; ?>
        <div class="alert alert-<?= $cls ?> d-flex gap-3 align-items-start" role="status">
            <i class="bi bi-<?= $icon ?> fs-1"></i>
            <div><h2 class="h5 mb-1"><?= e($title) ?></h2><p class="mb-0"><?= e($text) ?></p></div>
        </div>
        <?php if ($result['changes']): ?>
            <div class="card mb-3"><div class="card-header">Changements depuis l'émission</div>
                <ul class="list-group list-group-flush"><?php foreach ($result['changes'] as $c): ?><li class="list-group-item"><?= e($c) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($f && in_array($result['state'], ['authentique', 'perimee', 'revoquee'], true)): $d = $result['doc']; ?>
            <div class="card mb-3"><div class="card-header">Contenu de la fiche au moment de son émission</div><div class="card-body"><dl class="row mb-0 small">
                <dt class="col-sm-5">Référence de la parcelle</dt><dd class="col-sm-7 ref"><?= e($f['reference']) ?></dd>
                <dt class="col-sm-5">Référence cadastrale officielle</dt><dd class="col-sm-7"><?= e($f['cadastral_reference'] ?? 'non renseignée') ?></dd>
                <dt class="col-sm-5">Localisation</dt><dd class="col-sm-7"><?= e(implode(' › ', array_filter([$f['region'], $f['commune'], $f['localite']])) ?: '—') ?></dd>
                <dt class="col-sm-5">Superficie</dt><dd class="col-sm-7"><?= e(fmt_area($f['area_m2'])) ?></dd>
                <dt class="col-sm-5">Statut de vérification</dt><dd class="col-sm-7"><?= e(label('verification_status', $f['verification_status'])) ?></dd>
                <dt class="col-sm-5">Nature de la délimitation</dt><dd class="col-sm-7"><?= e(label('survey_level', $f['survey_level'])) ?></dd>
                <dt class="col-sm-5">Situation déclarée</dt><dd class="col-sm-7"><?= e(label('declared_status', $f['declared_status'])) ?></dd>
                <dt class="col-sm-5">Nombre de propriétaires enregistrés</dt><dd class="col-sm-7"><?= (int) $f['owner_count'] ?> <span class="text-muted">(identités non publiées)</span></dd>
                <dt class="col-sm-5">Émise le</dt><dd class="col-sm-7"><?= e(fmt_date($d['issued_at'], true)) ?></dd>
                <dt class="col-sm-5">Code de la fiche</dt><dd class="col-sm-7 ref"><?= e(IssuedDocumentService::shortCode($d['token'])) ?></dd>
                <?php if ($d['revoked_at']): ?><dt class="col-sm-5">Révoquée le</dt><dd class="col-sm-7"><?= e(fmt_date($d['revoked_at'], true)) ?> — <?= e($d['revoke_reason']) ?></dd><?php endif; ?>
            </dl>
            <?php if (!empty($f['is_demo'])): ?><div class="alert alert-warning mt-3 mb-0 py-1 small">Donnée de démonstration fictive.</div><?php endif; ?>
            </div></div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="get" class="card card-body mb-3">
        <label class="form-label" for="t">Code imprimé sous le QR code (ex. 3F2A-91C0-7B4E) ou adresse complète</label>
        <div class="input-group"><input class="form-control ref" id="t" name="t" value="<?= e($result ? '' : $input) ?>" required maxlength="200" autocomplete="off">
            <button class="btn btn-primary"><i class="bi bi-search"></i> Vérifier</button></div>
    </form>
    <p class="legal-notice small">Une fiche Niger Parcelles est un document de gestion : même authentique, elle ne constitue pas à elle seule un titre
        foncier officiel. Pour toute transaction, consultez le service des affaires domaniales ou du cadastre compétent.</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
