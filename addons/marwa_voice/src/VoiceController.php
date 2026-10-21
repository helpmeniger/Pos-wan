<?php
declare(strict_types=1);

namespace Addons\MarwaVoice;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\Request;

final class VoiceController extends Controller
{
    /** Réglages (responsable), réglages du poste (tous), diagnostic et historique (responsable). */
    public function index(): void
    {
        $manage = can('marwa_voice.manage');
        $tab = (string) Request::query('tab', $manage ? 'reglages' : 'poste');
        if (!$manage || !in_array($tab, ['reglages', 'poste', 'diagnostic', 'historique'], true)) {
            $tab = 'poste';
        }
        view('marwa_voice::index', ['title' => 'Assistant vocal', 'tab' => $tab, 'manage' => $manage,
            'events' => VoiceService::events(), 'piper' => $manage ? PiperService::status() : null,
            'history' => $manage && $tab === 'historique' ? VoiceService::history(['level' => Request::query('level', ''), 'status' => Request::query('status', '')]) : [],
            'stats' => $manage ? VoiceService::stats() : [], 'filters' => ['level' => (string) Request::query('level', ''), 'status' => (string) Request::query('status', '')],
            'scripts' => ['addons/marwa_voice/voix-reglages.js']]);
    }

    public function config(): void
    {
        header('Cache-Control: no-store');
        json_response(['ok' => true] + VoiceService::config());
    }

    public function settings(): void
    {
        $this->attempt(fn() => VoiceService::saveSettings($_POST), '/voix?tab=reglages');
        flash('success', 'Réglages de l\'assistant vocal enregistrés.');
        redirect('/voix?tab=reglages');
    }

    public function log(): void
    {
        $items = Request::input('items', []);
        $this->jsonAttempt(fn() => ['saved' => VoiceService::log(is_array($items) ? $items : [])]);
    }

    /** Son d'une phrase (Piper). En cas d'échec : JSON d'erreur ; le poste utilise alors sa voix locale. */
    public function tts(): void
    {
        try {
            $r = PiperService::synth((string) Request::input('text', ''));
        } catch (BusinessException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 503);
        }
        header('Content-Type: audio/wav');
        header('Content-Length: ' . filesize($r['path']));
        header('Cache-Control: private, max-age=86400');
        header('X-Voix-Via: ' . $r['via']);
        header('X-Voix-Ms: ' . $r['ms']);
        readfile($r['path']);
        exit;
    }

    public function clearCache(): void
    {
        $n = PiperService::clearCache();
        flash('success', $n . ' phrase(s) retirée(s) du cache vocal ; elles seront regénérées au besoin.');
        redirect('/voix?tab=diagnostic');
    }
}
