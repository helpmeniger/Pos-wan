<div class="row g-3">
  <div class="col-lg-5">
    <div class="card text-center"><div class="card-body">
      <?php if ($url): ?>
        <div class="fw-semibold mb-2">Scannez ce QR code avec l'appareil photo du smartphone</div>
        <div class="mx-auto mobile-qr" data-qr="<?= e($url) ?>" data-qr-size="8"></div>
        <div class="mt-2"><code class="fs-6" id="mobileUrl"><?= e($url) ?></code> <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#mobileUrl"><i class="bi bi-clipboard"></i></button></div>
        <div class="d-flex justify-content-center gap-2 mt-3 flex-wrap">
          <a class="btn btn-outline-primary" href="<?= e(url('/mobile')) ?>"><i class="bi bi-arrow-repeat"></i> Régénérer (adresse actuelle)</a>
          <button type="button" class="btn btn-outline-secondary" data-print-page><i class="bi bi-printer"></i> Imprimer</button>
          <button type="button" class="btn btn-outline-success" id="testAccess" data-health="<?= e(str_replace('/m', '/health', $url)) ?>"><i class="bi bi-wifi"></i> Tester l'adresse</button>
        </div>
        <div class="small mt-2" id="testResult"></div>
        <?php if (count($ips) > 1): ?><div class="small mt-2">Autres adresses détectées : <?php foreach ($ips as $i): if ($i === $ip) continue; ?><a href="<?= e(url('/mobile', ['ip' => $i])) ?>"><?= e($i) ?></a> <?php endforeach; ?></div><?php endif; ?>
      <?php else: ?>
        <div class="alert alert-danger text-start"><i class="bi bi-wifi-off"></i> <b>Aucune adresse de réseau local détectée.</b> Cet ordinateur n'est connecté à aucun réseau Wi-Fi ou câble (ou seulement à Internet par un modem USB). Connectez l'ordinateur et le smartphone au <b>même</b> réseau (même box ou même point d'accès), puis cliquez sur « Régénérer ».</div>
        <a class="btn btn-outline-primary" href="<?= e(url('/mobile')) ?>"><i class="bi bi-arrow-repeat"></i> Régénérer</a>
      <?php endif; ?>
      <?php if ($changed): ?><div class="alert alert-warning small mt-3 mb-0 text-start"><i class="bi bi-exclamation-triangle"></i> L'adresse du serveur a changé (avant : <?= e($changed) ?>). Les QR codes imprimés auparavant ne fonctionnent plus : imprimez celui-ci. Pour une adresse stable, réservez une IP fixe pour cet ordinateur dans la box (« bail DHCP statique »).</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-list-check"></i> Sur le smartphone</div><div class="card-body small">
      <ol class="mb-0">
        <li>Connectez le smartphone au <b>même Wi-Fi</b> que cet ordinateur.</li>
        <li>Ouvrez l'appareil photo (ou Google Lens) et visez le QR code : la page POS Marwa s'ouvre et vérifie la connexion au serveur.</li>
        <li>Touchez « Se connecter » et saisissez <b>votre identifiant et votre mot de passe</b> : le QR code ne donne aucun accès sans eux.</li>
        <li>Chaque utilisateur ne voit que les fonctions de son rôle. Les appareils sont listés dans <a href="<?= e(url('/devices')) ?>">Appareils connectés</a> (blocage possible à tout moment).</li>
      </ol>
    </div></div>
    <div class="card"><div class="card-header"><i class="bi bi-tools"></i> Si la page ne s'ouvre pas sur le smartphone</div><div class="card-body small">
      <p class="mb-2"><b>1. Apache n'accepte que cet ordinateur</b> (réglage par défaut de WampServer) : la page affiche « Forbidden ». Dans l'hôte virtuel de POS Marwa (<code>httpd-vhosts.conf</code>), remplacez <code>Require local</code> par :</p>
      <pre class="bg-body-tertiary p-2 rounded mb-2">Require local
Require ip 192.168 10 172.16.0.0/12</pre>
      <p class="mb-2">puis redémarrez Apache (icône Wamp → Apache → Service → Redémarrer). Ne mettez jamais <code>Require all granted</code> sur un ordinateur relié directement à Internet.</p>
      <p class="mb-2"><b>2. Le pare-feu Windows bloque le port <?= (int) $port ?></b> (la page ne répond pas, délai dépassé) : dans une invite de commandes <b>en administrateur</b> :</p>
      <pre class="bg-body-tertiary p-2 rounded mb-2">netsh advfirewall firewall add rule name="POS Marwa (Apache)" dir=in action=allow protocol=TCP localport=<?= (int) $port ?> profile=private</pre>
      <p class="mb-2">Le réseau Wi-Fi doit être déclaré « Privé » dans Windows (Paramètres → Réseau → propriétés).</p>
      <p class="mb-2"><b>3. Wi-Fi « invités » ou isolation des clients</b> : certains points d'accès empêchent les appareils de se voir. Utilisez le Wi-Fi principal.</p>
      <p class="mb-0"><b>Base de données</b> : MySQL/MariaDB n'est jamais exposé ; seuls les écrans de POS Marwa (Apache) sont accessibles, avec identifiant et mot de passe. <b>Caméra</b> : la lecture des codes-barres par la caméra en direct exige HTTPS (voir la documentation) ; sans HTTPS, la lecture par photo reste disponible.</p>
    </div></div>
  </div>
</div>
