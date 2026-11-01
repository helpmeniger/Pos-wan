<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\CustomerDisplay;

/** Paramètres de l'écran client et de l'afficheur client ; page affichée sur le second écran. */
final class CustomerDisplayController extends Controller
{
    public function settings(): void
    {
        view('settings/display', [
            'title' => 'Écran et afficheur client',
            's' => CustomerDisplay::settings(),
            'media' => CustomerDisplay::media(),
            'limits' => [
                'image' => (int) config('app.uploads.max_display_image_bytes'),
                'video' => (int) config('app.uploads.max_display_video_bytes'),
                'php_upload' => (string) ini_get('upload_max_filesize'),
                'php_post' => (string) ini_get('post_max_size'),
            ],
            'scripts' => ['js/customer-display-link.js'],
            'displayConfig' => CustomerDisplay::clientConfig(),
        ]);
    }

    public function save(): void
    {
        $this->attempt(fn() => CustomerDisplay::saveSettings($_POST), '/settings/display');
        flash('success', t('Réglages de l\'écran et de l\'afficheur client enregistrés.'));
        redirect('/settings/display');
    }

    public function mediaAdd(): void
    {
        if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            // Fichier plus gros que la limite de PHP : le serveur l'a refusé avant le logiciel
            $this->fail(sprintf(t('Fichier trop volumineux pour ce serveur (limite PHP : %s).'), (string) ini_get('post_max_size')), '/settings/display');
        }
        $this->attempt(fn() => CustomerDisplay::addMedia($_FILES['media'] ?? [], Request::str('title')), '/settings/display');
        flash('success', t('Fichier ajouté à la diffusion.'));
        redirect('/settings/display');
    }

    public function mediaUpdate(int $id): void
    {
        $this->attempt(function () use ($id) {
            if (in_array(Request::str('move'), ['up', 'down'], true)) {
                CustomerDisplay::move($id, Request::str('move') === 'up' ? -1 : 1);
                return;
            }
            CustomerDisplay::updateMedia($id, ['title' => Request::str('title'), 'duration_s' => Request::int('duration_s'), 'active' => Request::input('active') === '1']);
        }, '/settings/display');
        redirect('/settings/display');
    }

    public function mediaDelete(int $id): void
    {
        $this->attempt(fn() => CustomerDisplay::deleteMedia($id), '/settings/display');
        flash('success', t('Fichier retiré de la diffusion.'));
        redirect('/settings/display');
    }

    /** Essai de l'afficheur relié au serveur. */
    public function vfdTest(): void
    {
        $this->jsonAttempt(function () {
            $c = CustomerDisplay::clientConfig()['vfd'];
            CustomerDisplay::vfdSend($c['line1'] !== '' ? $c['line1'] : 'POS MARWA', 'ESSAI ' . date('H:i:s'));
            return [];
        });
    }

    /** Écran client (second écran du poste de caisse). */
    public function screen(): void
    {
        view('display/screen', ['cfg' => CustomerDisplay::clientConfig(), 'playlist' => CustomerDisplay::playlist(),
            'company' => (string) setting('company_name', 'POS Marwa'), 'logo' => upload_url(setting('company_logo'))], null);
    }

    /** Afficheur relié au serveur : deux lignes envoyées par la caisse. */
    public function vfd(): void
    {
        $this->jsonAttempt(function () {
            CustomerDisplay::vfdSend(mb_substr(Request::str('line1'), 0, 60), mb_substr(Request::str('line2'), 0, 60));
            return [];
        });
    }
}
