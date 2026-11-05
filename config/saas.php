<?php
use App\Core\Env;

/*
 * Mode SaaS (service en ligne multi-clients). Désactivé par défaut : une installation
 * classique (WampServer, o2switch pour un seul client) fonctionne exactement comme avant.
 * Toutes les valeurs viennent du fichier .env (voir SAAS.md) ; aucun mot de passe ici.
 */
return [
    'enabled' => (bool) Env::get('SAAS_MODE', false),
    // Domaine du service : le site public est sur ce domaine, chaque client sur <espace>.<domaine>
    'domain' => strtolower(trim((string) Env::get('SAAS_DOMAIN', ''), '. ')),
    'central' => [
        'host' => (string) Env::get('SAAS_DB_HOST', Env::get('DB_HOST', '127.0.0.1')),
        'port' => (int) Env::get('SAAS_DB_PORT', Env::get('DB_PORT', 3306)),
        'database' => (string) Env::get('SAAS_DB_DATABASE', 'posmarwa_saas'),
        'username' => (string) Env::get('SAAS_DB_USERNAME', Env::get('DB_USERNAME', '')),
        'password' => (string) Env::get('SAAS_DB_PASSWORD', Env::get('DB_PASSWORD', '')),
        'charset' => 'utf8mb4',
    ],
    // create : le service crée la base et un utilisateur MySQL dédié par client (serveur VPS/dédié)
    // pool   : bases créées à l'avance dans cPanel puis déclarées (hébergement mutualisé, ex. o2switch)
    'provision' => in_array(Env::get('SAAS_PROVISION', 'create'), ['create', 'pool'], true) ? (string) Env::get('SAAS_PROVISION', 'create') : 'create',
    'db_prefix' => (string) Env::get('SAAS_DB_PREFIX', 'pmt_'),
    'db_user_host' => (string) Env::get('SAAS_DB_USER_HOST', 'localhost'),
    // Clé de chiffrement des mots de passe des bases clients (base64, 32 octets) : php bin/saas.php key
    'key' => (string) Env::get('SAAS_KEY', ''),
    'trial_days' => max(1, (int) Env::get('SAAS_TRIAL_DAYS', 14)),
    // Modules jamais proposés en ligne : assistance à distance (poste Windows local) et caméras IP
    // (le serveur irait chercher des adresses réseau choisies par le client)
    'excluded_addons' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('SAAS_EXCLUDED_ADDONS', 'marwa_assist,vision'))))),
    'reserved' => ['www', 'admin', 'console', 'api', 'app', 'mail', 'smtp', 'ftp', 'cpanel', 'webmail', 'static', 'cdn', 'support', 'aide', 'demo-admin', 'saas', 'test', 'dev', 'ns1', 'ns2', 'autodiscover'],
];
