<?php
declare(strict_types=1);

namespace Verifia;

/** Rendu HTML minimal ; toutes les valeurs dynamiques passent par e(). */
final class View
{
    public const PRIORITY_LABELS = ['low' => 'Faible', 'normal' => 'Normale', 'high' => 'Élevée', 'critical' => 'Critique'];
    public const PRIORITY_BADGES = ['low' => 'secondary', 'normal' => 'info', 'high' => 'warning', 'critical' => 'danger'];
    public const STATUS_LABELS = ['new' => 'Non lue', 'read' => 'Lue', 'in_review' => 'En vérification', 'verified' => 'Vérifiée', 'ignored' => 'Ignorée'];

    public static function header(string $title, string $active = '', array $opts = []): void
    {
        $u = Auth::user();
        $csrf = $u ? Csrf::token() : '';
        $bare = !empty($opts['bare']);
        $nav = [
            'dashboard' => ['index.php', 'Tableau de bord'],
            'sessions'  => ['sessions.php', 'Sessions'],
            'alerts'    => ['alerts.php', "Centre d'alertes"],
            'manual'    => ['manual.php', 'Analyse manuelle'],
            'workspace' => ['workspace.php', 'Multi-écrans'],
            'events'    => ['events.php', 'Journal'],
        ];
        if (Auth::can('admin')) {
            $nav['settings'] = ['settings.php', 'Administration'];
        }
        echo '<!doctype html><html lang="fr" data-bs-theme="dark"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="csrf-token" content="' . e($csrf) . '">';
        echo '<title>' . e($title) . ' — VERIFIA</title>';
        echo '<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">';
        echo '<link rel="stylesheet" href="assets/css/app.css"></head><body class="' . ($bare ? 'bare' : '') . '">';
        if (!$bare && $u) {
            echo '<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom sticky-top"><div class="container-fluid">';
            echo '<a class="navbar-brand fw-bold" href="index.php">VERIFIA</a>';
            echo '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menu">☰</button>';
            echo '<div class="collapse navbar-collapse" id="nav"><ul class="navbar-nav me-auto">';
            foreach ($nav as $k => [$href, $label]) {
                echo '<li class="nav-item"><a class="nav-link' . ($k === $active ? ' active' : '') . '" href="' . e($href) . '">' . e($label);
                if ($k === 'alerts') {
                    echo ' <span class="badge text-bg-danger" id="nav-unread" hidden>0</span>';
                }
                echo '</a></li>';
            }
            echo '</ul>';
            echo '<span id="monitor-status" class="badge text-bg-secondary me-2" title="État du moteur de surveillance">moteur ?</span>';
            echo '<span id="worker-status" class="badge text-bg-secondary me-3" title="État du moteur d\'analyse">analyse ?</span>';
            if (Auth::can('analyst')) {
                echo '<button class="btn btn-sm btn-danger me-3" id="btn-emergency" title="Arrêter immédiatement toutes les sessions">⏹ ARRÊT IMMÉDIAT</button>';
            }
            echo '<span class="navbar-text me-2">' . e($u['name']) . ' <small class="text-body-secondary">(' . e($u['role']) . ')</small></span>';
            echo '<form method="post" action="logout.php" class="d-inline">' . Csrf::field() . '<button class="btn btn-sm btn-outline-secondary">Déconnexion</button></form>';
            echo '</div></div></nav>';
        }
        echo '<main class="' . ($bare ? 'container-fluid p-2' : 'container-fluid py-3') . '">';
    }

    public static function footer(array $scripts = []): void
    {
        echo '</main>';
        echo '<div id="toast-zone" class="toast-zone" aria-live="polite"></div>';
        echo '<script src="assets/vendor/bootstrap.bundle.min.js"></script>';
        echo '<script src="assets/js/common.js"></script>';
        foreach ($scripts as $s) {
            echo '<script src="assets/js/' . e($s) . '"></script>';
        }
        echo '</body></html>';
    }

    public static function priorityBadge(string $p): string
    {
        return '<span class="badge text-bg-' . (self::PRIORITY_BADGES[$p] ?? 'secondary') . '">' . e(self::PRIORITY_LABELS[$p] ?? $p) . '</span>';
    }

    /** Convertit une date UTC stockée en heure locale lisible. */
    public static function date(?string $utc): string
    {
        if (!$utc) {
            return '—';
        }
        $d = new \DateTime($utc, new \DateTimeZone('UTC'));
        $d->setTimezone(new \DateTimeZone((string) Config::get('app.timezone', 'UTC')));
        return $d->format('d/m/Y H:i:s');
    }
}
