<?php
$editing = $router !== null;
$title = $editing ? 'Modifier ' . $router['name'] : 'Ajouter un routeur';
$v = static fn (string $k, $d = '') => old($k, $editing ? ($router[$k] ?? $d) : $d);
$checked = static fn (string $k, bool $d) => (old('_submitted') ? old($k) : ($editing ? (bool) $router[$k] : $d)) ? 'checked' : '';
?>
<div class="mc-page-head">
    <div>
        <h1><?= $editing ? 'Modifier le' : 'Ajouter un' ?> <span class="mc-accent">routeur</span></h1>
        <p>Les identifiants API sont chiffrés en base et ne sont jamais renvoyés au navigateur.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url($editing ? '/routers/' . $router['id'] : '/routers')) ?>"><i class="bi bi-arrow-left"></i> Retour</a>
</div>
<form method="post" action="<?= e(url($editing ? '/routers/' . $router['id'] : '/routers')) ?>" id="router-form" novalidate>
    <?= csrf_field() ?><?= $editing ? method_field('PUT') : '' ?>
    <input type="hidden" name="_submitted" value="1">
    <?php if ($editing): ?><input type="hidden" name="router_id" value="<?= (int) $router['id'] ?>"><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="mc-card mb-3">
                <div class="mc-card-head"><h2><i class="bi bi-plug me-2 text-primary"></i>Connexion API RouterOS</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">Nom du routeur *</label>
                        <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($v('name')) ?>" required placeholder="ex. Routeur principal">
                        <?= error_for('name') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="host">Adresse IP / hôte *</label>
                        <input class="form-control<?= invalid('host') ?>" id="host" name="host" value="<?= e($v('host')) ?>" required placeholder="192.168.88.1">
                        <?= error_for('host') ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="api_port">Port API</label>
                        <input class="form-control<?= invalid('api_port') ?>" id="api_port" name="api_port" value="<?= e($v('api_port', 8728)) ?>" inputmode="numeric">
                        <?= error_for('api_port') ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="api_ssl_port">Port API-SSL</label>
                        <input class="form-control<?= invalid('api_ssl_port') ?>" id="api_ssl_port" name="api_ssl_port" value="<?= e($v('api_ssl_port', 8729)) ?>" inputmode="numeric">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="use_ssl" name="use_ssl" value="1" <?= $checked('use_ssl', false) ?>>
                            <label class="form-check-label" for="use_ssl">Utiliser API-SSL (recommandé hors réseau local)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="api_username">Utilisateur API *</label>
                        <input class="form-control<?= invalid('api_username') ?>" id="api_username" name="api_username" value="<?= e($v('api_username')) ?>" autocomplete="off" required>
                        <?= error_for('api_username') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="api_password">Mot de passe API <?= $editing ? '' : '*' ?></label>
                        <div class="input-group">
                            <input class="form-control<?= invalid('api_password') ?>" id="api_password" name="api_password" type="password" autocomplete="new-password" <?= $editing ? 'placeholder="Inchangé si vide"' : 'required' ?>>
                            <button class="btn btn-outline-secondary" type="button" data-toggle-password="api_password"><i class="bi bi-eye"></i></button>
                        </div>
                        <?= error_for('api_password') ?>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary" type="button" data-router-test data-url="<?= e(url('/routers/test')) ?>"><i class="bi bi-lightning-charge me-1"></i> Tester la connexion</button>
                        <div class="small mt-2" id="router-test-result" role="status"></div>
                    </div>
                </div>
            </div>
            <div class="mc-card">
                <div class="mc-card-head"><h2><i class="bi bi-wifi me-2 text-primary"></i>Hotspot</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="wifi_ssid">Nom du réseau Wi-Fi (SSID)</label>
                        <input class="form-control" id="wifi_ssid" name="wifi_ssid" value="<?= e($v('wifi_ssid')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="hotspot_server">Serveur Hotspot</label>
                        <input class="form-control" id="hotspot_server" name="hotspot_server" value="<?= e($v('hotspot_server')) ?>" placeholder="Tous (vide)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="hotspot_dns_name">Adresse de connexion</label>
                        <input class="form-control" id="hotspot_dns_name" name="hotspot_dns_name" value="<?= e($v('hotspot_dns_name')) ?>" placeholder="ex. wifi.marwa">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="wan_interface">Interface surveillée (débit)</label>
                        <input class="form-control" id="wan_interface" name="wan_interface" value="<?= e($v('wan_interface')) ?>" placeholder="ex. ether1">
                        <div class="form-text">Utilisée pour le débit en temps réel et les alertes de débit anormal.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="mc-card mb-3">
                <div class="mc-card-head"><h2><i class="bi bi-geo-alt me-2 text-primary"></i>Emplacement</h2></div>
                <div class="mc-card-body row g-3">
                    <div class="col-12">
                        <label class="form-label" for="site_id">Site / établissement</label>
                        <select class="form-select" id="site_id" name="site_id">
                            <option value="0">— Aucun —</option>
                            <?php foreach ($sites as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (string) $v('site_id') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                        </select>
                        <input class="form-control form-control-sm mt-2" name="new_site" placeholder="… ou créer un nouveau site" value="<?= e(old('new_site')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="location">Localisation</label>
                        <input class="form-control" id="location" name="location" value="<?= e($v('location')) ?>" placeholder="Quartier, ville">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="model">Modèle</label>
                        <input class="form-control" id="model" name="model" value="<?= e($v('model')) ?>" placeholder="Détecté automatiquement">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="connection_type">Type de connexion</label>
                        <select class="form-select" id="connection_type" name="connection_type">
                            <?php foreach (['lan' => 'Réseau local', 'vpn' => 'Tunnel VPN', 'public_ip' => 'IP publique', 'cloud' => 'MikroTik Cloud (DDNS)', 'other' => 'Autre'] as $k => $l): ?>
                                <option value="<?= e($k) ?>" <?= $v('connection_type', 'lan') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mc-card">
                <div class="mc-card-head"><h2><i class="bi bi-gear me-2 text-primary"></i>Fonctionnement</h2></div>
                <div class="mc-card-body">
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="status" name="status" value="active" <?= ($editing ? $router['status'] === 'active' : true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="status">Routeur actif</label></div>
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" <?= $checked('is_default', false) ?>>
                        <label class="form-check-label" for="is_default">Routeur principal</label></div>
                    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="sync_enabled" name="sync_enabled" value="1" <?= $checked('sync_enabled', true) ?>>
                        <label class="form-check-label" for="sync_enabled">Synchronisation automatique</label></div>
                    <label class="form-label" for="sync_interval_minutes">Intervalle (minutes)</label>
                    <input class="form-control" id="sync_interval_minutes" name="sync_interval_minutes" value="<?= e($v('sync_interval_minutes', (int) setting('mikrotik.sync_interval_minutes', 5))) ?>" inputmode="numeric">
                </div>
            </div>
            <button class="btn btn-primary w-100 mt-3 py-2" type="submit"><i class="bi bi-check2 me-1"></i> Enregistrer</button>
        </div>
    </div>
</form>
<?php if (!$editing): ?>
<div class="mc-card mt-3">
    <div class="mc-card-head"><h2><i class="bi bi-terminal me-2 text-primary"></i>Préparer le routeur (à exécuter dans le terminal MikroTik)</h2></div>
    <div class="mc-card-body">
<pre class="small mb-0 user-select-all">/user group add name=marwa-api policy=read,write,api,reboot,test,!local,!telnet,!ssh,!ftp,!winbox,!web,!sniff,!sensitive,!password,!policy,!romon
/user add name=marwa password=MOT_DE_PASSE_SOLIDE group=marwa-api address=IP_DU_SERVEUR
/ip service set api address=IP_DU_SERVEUR disabled=no
/ip service set api-ssl address=IP_DU_SERVEUR</pre>
        <p class="small text-muted mt-2 mb-0">Remplacez IP_DU_SERVEUR par l'adresse du PC WampServer. Pour un routeur distant, passez par un VPN : n'exposez jamais l'API sur Internet.</p>
    </div>
</div>
<?php endif; ?>
