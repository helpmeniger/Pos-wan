<?php
/**
 * Fonctions utilitaires réutilisables : configuration, base de données,
 * échappement, réponses JSON, validation, journalisation, libellés.
 */
declare(strict_types=1);

/** Exception métier dont le message peut être montré à l'utilisateur. */
class UserFacingException extends RuntimeException
{
    /** @var array<string,mixed> */
    public array $data = [];

    public function __construct(string $message, int $code = 422, array $data = [])
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}

// =============================================================================
// Configuration & base de données
// =============================================================================

function config(string $key, $default = null)
{
    return $GLOBALS['np_config'][$key] ?? $default;
}

function storage_path(string $sub = ''): string
{
    return rtrim((string) config('storage_path'), '/\\') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
}

/** Connexion PDO unique (requêtes préparées natives, exceptions, utf8mb4). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = require APP_ROOT . '/config/database.php';
    $pdo = db_connect($c);
    return $pdo;
}

function db_connect(array $c, bool $withDatabase = true): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset'] ?? 'utf8mb4');
    if ($withDatabase) {
        $dsn .= ';dbname=' . $c['database'];
    }
    $pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    return $pdo;
}

/** Exécute une requête préparée et retourne le PDOStatement. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = [])
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Exécute $fn dans une transaction (rollback automatique en cas d'erreur). */
function db_transaction(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function app_is_installed(): bool
{
    return is_file(storage_path('installed.lock'));
}

/** Paramètre applicatif stocké en base (table settings), avec cache. */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($key === '__reset__') {
        $cache = null;
        return null;
    }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) {
            log_error($e);
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, ?string $value): void
{
    db_query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    setting('__reset__');
}

/** Limites géographiques plausibles (Niger + marge). */
function geo_bounds(): array
{
    return [
        'min_lat' => (float) setting('bounds_min_lat', 11.3),
        'max_lat' => (float) setting('bounds_max_lat', 23.6),
        'min_lng' => (float) setting('bounds_min_lng', 0.0),
        'max_lng' => (float) setting('bounds_max_lng', 16.1),
    ];
}

// =============================================================================
// HTTP, URL, sortie
// =============================================================================

/** Chemin web de base de l'application, ex. "/niger_parcelles/". */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = config('base_url');
    if ($configured) {
        $base = rtrim((string) parse_url($configured, PHP_URL_PATH), '/') . '/';
        return $base;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', (string) realpath(APP_ROOT));
    $real = str_replace('\\', '/', (string) realpath($script));
    $rel = ($real !== '' && strpos($real, $root) === 0) ? substr($real, strlen($root)) : '/' . basename($script);
    $name = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = substr($name, 0, max(0, strlen($name) - strlen($rel)));
    $base = rtrim($base, '/') . '/';
    return $base;
}

function url(string $path = '', array $query = []): string
{
    $u = base_path() . ltrim($path, '/');
    if ($query) {
        $u .= (strpos($u, '?') === false ? '?' : '&') . http_build_query($query);
    }
    return $u;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path, array $query = []): void
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path, $query);
    header('Location: ' . $target);
    exit;
}

/** Échappement HTML (prévention XSS). */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    $tiles = 'https://tile.openstreetmap.org https://*.tile.openstreetmap.org https://server.arcgisonline.com https://*.arcgisonline.com';
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: $tiles; font-src 'self'; "
        . "connect-src 'self' https://nominatim.openstreetmap.org; frame-ancestors 'self'; "
        . "form-action 'self'; base-uri 'self'; object-src 'none'");
}

function json_response($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(['ok' => false, 'error' => $message] + $extra, $status);
}

/** Corps JSON d'une requête (API). */
function json_input(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $data = [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new UserFacingException('Corps de requête JSON invalide.', 400);
    }
    return $data = $decoded;
}

/** Paramètre de requête GET/POST/JSON nettoyé. */
function input(string $key, $default = null)
{
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $j = json_input();
        if (array_key_exists($key, $j)) {
            return $j[$key];
        }
    }
    return $default;
}

function input_str(string $key, int $maxLen = 255, ?string $default = null): ?string
{
    $v = input($key, $default);
    if ($v === null || is_array($v)) {
        return $default;
    }
    $v = trim((string) $v);
    if ($v === '') {
        return $default;
    }
    return mb_substr($v, 0, $maxLen);
}

function input_int(string $key, ?int $default = null): ?int
{
    $v = input($key);
    if ($v === null || $v === '' || is_array($v)) {
        return $default;
    }
    $f = filter_var($v, FILTER_VALIDATE_INT);
    return $f === false ? $default : (int) $f;
}

function input_float(string $key, ?float $default = null): ?float
{
    $v = input($key);
    if ($v === null || $v === '' || is_array($v)) {
        return $default;
    }
    $f = filter_var(str_replace(',', '.', (string) $v), FILTER_VALIDATE_FLOAT);
    return $f === false ? $default : (float) $f;
}

/** Valeur d'énumération validée (sinon $default). */
function input_enum(string $key, array $allowed, ?string $default = null): ?string
{
    $v = input_str($key, 60);
    return ($v !== null && in_array($v, $allowed, true)) ? $v : $default;
}

/** Date AAAA-MM-JJ validée. */
function input_date(string $key): ?string
{
    $v = input_str($key, 10);
    if ($v === null) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        throw new UserFacingException("Date invalide pour le champ « $key » (format attendu AAAA-MM-JJ).");
    }
    return $v;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

// =============================================================================
// Messages flash
// =============================================================================

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// =============================================================================
// Journalisation
// =============================================================================

function log_error(Throwable $e): void
{
    $line = sprintf("[%s] %s: %s in %s:%d\n%s\n", date('c'), get_class($e), $e->getMessage(),
        $e->getFile(), $e->getLine(), $e->getTraceAsString());
    @file_put_contents(storage_path('logs/app.log'), $line, FILE_APPEND | LOCK_EX);
}

/** Enregistre une opération sensible dans audit_logs. */
function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, $details = null): void
{
    try {
        $user = current_user();
        db_query('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
                  VALUES (?, ?, ?, ?, ?, ?)', [
            $user['id'] ?? null, $action, $entityType, $entityId,
            $details === null ? null : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE)),
            client_ip(),
        ]);
    } catch (Throwable $e) {
        log_error($e);
    }
}

/** Gestionnaire global : message générique, détail uniquement dans les logs. */
function np_exception_handler(Throwable $e): void
{
    if ($e instanceof UserFacingException) {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;
        $message = $e->getMessage();
    } else {
        log_error($e);
        $status = 500;
        $message = 'Une erreur interne est survenue. Elle a été enregistrée dans le journal.';
    }
    if (defined('NP_CLI')) {
        fwrite(STDERR, $e . "\n");
        exit(1);
    }
    if (defined('NP_API')) {
        json_error($message, $status, $e instanceof UserFacingException ? $e->data : []);
    }
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Erreur</title></head><body style="font-family:sans-serif;padding:2rem">'
        . '<h1>Erreur</h1><p>' . e($message) . '</p><p><a href="' . e(url('dashboard.php')) . '">Retour au tableau de bord</a></p></body></html>';
    exit;
}

// =============================================================================
// Libellés et formatage
// =============================================================================

function labels(string $group): array
{
    static $l = [
        'category' => [
            'residentiel' => 'Résidentiel', 'commercial' => 'Commercial', 'agricole' => 'Agricole',
            'industriel' => 'Industriel', 'administratif' => 'Administratif', 'terrain_nu' => 'Terrain nu',
            'en_construction' => 'Terrain en construction', 'autre' => 'Autre',
        ],
        'verification_status' => [
            'brouillon' => 'Brouillon', 'soumise' => 'Soumise à vérification', 'en_verification' => 'En cours de vérification',
            'verifiee' => 'Vérifiée', 'anomalie' => 'Anomalie signalée', 'rejetee' => 'Rejetée',
        ],
        'survey_level' => [
            'indicative' => 'Dessinée à titre indicatif', 'releve_terrain' => 'Relevée sur le terrain',
            'documentee' => 'Associée à des documents', 'verifiee_autorite' => 'Vérifiée par une autorité habilitée',
        ],
        'declared_status' => [
            'titre_foncier' => 'Titre foncier', 'permis_urbain' => "Permis urbain d'habiter", 'acte_cession' => 'Acte de cession / vente',
            'attestation_coutumiere' => 'Attestation coutumière', 'autre_document' => 'Autre document',
            'sans_document' => 'Sans document', 'inconnu' => 'Non renseigné',
        ],
        'owner_type' => [
            'personne_physique' => 'Personne physique', 'personne_morale' => 'Personne morale',
            'collectivite' => 'Collectivité', 'etat' => 'État', 'groupement' => 'Groupement / famille',
        ],
        'owner_info_status' => [
            'declare' => 'Déclaré', 'documente' => 'Documenté', 'verifie' => 'Vérifié par une personne habilitée',
            'conteste' => 'Contesté', 'incomplet' => 'Incomplet',
        ],
        'link_status' => [
            'declare' => 'Propriétaire déclaré', 'documente' => 'Propriétaire documenté',
            'verifie' => 'Vérifié par une autorité habilitée', 'conteste' => 'Contesté / incomplet',
        ],
        'ownership_role' => [
            'proprietaire' => 'Propriétaire', 'coproprietaire' => 'Copropriétaire', 'usufruitier' => 'Usufruitier',
            'ayant_droit' => 'Ayant droit', 'occupant' => 'Occupant', 'mandataire' => 'Mandataire',
        ],
        'mutation_type' => [
            'vente' => 'Vente', 'succession' => 'Succession', 'donation' => 'Donation', 'partage' => 'Partage',
            'echange' => 'Échange', 'decision_justice' => 'Décision de justice', 'attribution' => 'Attribution', 'autre' => 'Autre',
        ],
        'document_type' => [
            'plan' => 'Plan de terrain', 'acte' => 'Acte', 'attestation' => 'Attestation', 'titre_foncier' => 'Titre foncier',
            'contrat' => 'Contrat', 'proces_verbal' => 'Procès-verbal', 'rapport_verification' => 'Rapport de vérification',
            'photo' => 'Photographie', 'piece_identite' => "Pièce d'identité", 'autre' => 'Autre pièce',
        ],
        'document_status' => ['non_verifie' => 'Non vérifié', 'verifie' => 'Vérifié', 'rejete' => 'Rejeté'],
        'area_type' => [
            'pays' => 'Pays', 'region' => 'Région', 'departement' => 'Département', 'commune' => 'Commune',
            'quartier' => 'Quartier', 'village' => 'Village',
        ],
        'overlap_status' => ['a_verifier' => 'À vérifier', 'legitime' => 'Superposition légitime', 'corrige' => 'Corrigé'],
        'overlap_type' => ['chevauchement' => 'Chevauchement', 'doublon_probable' => 'Doublon probable'],
        'history_action' => [
            'creation' => 'Création de la parcelle', 'modification' => 'Modification des informations',
            'geometrie' => 'Modification de la géométrie', 'statut' => 'Changement de statut',
            'proprietaire_ajout' => 'Ajout d\'un propriétaire', 'proprietaire_fin' => 'Fin de lien propriétaire',
            'proprietaire_modif' => 'Modification du lien propriétaire', 'mutation' => 'Mutation enregistrée',
            'document_ajout' => 'Ajout de document', 'document_statut' => 'Vérification de document',
            'document_archive' => 'Archivage de document', 'chevauchement' => 'Chevauchement détecté',
            'chevauchement_statut' => 'Décision sur chevauchement', 'archivage' => 'Archivage', 'import' => 'Importation',
        ],
    ];
    return $l[$group] ?? [];
}

function label(string $group, ?string $key): string
{
    if ($key === null || $key === '') {
        return '—';
    }
    return labels($group)[$key] ?? $key;
}

function status_badge(string $status): string
{
    $map = [
        'brouillon' => 'secondary', 'soumise' => 'info', 'en_verification' => 'warning',
        'verifiee' => 'success', 'anomalie' => 'danger', 'rejetee' => 'dark',
    ];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . e(label('verification_status', $status)) . '</span>';
}

function fmt_number($n, int $decimals = 0): string
{
    return number_format((float) $n, $decimals, ',', "\u{202F}");
}

function fmt_area($m2): string
{
    return fmt_number($m2, 2) . ' m² (' . fmt_number(((float) $m2) / 10000, 4) . ' ha)';
}

function fmt_date(?string $d, bool $withTime = false): string
{
    if (!$d) {
        return '—';
    }
    $t = strtotime($d);
    return $t ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $t) : '—';
}

function owner_display_name(array $o): string
{
    if (!empty($o['organization_name']) && ($o['owner_type'] ?? '') !== 'personne_physique') {
        return (string) $o['organization_name'];
    }
    $n = trim(($o['last_name'] ?? '') . ' ' . ($o['first_name'] ?? ''));
    return $n !== '' ? $n : (string) ($o['organization_name'] ?? '(sans nom)');
}

/** Calcule la pagination. */
function paginate(int $total, int $perPage = 25, string $param = 'page'): array
{
    $page = max(1, (int) ($_GET[$param] ?? 1));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

/** Rendu HTML d'une pagination Bootstrap conservant les filtres. */
function pagination_links(array $p, string $param = 'page'): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $q = $_GET;
    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm flex-wrap">';
    $start = max(1, $p['page'] - 3);
    $end = min($p['pages'], $p['page'] + 3);
    $link = static function (int $n, string $text, bool $active = false, bool $disabled = false) use ($q, $param): string {
        $q[$param] = $n;
        $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
        return '<li class="' . $cls . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . $text . '</a></li>';
    };
    $html .= $link(max(1, $p['page'] - 1), '&laquo;', false, $p['page'] === 1);
    for ($i = $start; $i <= $end; $i++) {
        $html .= $link($i, (string) $i, $i === $p['page']);
    }
    $html .= $link(min($p['pages'], $p['page'] + 1), '&raquo;', false, $p['page'] === $p['pages']);
    return $html . '</ul></nav>';
}

/** Liste plate des zones administratives pour les listes déroulantes. */
function admin_area_options(): array
{
    $rows = db_all('SELECT id, name, area_type, parent_id, code FROM administrative_areas ORDER BY name');
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    $out = [];
    foreach ($rows as $r) {
        $path = [];
        $cur = $r;
        $guard = 0;
        while ($cur && $guard++ < 10) {
            array_unshift($path, $cur['name']);
            $cur = $cur['parent_id'] ? ($byId[(int) $cur['parent_id']] ?? null) : null;
        }
        if (count($path) > 1 && $path[0] === 'Niger') {
            array_shift($path);
        }
        $out[] = ['id' => (int) $r['id'], 'label' => implode(' › ', $path) . ' (' . label('area_type', $r['area_type']) . ')',
                  'type' => $r['area_type'], 'name' => $r['name']];
    }
    usort($out, static fn ($a, $b) => strcmp($a['label'], $b['label']));
    return $out;
}

/** Ancêtres d'une zone (de la plus précise à la plus large). */
function admin_area_chain(?int $areaId): array
{
    $chain = [];
    $guard = 0;
    while ($areaId && $guard++ < 10) {
        $a = db_one('SELECT id, name, area_type, parent_id, code FROM administrative_areas WHERE id = ?', [$areaId]);
        if (!$a) {
            break;
        }
        $chain[] = $a;
        $areaId = $a['parent_id'] ? (int) $a['parent_id'] : null;
    }
    return $chain;
}

/** Retourne ['region'=>..., 'departement'=>..., 'commune'=>..., 'quartier'=>..., 'village'=>...]. */
function admin_area_breakdown(?int $areaId): array
{
    $out = ['region' => null, 'departement' => null, 'commune' => null, 'quartier' => null, 'village' => null];
    foreach (admin_area_chain($areaId) as $a) {
        if (array_key_exists($a['area_type'], $out) && $out[$a['area_type']] === null) {
            $out[$a['area_type']] = $a['name'];
        }
    }
    return $out;
}

/** Identifiants de la zone et de toutes ses sous-zones (pour les filtres). */
function admin_area_descendants(int $areaId): array
{
    $ids = [$areaId];
    $frontier = [$areaId];
    $guard = 0;
    while ($frontier && $guard++ < 10) {
        $in = implode(',', array_fill(0, count($frontier), '?'));
        $children = array_map('intval', array_column(db_all("SELECT id FROM administrative_areas WHERE parent_id IN ($in)", $frontier), 'id'));
        $children = array_values(array_diff($children, $ids));
        $ids = array_merge($ids, $children);
        $frontier = $children;
    }
    return $ids;
}

// =============================================================================
// Aides pour les API JSON
// =============================================================================

/** Exige une requête POST munie d'un jeton CSRF valide. */
function api_require_post(): void
{
    if (request_method() !== 'POST') {
        json_error('Méthode non autorisée (POST attendu).', 405);
    }
    require_csrf();
}

/** Action demandée (?action=...), limitée aux caractères sûrs. */
function api_action(string $default = ''): string
{
    $a = (string) ($_GET['action'] ?? $_POST['action'] ?? $default);
    return preg_match('/^[a-z_]{1,40}$/', $a) ? $a : '';
}
