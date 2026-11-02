<?php
return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'marwa_connect'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    // Chemin de mysqldump (WampServer : C:\wamp64\bin\mysql\mysqlX.Y.Z\bin\mysqldump.exe). Facultatif.
    'mysqldump' => env('MYSQLDUMP_PATH', ''),
];
