<?php
declare(strict_types=1);

namespace Addons\MarwaAssist;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\Request;
use App\Services\LicenceService;

final class AssistController extends Controller
{
    public function index(): void
    {
        $manage = can('assist.manage');
        $s = AssistService::settings();
        $tabs = ['assistance'];
        if ($manage) {
            array_push($tabs, 'postes', 'diagnostic', 'historique', 'reglages');
            if ($s['role'] === 'serveur') {
                $tabs[] = 'htn';
            }
        }
        $tab = (string) Request::query('tab', 'assistance');
        if (!in_array($tab, $tabs, true)) {
            $tab = 'assistance';
        }
        $data = ['title' => 'Assistance à distance', 'tab' => $tab, 'tabs' => $tabs, 'manage' => $manage, 's' => $s,
            'code' => LicenceService::installId(), 'poste' => AssistService::currentPoste(), 'mine' => AssistService::active(Request::ip()),
            'all' => $manage ? AssistService::active() : [], 'postes' => $manage ? AssistService::postes() : [],
            'scripts' => ['addons/marwa_assist/assist-page.js']];
        if ($tab === 'diagnostic') {
            $data['diag'] = Diagnostic::run();
            $data['errors'] = Diagnostic::recentErrors();
            $data['diagText'] = Diagnostic::text($data['diag']);
        } elseif ($tab === 'historique') {
            $data['history'] = AssistService::history();
            $sel = (int) Request::query('i', 0);
            $data['events'] = $sel ? AssistService::events($sel) : [];
            $data['sel'] = $sel;
        } elseif ($tab === 'htn') {
            $data['relay'] = RelayServer::status();
            $data['boutiques'] = AssistService::boutiques();
        }
        view('marwa_assist::index', $data);
    }

    /** État pour le bandeau permanent (chaque page, toutes les 20 s : une requête légère). */
    public function state(): void
    {
        header('Cache-Control: no-store');
        $rows = AssistService::active(Request::ip());
        json_response(['ok' => true, 'active' => array_map(fn($i) => ['id' => (int) $i['id'], 'status' => $i['status'], 'poste' => $i['poste_label'],
            'control_until' => $i['control_until'], 'seconds_left' => $i['control_until'] ? max(0, strtotime($i['control_until']) - time()) : null], $rows)]);
    }

    public function request(): void
    {
        $id = $this->attempt(fn() => AssistService::request((string) Request::input('reason', ''), Request::int('poste_id') ?: null), '/assist');
        flash('success', 'Demande d\'assistance enregistrée. Appelez ou écrivez à High Tech Niger avec le code affiché.');
        redirect('/assist?i=' . $id);
    }

    public function share(int $id): void
    {
        $this->attempt(fn() => AssistService::share($id), '/assist');
        flash('success', 'Partage en observation : le technicien voit l\'écran, vous gardez la souris et le clavier. Acceptez la connexion dans la fenêtre RustDesk.');
        redirect('/assist');
    }

    public function control(int $id): void
    {
        $until = $this->attempt(fn() => AssistService::authorizeControl($id, (string) Request::input('username', ''), (string) Request::input('secret', ''), Request::int('minutes')), '/assist');
        flash('warning', 'Contrôle autorisé jusqu\'à ' . date('H:i', strtotime($until)) . '. Cochez « Clavier » dans la fenêtre RustDesk pour que le technicien puisse agir. Retour automatique en observation ensuite.');
        redirect('/assist');
    }

    public function end(int $id): void
    {
        $this->attempt(fn() => AssistService::end($id, (string) Request::input('technician', ''), (string) Request::input('summary', '')), '/assist');
        flash('success', 'Assistance terminée. Fermez aussi la fenêtre RustDesk (bouton Déconnecter) si elle est encore ouverte.');
        redirect('/assist');
    }

    public function endJson(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            AssistService::end($id);
            return [];
        });
    }

    public function kit(): void
    {
        try {
            // Adresse que les autres PC de la boutique peuvent joindre (pas « localhost » si le kit est préparé sur le serveur)
            $host = (string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST);
            $lan = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) ? (\App\Services\NetworkService::localIps()[0] ?? null) : null;
            $base = rtrim($lan ? \App\Services\NetworkService::urlFor($lan, '/') : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path_prefix(), '/');
            $k = AssistService::createKit((string) Request::input('kind', 'boutique'), (string) Request::input('label', ''), $base);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/assist?tab=postes');
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $k['name'] . '"');
        header('Content-Length: ' . filesize($k['path']));
        header('Cache-Control: no-store');
        readfile($k['path']);
        @unlink($k['path']);
        exit;
    }

    /** Adresse appelée par le script du kit (sans session) : jeton secret à usage limité. */
    public function register(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $r = AssistService::register((string) Request::query('jeton', ''), (string) Request::query('id', ''), (string) Request::query('poste', ''));
            echo 'OK : poste « ' . $r['label'] . ' » enregistre (identifiant ' . $r['id'] . ').';
        } catch (BusinessException $e) {
            http_response_code(403);
            echo 'Refuse : ' . $e->getMessage();
        }
        exit;
    }

    public function posteId(int $id): void
    {
        $this->attempt(fn() => AssistService::setPosteId($id, (string) Request::input('rustdesk_id', ''), Request::input('this_pc') ? Request::ip() : null), '/assist?tab=postes');
        flash('success', 'Identifiant enregistré.');
        redirect('/assist?tab=postes');
    }

    public function revoke(int $id): void
    {
        AssistService::revokePoste($id);
        flash('success', 'Poste retiré de l\'assistance. Désinstallez RustDesk de ce PC si vous ne l\'utilisez plus.');
        redirect('/assist?tab=postes');
    }

    public function settings(): void
    {
        $this->attempt(fn() => AssistService::saveSettings($_POST), '/assist?tab=reglages');
        flash('success', 'Réglages enregistrés.');
        redirect('/assist?tab=reglages');
    }

    public function boutique(int $id = 0): void
    {
        $this->attempt(fn() => AssistService::saveBoutique($_POST, $id ?: null), '/assist?tab=htn');
        flash('success', 'Boutique enregistrée.');
        redirect('/assist?tab=htn');
    }

    public function archiveBoutique(int $id): void
    {
        AssistService::archiveBoutique($id);
        flash('success', 'Boutique retirée de l\'annuaire.');
        redirect('/assist?tab=htn');
    }
}
