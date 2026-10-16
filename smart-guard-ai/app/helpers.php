<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;

/** Échappement HTML (protection XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL absolue (depuis la racine du site) vers une route de l'application. */
function url(string $path = '', array $query = []): string
{
    $base = rtrim(Router::basePath(), '/');
    $u = $base . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u === '' ? '/' : $u;
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function money(mixed $amount, string $currency = 'FCFA'): string
{
    return number_format((float) $amount, 0, ',', ' ') . ' ' . $currency;
}

function qty(mixed $q): string
{
    $f = (float) $q;
    return floor($f) == $f ? number_format($f, 0, ',', ' ') : number_format($f, 3, ',', ' ');
}

function pct(mixed $v, int $decimals = 0): string
{
    return $v === null ? '—' : number_format((float) $v * 100, $decimals, ',', ' ') . ' %';
}

function dt(?string $value, string $format = 'd/m/Y H:i:s'): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : e($value);
}

function uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function now_ms(): string
{
    $t = microtime(true);
    return date('Y-m-d H:i:s', (int) $t) . sprintf('.%03d', (int) (($t - floor($t)) * 1000));
}

function human_bytes(float $bytes): string
{
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i ? 1 : 0, ',', ' ') . ' ' . $units[$i];
}

/** Libellés français des valeurs ENUM. */
function label(string $group, ?string $value): string
{
    static $labels = [
        'level' => ['information' => 'Information', 'a_verifier' => 'À vérifier', 'prioritaire' => 'Prioritaire'],
        'alert_status' => [
            'nouvelle' => 'Nouvelle', 'en_examen' => 'En cours d\'examen', 'mouvement_normal' => 'Mouvement normal confirmé',
            'vente_retrouvee' => 'Vente retrouvée', 'erreur_detection' => 'Erreur de détection',
            'anomalie_confirmee' => 'Anomalie confirmée après vérification', 'non_concluante' => 'Non concluante', 'cloturee' => 'Clôturée',
        ],
        'alert_type' => [
            'sortie_observee' => 'Sortie observée', 'sortie_sans_vente' => 'Sortie sans vente correspondante',
            'mouvement_inhabituel' => 'Mouvement inhabituel', 'objet_non_identifie' => 'Objet non identifié',
            'perte_suivi' => 'Perte de suivi', 'franchissement_ambigu' => 'Franchissement ambigu',
            'camera' => 'Caméra', 'stock' => 'Stock', 'systeme' => 'Système',
        ],
        'direction' => ['sortie' => 'Vers la sortie', 'entree' => 'Vers l\'intérieur', 'ambigu' => 'Ambigu', 'perte_suivi' => 'Perte de suivi'],
        'recon' => [
            'non_applicable' => 'Non applicable', 'en_attente' => 'En attente', 'vente_trouvee' => 'Vente rapprochée',
            'aucune_vente' => 'Aucune vente identifiée', 'incertain' => 'Incertain',
        ],
        'camera_status' => ['inconnu' => 'Inconnu', 'en_ligne' => 'En ligne', 'hors_ligne' => 'Hors ligne', 'erreur' => 'Erreur', 'desactivee' => 'Désactivée'],
        'camera_type' => ['usb' => 'Webcam / caméra USB', 'ip_rtsp' => 'Caméra IP (RTSP)', 'ip_http' => 'Caméra IP (HTTP/MJPEG)', 'nvr' => 'Enregistreur existant (NVR/DVR)'],
        'camera_role' => ['sortie' => 'Sortie', 'entree' => 'Entrée', 'caisse' => 'Caisse', 'rayon' => 'Rayon', 'reserve' => 'Réserve', 'autre' => 'Autre'],
        'movement' => [
            'reception' => 'Réception de marchandises', 'vente' => 'Vente', 'retour_client' => 'Retour client',
            'retour_fournisseur' => 'Retour fournisseur', 'transfert' => 'Transfert entre zones', 'casse' => 'Casse',
            'perime' => 'Produit périmé', 'ajustement' => 'Ajustement de stock', 'sortie_autorisee' => 'Sortie autorisée',
            'mouvement_observe' => 'Mouvement observé par caméra (validé)', 'mouvement_non_explique' => 'Mouvement non expliqué',
            'inventaire' => 'Régularisation d\'inventaire',
        ],
        'recognition' => [
            'aucune' => 'Aucune photo', 'photos_insuffisantes' => 'Photos insuffisantes', 'prete' => 'Prête à préparer',
            'validee' => 'Validée (non activée)', 'active' => 'Reconnaissance active', 'echec' => 'Échec de validation',
        ],
        'job' => ['en_attente' => 'En attente', 'en_cours' => 'En cours', 'termine' => 'Terminé', 'echec' => 'Échec', 'annule' => 'Annulé'],
        'version' => ['en_preparation' => 'En préparation', 'prete' => 'Prête', 'active' => 'Active', 'archivee' => 'Archivée', 'echec' => 'Échec'],
        'sale_status' => ['validee' => 'Validée', 'annulee' => 'Annulée', 'remboursee' => 'Remboursée', 'partiellement_remboursee' => 'Partiellement remboursée'],
        'payment' => ['especes' => 'Espèces', 'mobile_money' => 'Mobile Money', 'carte' => 'Carte', 'credit' => 'Crédit', 'autre' => 'Autre'],
    ];
    return $labels[$group][$value ?? ''] ?? (string) $value;
}

function level_badge(string $level): string
{
    $cls = ['information' => 'bg-info-subtle text-info-emphasis', 'a_verifier' => 'bg-warning-subtle text-warning-emphasis', 'prioritaire' => 'bg-danger'][$level] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . e(label('level', $level)) . '</span>';
}

function status_badge(string $status): string
{
    $cls = match ($status) {
        'nouvelle' => 'bg-primary',
        'en_examen' => 'bg-warning text-dark',
        'anomalie_confirmee' => 'bg-danger',
        'mouvement_normal', 'vente_retrouvee' => 'bg-success',
        'erreur_detection', 'non_concluante' => 'bg-secondary',
        default => 'bg-dark',
    };
    return '<span class="badge ' . $cls . '">' . e(label('alert_status', $status)) . '</span>';
}
