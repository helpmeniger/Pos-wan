<?php
// Affiche l'URL de l'interface (utilisé par start.bat).
require dirname(__DIR__) . '/src/bootstrap.php';
echo rtrim((string) Verifia\Config::get('app.base_url'), '/');
