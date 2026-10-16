<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export;
use App\Services\ReportService;

final class ReportController extends Controller
{
    private function filters(Request $r): array
    {
        $level = $r->query('level');
        return ['camera_id' => (int) $r->query('camera_id') ?: null, 'product_id' => (int) $r->query('product_id') ?: null,
            'level' => in_array($level, ['information', 'a_verifier', 'prioritaire'], true) ? $level : null];
    }

    /** Raccourcis de période : jour, semaine, mois, année. */
    private function period(Request $r): array
    {
        return match ($r->query('period')) {
            'week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
            'month' => [date('Y-m-01'), date('Y-m-d')],
            'year' => [date('Y-01-01'), date('Y-m-d')],
            default => $this->dateRange(),
        };
    }

    public function index(Request $r): void
    {
        [$from, $to] = $this->period($r);
        $f = $this->filters($r);
        $s = $this->storeId();
        $this->view('reports/index', ['title' => 'Rapports', 'from' => $from, 'to' => $to, 'f' => $f,
            'summary' => ReportService::summary($s, $from, $to, $f), 'products' => ReportService::byProduct($s, $from, $to, $f),
            'daily' => ReportService::daily($s, $from, $to),
            'cameras' => Database::fetchAll('SELECT id, name FROM cameras WHERE store_id = :s ORDER BY name', ['s' => $s]),
            'productList' => Database::fetchAll('SELECT id, name FROM products WHERE store_id = :s AND deleted_at IS NULL ORDER BY name', ['s' => $s]),
            'scripts' => ['vendor/chartjs/chart.umd.js']]);
    }

    public function dailyList(Request $r): void
    {
        $rows = Database::fetchAll('SELECT id, report_date, generated_by, generated_at, summary_json FROM daily_reports WHERE store_id = :s ORDER BY report_date DESC LIMIT 120', ['s' => $this->storeId()]);
        $this->view('reports/daily_list', ['title' => 'Rapports journaliers', 'rows' => $rows]);
    }

    public function dailyShow(Request $r): void
    {
        $rep = Database::fetch('SELECT * FROM daily_reports WHERE id = :id AND store_id = :s', ['id' => (int) $r->param('id'), 's' => $this->storeId()]);
        if (!$rep) {
            throw new HttpException(404, 'Rapport introuvable.');
        }
        $this->view('reports/daily_show', ['title' => 'Rapport du ' . dt($rep['report_date'], 'd/m/Y'), 'rep' => $rep,
            'summary' => json_decode($rep['summary_json'], true), 'products' => json_decode($rep['products_json'], true), 'cams' => json_decode($rep['cameras_json'], true)]);
    }

    public function generate(Request $r): void
    {
        $date = (string) $r->input('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            $this->back('error', 'Date invalide.', 'reports/daily');
        }
        $id = ReportService::generateDaily($this->storeId(), $date, 'manuel');
        Audit::log('rapport_genere', 'daily_report', $id, ['date' => $date]);
        $this->flash('success', 'Rapport généré.' . ($date === date('Y-m-d') ? ' (journée en cours : chiffres provisoires)' : ''));
        Response::redirect('reports/daily/' . $id);
    }

    public function export(Request $r): void
    {
        [$from, $to] = $this->period($r);
        $f = $this->filters($r);
        $s = $this->storeId();
        $format = (string) $r->query('format', 'csv');
        $sum = ReportService::summary($s, $from, $to, $f);
        $prod = ReportService::byProduct($s, $from, $to, $f);
        $store = Database::value('SELECT name FROM stores WHERE id = :id', ['id' => $s]);
        $sections = [
            ['title' => 'Synthèse', 'sheet' => 'Synthèse', 'headers' => ['Indicateur', 'Valeur'], 'rows' => [
                ['Période', "du {$from} au {$to}"],
                ['Détections vidéo (objets suivis)', $sum['detections_total']], ['Produits identifiés', $sum['products_identified']],
                ['Objets non identifiés', $sum['objects_unidentified']], ['Franchissements vers la sortie', $sum['exits']],
                ['Franchissements vers l\'entrée', $sum['entries']], ['Passages ambigus', $sum['ambiguous']], ['Pertes de suivi près de la ligne', $sum['track_lost_events']],
                ['Sorties rapprochées d\'une vente', $sum['exits_matched']], ['Sorties non rapprochées', $sum['exits_unmatched']],
                ['Événements à vérifier', $sum['to_verify']], ['Événements vérifiés', $sum['verified']], ['Encore ouverts', $sum['open_alerts']],
                ['Erreurs de détection confirmées', $sum['detection_errors']], ['Anomalies confirmées après vérification', $sum['confirmed_anomalies']],
                ['Erreurs de suivi', $sum['tracking_errors']], ['Durée de surveillance effective', ReportService::duration((int) $sum['monitored_seconds'])],
                ['Durée sans vidéo exploitable', ReportService::duration((int) $sum['unusable_seconds'])],
                ['Tickets de caisse', $sum['sales_tickets']], ['Articles vendus', $sum['sales_articles']],
            ]],
            ['title' => 'Par produit', 'sheet' => 'Produits', 'headers' => ['Produit', 'Catégorie', 'Détections vidéo', 'Sorties observées', 'Entrées observées', 'À vérifier', 'Vendus (caisse)', 'Sorties rapprochées', 'Sorties non rapprochées'],
                'rows' => array_map(static fn ($p) => [$p['name'], $p['category'], (int) $p['detections'], (int) $p['exits'], (int) $p['entries'], (int) $p['to_verify'], (float) $p['sold'], (int) $p['matched'], (int) $p['unmatched']], $prod)],
            ['title' => 'Par caméra', 'sheet' => 'Caméras', 'headers' => ['Caméra', 'Statut actuel', 'Surveillance effective', 'Sans vidéo exploitable', 'Déconnexions'],
                'rows' => array_map(static fn ($c) => [$c['name'], label('camera_status', $c['status']), ReportService::duration($c['monitored_seconds']), ReportService::duration($c['unusable_seconds']), $c['disconnections']], $sum['cameras'])],
        ];
        $alerts = Database::fetchAll("SELECT a.occurred_at, a.level, a.alert_type, a.status, a.confidence, c.name camera, p.name product FROM alerts a
            LEFT JOIN cameras c ON c.id = a.camera_id LEFT JOIN products p ON p.id = a.product_id
            WHERE a.store_id = :s AND a.occurred_at BETWEEN :f AND :t" . ($f['level'] ? ' AND a.level = :lv' : '') . ($f['camera_id'] ? ' AND a.camera_id = :c' : '') . ($f['product_id'] ? ' AND a.product_id = :p' : '')
            . ' ORDER BY a.occurred_at LIMIT 5000', array_filter(['s' => $s, 'f' => "$from 00:00:00", 't' => "$to 23:59:59", 'lv' => $f['level'], 'c' => $f['camera_id'], 'p' => $f['product_id']], static fn ($v) => $v !== null));
        $sections[] = ['title' => 'Alertes', 'sheet' => 'Alertes', 'headers' => ['Date', 'Niveau', 'Type', 'Caméra', 'Produit', 'Confiance', 'Statut'],
            'rows' => array_map(static fn ($a) => [$a['occurred_at'], label('level', $a['level']), label('alert_type', $a['alert_type']), $a['camera'], $a['product'] ?? 'Non identifié',
                $a['confidence'] !== null ? round((float) $a['confidence'] * 100) . ' %' : '', label('alert_status', $a['status'])], $alerts)];
        $sections[0]['rows'][] = ['Avertissement', ReportService::DISCLAIMER];
        Audit::log('rapport_exporte', 'report', null, ['format' => $format, 'du' => $from, 'au' => $to]);
        $name = "smart-guard-rapport-{$from}_{$to}";
        match ($format) {
            'xlsx' => Response::download(Export::xlsx($sections), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "$name.xlsx"),
            'pdf' => Response::download(Export::pdf("Rapport SMART GUARD AI — {$store}", array_map(static fn ($x) => $x + ['notes' => []], $sections),
                ["Période : du " . dt($from, 'd/m/Y') . ' au ' . dt($to, 'd/m/Y'), ReportService::DISCLAIMER]), 'application/pdf', "$name.pdf"),
            default => Response::download(Export::csv($sections), 'text/csv; charset=utf-8', "$name.csv"),
        };
    }
}
