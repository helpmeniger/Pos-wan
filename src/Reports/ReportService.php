<?php
declare(strict_types=1);

namespace Verifia\Reports;

use Verifia\Alerts\AlertService;
use Verifia\Analysis\TextAnalyzer;
use Verifia\Config;
use Verifia\Database;
use Verifia\EventLog;
use Verifia\View;

/** Génération des rapports d'alerte (PDF via Dompdf, HTML imprimable en secours). */
final class ReportService
{
    public static function dompdfAvailable(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** @return array{path:string, format:string, id:int} */
    public static function forAlert(int $alertId, ?int $userId): array
    {
        $a = AlertService::detail($alertId);
        if (!$a) {
            throw new \RuntimeException('Alerte introuvable');
        }
        $html = self::html($a);
        $dir = Config::storagePath('reports/' . gmdate('Y/m'));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $base = 'alerte_' . $alertId . '_' . gmdate('Ymd_His');
        if (self::dompdfAvailable()) {
            $opt = new \Dompdf\Options();
            $opt->set('isRemoteEnabled', false);      // aucune requête réseau pendant le rendu
            $opt->set('isPhpEnabled', false);
            $opt->set('defaultFont', 'DejaVu Sans');  // police avec accents
            $opt->setChroot([Config::storagePath()]);
            $pdf = new \Dompdf\Dompdf($opt);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();
            $bin = $pdf->output();
            $format = 'pdf';
        } else {
            $bin = $html;
            $format = 'html';
        }
        $file = $dir . DIRECTORY_SEPARATOR . $base . '.' . $format;
        file_put_contents($file, $bin);
        $rel = 'reports/' . gmdate('Y/m') . '/' . $base . '.' . $format;
        $id = Database::insert('reports', [
            'alert_id' => $alertId, 'file_path' => $rel, 'format' => $format,
            'sha256' => hash('sha256', $bin), 'created_by' => $userId,
        ]);
        AlertService::history($alertId, $userId, 'report', null, $format);
        EventLog::audit($userId, 'report_generate', 'alert', $alertId, ['report_id' => $id, 'format' => $format]);
        return ['path' => $file, 'format' => $format, 'id' => $id];
    }

    private static function img(?string $rel, int $maxW = 480): string
    {
        if (!$rel) {
            return '';
        }
        $abs = Config::storagePath($rel);
        if (!is_file($abs) || filesize($abs) > 3_000_000) {
            return '';
        }
        $mime = mime_content_type($abs) ?: 'image/png';
        // Dompdf ne gère pas le WebP : on le signale au lieu de produire une image cassée.
        if ($mime === 'image/webp') {
            return '<p><em>[image WebP non incluse dans le PDF : ' . e(basename($rel)) . ']</em></p>';
        }
        return '<img src="data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($abs)) . '" style="max-width:' . $maxW . 'px;max-height:320px">';
    }

    public static function html(array $a): string
    {
        $p = $a['publication'];
        $r = $p['result'] ?? [];
        $ind = json_decode((string) ($r['indicators_json'] ?? '[]'), true) ?: [];
        $places = json_decode((string) ($r['places_json'] ?? '{}'), true) ?: [];
        $sources = json_decode((string) ($r['sources_json'] ?? '[]'), true) ?: [];
        $similar = json_decode((string) ($r['similar_json'] ?? '[]'), true) ?: [];
        $cat = TextAnalyzer::CATEGORY_LABELS[$a['category']] ?? $a['category'];
        ob_start();
        ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Rapport VERIFIA — alerte <?= (int) $a['id'] ?></title>
<style>
 body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:10.5px;color:#111}
 h1{font-size:18px;margin:0 0 4px} h2{font-size:13px;border-bottom:1px solid #999;margin:14px 0 6px;padding-bottom:2px}
 table{border-collapse:collapse;width:100%} td,th{border:1px solid #ccc;padding:3px 5px;vertical-align:top;text-align:left}
 th{background:#eee;width:28%} .muted{color:#555} .box{border:1px solid #ccc;padding:6px;background:#fafafa;white-space:pre-wrap}
 .warn{border:1px solid #c90;background:#fff6dd;padding:6px}
</style></head><body>
<h1>VERIFIA — Rapport d'alerte n° <?= (int) $a['id'] ?></h1>
<div class="muted">Généré le <?= e(View::date(gmdate('Y-m-d H:i:s'))) ?> · Version d'analyse <?= e($r['analyzer_version'] ?? '—') ?></div>
<div class="warn" style="margin-top:6px">Une priorité élevée ou critique signifie que la publication nécessite une vérification humaine. Elle ne signifie pas que la publication est fausse.</div>

<h2>Synthèse</h2>
<table>
<tr><th>Priorité</th><td><?= e(View::PRIORITY_LABELS[$a['priority']] ?? $a['priority']) ?> (score <?= (int) ($r['score'] ?? 0) ?>)</td></tr>
<tr><th>Catégorie</th><td><?= e($cat) ?><?= !empty($r['topic']) ? ' — sujet : ' . e($r['topic']) : '' ?></td></tr>
<tr><th>Confiance de l'analyse</th><td><?= round((float) $a['confidence'] * 100) ?> %</td></tr>
<tr><th>Statut de vérification</th><td><?= e(View::STATUS_LABELS[$a['status']] ?? $a['status']) ?><?= $a['assignee'] ? ' — affectée à ' . e($a['assignee']) : '' ?></td></tr>
<tr><th>Motif</th><td><?= e($a['reason']) ?></td></tr>
<tr><th>Site / session</th><td><?= e($a['site_name'] ?? '—') ?> / <?= e($a['session_name'] ?? '—') ?></td></tr>
<tr><th>Détection</th><td><?= e(View::date($a['created_at'])) ?> (première vue : <?= e(View::date($p['first_seen_at'])) ?>, <?= (int) $p['occurrences'] ?> occurrence(s))</td></tr>
<tr><th>Date affichée par le site</th><td><?= e($p['published_label'] ?? 'non disponible') ?></td></tr>
<tr><th>Lien</th><td><?= e($p['permalink'] ?? 'non disponible') ?></td></tr>
<tr><th>Auteur affiché</th><td><?= e($p['author_label'] ?? '—') ?></td></tr>
</table>

<h2>Contenu</h2>
<?= self::img($p['thumbnail_path']) ?>
<div class="box"><?= e($p['text_content']) ?></div>

<h2>Indices relevés</h2>
<?php if (!$ind): ?><p>Aucun indice particulier.</p><?php else: ?>
<table><tr><th>Indice</th><td><b>Poids</b></td><td><b>Détail</b></td></tr>
<?php foreach ($ind as $i): ?><tr><th><?= e($i['label']) ?></th><td><?= (int) $i['weight'] ?></td><td><?= e($i['excerpt'] ?? '') ?></td></tr><?php endforeach; ?>
</table><?php endif; ?>

<h2>Dates, lieux et sources citées dans la publication</h2>
<p><b>Dates :</b> <?= e(implode(', ', array_column($places['dates'] ?? [], 'text')) ?: 'aucune') ?><br>
<b>Lieux probables :</b> <?= e(implode(', ', $places['places'] ?? []) ?: 'aucun') ?><br>
<b>Sources citées :</b> <?= e(implode(' ; ', array_column($sources, 'value')) ?: 'aucune') ?></p>

<h2>Affirmations vérifiables</h2>
<?php if (!$p['claims']): ?><p>Aucune affirmation factuelle identifiée.</p><?php endif; ?>
<?php foreach ($p['claims'] as $n => $c): ?>
<p><b><?= $n + 1 ?>. </b><?= e($c['claim_text']) ?><br><span class="muted">Verdict : <?= e($c['verdict']) ?><?= $c['verification_method'] ? ' · Méthode : ' . e($c['verification_method']) : '' ?></span></p>
<?php if ($c['evidence']): ?><table><tr><th>Source</th><td><b>Position</b></td><td><b>Date</b></td><td><b>Indépendance</b></td></tr>
<?php foreach ($c['evidence'] as $ev): ?><tr><th><?= e(($ev['title'] ?: '') . ' ' . $ev['url']) ?></th><td><?= e($ev['stance']) ?></td><td><?= e($ev['source_published_at'] ?? '—') ?></td><td><?= e($ev['independence']) ?></td></tr><?php endforeach; ?>
</table><?php else: ?><p class="muted">Aucune source ajoutée : aucune recherche externe n'a été enregistrée pour cette affirmation.</p><?php endif; ?>
<?php if ($c['limitations']): ?><p class="muted">Limites : <?= e($c['limitations']) ?></p><?php endif; ?>
<?php endforeach; ?>

<?php if ($p['images']): ?><h2>Images</h2>
<?php foreach ($p['images'] as $img): ?>
<table><tr><th>Image #<?= (int) $img['id'] ?></th><td><?= self::img($img['file_path'], 260) ?></td></tr>
<tr><th>SHA-256</th><td><?= e($img['sha256']) ?></td></tr>
<tr><th>Empreinte perceptuelle (dHash)</th><td><?= e(sprintf('%016x', (int) $img['dhash'])) ?></td></tr>
<tr><th>OCR</th><td><?= e($img['ocr_status']) ?><?= $img['ocr_text'] ? ' : ' . e($img['ocr_text']) : '' ?></td></tr>
<tr><th>EXIF</th><td><?= e($img['exif_json'] ?? 'aucune métadonnée disponible') ?></td></tr>
<tr><th>Chronologie</th><td><?php foreach (AlertService::imageTimeline($img, $p) as $t): ?><?= e(View::date($t['date'])) ?> — <?= e($t['label']) ?><?= $t['shown_date'] ? ' (date affichée : ' . e($t['shown_date']) . ')' : '' ?><br><?php endforeach; ?>
<span class="muted">Les dates de détection VERIFIA ne correspondent pas nécessairement à la date de création de la photographie.</span></td></tr>
</table><br>
<?php endforeach; endif; ?>

<?php if ($similar): ?><h2>Contenus antérieurs similaires</h2><ul>
<?php foreach ($similar as $s): ?><li>Publication #<?= (int) $s['publication_id'] ?> — similarité <?= round($s['similarity'] * 100) ?> % — vue le <?= e(View::date($s['first_seen_at'])) ?></li><?php endforeach; ?></ul><?php endif; ?>

<h2>Limites de l'analyse</h2>
<div class="box"><?= e($r['limitations'] ?? '') ?></div>

<h2>Historique de l'alerte</h2>
<table><?php foreach ($a['history'] as $h): ?><tr><th><?= e(View::date($h['created_at'])) ?></th><td><?= e($h['action']) ?> <?= e(trim(($h['old_value'] ?? '') . ' → ' . ($h['new_value'] ?? ''), ' →')) ?> <?= $h['display_name'] ? '(' . e($h['display_name']) . ')' : '' ?> <?= e($h['comment'] ?? '') ?></td></tr><?php endforeach; ?></table>
</body></html>
        <?php
        return (string) ob_get_clean();
    }
}
