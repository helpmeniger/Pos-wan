<?php
/**
 * Exports (respect des permissions, journalisés).
 *   ?type=csv|xlsx|geojson[&filtres de parcelles.php]  -> liste de parcelles
 *   ?type=geojson&id=                                   -> une parcelle
 *   ?type=fiche_pdf&id=                                 -> fiche parcellaire PDF
 *   ?type=owner_pdf&id=                                 -> fiche propriétaire PDF
 *   ?type=report&report=clé&format=csv|xlsx|pdf         -> rapport
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_login();
require_permission('export.run');

const EXPORT_MAX = 50000;

function send_download(string $content, string $mime, string $filename): void
{
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, no-store');
    echo $content;
    exit;
}

/** Lignes tabulaires des parcelles (colonnes personnelles selon les droits). */
function parcel_rows(array $filters): array
{
    [$where, $params] = ParcelQuery::where($filters);
    $personal = can('owner.view_personal');
    $headers = ['Référence', 'Réf. cadastrale', 'Région', 'Département', 'Commune', 'Quartier/Village', 'Localisation', 'Catégorie',
                'Superficie (m²)', 'Superficie (ha)', 'Périmètre (m)', 'Latitude centre', 'Longitude centre', 'Délimitation',
                'Situation déclarée', 'Titre foncier', 'Statut', 'Chevauchement', 'Nb propriétaires'];
    if ($personal) {
        $headers[] = 'Propriétaires actuels';
    }
    array_push($headers, 'Créée le', 'Démonstration');
    $rows = [];
    $areaCache = [];
    foreach (db_all("SELECT p.* FROM parcels p WHERE $where ORDER BY p.parcel_reference LIMIT " . EXPORT_MAX, $params) as $p) {
        $aid = (int) $p['administrative_area_id'];
        $areaCache[$aid] = $areaCache[$aid] ?? admin_area_breakdown($aid ?: null);
        $a = $areaCache[$aid];
        $owners = OwnerService::currentOwners((int) $p['id']);
        $row = [$p['parcel_reference'], $p['cadastral_reference'] ?? '', $a['region'] ?? '', $a['departement'] ?? '', $a['commune'] ?? '',
                $a['quartier'] ?? $a['village'] ?? '', $p['locality_description'] ?? '', label('category', $p['category']),
                (float) $p['area_m2'], round((float) $p['area_m2'] / 10000, 4), (float) $p['perimeter_m'], (float) $p['centroid_lat'], (float) $p['centroid_lng'],
                label('survey_level', $p['survey_level']), label('declared_status', $p['declared_status']), $p['title_reference'] ?? '',
                label('verification_status', $p['verification_status']), (int) $p['has_overlap_warning'] ? 'Oui' : 'Non', count($owners)];
        if ($personal) {
            $row[] = implode(' ; ', array_map(static fn ($o) => owner_display_name($o) . ' (' . label('ownership_role', $o['ownership_role'])
                . ($o['share_percentage'] !== null ? ' ' . $o['share_percentage'] . ' %' : '') . ')', $owners));
        }
        array_push($row, $p['created_at'], (int) $p['is_demo'] ? 'FICTIF' : '');
        $rows[] = $row;
    }
    return [$headers, $rows];
}

function csv_content(array $headers, array $rows): string
{
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
    fputcsv($fh, $headers, ';', '"', '');
    foreach ($rows as $r) {
        // Neutralise les formules (injection CSV)
        $r = array_map(static fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : (is_float($v) ? str_replace('.', ',', (string) $v) : $v), $r);
        fputcsv($fh, $r, ';', '"', '');
    }
    rewind($fh);
    return (string) stream_get_contents($fh);
}

function xlsx_content(string $sheet, array $headers, array $rows): array
{
    $tmp = tempnam(storage_path('exports'), 'xl');
    $mime = XlsxWriter::write($tmp, $sheet, $headers, $rows);
    $content = (string) file_get_contents($tmp);
    @unlink($tmp);
    return [$content, $mime, $mime === 'application/vnd.ms-excel' ? 'xls' : 'xlsx'];
}

$type = (string) ($_GET['type'] ?? '');
$stamp = date('Ymd_His');

switch ($type) {
    case 'csv':
    case 'xlsx':
        $filters = ParcelQuery::filtersFrom($_GET);
        [$headers, $rows] = parcel_rows($filters);
        audit_log('export_parcelles', null, null, ['format' => $type, 'lignes' => count($rows), 'filtres' => array_filter($filters)]);
        if ($type === 'csv') {
            send_download(csv_content($headers, $rows), 'text/csv; charset=utf-8', "parcelles_$stamp.csv");
        }
        [$content, $mime, $ext] = xlsx_content('Parcelles', $headers, $rows);
        send_download($content, $mime, "parcelles_$stamp.$ext");

    case 'geojson':
        $personal = can('owner.view_personal');
        if (!empty($_GET['id'])) {
            $sql = 'p.id = ?';
            $params = [(int) $_GET['id']];
        } else {
            [$sql, $params] = ParcelQuery::where(ParcelQuery::filtersFrom($_GET));
        }
        $features = [];
        foreach (db_all("SELECT p.*, g.geojson, g.geometry_version, a.name AS area_name FROM parcels p
                         JOIN parcel_geometries g ON g.parcel_id = p.id AND g.geometry_version = p.current_geometry_version
                         LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id WHERE $sql ORDER BY p.parcel_reference LIMIT " . EXPORT_MAX, $params) as $p) {
            $props = ['reference' => $p['parcel_reference'], 'cadastral_reference' => $p['cadastral_reference'], 'zone' => $p['area_name'],
                      'categorie' => $p['category'], 'superficie_m2' => (float) $p['area_m2'], 'perimetre_m' => (float) $p['perimeter_m'],
                      'delimitation' => $p['survey_level'], 'situation_declaree' => $p['declared_status'], 'statut' => $p['verification_status'],
                      'version_geometrie' => (int) $p['geometry_version'], 'cree_le' => $p['created_at'], 'demo' => (int) $p['is_demo'] === 1];
            if ($personal) {
                $props['proprietaires'] = array_map('owner_display_name', OwnerService::currentOwners((int) $p['id']));
            }
            $features[] = ['type' => 'Feature', 'id' => $p['parcel_reference'], 'geometry' => json_decode($p['geojson'], true), 'properties' => $props];
        }
        if (!empty($_GET['id']) && !$features) {
            json_error('Parcelle introuvable.', 404);
        }
        audit_log('export_geojson', null, null, ['entites' => count($features)]);
        $fc = ['type' => 'FeatureCollection', 'name' => 'niger_parcelles',
               'metadata' => ['source' => 'Niger Parcelles', 'crs' => 'EPSG:4326 (WGS84, RFC 7946)', 'exporte_le' => date('c'),
                              'avertissement' => 'Données de gestion : ne valent pas titre foncier.'], 'features' => $features];
        send_download(json_encode($fc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'application/geo+json',
            (count($features) === 1 && !empty($_GET['id']) ? $features[0]['id'] : 'parcelles_' . $stamp) . '.geojson');

    case 'fiche_pdf':
        $id = (int) ($_GET['id'] ?? 0);
        $p = ParcelService::getOrFail($id);
        send_download(PdfService::parcelSheet($id), 'application/pdf', 'fiche_' . $p['parcel_reference'] . '.pdf');

    case 'owner_pdf':
        require_permission('owner.view_personal');
        $id = (int) ($_GET['id'] ?? 0);
        send_download(PdfService::ownerSheet($id), 'application/pdf', 'fiche_proprietaire_' . $id . '.pdf');

    case 'report':
        require_permission('report.view');
        $report = ReportService::run((string) ($_GET['report'] ?? ''), $_GET);
        $format = (string) ($_GET['format'] ?? 'csv');
        audit_log('export_rapport', null, null, ['rapport' => $report['key'], 'format' => $format, 'lignes' => count($report['rows'])]);
        $name = 'rapport_' . $report['key'] . '_' . $stamp;
        if ($format === 'pdf') {
            send_download(PdfService::report($report), 'application/pdf', "$name.pdf");
        }
        if ($format === 'xlsx') {
            [$content, $mime, $ext] = xlsx_content(mb_substr($report['title'], 0, 31), $report['columns'], $report['rows']);
            send_download($content, $mime, "$name.$ext");
        }
        send_download(csv_content($report['columns'], $report['rows']), 'text/csv; charset=utf-8', "$name.csv");
}
json_error('Type d\'export inconnu.', 400);
