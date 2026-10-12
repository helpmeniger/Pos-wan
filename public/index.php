<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

$kernel = new App\Core\Kernel();
try {
    $request = App\Core\Request::fromGlobals();
} catch (App\Core\HttpException $e) {
    App\Core\Response::json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status)->send();
    exit;
}
$kernel->handle($request)->send();
