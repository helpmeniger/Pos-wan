<?php
use Addons\MarwaAssist\AssistService;

$labels = ['assistance' => ['Assistance', 'bi-headset'], 'postes' => ['Postes équipés', 'bi-pc-display'], 'diagnostic' => ['Diagnostic', 'bi-activity'],
    'historique' => ['Historique', 'bi-clock-history'], 'reglages' => ['Réglages', 'bi-sliders'], 'htn' => ['Serveur HTN et boutiques', 'bi-hdd-network']];
$badge = ['demandee' => 'secondary', 'observation' => 'info', 'controle' => 'danger', 'terminee' => 'success', 'expiree' => 'dark'];
$digits = preg_replace('/\D/', '', $s['phone']);
$wa = function (string $text) use ($digits): string {
    return $digits !== '' ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($text) : '';
};
$cur = $mine[0] ?? null;
$old = fn(string $k, string $v) => App\Core\Session::old($k, $v); // saisie conservée si un champ est refusé
?>
<div class="alert alert-light border small mb-3">
  <i class="bi bi-headset text-primary"></i> <b>MARWA REMOTE ASSIST</b> — High Tech Niger peut voir l'écran de ce poste pour vous aider,
  <b>sans arrêter la caisse</b>. Chaque connexion doit être <b>acceptée sur ce poste</b> ; le technicien <b>observe seulement</b>
  tant qu'un responsable n'a pas autorisé le contrôle (durée limitée). Une fenêtre RustDesk et un bandeau rouge restent affichés pendant toute l'assistance.
</div>

<ul class="nav nav-tabs mb-3">
  <?php foreach ($tabs as $t): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $t ? 'active' : '' ?>" href="<?= e(url('/assist?tab=' . $t)) ?>"><i class="bi <?= e($labels[$t][1]) ?>"></i> <?= e($labels[$t][0]) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'assistance'): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-header"><i class="bi bi-person-badge"></i> À donner au technicien</div><div class="card-body">
      <div class="small text-secondary">Code boutique</div>
      <div class="fs-4 fw-bold font-monospace" data-copy><?= e($code) ?></div>
      <div class="small text-secondary mt-2">Ce poste</div>
      <?php if ($poste): ?>
        <div class="fw-semibold"><?= e($poste['label']) ?></div>
        <div class="small text-secondary">Identifiant RustDesk</div>
        <div class="fs-3 fw-bold font-monospace" data-copy><?= e(trim(chunk_split($poste['rustdesk_id'], 3, ' '))) ?></div>
      <?php else: ?>
        <div class="alert alert-warning py-2 small mb-0">Ce poste n'est pas encore équipé pour l'assistance. Le responsable prépare un kit dans
          <b>Assistance à distance → Postes équipés</b> (une seule fois par PC). En attendant, ouvrez RustDesk s'il est installé et lisez son identifiant au technicien.</div>
      <?php endif; ?>
      <hr>
      <div class="small">High Tech Niger : <b><?= e($s['phone'] ?: '—') ?></b></div>
      <?php if ($digits !== ''): ?>
        <a class="btn btn-success btn-sm mt-2" target="_blank" rel="noopener" href="<?= e($wa('Bonjour High Tech Niger, besoin d\'assistance POS Marwa. Code boutique : ' . $code . ($poste ? ' ; poste : ' . $poste['label'] . ' ; identifiant : ' . $poste['rustdesk_id'] : '') . ($cur ? ' ; problème : ' . $cur['reason'] : ''))) ?>"><i class="bi bi-whatsapp"></i> Écrire au technicien</a>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-7">
    <?php if (!$cur): ?>
      <div class="card"><div class="card-header"><i class="bi bi-life-preserver"></i> Assistance à distance</div><div class="card-body">
        <form method="post" action="<?= e(url('/assist/demande')) ?>"><?= csrf_field() ?>
          <label class="form-label small mb-0">Quel est le problème ?</label>
          <textarea name="reason" class="form-control mb-2" rows="2" maxlength="300" required placeholder="ex. l'imprimante n'imprime plus le ticket"></textarea>
          <?php if ($manage && count(array_filter($postes, fn($p) => $p['rustdesk_id'] && !$p['revoked_at'])) > 1): ?>
            <label class="form-label small mb-0">Poste concerné</label>
            <select name="poste_id" class="form-select form-select-sm mb-2"><option value="">Ce poste</option>
              <?php foreach ($postes as $p): if (!$p['rustdesk_id'] || $p['revoked_at']) continue; ?><option value="<?= (int) $p['id'] ?>"><?= e($p['label'] . ' (' . $p['rustdesk_id'] . ')') ?></option><?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button class="btn btn-primary"><i class="bi bi-headset"></i> Assistance à distance</button>
        </form>
      </div></div>
    <?php else: ?>
      <div class="card border-<?= e($badge[$cur['status']]) ?>"><div class="card-header d-flex justify-content-between">
        <span><i class="bi bi-life-preserver"></i> Assistance n° <?= (int) $cur['id'] ?> — <span class="badge text-bg-<?= e($badge[$cur['status']]) ?>"><?= e(AssistService::STATUS[$cur['status']]) ?></span></span>
        <span class="small text-secondary"><?= e($cur['created_at']) ?></span></div>
      <div class="card-body">
        <div class="mb-2"><b>Problème :</b> <?= e($cur['reason']) ?></div>
        <?php if ($cur['status'] === 'controle'): ?>
          <div class="alert alert-danger py-2"><i class="bi bi-mouse"></i> <b>Contrôle autorisé</b> par <?= e($cur['control_name'] ?? '?') ?> jusqu'à <b data-until="<?= e($cur['control_until']) ?>"><?= e(date('H:i', strtotime($cur['control_until']))) ?></b>.
            Le technicien peut utiliser la souris et le clavier si « Clavier » est coché dans la fenêtre RustDesk. Retour automatique en observation à l'heure indiquée.</div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($cur['status'] !== 'observation'): ?>
            <form method="post" action="<?= e(url('/assist/' . (int) $cur['id'] . '/partager')) ?>"><?= csrf_field() ?>
              <button class="btn btn-info"><i class="bi bi-display"></i> <?= $cur['status'] === 'controle' ? 'Retirer le contrôle (observation)' : 'Partager mon écran' ?></button></form>
          <?php endif; ?>
          <?php if ($cur['status'] !== 'controle'): ?>
            <button type="button" class="btn btn-outline-danger" data-show="#ctrlForm"><i class="bi bi-mouse"></i> Autoriser le contrôle à distance</button>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-secondary" data-show="#endForm"><i class="bi bi-stop-circle"></i> Terminer l'assistance</button>
        </div>
        <form id="ctrlForm" class="border rounded p-2 mt-2 d-none" method="post" action="<?= e(url('/assist/' . (int) $cur['id'] . '/controle')) ?>" autocomplete="off"><?= csrf_field() ?>
          <div class="small mb-1"><b>Autorisation d'un responsable</b> (gérant ou propriétaire) : le technicien pourra utiliser la souris et le clavier pendant la durée choisie.</div>
          <div class="row g-2">
            <div class="col-sm-4"><input name="username" class="form-control form-control-sm" placeholder="Identifiant du responsable" required></div>
            <div class="col-sm-4"><input name="secret" type="password" class="form-control form-control-sm" placeholder="Mot de passe ou PIN" required></div>
            <div class="col-sm-2"><select name="minutes" class="form-select form-select-sm"><?php foreach ([5, 10, 15, 30, 60] as $m): if ($m > $s['minutes']) continue; ?><option value="<?= $m ?>" <?= $m === min(15, $s['minutes']) ? 'selected' : '' ?>><?= $m ?> min</option><?php endforeach; ?></select></div>
            <div class="col-sm-2"><button class="btn btn-danger btn-sm w-100">Autoriser</button></div>
          </div>
        </form>
        <form id="endForm" class="border rounded p-2 mt-2 d-none" method="post" action="<?= e(url('/assist/' . (int) $cur['id'] . '/terminer')) ?>"><?= csrf_field() ?>
          <div class="row g-2">
            <div class="col-sm-4"><input name="technician" class="form-control form-control-sm" maxlength="60" placeholder="Nom du technicien"></div>
            <div class="col-sm-6"><input name="summary" class="form-control form-control-sm" maxlength="500" placeholder="Ce qui a été fait (facultatif)"></div>
            <div class="col-sm-2"><button class="btn btn-secondary btn-sm w-100">Terminer</button></div>
          </div>
        </form>
        <hr>
        <ol class="small mb-0">
          <li>Donnez le <b>code boutique</b> et l'<b>identifiant</b> au technicien (téléphone ou WhatsApp).</li>
          <li>Quand la fenêtre RustDesk demande d'<b>accepter</b> la connexion, cliquez <b>Accepter</b>. Continuez vos ventes normalement.</li>
          <li>Le technicien a besoin de la souris ? Un responsable clique <b>Autoriser le contrôle</b>, puis cochez <b>Clavier</b> dans la fenêtre RustDesk.</li>
          <li>Fini : <b>Terminer l'assistance</b> ici et <b>Déconnecter</b> dans la fenêtre RustDesk.</li>
        </ol>
      </div></div>
    <?php endif; ?>
    <?php $others = array_filter($all, fn($i) => !$cur || (int) $i['id'] !== (int) $cur['id']); if ($others): ?>
      <div class="card mt-3"><div class="card-header small">Autres assistances en cours dans la boutique</div><ul class="list-group list-group-flush small">
        <?php foreach ($others as $i): ?><li class="list-group-item d-flex justify-content-between align-items-center"><span>n° <?= (int) $i['id'] ?> · <?= e($i['poste_label']) ?> · <span class="badge text-bg-<?= e($badge[$i['status']]) ?>"><?= e(AssistService::STATUS[$i['status']]) ?></span> · <?= e($i['reason']) ?></span>
          <form method="post" action="<?= e(url('/assist/' . (int) $i['id'] . '/terminer')) ?>" data-confirm="Terminer cette assistance ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Terminer</button></form></li><?php endforeach; ?>
      </ul></div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'postes'): ?>
<div class="row g-3">
  <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-box-arrow-down"></i> Équiper un poste (une seule fois)</div><div class="card-body small">
    <?php if (!$s['host'] || !$s['key']): ?><div class="alert alert-warning py-2">Réglez d'abord l'adresse et la clé du serveur HTN (onglet Réglages).</div><?php endif; ?>
    <form method="post" action="<?= e(url('/assist/kit')) ?>" class="mb-3"><?= csrf_field() ?><input type="hidden" name="kind" value="boutique">
      <label class="form-label mb-0">Nom du poste de la boutique</label>
      <div class="input-group input-group-sm"><input name="label" class="form-control" maxlength="40" required placeholder="ex. Caisse 1"><button class="btn btn-primary" <?= $s['host'] && $s['key'] ? '' : 'disabled' ?>><i class="bi bi-download"></i> Kit du poste</button></div>
      <div class="text-secondary mt-1">Téléchargez le kit <b>sur le PC à équiper</b>, décompressez-le, puis double-cliquez <code>installer-assistance.bat</code> (droits administrateur). RustDesk s'installe, se règle sur votre serveur et transmet son identifiant ici.</div>
    </form>
    <form method="post" action="<?= e(url('/assist/kit')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="technicien">
      <label class="form-label mb-0">Poste du technicien High Tech Niger</label>
      <div class="input-group input-group-sm"><input name="label" class="form-control" maxlength="40" required placeholder="ex. Technicien Mohamed"><button class="btn btn-outline-primary" <?= $s['host'] && $s['key'] ? '' : 'disabled' ?>><i class="bi bi-download"></i> Kit technicien</button></div>
    </form>
    <div class="text-secondary mt-2">Kits valables <?= (int) $s['kit_days'] ?> jour(s). RustDesk 1.5.0 officiel, non modifié (licence AGPL-3.0 jointe).</div>
  </div></div></div>
  <div class="col-lg-7"><div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
    <thead><tr><th>Poste</th><th>Identifiant</th><th>PC / adresse</th><th>État</th><th></th></tr></thead><tbody>
    <?php if (!$postes): ?><tr><td colspan="5" class="text-secondary text-center py-3">Aucun poste équipé.</td></tr><?php endif; ?>
    <?php foreach ($postes as $p): ?>
      <tr class="<?= $p['revoked_at'] ? 'text-secondary' : '' ?>">
        <td><?= e($p['label']) ?><div class="text-secondary"><?= $p['kind'] === 'technicien' ? 'technicien' : 'boutique' ?></div></td>
        <td class="font-monospace"><?= e($p['rustdesk_id'] ?? '—') ?></td>
        <td><?= e(($p['hostname'] ?? '') . ' ' . ($p['ip'] ?? '')) ?></td>
        <td><?php if ($p['revoked_at']): ?>retiré<?php elseif ($p['rustdesk_id']): ?><span class="text-success">prêt</span><?php elseif (strtotime((string) $p['token_expires']) < time()): ?><span class="text-warning">kit expiré</span><?php else: ?>kit en attente<?php endif; ?></td>
        <td class="text-end"><?php if (!$p['revoked_at']): ?>
          <?php if ($p['kind'] === 'boutique'): ?>
          <form method="post" action="<?= e(url('/assist/postes/' . (int) $p['id'] . '/id')) ?>" class="d-inline-flex gap-1 mb-1"><?= csrf_field() ?>
            <input name="rustdesk_id" class="form-control form-control-sm" style="width:8rem" placeholder="Identifiant" inputmode="numeric">
            <label class="small text-nowrap" title="Associer ce poste à l'ordinateur sur lequel vous êtes"><input type="checkbox" name="this_pc" value="1"> ce PC</label>
            <button class="btn btn-sm btn-outline-primary">OK</button></form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/assist/postes/' . (int) $p['id'] . '/revoquer')) ?>" class="d-inline" data-confirm="Retirer ce poste de l'assistance ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Retirer</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div></div></div>
</div>

<?php elseif ($tab === 'diagnostic'): ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-activity"></i> État technique</div><table class="table table-sm mb-0 small">
    <?php foreach ($diag as [$l, $st, $d]): ?><tr><td><?= ['ok' => '<i class="bi bi-check-circle-fill text-success"></i>', 'warn' => '<i class="bi bi-exclamation-triangle-fill text-warning"></i>', 'bad' => '<i class="bi bi-x-circle-fill text-danger"></i>', 'info' => '<i class="bi bi-info-circle text-secondary"></i>'][$st] ?></td><td><?= e($l) ?></td><td><?= e($d) ?></td></tr><?php endforeach; ?>
  </table></div>
  <div class="card mt-3"><div class="card-header small">Dernières erreurs techniques</div><div class="card-body small">
    <?php if (!$errors): ?><span class="text-success">Aucune erreur récente.</span><?php else: ?><ul class="mb-0 font-monospace" style="font-size:.75rem"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="card"><div class="card-header small">À transmettre au technicien</div><div class="card-body">
    <textarea class="form-control form-control-sm font-monospace mb-2" rows="10" readonly data-diag><?= e($diagText) ?></textarea>
    <button type="button" class="btn btn-sm btn-outline-primary" data-copy-from="[data-diag]"><i class="bi bi-clipboard"></i> Copier</button>
    <?php if ($digits !== ''): ?><a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="<?= e($wa($diagText)) ?>"><i class="bi bi-whatsapp"></i> Envoyer</a><?php endif; ?>
    <div class="small text-secondary mt-2">Seul l'état technique est inclus : aucune vente, aucun client, aucun mot de passe.</div>
  </div></div></div>
</div>

<?php elseif ($tab === 'historique'): ?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-striped mb-0 small">
  <thead><tr><th>N°</th><th>Date</th><th>Poste</th><th>Problème</th><th>État</th><th>Demandé par</th><th>Contrôle autorisé par</th><th>Technicien / résumé</th><th>Durée</th></tr></thead><tbody>
  <?php if (!$history): ?><tr><td colspan="9" class="text-center text-secondary py-3">Aucune intervention.</td></tr><?php endif; ?>
  <?php foreach ($history as $h): ?><tr>
    <td><a href="<?= e(url('/assist?tab=historique&i=' . (int) $h['id'])) ?>"><?= (int) $h['id'] ?></a></td><td class="text-nowrap"><?= e($h['created_at']) ?></td><td><?= e($h['poste_label']) ?></td><td><?= e($h['reason']) ?></td>
    <td><span class="badge text-bg-<?= e($badge[$h['status']]) ?>"><?= e(AssistService::STATUS[$h['status']]) ?></span></td><td><?= e($h['requested_name'] ?? '') ?></td><td><?= e($h['control_name'] ?? '—') ?></td>
    <td><?= e(trim(($h['technician'] ?? '') . ' ' . ($h['summary'] ?? ''))) ?></td><td><?= (int) $h['minutes'] ?> min</td></tr>
    <?php if ($sel === (int) $h['id']): ?><tr><td colspan="9"><ul class="mb-0"><?php foreach ($events as $ev): ?><li><?= e($ev['created_at'] . ' — ' . $ev['action'] . ' — ' . ($ev['full_name'] ?? '') . ' ' . ($ev['details'] ?? '') . ' (' . $ev['ip'] . ')') ?></li><?php endforeach; ?></ul></td></tr><?php endif; ?>
  <?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'reglages'): ?>
<form method="post" action="<?= e(url('/assist/reglages')) ?>" class="card"><?= csrf_field() ?><div class="card-body row g-3 small">
  <div class="col-md-6">
    <label class="form-label mb-0">Rôle de cette installation</label>
    <select name="assist_role" class="form-select form-select-sm"><option value="boutique" <?= $old('assist_role', $s['role']) === 'boutique' ? 'selected' : '' ?>>Boutique (postes assistés)</option><option value="serveur" <?= $old('assist_role', $s['role']) === 'serveur' ? 'selected' : '' ?>>High Tech Niger : serveur d'assistance + annuaire des boutiques</option></select>
    <label class="form-label mb-0 mt-2">Adresse du serveur d'assistance HTN</label>
    <input name="assist_serveur_adresse" class="form-control form-control-sm font-monospace" value="<?= e($old('assist_serveur_adresse', $s['host'])) ?>" placeholder="ex. 41.203.10.20 (adresse IP publique, pas de nom de domaine nécessaire)">
    <label class="form-label mb-0 mt-2">Clé publique du serveur HTN</label>
    <input name="assist_serveur_cle" class="form-control form-control-sm font-monospace" value="<?= e($old('assist_serveur_cle', $s['key'])) ?>" placeholder="44 caractères, affichée par le serveur">
    <div class="text-secondary">Un poste qui n'a pas cette clé est refusé par le serveur.</div>
  </div>
  <div class="col-md-6">
    <label class="form-label mb-0">Durée maximale du contrôle à distance</label>
    <select name="assist_controle_minutes" class="form-select form-select-sm"><?php foreach ([5, 10, 15, 30, 60] as $m): ?><option value="<?= $m ?>" <?= $s['minutes'] === $m ? 'selected' : '' ?>><?= $m ?> minutes</option><?php endforeach; ?></select>
    <label class="form-label mb-0 mt-2">Téléphone / WhatsApp de High Tech Niger</label>
    <input name="assist_tel_htn" class="form-control form-control-sm" value="<?= e($old('assist_tel_htn', $s['phone'])) ?>">
    <label class="form-label mb-0 mt-2">Validité des kits (jours)</label>
    <input type="number" min="1" max="30" name="assist_kit_jours" class="form-control form-control-sm" value="<?= (int) $s['kit_days'] ?>">
    <div class="border border-danger rounded p-2 mt-3">
      <label><input type="checkbox" name="assist_sans_surveillance" value="1" <?= $s['unattended'] ? 'checked' : '' ?>> <b>Accès sans surveillance</b> (désactivé par défaut)</label>
      <div class="text-secondary">Les <b>nouveaux kits</b> demanderont un mot de passe permanent au lieu d'un clic d'acceptation : le technicien pourra se connecter sans personne devant le poste. À n'activer qu'avec l'accord écrit du propriétaire de la boutique.</div>
      <?php if (!$s['unattended']): ?><input name="confirmation" class="form-control form-control-sm mt-1" placeholder="Pour activer, tapez : SANS SURVEILLANCE" autocomplete="off"><?php endif; ?>
    </div>
  </div>
  <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer</button></div>
</div></form>

<?php else: /* htn */ ?>
<div class="row g-3">
  <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-hdd-network"></i> Serveur d'assistance (ce poste)</div><div class="card-body small">
    <div class="mb-2">État : <?= $relay['running'] ? '<b class="text-success">actif</b>' : '<b class="text-danger">arrêté</b>' ?>
      <?php foreach ($relay['ports'] as $p => $on): ?><span class="badge text-bg-<?= $on ? 'success' : 'secondary' ?> ms-1"><?= e((string) $p) ?></span><?php endforeach; ?></div>
    <?php if ($relay['key']): ?>
      <div>Clé publique à mettre dans chaque boutique :</div>
      <div class="input-group input-group-sm mb-2"><input class="form-control font-monospace" readonly value="<?= e($relay['key']) ?>" data-key><button type="button" class="btn btn-outline-primary" data-copy-from="[data-key]"><i class="bi bi-clipboard"></i></button></div>
    <?php endif; ?>
    <ol class="ps-3 mb-0">
      <li>Démarrer : double-cliquez <code>windows\assist-serveur.bat</code> (ou <code>php bin/assist.php serveur</code> sur un VPS Linux). La fenêtre doit rester ouverte.</li>
      <li>Ouvrir vers ce poste, sur la box ou le pare-feu : <b>TCP 21115, 21116, 21117</b> et <b>UDP 21116</b>. Rien d'autre (POS Marwa reste fermé à Internet).</li>
      <li>Dans chaque boutique (Réglages) : votre <b>adresse IP publique</b> (ou l'adresse de ce poste sur le réseau local, ex. 192.168.1.10, jamais 127.0.0.1) et la <b>clé</b> ci-dessus. Aucun nom de domaine n'est nécessaire.</li>
    </ol>
  </div></div></div>
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-shop"></i> Boutiques suivies</div><div class="card-body small">
    <form method="post" action="<?= e(url('/assist/boutiques')) ?>" class="row g-2 mb-3"><?= csrf_field() ?>
      <div class="col-md-4"><input name="name" class="form-control form-control-sm" required placeholder="Nom de la boutique"></div>
      <div class="col-md-4"><input name="code" class="form-control form-control-sm font-monospace" placeholder="Code PW-XXXX-…"></div>
      <div class="col-md-4"><input name="phone" class="form-control form-control-sm" placeholder="Téléphone"></div>
      <div class="col-md-10"><input name="postes" class="form-control form-control-sm" placeholder="Postes : Caisse 1 = 123456789 ; Bureau = 987654321"></div>
      <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Ajouter</button></div>
    </form>
    <table class="table table-sm align-middle mb-0"><thead><tr><th>Boutique</th><th>Postes (identifiants RustDesk)</th><th></th></tr></thead><tbody>
      <?php if (!$boutiques): ?><tr><td colspan="3" class="text-secondary">Aucune boutique.</td></tr><?php endif; ?>
      <?php foreach ($boutiques as $b): ?><tr><td><b><?= e($b['name']) ?></b><div class="font-monospace text-secondary"><?= e($b['code'] ?? '') ?></div><?= e($b['phone'] ?? '') ?></td>
        <td class="font-monospace"><?= e($b['postes'] ?? '') ?></td>
        <td><form method="post" action="<?= e(url('/assist/boutiques/' . (int) $b['id'] . '/archiver')) ?>" data-confirm="Retirer cette boutique de l'annuaire ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Retirer</button></form></td></tr><?php endforeach; ?>
    </tbody></table>
  </div></div></div>
</div>
<?php endif; ?>
