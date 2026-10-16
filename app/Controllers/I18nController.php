<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Support\I18n;

/** Dictionnaires de langue, choix de la langue, éditeur de traductions (administration). */
final class I18nController extends Controller
{
    /** GET /i18n/{lang}.js — dictionnaire chargé par le navigateur (mis en cache). */
    public function script(Request $r): Response
    {
        $lang = (string) $r->param('lang');
        if ($lang === 'fr' || !isset(I18n::NAMES[$lang])) {
            throw HttpException::notFound();
        }
        $js = 'window.MW_I18N=' . json_encode(['lang' => $lang, 'dict' => (object) I18n::dictionary($lang)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';';
        return new Response(200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff'], $js);
    }

    /** POST /api/account/locale — langue préférée du compte (notifications comprises). */
    public function setLocale(Request $r): Response
    {
        $v = $this->validate($r, ['locale' => 'required|string|max:10']);
        if (!I18n::valid($v['locale'])) {
            throw \App\Core\ValidationException::field('locale', 'Langue non disponible.');
        }
        if (Auth::check()) {
            Database::update('users', ['locale' => $v['locale']], 'id = :id', ['id' => Auth::id()]);
        }
        return $this->json(['locale' => $v['locale']]);
    }

    /** GET /api/admin/translations?locale=ha&q=&missing=1 */
    public function list(Request $r): Response
    {
        $v = $this->validate($r, ['locale' => 'required|string|max:10', 'q' => 'nullable|string|max:120', 'missing' => 'nullable|bool', 'page' => 'nullable|int|min:1']);
        if ($v['locale'] === 'fr' || !isset(I18n::NAMES[$v['locale']])) {
            throw \App\Core\ValidationException::field('locale', 'Langue inconnue.');
        }
        $sources = json_decode((string) @file_get_contents(MARWA_ROOT . '/public/assets/i18n/_source.json'), true) ?: [];
        $file = json_decode((string) @file_get_contents(MARWA_ROOT . '/public/assets/i18n/' . $v['locale'] . '.json'), true) ?: [];
        $over = [];
        foreach (Database::select('SELECT source, target, updated_at FROM translations WHERE locale = :l', ['l' => $v['locale']]) as $row) {
            $over[$row['source']] = $row;
        }
        $rows = [];
        foreach (array_unique(array_merge($sources, array_keys($over))) as $src) {
            if ($src === '_meta') {
                continue;
            }
            $target = $over[$src]['target'] ?? ($file[$src] ?? '');
            if (!empty($v['missing']) && $target !== '') {
                continue;
            }
            if (!empty($v['q']) && mb_stripos($src, $v['q']) === false && mb_stripos($target, $v['q']) === false) {
                continue;
            }
            $rows[] = ['source' => $src, 'target' => $target, 'edited' => isset($over[$src]), 'file' => $file[$src] ?? ''];
        }
        $total = count($sources);
        $done = count(array_filter($sources, fn ($s) => ($over[$s]['target'] ?? ($file[$s] ?? '')) !== ''));
        $page = (int) ($v['page'] ?? 1);
        return $this->json([
            'locale' => $v['locale'], 'name' => I18n::NAMES[$v['locale']],
            'progress' => ['done' => $done, 'total' => $total],
            'reviewed_by_native' => (bool) (($file['_meta']['reviewed'] ?? false)),
            'count' => count($rows), 'items' => array_slice($rows, ($page - 1) * 50, 50),
            'languages' => I18n::languages(), 'all_languages' => I18n::NAMES,
        ]);
    }

    /** PUT /api/admin/translations — corrige ou complète une traduction (prioritaire sur le fichier). */
    public function save(Request $r): Response
    {
        $v = $this->validate($r, ['locale' => 'required|string|max:10', 'source' => 'required|string|max:2000', 'target' => 'nullable|string|max:2000']);
        if ($v['locale'] === 'fr' || !isset(I18n::NAMES[$v['locale']])) {
            throw \App\Core\ValidationException::field('locale', 'Langue inconnue.');
        }
        $hash = sha1($v['source']);
        $target = trim((string) ($v['target'] ?? ''));
        if ($target === '') {
            Database::execute('DELETE FROM translations WHERE locale = :l AND source_hash = :h', ['l' => $v['locale'], 'h' => $hash]);
        } else {
            Database::execute('INSERT INTO translations (locale, source_hash, source, target, updated_by) VALUES (:l, :h, :s, :t, :u)
                               ON DUPLICATE KEY UPDATE target = VALUES(target), updated_by = VALUES(updated_by)',
                ['l' => $v['locale'], 'h' => $hash, 's' => $v['source'], 't' => $target, 'u' => Auth::id()]);
        }
        Audit::log('i18n.translation_saved', 'translation', null, null, ['locale' => $v['locale'], 'source' => mb_substr($v['source'], 0, 120), 'target' => mb_substr($target, 0, 120)]);
        I18n::forget();
        return $this->ok('Traduction enregistrée.');
    }

    /** PUT /api/admin/languages — langues proposées aux utilisateurs. */
    public function languages(Request $r): Response
    {
        $codes = array_values(array_unique(array_filter((array) $r->input('languages', []), fn ($c) => is_string($c) && isset(I18n::NAMES[$c]))));
        if (!in_array('fr', $codes, true)) {
            array_unshift($codes, 'fr');
        }
        \App\Core\Settings::set('i18n.languages', $codes, (int) Auth::id());
        Audit::log('i18n.languages_changed', 'setting', null, null, ['languages' => $codes]);
        return $this->json(['languages' => $codes]);
    }
}
