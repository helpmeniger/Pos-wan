<div class="alert alert-info small"><i class="bi bi-tv"></i> Sur un <b>téléviseur connecté</b>, une box Android, une tablette ou un second PC : ouvrez le navigateur, tapez l'adresse de l'écran, puis l'identifiant et le mot de passe <b>d'un utilisateur autorisé pour cet écran</b>. L'écran est mémorisé : il se reconnecte seul après une coupure de courant ou de réseau. Il n'a accès qu'à son affichage, jamais à la caisse ni à la gestion.</div>
<?php if ($local): ?>
<div class="alert <?= $ip ? 'alert-success' : 'alert-warning' ?> small d-flex flex-wrap align-items-center gap-2">
  <?php if ($ip): ?><i class="bi bi-router"></i> Adresse du réseau détectée automatiquement : <b><?= e($ip) ?></b>. Le téléviseur doit être connecté au <b>même Wi-Fi / réseau</b> que cet ordinateur. Cette adresse peut changer après un redémarrage de la box : revenez sur cette page pour la vérifier.
    <?php if (count($ips) > 1): ?><form method="get" class="ms-auto d-flex gap-1 align-items-center"><label class="small" for="scrIp">Autre carte réseau :</label><select name="ip" id="scrIp" class="form-select form-select-sm w-auto" data-autosubmit><?php foreach ($ips as $x): ?><option <?= $x === $ip ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-secondary">OK</button></form><?php endif; ?>
  <?php else: ?><i class="bi bi-exclamation-triangle"></i> Aucune adresse de réseau local trouvée : cet ordinateur n'est relié à aucun Wi-Fi / réseau. Branchez-le au réseau de la boutique puis rechargez la page.<?php endif; ?>
</div>
<?php endif; ?>
<div class="row g-3 mb-3">
<?php foreach ($screens as $code => $s): ?>
  <div class="col-md-6 col-xl-4"><div class="card h-100 <?= $s['available'] ? '' : 'opacity-50' ?>"><div class="card-body">
    <div class="d-flex gap-3">
      <div data-qr="<?= e($s['url']) ?>" data-qr-size="3" style="width:96px;flex:none" class="qr"></div>
      <div class="flex-grow-1"><div class="fw-semibold"><i class="bi <?= e($s['icon']) ?>"></i> <?= e($s['label']) ?></div>
        <div class="small text-secondary mb-1">Droit requis : <code><?= e($s['perm']) ?></code><?= $s['available'] ? '' : ' · <b>module non activé</b>' ?></div>
        <div class="input-group input-group-sm"><input class="form-control" value="<?= e($s['url']) ?>" readonly aria-label="Adresse"><a class="btn btn-outline-primary" href="<?= e($s['url']) ?>" target="_blank" title="Ouvrir"><i class="bi bi-box-arrow-up-right"></i></a></div></div>
    </div>
  </div></div></div>
<?php endforeach; ?>
</div>
<div class="card"><div class="card-header"><i class="bi bi-display"></i> Écrans mémorisés</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Écran</th><th>Compte</th><th>Appareil</th><th>Connecté le</th><th>Dernière activité</th><th></th></tr></thead><tbody>
  <?php foreach ($devices as $d): $sc = $screens[$d['screen']] ?? null; ?>
    <tr class="<?= $d['revoked_at'] ? 'text-secondary' : '' ?>"><td><?= e($sc['label'] ?? $d['screen']) ?></td><td><?= e($d['full_name']) ?> <span class="small text-secondary">(<?= e($d['username']) ?>)</span></td>
      <td class="small text-truncate" style="max-width:260px" title="<?= e($d['label'] ?? '') ?>"><?= e($d['label'] ?? '—') ?><div class="text-secondary"><?= e($d['ip'] ?? '') ?></div></td>
      <td class="small"><?= e(fdate($d['created_at'])) ?></td><td class="small"><?= $d['last_seen_at'] ? e(fdate($d['last_seen_at'])) : '—' ?></td>
      <td><?php if ($d['revoked_at']): ?><span class="badge text-bg-secondary">Déconnecté</span><?php else: ?><form method="post" action="<?= e(url('/screens/' . $d['id'] . '/revoke')) ?>" data-confirm="Déconnecter cet écran ? Il demandera de nouveau un mot de passe."><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Déconnecter</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$devices): ?><tr><td colspan="6" class="text-secondary small p-3">Aucun écran mémorisé.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-footer small text-secondary">Un écran est aussi déconnecté automatiquement si le mot de passe du compte change, si le compte est désactivé ou perd le droit de l'écran.</div>
</div>
