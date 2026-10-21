<?php
use Addons\MarwaVoice\VoiceService;

$levels = VoiceService::LEVELS;
$levelCls = ['secondary', 'info', 'warning', 'danger', 'dark'];
$tabs = $manage ? ['reglages' => ['Réglages', 'bi-sliders'], 'poste' => ['Ce poste', 'bi-pc-display'], 'diagnostic' => ['Diagnostic', 'bi-activity'], 'historique' => ['Historique', 'bi-clock-history']]
    : ['poste' => ['Ce poste', 'bi-pc-display']];
$val = fn($k, $d = '') => (string) setting($k, $d);
?>
<div class="alert alert-light border small mb-3">
  <i class="bi bi-megaphone text-info"></i> <b>MARWA VOICE AI — phase 1 : POS Marwa parle.</b>
  Annonces des ventes, des erreurs de caisse, de paiement et d'impression, et des messages d'erreur des pages.
  La voix est produite <b>sur place, sans Internet</b> (Piper sur le serveur, sinon la voix française de Windows) et lue
  <b>sur le poste concerné</b>. Les messages restent toujours affichés à l'écran. Aucun microphone n'est utilisé dans cette phase.
</div>

<ul class="nav nav-tabs mb-3">
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/voix?tab=' . $k)) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'reglages'): ?>
<form method="post" action="<?= e(url('/voix/reglages')) ?>"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card h-100"><div class="card-header"><i class="bi bi-sliders"></i> Général</div><div class="card-body">
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="voix_actif" id="vActif" value="1" <?= $val('voix_actif', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="vActif"><b>Annonces vocales activées</b></label></div>
        <label class="form-label small mb-0">Voix utilisée</label>
        <select name="voix_moteur" class="form-select form-select-sm mb-2">
          <?php foreach (['auto' => 'Automatique : Piper si installé, sinon voix du poste', 'piper' => 'Piper (serveur) — voix du poste en secours', 'navigateur' => 'Voix installée sur chaque poste (Windows)'] as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $val('voix_moteur', 'auto') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($piper['voices']): ?>
          <label class="form-label small mb-0">Voix Piper</label>
          <select name="voix_piper_voix" class="form-select form-select-sm mb-2">
            <option value="">Première voix française</option>
            <?php foreach ($piper['voices'] as $v): ?><option value="<?= e($v['file']) ?>" <?= $val('voix_piper_voix') === $v['file'] ? 'selected' : '' ?>><?= e($v['name'] . ' (' . $v['lang'] . ', ' . $v['quality'] . ')') ?></option><?php endforeach; ?>
          </select>
        <?php else: ?><input type="hidden" name="voix_piper_voix" value=""><?php endif; ?>
        <label class="form-label small mb-0">Voix des postes (nom exact, facultatif)</label>
        <input name="voix_navigateur_voix" class="form-control form-control-sm mb-1" value="<?= e($val('voix_navigateur_voix')) ?>" placeholder="ex. Microsoft Hortense - French (France)" list="vVoiceList">
        <datalist id="vVoiceList"></datalist>
        <div class="form-check mb-2 small"><input class="form-check-input" type="checkbox" name="voix_hors_ligne" id="vOff" value="1" <?= $val('voix_hors_ligne', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="vOff">Voix locales uniquement (aucune voix « en ligne » qui enverrait le texte sur Internet)</label></div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small mb-0">Volume</label><input type="range" class="form-range" name="voix_volume" min="0.1" max="1" step="0.05" value="<?= e($val('voix_volume', '0.9')) ?>"></div>
          <div class="col-6"><label class="form-label small mb-0">Débit</label><input type="range" class="form-range" name="voix_debit" min="0.6" max="1.6" step="0.05" value="<?= e($val('voix_debit', '1.0')) ?>"></div>
        </div>
        <hr>
        <div class="fw-semibold small mb-1"><i class="bi bi-moon"></i> Plage de silence (tous les postes)</div>
        <div class="row g-2 align-items-center small">
          <div class="col-auto">de</div><div class="col"><input type="time" name="voix_silence_debut" class="form-control form-control-sm" value="<?= e($val('voix_silence_debut')) ?>"></div>
          <div class="col-auto">à</div><div class="col"><input type="time" name="voix_silence_fin" class="form-control form-control-sm" value="<?= e($val('voix_silence_fin')) ?>"></div>
        </div>
        <div class="form-check mt-1 small"><input class="form-check-input" type="checkbox" name="voix_critique_en_silence" id="vCrit" value="1" <?= $val('voix_critique_en_silence', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="vCrit">Lire quand même les alertes <b>critiques</b> pendant le silence</label></div>
        <hr>
        <div class="row g-2 small">
          <div class="col-7"><label class="form-label mb-0">Historique : conserver à partir du niveau</label>
            <select name="voix_journal_niveau" class="form-select form-select-sm"><?php foreach ($levels as $i => $l): ?><option value="<?= $i ?>" <?= (int) $val('voix_journal_niveau', '2') === $i ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-5"><label class="form-label mb-0">pendant (jours)</label><input type="number" min="7" max="730" name="voix_journal_jours" class="form-control form-control-sm" value="<?= e($val('voix_journal_jours', '90')) ?>"></div>
        </div>
      </div></div>
    </div>
    <div class="col-lg-7">
      <div class="card h-100"><div class="card-header"><i class="bi bi-list-check"></i> Événements annoncés</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
          <thead><tr><th>Lire</th><th>Événement</th><th style="width:175px">Niveau</th></tr></thead>
          <tbody>
          <?php $mod = null; foreach ($events as $code => $ev): $k = str_replace('.', '_', $code); ?>
            <?php if ($ev['module'] !== $mod): $mod = $ev['module']; ?><tr class="table-light"><td colspan="3" class="fw-semibold"><?= e(VoiceService::MODULES[$mod] ?? $mod) ?></td></tr><?php endif; ?>
            <tr>
              <td><input class="form-check-input" type="checkbox" name="ev_on[<?= e($k) ?>]" value="1" <?= $ev['on'] ? 'checked' : '' ?> aria-label="Lire : <?= e($ev['label']) ?>"></td>
              <td><?= e($ev['label']) ?><div class="text-secondary" style="font-size:.75rem"><?= e($code) ?></div></td>
              <td><select name="ev_level[<?= e($k) ?>]" class="form-select form-select-sm"><?php foreach ($levels as $i => $l): ?><option value="<?= $i ?>" <?= $ev['level'] === $i ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="card-footer small text-secondary">
          Ordre de lecture : <b>Critique</b> &gt; Erreur &gt; Avertissement &gt; Notification &gt; Information. Une alerte critique interrompt
          une annonce moins importante. Une même annonce n'est pas répétée dans les 10 secondes ; une information devenue
          trop ancienne (8 s) n'est plus lue.
        </div>
      </div>
    </div>
  </div>
  <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div>
</form>

<?php elseif ($tab === 'poste'): ?>
<div class="row g-3" id="voixPoste">
  <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-pc-display"></i> Réglages de ce poste <span class="text-secondary small">(gardés dans ce navigateur)</span></div><div class="card-body">
    <div data-voix-off class="alert alert-warning small d-none">L'assistant vocal est désactivé par le responsable, ou ce compte n'y a pas accès.</div>
    <label class="form-label small mb-0">Nom du poste (apparaît dans l'historique)</label>
    <input data-p="name" class="form-control form-control-sm mb-2" maxlength="40" placeholder="ex. Caisse 1, Cuisine, Magasin">
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" data-p="muted" id="pMuted"><label class="form-check-label" for="pMuted">Voix coupée sur ce poste (les messages restent affichés)</label></div>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small mb-0">Volume <span data-show="volume"></span></label><input type="range" class="form-range" data-p="volume" min="0.1" max="1" step="0.05"></div>
      <div class="col-6"><label class="form-label small mb-0">Débit <span data-show="rate"></span></label><input type="range" class="form-range" data-p="rate" min="0.6" max="1.6" step="0.05"></div>
    </div>
    <label class="form-label small mb-0">Voix</label>
    <select data-p="engine" class="form-select form-select-sm mb-2"><option value="">Comme réglé par le responsable</option><option value="piper">Piper (serveur)</option><option value="navigateur">Voix de ce poste</option></select>
    <label class="form-label small mb-0">Voix de ce poste</label>
    <select data-p="voice" class="form-select form-select-sm mb-2"><option value="">Automatique (française)</option></select>
    <div class="small fw-semibold mt-2">Ne pas lire sur ce poste :</div>
    <?php foreach (VoiceService::MODULES as $k => $l): if ($k === 'systeme') continue; ?>
      <div class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" data-off="<?= e($k) ?>" id="off_<?= e($k) ?>"><label class="form-check-label" for="off_<?= e($k) ?>"><?= e($l) ?></label></div>
    <?php endforeach; ?>
    <div class="form-check small mt-2"><input class="form-check-input" type="checkbox" data-p="hideWidget" id="pHide"><label class="form-check-label" for="pHide">Masquer l'indicateur vocal (coin inférieur gauche)</label></div>
    <div class="mt-3 d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-primary btn-sm" data-save><i class="bi bi-check2"></i> Enregistrer pour ce poste</button>
      <span class="small text-success align-self-center d-none" data-saved>Enregistré.</span>
    </div>
  </div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-play-circle"></i> Essai</div><div class="card-body">
    <textarea data-test-text class="form-control form-control-sm mb-2" rows="2" maxlength="400">Vente enregistrée. Total : 12 500 FCFA. Monnaie à rendre : 2 500 FCFA.</textarea>
    <button type="button" class="btn btn-outline-primary btn-sm" data-test><i class="bi bi-volume-up"></i> Écouter</button>
    <div class="small text-secondary mt-2" data-test-out></div>
    <hr>
    <div class="small"><b>Voix disponibles sur ce poste :</b><ul class="mb-0" data-voices><li class="text-secondary">…</li></ul>
    <div class="text-secondary mt-1">« locale » : fonctionne sans Internet. « en ligne » : le texte est envoyé au service du navigateur ; ces voix ne sont pas utilisées si le responsable a choisi « voix locales uniquement ».</div></div>
  </div></div></div>
</div>

<?php elseif ($tab === 'diagnostic'): ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-hdd"></i> Voix Piper sur le serveur</div><div class="card-body small">
    <table class="table table-sm mb-2">
      <tr><th>Programme</th><td><?= $piper['binary'] ? '<span class="text-success">✔ installé</span>' : '<span class="text-danger">✘ absent</span>' ?></td></tr>
      <tr><th>Voix</th><td><?= $piper['voices'] ? e(implode(', ', array_column($piper['voices'], 'name'))) : '<span class="text-danger">aucune</span>' ?></td></tr>
      <tr><th>Voix utilisée</th><td><?= e($piper['voice'] ?? '—') ?></td></tr>
      <tr><th>Service persistant</th><td><?= $piper['service'] ? '<span class="text-success">✔ actif (127.0.0.1:' . (int) $piper['port'] . ')</span>' : '<span class="text-warning">arrêté</span> — Piper est lancé pour chaque phrase nouvelle (≈ 1 à 2 s)' ?></td></tr>
      <tr><th>Phrases en cache</th><td><?= (int) $piper['cache_files'] ?> (<?= e(number_format($piper['cache_bytes'] / 1048576, 1, ',', ' ')) ?> Mo)</td></tr>
      <tr><th>proc_open (PHP)</th><td><?= $piper['proc_open'] ? '✔' : '<span class="text-danger">désactivé : Piper inutilisable</span>' ?></td></tr>
    </table>
    <?php if (!$piper['binary'] || !$piper['voices']): ?>
      <div class="alert alert-info py-2">La voix Piper est livrée dans le dossier de POS Marwa (<code>addons/marwa_voice/piper</code>) et se met en place toute seule sous Windows.
        Si ce message reste affiché : double-cliquez <code>windows\installer-voix.bat</code> (ou <code>php bin/voix.php deployer</code>) : il indique le fichier manquant ou abîmé.
        Serveur Linux : <code>php bin/voix.php installer</code>. Sans Piper, la voix française de Windows est utilisée.</div>
    <?php endif; ?>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-outline-primary btn-sm" data-piper-test <?= $piper['binary'] && $piper['voices'] ? '' : 'disabled' ?>><i class="bi bi-volume-up"></i> Tester Piper</button>
      <form method="post" action="<?= e(url('/voix/cache/vider')) ?>" data-confirm="Retirer toutes les phrases du cache ? Elles seront regénérées au besoin."><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-trash"></i> Vider le cache</button></form>
    </div>
    <div class="mt-2" data-piper-out></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-bar-chart"></i> 7 derniers jours</div><div class="card-body small">
    <?php if (!$stats): ?><span class="text-secondary">Aucune annonce enregistrée.</span><?php endif; ?>
    <?php foreach ($stats as $st => $n): ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= e($st) ?></span><b><?= (int) $n ?></b></div><?php endforeach; ?>
    <div class="text-secondary mt-2">« erreur_voix » : la phrase n'a pas pu être lue (aucune voix, son bloqué…) ; le message était affiché à l'écran.</div>
  </div></div></div>
</div>

<?php else: ?>
<form class="row g-2 mb-2 small" method="get" action="<?= e(url('/voix')) ?>"><input type="hidden" name="tab" value="historique">
  <div class="col-auto"><select name="level" class="form-select form-select-sm" data-autosubmit><option value="">Tous niveaux</option><?php foreach ($levels as $i => $l): ?><option value="<?= $i ?>" <?= $filters['level'] === (string) $i ? 'selected' : '' ?>><?= e($l) ?> et plus</option><?php endforeach; ?></select></div>
  <div class="col-auto"><select name="status" class="form-select form-select-sm" data-autosubmit><option value="">Tous résultats</option><?php foreach (VoiceService::STATUSES as $s): ?><option <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-striped mb-0 small">
  <thead><tr><th>Date</th><th>Niveau</th><th>Événement</th><th>Texte</th><th>Résultat</th><th>Voix</th><th>Poste</th><th>Utilisateur</th></tr></thead>
  <tbody>
  <?php if (!$history): ?><tr><td colspan="8" class="text-secondary text-center py-3">Aucune annonce.</td></tr><?php endif; ?>
  <?php foreach ($history as $h): ?>
    <tr><td class="text-nowrap"><?= e($h['created_at']) ?></td><td><span class="badge text-bg-<?= e($levelCls[(int) $h['level']] ?? 'secondary') ?>"><?= e($levels[(int) $h['level']] ?? '?') ?></span></td>
      <td><?= e($h['event']) ?></td><td><?= e($h['text'] ?? '') ?></td><td><?= e($h['status']) ?></td><td><?= e($h['engine'] ?? '') ?></td><td><?= e($h['poste'] ?? '') ?></td><td><?= e($h['full_name'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div><div class="card-footer small text-secondary">200 dernières lignes. Conservation : <?= (int) setting('voix_journal_jours', '90') ?> jours. Seuls les textes annoncés sont gardés, jamais de son.</div></div>
<?php endif; ?>
