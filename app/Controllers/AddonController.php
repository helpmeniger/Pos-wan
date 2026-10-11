<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Addons;
use App\Core\BusinessException;
use App\Core\Logger;
use App\Core\Request;

/** Gestion → Modules complémentaires (dossier addons/). */
final class AddonController extends Controller
{
    public function index(): void
    {
        Addons::reset();
        foreach (Addons::normalizeFolders() as $note) {
            flash('info', $note);
        }
        $mods = Addons::discover(true);
        // Modules installés dont le dossier a été retiré
        foreach (Addons::installed() as $code => $row) {
            $mods[$code] ??= ['code' => $code, 'name' => $row['name'], 'version' => $row['version'], 'errors' => ['Dossier addons/' . $code . ' introuvable : le module est inactif (ses données sont conservées).']];
        }
        view('addons/index', ['title' => 'Modules complémentaires', 'mods' => $mods, 'installed' => Addons::installed(), 'dir' => Addons::dir()]);
    }

    public function action(): void
    {
        $code = Request::str('code');
        $do = Request::str('do');
        try {
            match ($do) {
                'install', 'upgrade' => Addons::install($code),
                'enable' => Addons::setActive($code, true),
                'disable' => Addons::setActive($code, false),
                default => throw new BusinessException('Action inconnue.'),
            };
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/addons');
        } catch (\Throwable $e) {
            // Erreur technique (base de données, droits d'écriture…) : message réel affiché à
            // l'administrateur (page réservée) et journalisé, au lieu d'une page « Erreur 500 ».
            Logger::error('Installation du module « ' . $code . ' » impossible : ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
            $this->fail('Installation du module « ' . $code . ' » impossible : ' . $e->getMessage()
                . ' — Une sauvegarde a été faite avant l\'opération (Paramètres → Sauvegardes). Détail : storage/logs.', '/addons');
        }
        flash('success', match ($do) {
            'install' => 'Module installé et activé (sauvegarde de sécurité effectuée). Il apparaît dans le menu selon les droits de chaque rôle.',
            'upgrade' => 'Module mis à jour (sauvegarde de sécurité effectuée).',
            'enable' => 'Module activé.',
            default => 'Module désactivé : ses pages et son menu sont masqués, ses données sont conservées.',
        });
        redirect('/addons');
    }
}
