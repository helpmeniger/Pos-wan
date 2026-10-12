<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Csrf;
use Verifia\Http;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
Csrf::verifyRequest();
Auth::logout();
Http::redirect('login.php');
