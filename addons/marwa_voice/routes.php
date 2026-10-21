<?php
/** Routes de MARWA VOICE AI (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\MarwaVoice\VoiceController as V;

$router->get('/voix', [V::class, 'index'], 'marwa_voice.use');
$router->get('/voix/config', [V::class, 'config'], 'marwa_voice.use');
$router->post('/voix/reglages', [V::class, 'settings'], 'marwa_voice.manage');
$router->post('/voix/journal', [V::class, 'log'], 'marwa_voice.use');
$router->post('/voix/tts', [V::class, 'tts'], 'marwa_voice.use');
$router->post('/voix/cache/vider', [V::class, 'clearCache'], 'marwa_voice.manage');
