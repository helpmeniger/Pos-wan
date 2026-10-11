<?php
/**
 * Lecteur Shapefile en PHP pur (polygones 2D/Z/M, attributs DBF, projection .prj).
 * Lit les composants depuis une archive ZIP sans extraction sur disque
 * (aucun risque de traversée de répertoire).
 */
declare(strict_types=1);

final class ShapefileReader
{
    /**
     * @return array{features: array, prj: ?string, warnings: array}
     */
    public static function readZip(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new InvalidArgumentException('L\'extension PHP zip est nécessaire pour importer un Shapefile (WampServer > PHP > Extensions > php_zip).');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('Archive ZIP illisible.');
        }
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);
            if (substr($name, -1) === '/' || strpos($name, '__MACOSX') !== false) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['shp', 'dbf', 'prj', 'cpg', 'shx'], true) && !isset($parts[$ext])) {
                if (($stat['size'] ?? 0) > 200 * 1024 * 1024) {
                    throw new InvalidArgumentException('Composant du Shapefile trop volumineux.');
                }
                $parts[$ext] = ['name' => $name, 'index' => $i];
            }
        }
        if (!isset($parts['shp'])) {
            throw new InvalidArgumentException('Aucun fichier .shp trouvé dans l\'archive ZIP.');
        }
        $warnings = [];
        if (!isset($parts['dbf'])) {
            $warnings[] = 'Fichier .dbf absent : les attributs ne seront pas importés.';
        }
        if (!isset($parts['prj'])) {
            $warnings[] = 'Fichier .prj absent : le système de coordonnées doit être choisi manuellement.';
        }
        $shp = (string) $zip->getFromIndex($parts['shp']['index']);
        $dbf = isset($parts['dbf']) ? (string) $zip->getFromIndex($parts['dbf']['index']) : null;
        $prj = isset($parts['prj']) ? (string) $zip->getFromIndex($parts['prj']['index']) : null;
        $cpg = isset($parts['cpg']) ? trim((string) $zip->getFromIndex($parts['cpg']['index'])) : null;
        $zip->close();

        $geoms = self::parseShp($shp);
        $attrs = $dbf !== null ? self::parseDbf($dbf, $cpg) : [];
        $features = [];
        foreach ($geoms as $i => $g) {
            $features[] = ['properties' => $attrs[$i] ?? [], 'geometry' => $g];
        }
        return ['features' => $features, 'prj' => $prj, 'warnings' => $warnings];
    }

    /** @return array<int, ?array> géométries GeoJSON (coordonnées dans le CRS du fichier) */
    public static function parseShp(string $data): array
    {
        if (strlen($data) < 100 || unpack('N', substr($data, 0, 4))[1] !== 9994) {
            throw new InvalidArgumentException('Fichier .shp invalide.');
        }
        $type = unpack('V', substr($data, 32, 4))[1];
        if (!in_array($type, [5, 15, 25], true)) {
            throw new InvalidArgumentException("Type de géométrie Shapefile non pris en charge ($type) : seuls les polygones sont acceptés.");
        }
        $out = [];
        $pos = 100;
        $len = strlen($data);
        while ($pos + 8 <= $len) {
            $hdr = unpack('Nnum/Nwords', substr($data, $pos, 8));
            $contentLen = $hdr['words'] * 2;
            $content = substr($data, $pos + 8, $contentLen);
            $pos += 8 + $contentLen;
            if (strlen($content) < 4) {
                break;
            }
            $st = unpack('V', substr($content, 0, 4))[1];
            if ($st === 0) {
                $out[] = null;
                continue;
            }
            $numParts = unpack('V', substr($content, 36, 4))[1];
            $numPoints = unpack('V', substr($content, 40, 4))[1];
            if ($numParts > 100000 || $numPoints > 1000000) {
                throw new InvalidArgumentException('Enregistrement Shapefile corrompu.');
            }
            $parts = array_values(unpack('V' . $numParts, substr($content, 44, 4 * $numParts)));
            $ptOffset = 44 + 4 * $numParts;
            $coords = array_values(unpack('e' . (2 * $numPoints), substr($content, $ptOffset, 16 * $numPoints)));
            $rings = [];
            for ($p = 0; $p < $numParts; $p++) {
                $start = $parts[$p];
                $end = $p + 1 < $numParts ? $parts[$p + 1] : $numPoints;
                $ring = [];
                for ($k = $start; $k < $end; $k++) {
                    $ring[] = [$coords[2 * $k], $coords[2 * $k + 1]];
                }
                $rings[] = $ring;
            }
            // Convention Shapefile : anneaux extérieurs horaires, trous anti-horaires
            $polys = [];
            foreach ($rings as $ring) {
                $s = 0.0;
                for ($k = 0, $n = count($ring) - 1; $k < $n; $k++) {
                    $s += $ring[$k][0] * $ring[$k + 1][1] - $ring[$k + 1][0] * $ring[$k][1];
                }
                if ($s <= 0 || !$polys) {
                    $polys[] = [$ring];
                } else {
                    $polys[count($polys) - 1][] = $ring;
                }
            }
            $out[] = count($polys) === 1 ? ['type' => 'Polygon', 'coordinates' => $polys[0]]
                                        : ['type' => 'MultiPolygon', 'coordinates' => $polys];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function parseDbf(string $data, ?string $cpg = null): array
    {
        if (strlen($data) < 32) {
            return [];
        }
        $h = unpack('Cver/Cy/Cm/Cd/Vcount/vheader/vrecord', substr($data, 0, 12));
        $fields = [];
        for ($off = 32; $off + 32 <= $h['header'] && $data[$off] !== "\x0D"; $off += 32) {
            $fields[] = [
                'name' => rtrim(substr($data, $off, 11), "\0 "),
                'type' => $data[$off + 11],
                'len' => ord($data[$off + 16]),
            ];
        }
        $utf8 = $cpg !== null && stripos($cpg, 'utf') !== false;
        $rows = [];
        for ($r = 0; $r < $h['count']; $r++) {
            $rec = substr($data, $h['header'] + $r * $h['record'], $h['record']);
            if ($rec === '' || $rec[0] === '*') {
                $rows[] = [];
                continue;
            }
            $pos = 1;
            $row = [];
            foreach ($fields as $f) {
                $raw = trim(substr($rec, $pos, $f['len']));
                $pos += $f['len'];
                if (!$utf8 && !mb_check_encoding($raw, 'UTF-8')) {
                    $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
                }
                if (in_array($f['type'], ['N', 'F'], true)) {
                    $row[$f['name']] = $raw === '' ? null : (is_numeric($raw) ? $raw + 0 : $raw);
                } else {
                    $row[$f['name']] = $raw;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
