<?php
use Addons\Fiscal\FiscalAdmin as A;
$id = (int) ($c['id'] ?? 0); $drv = $c['driver'] ?? ($type === 'api' ? 'generic_rest' : 'generic_tcp'); $info = $drivers[$drv] ?? null;
$cfg = json_decode((string) ($c['config_json'] ?? ''), true) ?: [];
$examples = [
  'generic_rest' => ['base_url' => 'https://api-test.exemple/v1', 'auth' => ['type' => 'api_key', 'header' => 'X-Api-Key'], 'endpoints' => ['test' => ['method' => 'GET', 'path' => '/status'], 'certify' => ['method' => 'POST', 'path' => '/invoices'], 'status' => ['method' => 'GET', 'path' => '/invoices/{{idempotency_key}}']], 'response_map' => ['fiscal_number' => 'data.code', 'signature' => 'data.signature', 'qr_payload' => 'data.qr', 'device_id' => 'data.device'], 'success' => ['path' => 'status', 'values' => ['ACCEPTED']]],
  'generic_soap' => ['base_url' => 'https://service-test.exemple/soap', 'soap_version' => '1.1', 'endpoints' => ['certify' => ['action' => 'urn:Certify', 'template' => '<Envelope>…<Number>{{local_number}}</Number>…</Envelope>']], 'response_map' => ['fiscal_number' => "//*[local-name()='Code']"]],
  'generic_tcp' => ['host' => '192.168.1.50', 'port' => 9000, 'framing' => 'json_line', 'endpoints' => ['test' => [], 'certify' => []], 'response_map' => ['fiscal_number' => 'number', 'signature' => 'sig', 'device_id' => 'mcf']],
  'generic_serial' => ['port' => 'COM3', 'baud' => 9600, 'data_bits' => 8, 'parity' => 'none', 'stop_bits' => 1, 'endpoints' => ['test' => [], 'certify' => []], 'response_map' => ['fiscal_number' => 'number']],
];
$cfgShown = $cfg; unset($cfgShown['openapi'], $cfgShown['scenario']);
?>
<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<?php if ($info && !empty($info['skeleton'])): ?><div class="alert alert-danger small py-2"><i class="bi bi-x-octagon"></i> <?= e(t('Pilote squelette, non développé et NON COMPATIBLE : il ne peut ni être activé ni certifier. Spécification officielle requise.')) ?></div><?php endif; ?>
<?php if ($c && $c['kind'] === 'simulation'): ?><div class="alert alert-dark small py-2"><i class="bi bi-cone-striped"></i> <?= e(t('Connecteur de simulation : documents marqués « NON CERTIFIÉ – TEST », aucune valeur fiscale, interdit en production.')) ?></div><?php endif; ?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-header fw-semibold"><?= e(t($c ? 'Configuration' : 'Nouveau connecteur')) ?></div><div class="card-body">
<form method="post" action="<?= e(url($id ? "/fiscal/connecteurs/$id" : '/fiscal/connecteurs')) ?>" class="row g-2"><?= csrf_field() ?>
  <div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Nom')) ?></label><input name="name" value="<?= e($c['name'] ?? '') ?>" class="form-control form-control-sm" required maxlength="120"></div>
  <div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Type')) ?></label><select name="kind" class="form-select form-select-sm"><?php foreach (A::KINDS as $k => $l): ?><option value="<?= $k ?>" <?= ($c['kind'] ?? ($type === 'api' ? 'api' : 'mcf')) === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Pilote')) ?></label><select name="driver" class="form-select form-select-sm"><?php foreach ($drivers as $k => $d): ?><option value="<?= e($k) ?>" <?= $drv === $k ? 'selected' : '' ?>><?= e($d['label']) ?><?= !empty($d['skeleton']) ? ' — ' . e(t('squelette')) : '' ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0"><?= e(t('Liaison')) ?></label><select name="transport" class="form-select form-select-sm"><?php foreach (['none' => '—', 'usb' => 'USB', 'serial' => 'COM / RS232', 'tcp' => 'Ethernet / TCP', 'http' => 'HTTP', 'rest' => 'REST / JSON', 'soap' => 'SOAP / XML', 'cloud' => 'Plateforme en ligne'] as $k => $l): ?><option value="<?= $k ?>" <?= ($c['transport'] ?? '') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0"><?= e(t('Environnement')) ?></label><select name="environment" class="form-select form-select-sm"><option value="test">TEST</option><option value="prod" <?= ($c['environment'] ?? '') === 'prod' ? 'selected' : '' ?>>PRODUCTION</option></select></div>
  <div class="col-md-4"><label class="form-label small mb-0"><?= e(t('Boutique (facultatif)')) ?></label><select name="store_id" class="form-select form-select-sm"><option value=""><?= e(t('Toutes')) ?></option><?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($c['store_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label small mb-0"><?= e(t('Caisse (facultatif)')) ?></label><select name="register_id" class="form-select form-select-sm"><option value=""><?= e(t('Toutes')) ?></option><?php foreach ($registers as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) ($c['register_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0"><?= e(t('Délai (s)')) ?></label><input type="number" name="timeout_s" min="1" max="120" value="<?= (int) ($c['timeout_s'] ?? 15) ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><label class="form-label small mb-0"><?= e(t('Essais max')) ?></label><input type="number" name="max_retries" min="1" max="20" value="<?= (int) ($c['max_retries'] ?? 5) ?>" class="form-control form-control-sm"></div>
  <?php if (($c['kind'] ?? '') === 'simulation' || $drv === 'simulation'): ?><div class="col-md-6"><label class="form-label small mb-0"><?= e(t('Scénario de test')) ?></label><select name="scenario" class="form-select form-select-sm"><?php foreach (['accept' => 'Réponse positive', 'reject' => 'Refus du dispositif', 'unreachable' => 'Dispositif injoignable', 'timeout' => 'Délai dépassé', 'invalid' => 'Réponse incomplète', 'auth_fail' => 'Authentification refusée', 'lost_response' => 'Réponse perdue après certification'] as $k => $l): ?><option value="<?= $k ?>" <?= ($cfg['scenario'] ?? 'accept') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="col-12"><label class="form-label small mb-0"><?= e(t('Configuration technique (JSON, sans aucun secret)')) ?></label><textarea name="config_json" rows="10" class="form-control form-control-sm font-monospace" spellcheck="false" placeholder="<?= e(json_encode($examples[$drv] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?>"><?= e($cfgShown ? json_encode($cfgShown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '') ?></textarea>
    <div class="form-text"><?= e(t('Gabarits : {{local_number}}, {{totals.ttc}}, {{seller.nif}}, {{json:lines}}… Champs lus dans la réponse : fiscal_number, device_id, signature, qr_payload, counters. Les correspondances doivent venir de la documentation officielle du dispositif ou de l\'API.')) ?></div></div>
  <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-save"></i> <?= e(t('Enregistrer')) ?></button></div>
</form></div></div></div>
<div class="col-lg-5">
<?php if ($c): ?>
  <div class="card mb-3"><div class="card-header fw-semibold"><?= e(t('État')) ?></div><div class="card-body small">
    <p class="mb-1"><?= (int) $c['active'] ? '<span class="badge text-bg-success">' . e(t('actif')) . '</span>' : '<span class="badge text-bg-light border">' . e(t('inactif')) . '</span>' ?> <?= (int) $c['is_default'] ? '<span class="badge text-bg-primary">' . e(t('par défaut')) . '</span>' : '' ?> · <?= e(t('test')) ?> : <b class="<?= $c['status'] === 'ok' ? 'text-success' : ($c['status'] === 'error' ? 'text-danger' : '') ?>"><?= e($c['status']) ?></b> <?= e(fdate($c['last_test_at'])) ?></p>
    <?php if ($c['last_test_message']): ?><p class="text-secondary mb-2"><?= e($c['last_test_message']) ?></p><?php endif; ?>
    <?php if ($device): ?><p class="mb-2"><?= e(t('Dispositif')) ?> : <b><?= e($device['device_id']) ?></b> <?= e($device['model']) ?> <?= e($device['serial_number']) ?> · <?= e(fdate($device['last_seen_at'])) ?></p><?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
      <?php if (in_array('testConnection', $info['capabilities'] ?? [], true)): ?><form method="post" action="<?= e(url("/fiscal/connecteurs/$id/tester")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-activity"></i> <?= e(t('Tester la connexion')) ?></button></form><?php endif; ?>
      <?php if (empty($info['skeleton'])): ?><form method="post" action="<?= e(url("/fiscal/connecteurs/$id/activer")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-<?= (int) $c['active'] ? 'secondary' : 'success' ?>"><?= e(t((int) $c['active'] ? 'Désactiver' : 'Activer')) ?></button></form><?php endif; ?>
      <?php if ((int) $c['active'] && !(int) $c['is_default']): ?><form method="post" action="<?= e(url("/fiscal/connecteurs/$id/defaut")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><?= e(t('Par défaut')) ?></button></form><?php endif; ?>
      <form method="post" action="<?= e(url("/fiscal/connecteurs/$id/supprimer")) ?>" data-confirm="<?= e(t('Supprimer ce connecteur ? (refusé s\'il a déjà servi)')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </div>
    <p class="mt-2 mb-0 text-secondary"><?= e(t('Fonctions du pilote')) ?> : <?= e(implode(', ', $info['capabilities'] ?? []) ?: t('aucune')) ?>. <?= e(t('Protocole vérifié sur documentation officielle')) ?> : <b><?= !empty($info['verified']) ? e(t('oui')) : e(t('non')) ?></b>.</p>
  </div></div>
  <?php $fields = A::SECRET_FIELDS[$c['driver']] ?? []; if ($fields && can('fiscal.secrets')): ?>
  <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-key"></i> <?= e(t('Secrets (chiffrés, jamais réaffichés)')) ?></div><div class="card-body">
    <form method="post" action="<?= e(url("/fiscal/connecteurs/$id/secrets")) ?>" class="row g-2" autocomplete="off"><?= csrf_field() ?>
    <?php foreach ($fields as $k => $l): ?><div class="col-12"><label class="form-label small mb-0"><?= e(t($l)) ?> <?= isset($hints[$k]) ? '<span class="badge text-bg-success">' . e(t('enregistré')) . ' ' . e($hints[$k]) . '</span>' : '' ?></label>
      <div class="input-group input-group-sm"><input type="password" name="secret[<?= e($k) ?>]" class="form-control" autocomplete="new-password" placeholder="<?= e(t(isset($hints[$k]) ? 'Laisser vide pour conserver' : 'Non renseigné')) ?>"><?php if (isset($hints[$k])): ?><span class="input-group-text"><input class="form-check-input mt-0 me-1" type="checkbox" name="clear[]" value="<?= e($k) ?>"><?= e(t('effacer')) ?></span><?php endif; ?></div></div><?php endforeach; ?>
    <div class="col-12"><button class="btn btn-sm btn-outline-primary"><?= e(t('Enregistrer les secrets')) ?></button></div></form></div></div>
  <?php endif; ?>
  <?php if (in_array($c['driver'], ['generic_rest', 'generic_soap'], true)): ?>
  <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-file-earmark-lock"></i> <?= e(t('Certificats SSL / mTLS')) ?></div><div class="card-body small">
    <?php foreach ($certs as $x): ?><div><?= e($x['kind']) ?> · <?= e($x['filename']) ?> · <?= e(t('expire')) ?> <?= e(fdate($x['valid_to'], false)) ?> · <code><?= e(substr($x['fingerprint_sha256'], 0, 16)) ?>…</code></div><?php endforeach; ?>
    <?php if (can('fiscal.secrets')): ?><form method="post" action="<?= e(url('/fiscal/certificats')) ?>" enctype="multipart/form-data" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="connector_id" value="<?= $id ?>"><input type="hidden" name="back" value="connector">
      <div class="col-4"><select name="kind" class="form-select form-select-sm"><option value="ca"><?= e(t('Autorité (CA)')) ?></option><option value="client_cert"><?= e(t('Certificat client')) ?></option><option value="client_key"><?= e(t('Clé privée client')) ?></option></select></div>
      <div class="col-8"><input type="file" name="file" accept=".pem,.crt,.key,.cer" class="form-control form-control-sm" required></div>
      <div class="col-8"><input type="password" name="password" class="form-control form-control-sm" placeholder="<?= e(t('Mot de passe de la clé (si chiffrée)')) ?>" autocomplete="new-password"></div>
      <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100"><?= e(t('Téléverser')) ?></button></div></form><?php endif; ?>
  </div></div>
  <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-filetype-json"></i> <?= e(t('Importer une description OpenAPI')) ?></div><div class="card-body small">
    <form method="post" action="<?= e(url("/fiscal/connecteurs/$id/openapi")) ?>" enctype="multipart/form-data" class="d-flex gap-2"><?= csrf_field() ?><input type="file" name="openapi" accept=".json" class="form-control form-control-sm" required><button class="btn btn-sm btn-outline-primary"><?= e(t('Lire')) ?></button></form>
    <?php if (!empty($cfg['openapi'])): $o = $cfg['openapi']; ?><p class="mt-2 mb-1"><b><?= e($o['title']) ?></b> <?= e($o['version']) ?> · <?= e(implode(', ', $o['servers'])) ?></p>
      <div style="max-height:220px;overflow:auto"><table class="table table-sm mb-0"><?php foreach ($o['operations'] as $op): ?><tr><td><code><?= e($op['method']) ?></code></td><td><code><?= e($op['path']) ?></code></td><td><?= e($op['summary']) ?></td></tr><?php endforeach; ?></table></div>
      <p class="text-secondary mb-0 mt-1"><?= e(t('Référence seulement : reportez les chemins utiles dans « endpoints ». Aucun champ fiscal n\'est deviné.')) ?></p><?php endif; ?>
  </div></div>
  <?php if (can('fiscal.secrets')): ?><div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-broadcast"></i> <?= e(t('Webhook (notifications du service)')) ?></div><div class="card-body small">
    <?php if ($webhook): ?><p class="mb-1"><?= e(t('Créé le')) ?> <?= e(fdate($webhook['created_at'])) ?> · <?= e(t('dernière réception')) ?> : <?= e(fdate($webhook['last_received_at']) ?: '—') ?></p><?php endif; ?>
    <p class="text-secondary"><?= e(t('Le service doit signer le corps (HMAC-SHA256) dans l\'en-tête X-Fiscal-Signature. Une notification déclenche seulement une vérification auprès du service : elle ne certifie jamais une facture à elle seule.')) ?></p>
    <form method="post" action="<?= e(url("/fiscal/connecteurs/$id/webhook")) ?>" data-confirm="<?= e(t($webhook ? 'Remplacer le webhook ? L\'ancienne adresse cessera de fonctionner.' : 'Créer le webhook ?')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><?= e(t($webhook ? 'Régénérer' : 'Créer')) ?></button></form></div></div><?php endif; ?>
  <?php endif; ?>
<?php else: ?>
  <div class="card"><div class="card-body small text-secondary"><?= e(t('Enregistrez le connecteur : vous pourrez ensuite saisir ses secrets, ses certificats, le tester et l\'activer. Un nouveau connecteur est créé inactif.')) ?>
    <ul class="mt-2 mb-0"><?php foreach ($drivers as $d): ?><li><b><?= e($d['label']) ?></b> — <?= e(t($d['notes'])) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
</div></div>
