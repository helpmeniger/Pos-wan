<?php
use App\Controllers\SettingsController;
$title = 'Paramètres';
$tabs = SettingsController::TABS;
$canEdit = can('settings.manage');
?>
<div class="mc-page-head"><div><h1><span class="mc-accent">Paramètres</span></h1><p>Configuration générale de MARWA CONNECT.</p></div></div>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="mc-card"><div class="list-group list-group-flush">
            <?php foreach ($tabs as $k => [$label, $icon]): ?>
                <a class="list-group-item list-group-item-action <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/settings', ['tab' => $k])) ?>"><i class="bi bi-<?= $icon ?> me-2"></i><?= e($label) ?></a>
            <?php endforeach; ?>
            <a class="list-group-item list-group-item-action" href="<?= e(url('/tickets/templates')) ?>"><i class="bi bi-printer me-2"></i>Modèles d'impression</a>
        </div></div>
    </div>
    <div class="col-lg-9">
    <?php if ($tab === 'payments'): ?>
        <div class="alert alert-info small">Les paiements électroniques en caisse exigent la saisie de la référence de transaction. Pour la vente en ligne, configurez le secret de webhook fourni par votre prestataire :
            les notifications doivent être envoyées en <code>POST</code> à <code><?= e(url('/webhooks/payments/{code}')) ?></code> avec l'en-tête <code>X-Signature</code> = HMAC-SHA256 du corps.</div>
        <?php foreach ($methods as $m): ?>
            <form class="mc-card mb-3" method="post" action="<?= e(url('/settings/payments/' . $m['id'])) ?>"><?= csrf_field() ?>
                <div class="mc-card-head"><h2><?= e($m['name']) ?> <small class="text-muted">(<?= e($m['code']) ?>)</small></h2>
                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $m['is_active'] ? 'checked' : '' ?> aria-label="Actif"></div></div>
                <div class="mc-card-body row g-2">
                    <div class="col-md-4"><label class="form-label small">Nom affiché</label><input class="form-control form-control-sm" name="name" value="<?= e($m['name']) ?>"></div>
                    <div class="col-md-4"><label class="form-label small">Numéro marchand</label><input class="form-control form-control-sm" name="merchant_number" value="<?= e($m['merchant_number'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label small">Secret webhook</label><input class="form-control form-control-sm" type="password" name="webhook_secret" placeholder="<?= $m['has_secret'] ? 'Configuré — inchangé si vide' : 'Non configuré' ?>" autocomplete="off"></div>
                    <div class="col-12"><label class="form-label small">Instructions au client</label><input class="form-control form-control-sm" name="instructions" value="<?= e($m['instructions'] ?? '') ?>" placeholder="Ex. Composez *xxx# puis…"></div>
                    <div class="col-12 d-flex gap-3">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="available_pos" value="1" id="pos<?= (int) $m['id'] ?>" <?= $m['available_pos'] ? 'checked' : '' ?>><label class="form-check-label small" for="pos<?= (int) $m['id'] ?>">En caisse</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="available_online" value="1" id="on<?= (int) $m['id'] ?>" <?= $m['available_online'] ? 'checked' : '' ?>><label class="form-check-label small" for="on<?= (int) $m['id'] ?>">En ligne</label></div>
                    </div>
                </div>
                <?php if (can('payments.manage', 'settings.manage')): ?><div class="mc-card-head border-top border-bottom-0 justify-content-end"><button class="btn btn-sm btn-primary">Enregistrer</button></div><?php endif; ?>
            </form>
        <?php endforeach; ?>
    <?php else: ?>
        <form class="mc-card" method="post" action="<?= e(url('/settings/' . $tab)) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
            <div class="mc-card-head"><h2><i class="bi bi-<?= $tabs[$tab][1] ?> me-2 text-primary"></i><?= e($tabs[$tab][0]) ?></h2></div>
            <div class="mc-card-body row g-3">
                <?php foreach ($tabs[$tab][2] as [$key, $label, $type]):
                    $field = str_replace('.', '__', $key);
                    $val = $values[$key] ?? ''; ?>
                    <div class="<?= in_array($type, ['text'], true) ? 'col-12' : 'col-md-6' ?>">
                    <?php if ($type === 'bool'): ?>
                        <div class="form-check form-switch mt-md-4"><input class="form-check-input" type="checkbox" id="<?= $field ?>" name="<?= $field ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>><label class="form-check-label" for="<?= $field ?>"><?= e($label) ?></label></div>
                    <?php else: ?>
                        <label class="form-label" for="<?= $field ?>"><?= e($label) ?></label>
                        <?php if ($type === 'text'): ?><textarea class="form-control" id="<?= $field ?>" name="<?= $field ?>" rows="3"><?= e($val) ?></textarea>
                        <?php elseif ($type === 'color'): ?><input class="form-control form-control-color" type="color" id="<?= $field ?>" name="<?= $field ?>" value="<?= e($val ?: '#D7192A') ?>">
                        <?php elseif ($type === 'secret'): ?><input class="form-control" type="password" id="<?= $field ?>" name="<?= $field ?>" placeholder="<?= $val ? 'Configurée — inchangée si vide' : '' ?>" autocomplete="off">
                            <?php if ($val): ?><div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="<?= $field ?>__clear" value="1" id="<?= $field ?>c"><label class="form-check-label" for="<?= $field ?>c">Supprimer la clé</label></div><?php endif; ?>
                        <?php elseif (str_starts_with($type, 'select:')): ?><select class="form-select" id="<?= $field ?>" name="<?= $field ?>">
                            <?php foreach (explode(',', substr($type, 7)) as $opt): [$ov, $ol] = explode('=', $opt, 2); ?><option value="<?= e($ov) ?>" <?= $val === $ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
                        <?php else: ?><input class="form-control" id="<?= $field ?>" name="<?= $field ?>" value="<?= e($val) ?>" <?= $type === 'int' ? 'inputmode="numeric"' : '' ?>><?php endif; ?>
                    <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($tab === 'company'): ?>
                    <div class="col-md-6"><label class="form-label" for="logo">Logo (PNG, JPG, WEBP — 1 Mo max)</label><input class="form-control" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
                        <?php if (!empty($values['company.logo'])): ?><div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(asset($values['company.logo'])) ?>" alt="Logo" height="40"><div class="form-check small"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="rl"><label class="form-check-label" for="rl">Retirer</label></div></div><?php endif; ?></div>
                <?php endif; ?>
                <?php if ($tab === 'ai'): ?>
                    <div class="col-12"><div class="alert alert-<?= $sdk ? 'success' : 'secondary' ?> small mb-0">
                        Le moteur local d'Optimus fonctionne sans Internet. Le modèle externe est facultatif : il ne reçoit que des indicateurs chiffrés (aucun identifiant ni mot de passe).
                        <?= $sdk ? 'SDK Anthropic détecté.' : 'Pour l\'activer, installez le SDK officiel dans le dossier du projet : <code>composer require anthropic-ai/sdk</code> (Internet requis).' ?></div></div>
                <?php endif; ?>
            </div>
            <?php if ($canEdit): ?><div class="mc-card-head border-top border-bottom-0 justify-content-end"><button class="btn btn-primary">Enregistrer</button></div><?php endif; ?>
        </form>
    <?php endif; ?>
    </div>
</div>
