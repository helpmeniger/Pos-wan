<?php
declare(strict_types=1);

/**
 * Crée .env et ai-service/.env à partir des modèles et génère des secrets aléatoires
 * (APP_KEY, AI_SERVICE_KEY, AI_CALLBACK_TOKEN, SALES_API_TOKEN). N'écrase jamais une valeur existante.
 * Usage : php scripts/generate_keys.php [--db-pass=xxx] [--base-path=/smart-guard-ai]
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI uniquement');
}
$root = dirname(__DIR__);
$opts = getopt('', ['db-pass::', 'db-user::', 'db-name::', 'base-path::', 'callback-url::']);

function setEnv(string $file, array $values, bool $overwrite = false): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as &$line) {
        foreach ($values as $k => $v) {
            if (preg_match('/^' . preg_quote($k, '/') . '=(.*)$/', $line, $m) && ($overwrite || trim($m[1]) === '')) {
                $line = $k . '=' . $v;
            }
        }
    }
    file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
}

$env = $root . '/.env';
$aiEnv = $root . '/ai-service/.env';
if (!is_file($env)) {
    copy($root . '/.env.example', $env);
    echo "Créé : .env\n";
}
if (!is_file($aiEnv)) {
    copy($root . '/ai-service/.env.example', $aiEnv);
    echo "Créé : ai-service/.env\n";
}
$current = parse_ini_string(preg_replace('/^#.*$/m', '', (string) file_get_contents($env)), false, INI_SCANNER_RAW) ?: [];
$aiKey = $current['AI_SERVICE_KEY'] ?? '' ?: bin2hex(random_bytes(24));
$cbToken = $current['AI_CALLBACK_TOKEN'] ?? '' ?: bin2hex(random_bytes(24));
$values = ['APP_KEY' => bin2hex(random_bytes(32)), 'AI_SERVICE_KEY' => $aiKey, 'AI_CALLBACK_TOKEN' => $cbToken, 'SALES_API_TOKEN' => bin2hex(random_bytes(24))];
setEnv($env, $values);
$explicit = array_filter(['DB_PASS' => $opts['db-pass'] ?? null, 'DB_USER' => $opts['db-user'] ?? null, 'DB_NAME' => $opts['db-name'] ?? null,
    'APP_BASE_PATH' => $opts['base-path'] ?? null], static fn ($v) => $v !== null && $v !== false);
if ($explicit) {
    setEnv($env, $explicit, true);
}
$base = $opts['base-path'] ?? '/smart-guard-ai';
$aiValues = ['AI_SERVICE_KEY' => $aiKey, 'AI_CALLBACK_TOKEN' => $cbToken];
setEnv($aiEnv, $aiValues, true);
if (isset($opts['callback-url'])) {
    setEnv($aiEnv, ['PHP_CALLBACK_URL' => $opts['callback-url']], true);
} elseif ($base !== '/smart-guard-ai') {
    setEnv($aiEnv, ['PHP_CALLBACK_URL' => 'http://127.0.0.1' . rtrim($base, '/') . '/api/ai/events'], true);
}
echo "Secrets générés (les valeurs existantes ont été conservées).\n";
echo "IMPORTANT : sauvegardez le fichier .env en lieu sûr (APP_KEY chiffre les mots de passe des caméras).\n";
