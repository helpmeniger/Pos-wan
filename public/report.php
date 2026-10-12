<?php
declare(strict_types=1);
// Génère puis télécharge le rapport PDF d'une alerte.
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Reports\ReportService;

$user = Auth::require('viewer');
$r = ReportService::forAlert((int) ($_GET['id'] ?? 0), $user['id']);
header('Content-Type: ' . ($r['format'] === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8'));
header('Content-Disposition: ' . ($r['format'] === 'pdf' ? 'attachment' : 'inline') . '; filename="' . basename($r['path']) . '"');
header('Content-Length: ' . filesize($r['path']));
readfile($r['path']);
