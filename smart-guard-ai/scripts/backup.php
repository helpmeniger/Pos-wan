<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';
$b = App\Services\BackupService::backup('cli');
out($b['ok'] ? "Sauvegarde créée : storage/backups/{$b['file']} (" . human_bytes($b['size']) . ")\nSHA-256 : {$b['sha256']}" : 'Échec : ' . $b['error']);
exit($b['ok'] ? 0 : 1);
