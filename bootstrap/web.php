<?php
declare(strict_types=1);

/**
 * Contrôleur frontal commun (public/index.php et index.php racine).
 * @var string $marwaEntry « public » ou « root »
 */
require __DIR__ . '/app.php';

App\Core\Paths::detect($_SERVER, $marwaEntry ?? 'public');

// Installation / mise à jour automatique de la base au premier lancement.
if (!App\Support\Installer::upToDate()) {
    $installResponse = App\Controllers\InstallController::handle($_SERVER, $_POST, $_COOKIE);
    if ($installResponse !== null) {
        $installResponse->send();
        exit;
    }
}

$kernel = new App\Core\Kernel();
try {
    $request = App\Core\Request::fromGlobals();
} catch (App\Core\HttpException $e) {
    App\Core\Response::json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status)->send();
    exit;
}
$kernel->handle($request)->send();
