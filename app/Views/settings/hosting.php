<?php /** Configuration → Hébergement et synchronisation. */ $online = $mode === 'online'; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-hdd-network"></i> Où POS Marwa est-il installé ?</div><div class="card-body">
      <form method="post" action="<?= e(url('/settings/hosting/mode')) ?>"><?= csrf_field() ?>
        <div class="btn-group w-100 mb-3" role="group">
          <input type="radio" class="btn-check" name="hosting_mode" value="local" id="hmLocal" <?= !$online ? 'checked' : '' ?>><label class="btn btn-outline-primary" for="hmLocal"><i class="bi bi-pc-display"></i> En local<br><small>PC ou serveur de la boutique</small></label>
          <input type="radio" class="btn-check" name="hosting_mode" value="online" id="hmOnline" <?= $online ? 'checked' : '' ?>><label class="btn btn-outline-primary" for="hmOnline"><i class="bi bi-cloud"></i> En ligne<br><small>hébergeur (o2switch…)</small></label>
        </div>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
        <span class="small text-secondary ms-2"><?= $explicit ? 'Choix enregistré.' : 'Détection automatique : ' . ($guess === 'online' ? 'en ligne' : 'en local') . '.' ?></span>
      </form>
      <hr>
      <p class="small mb-1"><b>En local</b> : impression directe sur les imprimantes du réseau, accès mobile par le Wi-Fi, caméras, assistance à distance, synchronisation vers la base en ligne.</p>
      <p class="small mb-0"><b>En ligne</b> : ces écrans propres au réseau de la boutique sont masqués ; l'impression passe par le navigateur ; la synchronisation ne se configure pas ici (elle part du poste local).</p>
    </div></div>
    <?php if ($online && $replicaAt): ?><div class="alert alert-warning small"><i class="bi bi-arrow-repeat"></i> Cette installation en ligne est <b>alimentée par un poste local</b> (dernière synchronisation reçue : <?= e(fdate($replicaAt)) ?>). Ce qui est saisi ici sera <b>remplacé</b> à la prochaine synchronisation : saisissez sur le poste local.</div><?php endif; ?>
  </div>
  <div class="col-lg-7">
    <?php if ($online): ?>
    <div class="card"><div class="card-body text-secondary"><i class="bi bi-info-circle"></i> La synchronisation se règle sur le poste <b>local</b> de la boutique : c'est lui qui envoie ses données vers cette base en ligne.</div></div>
    <?php else: ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-database-up"></i> Base de données MySQL en ligne</div><div class="card-body">
      <form method="post" action="<?= e(url('/settings/hosting')) ?>" class="row g-2" autocomplete="off"><?= csrf_field() ?>
        <div class="col-md-8"><label class="form-label small mb-0">Serveur MySQL (ex. nom-du-serveur.o2switch.net)</label><input name="host" value="<?= e($c['host']) ?>" class="form-control form-control-sm" required maxlength="190"></div>
        <div class="col-md-4"><label class="form-label small mb-0">Port</label><input name="port" type="number" value="<?= (int) $c['port'] ?>" class="form-control form-control-sm" min="1" max="65535"></div>
        <div class="col-md-6"><label class="form-label small mb-0">Nom de la base</label><input name="database" value="<?= e($c['database']) ?>" class="form-control form-control-sm" required maxlength="64"></div>
        <div class="col-md-6"><label class="form-label small mb-0">Utilisateur de la base</label><input name="username" value="<?= e($c['username']) ?>" class="form-control form-control-sm" required maxlength="64" autocomplete="off"></div>
        <div class="col-md-6"><label class="form-label small mb-0">Mot de passe <?= $c['has_password'] ? '<span class="text-success">(enregistré, chiffré)</span>' : '' ?></label><input name="password" type="password" class="form-control form-control-sm" autocomplete="new-password" placeholder="<?= $c['has_password'] ? 'laisser vide pour le garder' : '' ?>" <?= $c['has_password'] ? '' : 'required' ?>></div>
        <div class="col-md-6"><label class="form-label small mb-0">Synchronisation automatique</label><select name="auto_minutes" class="form-select form-select-sm"><?php foreach ([0 => 'Non (manuelle ou tâche planifiée)', 15 => 'Toutes les 15 minutes', 30 => 'Toutes les 30 minutes', 60 => 'Toutes les heures', 240 => 'Toutes les 4 heures', 1440 => 'Une fois par jour'] as $k => $l): ?><option value="<?= $k ?>" <?= $c['auto_minutes'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="syncOn" <?= $c['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="syncOn">Activer la synchronisation vers la base en ligne</label></div></div>
        <div class="col-md-4 text-end"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button></div>
      </form>
      <p class="small text-secondary mt-2 mb-0"><i class="bi bi-shield-lock"></i> Le mot de passe est chiffré avec la clé de cette installation ; il n'est jamais affiché ni envoyé en ligne. Chez o2switch : cPanel → « MySQL distant » → ajoutez l'adresse IP publique de la boutique, sinon la connexion est refusée.</p>
    </div></div>
    <div class="card"><div class="card-header"><i class="bi bi-arrow-repeat"></i> Synchroniser</div><div class="card-body">
      <p class="small mb-2">Dernière synchronisation : <b><?= $c['last_at'] ? e(fdate($c['last_at'])) : 'jamais' ?></b><?= $c['last_result'] !== '' && $c['last_result'] !== 'ok' ? ' · <span class="text-danger">dernier essai : ' . e($c['last_result']) . '</span>' : '' ?> · <?= (int) $tables ?> table(s) locales.</p>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <button type="button" class="btn btn-outline-primary btn-sm" id="syncTest" <?= $c['has_password'] ? '' : 'disabled' ?>><i class="bi bi-plug"></i> Tester la connexion</button>
        <button type="button" class="btn btn-success btn-sm" id="syncGo" <?= $c['has_password'] && $c['enabled'] ? '' : 'disabled' ?>><i class="bi bi-cloud-upload"></i> Synchroniser maintenant</button>
        <div class="form-check ms-2"><input class="form-check-input" type="checkbox" id="syncConfirm"><label class="form-check-label small" for="syncConfirm">Je confirme : remplacer le contenu actuel de la base en ligne (je l'ai sauvegardé)</label></div>
      </div>
      <div class="progress d-none mb-2" style="height:10px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="syncBar" style="width:0"></div></div>
      <div id="syncOut" class="alert d-none py-2 small" role="status"></div>
      <ul class="small text-secondary mb-0">
        <li><b>Sens unique</b> : le poste local est la référence. La base en ligne devient sa copie ; ce qui a été saisi directement en ligne est remplacé.</li>
        <li>Seules les données qui ont changé sont envoyées. Sans Internet, rien ne se passe : un nouvel essai est fait plus tard.</li>
        <li>La <b>base de données</b> est copiée, pas les fichiers : les photos des articles et les documents archivés restent sur ce poste.</li>
        <li>Tâche planifiée Windows (sans ouvrir POS Marwa) : <code>php bin\sync.php</code>.</li>
        <li>La case de confirmation n'est demandée que si la base en ligne contient déjà des tables qui ne viennent pas de ce poste.</li>
      </ul>
    </div></div>
    <?php endif; ?>
  </div>
</div>
