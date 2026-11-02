<?php
use App\Services\TicketPrintService;
$title = "Modèles d'impression";
?>
<div class="mc-page-head">
    <div><h1>Modèles <span class="mc-accent">d'impression</span></h1><p>Choisissez les champs imprimés, la couleur et le format de chaque modèle.</p></div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/tickets')) ?>"><i class="bi bi-arrow-left"></i> Tickets</a>
</div>
<div class="row g-3">
<?php foreach ($templates as $tp): ?>
    <div class="col-md-6 col-xl-4">
        <form class="mc-card h-100" method="post" action="<?= e(url('/tickets/templates/' . $tp['id'])) ?>">
            <?= csrf_field() ?>
            <div class="mc-card-head"><input class="form-control form-control-sm fw-bold" name="name" value="<?= e($tp['name']) ?>" aria-label="Nom du modèle">
                <?= $tp['is_default'] ? '<span class="badge text-bg-primary ms-2">Défaut</span>' : '' ?></div>
            <div class="mc-card-body">
                <div class="row g-2 mb-3">
                    <div class="col-8"><select class="form-select form-select-sm" name="format"><?php foreach (TicketPrintService::FORMATS as $k => $f): ?><option value="<?= $k ?>" <?= $tp['format'] === $k ? 'selected' : '' ?>><?= e($f['label']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-4"><input class="form-control form-control-sm form-control-color w-100" type="color" name="accent" value="<?= e($tp['options']['accent'] ?? '#D7192A') ?>" title="Couleur"></div>
                </div>
                <div class="row">
                <?php foreach (TicketPrintService::FIELDS as $k => $l): ?>
                    <div class="col-6"><div class="form-check small"><input class="form-check-input" type="checkbox" name="fields[]" value="<?= $k ?>" id="f<?= $tp['id'] . $k ?>" <?= in_array($k, $tp['fields'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="f<?= $tp['id'] . $k ?>"><?= e($l) ?></label></div></div>
                <?php endforeach; ?>
                </div>
                <div class="form-check small mt-2"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="d<?= $tp['id'] ?>" <?= $tp['is_default'] ? 'checked' : '' ?>><label class="form-check-label" for="d<?= $tp['id'] ?>">Modèle par défaut</label></div>
            </div>
            <div class="mc-card-head border-top border-bottom-0 justify-content-end"><button class="btn btn-sm btn-primary">Enregistrer</button></div>
        </form>
    </div>
<?php endforeach; ?>
</div>
<p class="text-muted small mt-3">Le nom du réseau Wi-Fi et les instructions se règlent dans <a href="<?= e(url('/settings', ['tab' => 'tickets'])) ?>">Paramètres → Tickets</a> et sur chaque routeur.</p>
