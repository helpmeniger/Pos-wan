<?php
declare(strict_types=1);

namespace Addons\Doublons;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

final class DoublonController extends Controller
{
    public function index(): void
    {
        @set_time_limit(120);
        $arch = Request::query('archives') === '1';
        $withSimilar = Request::query('ressemblants', '1') !== '0';
        $scan = DoublonService::scan($arch, $withSimilar);
        $same = array_map([DoublonService::class, 'compare'], array_slice($scan['same'], 0, 150));
        $similar = array_map([DoublonService::class, 'compare'], array_slice($scan['similar'], 0, 100));
        view('doublons::index', ['title' => 'Doublons d\'articles', 'same' => $same, 'similar' => $similar, 'scanned' => $scan['scanned'],
            'totalSame' => count($scan['same']), 'totalSimilar' => count($scan['similar']), 'arch' => $arch, 'withSimilar' => $withSimilar,
            'merged' => (int) DB::value('SELECT COUNT(*) FROM doublons_journal')]);
    }

    /** Fusion d'un groupe : l'article choisi est conservé, les articles cochés y sont fusionnés. */
    public function merge(): void
    {
        $keep = Request::int('keep');
        $dups = array_values(array_diff(array_map('intval', (array) Request::input('merge', [])), [$keep])); // l'article conservé n'est jamais fusionné
        $back = '/doublons' . (Request::str('back') !== '' ? '?' . http_build_query(['archives' => Request::str('archives'), 'ressemblants' => Request::str('ressemblants')]) : '');
        if (!$keep || !$dups) {
            $this->fail('Choisissez l\'article à conserver et cochez au moins un doublon.', $back);
        }
        $bk = $this->attempt(fn () => DoublonService::backup(), $back);
        $r = $this->attempt(fn () => DoublonService::merge($keep, $dups), $back);
        $names = implode(', ', array_map(fn ($x) => '« ' . $x['name'] . ' »', $r));
        $kept = (string) DB::value('SELECT name FROM products WHERE id = ?', [$keep]);
        flash('success', count($r) . ' doublon(s) fusionné(s) dans « ' . $kept . ' » : ' . $names . '. ' . array_sum(array_column($r, 'moved')) . ' ligne(s) d\'historique transférée(s).'
            . ($bk ? ' Sauvegarde : ' . $bk . '.' : ''));
        redirect($back);
    }

    /** Tous les doublons identiques, avec le choix automatique (le plus utilisé est conservé). */
    public function mergeAll(): void
    {
        @set_time_limit(600);
        $scan = DoublonService::scan(Request::str('archives') === '1', false);
        if (!$scan['same']) {
            $this->fail('Aucun doublon identique à fusionner.', '/doublons');
        }
        $bk = $this->attempt(fn () => DoublonService::backup(), '/doublons');
        $groups = 0; $removed = 0; $errors = [];
        foreach ($scan['same'] as $ids) {
            try {
                $c = DoublonService::compare($ids);
                $removed += count(DoublonService::merge($c['keep'], array_diff($ids, [$c['keep']])));
                $groups++;
            } catch (\Throwable $e) {
                $errors[] = '#' . implode(', #', $ids) . ' : ' . $e->getMessage();
            }
        }
        flash($errors ? 'warning' : 'success', "$groups groupe(s) traité(s), $removed doublon(s) fusionné(s)." . ($bk ? ' Sauvegarde : ' . $bk . '.' : '')
            . ($errors ? ' Non fusionnés : ' . implode(' ; ', array_slice($errors, 0, 5)) : ''));
        redirect('/doublons');
    }

    public function journal(): void
    {
        $p = paginate('FROM doublons_journal j LEFT JOIN users u ON u.id = j.user_id', [], 'j.*, u.full_name', 'j.id DESC');
        view('doublons::journal', ['title' => 'Doublons : journal des fusions', 'p' => $p]);
    }
}
