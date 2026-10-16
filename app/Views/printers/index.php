<?php $e = $edit ?? []; $sel = fn($k, $v) => (string) ($e[$k] ?? '') === (string) $v ? 'selected' : ''; $eu = array_map('trim', explode(',', (string) ($e['usages'] ?? 'receipt'))); ?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle"><thead><tr><th>Imprimante</th><th>Connexion</th><th>Papier</th><th>Usages</th><th>Caisse</th><th>État</th><th></th></tr></thead><tbody>
    <?php foreach ($printers as $p): ?><tr class="<?= (int) $p['active'] ? '' : 'text-secondary' ?>"><td class="fw-semibold"><?= e($p['name']) ?></td><td class="small"><?= e(App\Services\PrintService::DRIVERS[$p['driver']]) ?><br><code><?= e($p['address'] ?? '') ?></code></td><td><?= e($p['paper'] === 'a4' ? 'A4' : $p['paper'] . ' mm') ?></td>
      <td class="small"><?php foreach (explode(',', $p['usages']) as $u): ?><span class="badge badge-soft me-1"><?= e($usages[trim($u)] ?? $u) ?></span><?php endforeach; ?></td><td><?= e($p['register'] ?? 'Toutes') ?></td><td><?= (int) $p['active'] ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>' ?></td>
      <td class="text-end text-nowrap"><form method="post" action="<?= e(url('/printers/' . $p['id'] . '/test')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary" title="Tester"><i class="bi bi-printer"></i> Tester</button></form>
        <a class="btn btn-sm btn-light" href="<?= e(url('/printers', ['edit' => $p['id']])) ?>"><i class="bi bi-pencil"></i></a>
        <form method="post" action="<?= e(url('/printers/' . $p['id'] . '/delete')) ?>" class="d-inline" data-confirm="Supprimer cette imprimante ?"><?= csrf_field() ?><button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?>
    <?php if (!$printers): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucune imprimante configurée : tout s'imprime sur l'imprimante par défaut du navigateur.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="alert alert-info small">
      <b>Comment ça marche ?</b> Le navigateur imprime toujours sur l'imprimante <i>par défaut</i> de l'appareil. Pour envoyer chaque document vers sa propre imprimante (ex. tickets de l'alimentation à la caisse, bons cuisine en cuisine, bons boissons au bar), le serveur POS Marwa les imprime lui-même :
      <ul class="mb-1 mt-1">
        <li><b>Imprimante réseau</b> (câble Ethernet ou Wi-Fi) : saisir son adresse IP, port 9100 (ex. <code>192.168.1.50:9100</code>).</li>
        <li><b>Imprimante USB</b> branchée sur le PC serveur : dans Windows, <i>Paramètres → Imprimantes → Propriétés → Partage</i>, partager l'imprimante (ex. nom <code>XP-80</code>), puis saisir <code>\\localhost\XP-80</code>.</li>
        <li>Le pilote doit être de type ESC/POS (la plupart des imprimantes thermiques 58/80 mm) ; l'arabe s'imprime via le navigateur.</li>
      </ul>
      Sans imprimante serveur pour un usage, l'impression passe par le navigateur comme avant.
    </div>
  </div>
  <div class="col-xl-4"><div class="card <?= $e ? 'border-primary' : '' ?>"><div class="card-header"><?= $e ? 'Modifier « ' . e($e['name']) . ' »' : '<i class="bi bi-plus-circle"></i> Ajouter une imprimante' ?></div><div class="card-body">
    <form method="post" action="<?= e(url($e ? '/printers/' . $e['id'] : '/printers')) ?>" class="row g-2"><?= csrf_field() ?>
      <div class="col-12"><label class="form-label required">Nom</label><input name="name" value="<?= e($e['name'] ?? '') ?>" class="form-control" required maxlength="100" placeholder="Caisse alimentation, Cuisine, Bar…"></div>
      <div class="col-12"><label class="form-label">Connexion</label><select name="driver" class="form-select"><?php foreach (App\Services\PrintService::DRIVERS as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('driver', $k) ?: ($k === 'network' && !$e ? 'selected' : '') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label">Adresse</label><input name="address" value="<?= e($e['address'] ?? '') ?>" class="form-control" placeholder="192.168.1.50:9100 ou \\localhost\XP-80" maxlength="255"></div>
      <div class="col-6"><label class="form-label">Papier</label><select name="paper" class="form-select"><option value="80" <?= $sel('paper', '80') ?>>80 mm</option><option value="58" <?= $sel('paper', '58') ?>>58 mm</option><option value="a4" <?= $sel('paper', 'a4') ?>>A4</option></select></div>
      <div class="col-6"><label class="form-label">Copies</label><input type="number" name="copies" min="1" max="5" value="<?= (int) ($e['copies'] ?? 1) ?>" class="form-control"></div>
      <div class="col-12"><label class="form-label">Usages</label><?php foreach ($usages as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="usages[]" value="<?= e($k) ?>" id="u_<?= e($k) ?>" <?= in_array($k, $eu, true) ? 'checked' : '' ?>><label class="form-check-label" for="u_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
      <div class="col-12"><label class="form-label">Caisse concernée</label><select name="register_id" class="form-select"><option value="">Toutes les caisses</option><?php foreach ($registers as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $sel('register_id', $r['id']) ?>><?= e($r['name']) ?></option><?php endforeach; ?></select><div class="form-text">Ex. caisse « Alimentation » → imprimante A, caisse « Restaurant » → imprimante B.</div></div>
      <div class="col-12 form-check ms-1"><input type="hidden" name="cut" value="0"><input type="checkbox" class="form-check-input" name="cut" value="1" id="pc" <?= (int) ($e['cut'] ?? 1) ? 'checked' : '' ?>><label for="pc" class="form-check-label">Coupe automatique du papier</label></div>
      <div class="col-12 form-check ms-1"><input type="hidden" name="open_drawer" value="0"><input type="checkbox" class="form-check-input" name="open_drawer" value="1" id="pd" <?= (int) ($e['open_drawer'] ?? 0) ? 'checked' : '' ?>><label for="pd" class="form-check-label">Ouvrir le tiroir-caisse à chaque ticket</label></div>
      <div class="col-12 form-check ms-1"><input type="hidden" name="active" value="0"><input type="checkbox" class="form-check-input" name="active" value="1" id="pa" <?= (int) ($e['active'] ?? 1) ? 'checked' : '' ?>><label for="pa" class="form-check-label">Active</label></div>
      <div class="col-12"><button class="btn btn-primary">Enregistrer</button> <?php if ($e): ?><a href="<?= e(url('/printers')) ?>" class="btn btn-light">Annuler</a><?php endif; ?></div>
    </form>
  </div></div></div>
</div>
