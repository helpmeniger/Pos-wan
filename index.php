<?php
/*
 * Cette page n'est affichée que si le module Apache « rewrite » est désactivé
 * (sinon .htaccess renvoie directement vers public/).
 */
http_response_code(503);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>JOB NIGER — configuration Apache requise</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;color:#14213D;line-height:1.5}
h1{color:#0B7A5C;font-size:1.5rem}code{background:#F1F3EF;padding:2px 6px;border-radius:4px}li{margin:.4rem 0}</style></head>
<body>
<h1>JOB NIGER : activez le module « rewrite » d'Apache</h1>
<p>L'application a besoin du module Apache <code>rewrite_module</code>, qui est actuellement désactivé.</p>
<ol>
  <li>Clic gauche sur l'icône <strong>WampServer</strong> (barre des tâches) → <strong>Apache</strong> → <strong>Modules Apache</strong>.</li>
  <li>Cochez <code>rewrite_module</code> (WampServer redémarre Apache automatiquement).</li>
  <li>Rechargez cette page : l'assistant d'installation s'ouvrira.</li>
</ol>
</body></html>
