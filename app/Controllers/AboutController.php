<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\Contract;
use App\Services\LicenceService;

/** À propos (auteur, version, licence), contrat de licence et activation. */
final class AboutController extends Controller
{
    public function about(): void
    {
        view('about/index', ['title' => 'À propos de POS Wan', 'author' => config('licence.author'), 'lic' => LicenceService::status()]);
    }

    public function contract(): void
    {
        view('about/contract', ['title' => 'Contrat de licence d\'utilisation', 'clauses' => Contract::clauses(), 'author' => config('licence.author')]);
    }

    public function licence(): void
    {
        view('about/licence', ['title' => 'Licence et activation', 'lic' => LicenceService::status(), 'author' => config('licence.author')]);
    }

    public function activate(): void
    {
        try {
            $p = $this->attempt(fn() => LicenceService::activate(Request::str('licence_key')), '/licence');
        } catch (\Throwable $e) {
            // Erreur technique (extension PHP, base…) : message affiché au lieu d'une erreur 500
            \App\Core\Logger::error('Activation de licence impossible : ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
            $this->fail('Activation impossible : ' . $e->getMessage() . ' (détail dans storage/logs). Assistance : ' . implode(' / ', (array) config('licence.author.phones')) . '.', '/licence');
        }
        flash('success', 'Licence activée pour « ' . $p['c'] . ' »' . (!empty($p['e']) ? ' jusqu\'au ' . fdate($p['e'], false) : ' (sans limite de durée)') . '. Merci !');
        redirect('/licence');
    }
}
