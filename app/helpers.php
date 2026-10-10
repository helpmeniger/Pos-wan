<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Échappement HTML systématique contre les attaques XSS. */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Préfixe d'URL de l'application (ex. "/pos-wan/public" ou "" en virtual host). */
function base_path_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }
    $cfg = (string) config('app.base_url', '');
    if ($cfg !== '') {
        return $prefix = rtrim((string) parse_url($cfg, PHP_URL_PATH), '/');
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');
    return $prefix = ($dir === '.' ? '' : $dir);
}

function url(string $path = '/', array $query = []): string
{
    $u = base_path_prefix() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function asset(string $path): string
{
    $file = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return base_path_prefix() . '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function upload_url(?string $path): ?string
{
    return $path ? base_path_prefix() . '/uploads/' . ltrim($path, '/') : null;
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path, $query)));
    exit;
}

function back(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // On ne redirige que vers notre propre hôte (pas de redirection ouverte)
    if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === explode(':', $host)[0]) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallback);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function view(string $template, array $data = [], ?string $layout = 'layouts/admin'): never
{
    echo App\Core\Lang::html(App\Core\View::render($template, $data, $layout));
    exit;
}

/** Traduction d'un texte de l'interface (le français est la langue source). */
function t(string $text, array $params = []): string
{
    return App\Core\Lang::t($text, $params);
}

/** Attributs <html> : langue et sens d'écriture (arabe : droite à gauche). */
function html_attrs(): string
{
    return 'lang="' . e(App\Core\Lang::locale()) . '" dir="' . e(App\Core\Lang::dir()) . '"';
}

/** Feuille Bootstrap adaptée au sens d'écriture. */
function bootstrap_css(): string
{
    return asset(App\Core\Lang::dir() === 'rtl' ? 'vendor/bootstrap/bootstrap.rtl.min.css' : 'vendor/bootstrap/bootstrap.min.css');
}

/** Dictionnaire de traduction pour les scripts (vide en français). */
function i18n_script(): string
{
    if (App\Core\Lang::locale() === 'fr') {
        return '';
    }
    return '<script type="application/json" id="pw-i18n">' . App\Core\Lang::jsonForJs() . '</script>';
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

function can(string $perm): bool
{
    return Auth::can($perm);
}

function flash(string $type, string $message): void
{
    App\Core\Session::flash($type, $message);
}

/** Paramètres de la devise (Paramètres → Devise). */
function currency(): array
{
    return [
        'code' => (string) setting('currency_code', 'XOF'),
        'symbol' => (string) setting('currency_symbol', 'F'),
        'name' => (string) setting('currency_name', 'francs CFA'),
        'position' => setting('currency_position', 'after') === 'before' ? 'before' : 'after',
        'decimals' => max(0, min(3, (int) setting('currency_decimals', '0'))),
    ];
}

/** Format monétaire selon la devise configurée : 13 000 F, $ 12,50, 1 250,000 DT… */
function money(mixed $amount, bool $withCurrency = true): string
{
    $c = currency();
    $a = (float) $amount;
    $dec = $c['decimals'];
    if ($dec === 0 && abs($a - round($a)) > 0.001) {
        $dec = 2; // montant non entier (rare en FCFA) : on n'arrondit pas en silence
    }
    $s = number_format($a, $dec, ',', ' ');
    if (!$withCurrency) {
        return $s;
    }
    return $c['position'] === 'before' ? $c['symbol'] . ' ' . $s : $s . ' ' . $c['symbol'];
}

function qty(mixed $q): string
{
    $q = (float) $q;
    return abs($q - round($q)) < 0.0005 ? number_format($q, 0, ',', ' ') : rtrim(rtrim(number_format($q, 3, ',', ' '), '0'), ',');
}

function fdate(?string $d, bool $time = true): string
{
    if (!$d) {
        return '';
    }
    $ts = strtotime($d);
    return $ts ? date($time ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '';
}

/** Paramètres applicatifs stockés en base (table settings). */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($key === '__reset') {
        $cache = null;
        return null;
    }
    if ($cache === null) {
        try {
            $cache = [];
            foreach (DB::all('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        } catch (\Throwable) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, ?string $value): void
{
    DB::run('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
    setting('__reset');
}

/** Fonctionnalité activée selon le type de commerce (lots, variantes, pharmacie...). */
function feature(string $name): bool
{
    return setting('feature_' . $name, '0') === '1';
}

function payment_methods(): array
{
    $all = [
        'cash' => 'Espèces',
        'card' => 'Carte bancaire',
        'mobile_money' => 'Mobile money',
        'transfer' => 'Virement',
        'cheque' => 'Chèque',
    ];
    $enabled = array_filter(explode(',', (string) setting('payment_methods', 'cash,card,mobile_money,transfer')));
    return array_intersect_key($all, array_flip($enabled)) ?: ['cash' => 'Espèces'];
}

function method_label(?string $m): string
{
    return [
        'cash' => 'Espèces', 'card' => 'Carte', 'mobile_money' => 'Mobile money', 'transfer' => 'Virement',
        'cheque' => 'Chèque', 'credit' => 'Crédit client', 'credit_note' => 'Avoir client',
    ][$m] ?? (string) $m;
}

/** Pagination simple. */
function paginate(string $sqlFrom, array $params, string $select, string $order, int $perPage = 25): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) DB::value("SELECT COUNT(*) $sqlFrom", $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $rows = DB::all("SELECT $select $sqlFrom ORDER BY $order LIMIT $perPage OFFSET $offset", $params);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function query_with(array $changes): string
{
    $q = array_merge($_GET, $changes);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== ''));
}

/** Plage de dates depuis la requête (défaut : mois en cours). */
function date_range(string $defaultFrom = 'first day of this month'): array
{
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    if (!App\Core\Validator::isDate($from)) {
        $from = date('Y-m-d', strtotime($defaultFrom));
    }
    if (!App\Core\Validator::isDate($to)) {
        $to = date('Y-m-d');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

/** Montant en toutes lettres (factures) : 13500 → « treize mille cinq cents ». */
function amount_words(float $n): string
{
    $n = (int) round($n);
    if ($n === 0) {
        return 'zéro';
    }
    $units = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
    $tens = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];
    $below100 = function (int $x) use ($units, $tens): string {
        if ($x <= 16) {
            return $units[$x];
        }
        if ($x < 20) {
            return 'dix-' . $units[$x - 10];
        }
        $t = intdiv($x, 10);
        $u = $x % 10;
        if ($t === 7 || $t === 9) {
            $base = $t === 7 ? 'soixante' : 'quatre-vingt';
            $rest = $x - ($t === 7 ? 60 : 80);
            return $base . ($rest === 11 && $t === 7 ? ' et onze' : '-' . ($rest <= 16 ? $units[$rest] : 'dix-' . $units[$rest - 10]));
        }
        if ($t === 8) {
            return 'quatre-vingt' . ($u === 0 ? 's' : '-' . $units[$u]);
        }
        return $tens[$t] . ($u === 0 ? '' : ($u === 1 ? ' et un' : '-' . $units[$u]));
    };
    $below1000 = function (int $x) use ($below100): string {
        $h = intdiv($x, 100);
        $r = $x % 100;
        $s = $h === 0 ? '' : ($h === 1 ? 'cent' : $below100($h) . ' cent' . ($r === 0 ? 's' : ''));
        return trim($s . ($r ? ' ' . $below100($r) : ''));
    };
    $parts = [];
    foreach ([1000000000 => 'milliard', 1000000 => 'million', 1000 => 'mille'] as $div => $word) {
        $q = intdiv($n, $div);
        if ($q > 0) {
            $parts[] = $div === 1000 ? ($q === 1 ? 'mille' : preg_replace('/cents$/', 'cent', $below1000($q)) . ' mille') : $below1000($q) . ' ' . $word . ($q > 1 ? 's' : '');
            $n %= $div;
        }
    }
    if ($n > 0) {
        $parts[] = $below1000($n);
    }
    return implode(' ', $parts);
}
