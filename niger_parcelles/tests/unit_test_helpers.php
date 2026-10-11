<?php
/** Fonctions utilitaires partagées par les tests. */
declare(strict_types=1);

/** Fabrique un Shapefile polygonal (UTM 31N) zippé pour les tests. */
function np_make_shapefile_zip(string $zipPath, array $polygons, array $names, ?string $prj): void
{
    $records = '';
    $num = 1;
    foreach ($polygons as $ring) {
        $n = count($ring);
        $xs = array_column($ring, 0);
        $ys = array_column($ring, 1);
        $content = pack('V', 5) . pack('e4', min($xs), min($ys), max($xs), max($ys)) . pack('V', 1) . pack('V', $n) . pack('V', 0);
        foreach ($ring as $p) {
            $content .= pack('e2', $p[0], $p[1]);
        }
        $records .= pack('N', $num++) . pack('N', strlen($content) / 2) . $content;
    }
    $header = pack('N', 9994) . str_repeat("\0", 20) . pack('N', (100 + strlen($records)) / 2) . pack('V', 1000) . pack('V', 5) . str_repeat("\0", 64);
    $shp = $header . $records;
    // DBF : champs NAME (C20) et REF_CAD (C20)
    $fields = [['NAME', 20], ['REF_CAD', 20]];
    $recLen = 1 + array_sum(array_column($fields, 1));
    $hdrLen = 32 + 32 * count($fields) + 1;
    $dbf = pack('C', 3) . pack('C3', 126, 1, 1) . pack('V', count($names)) . pack('v', $hdrLen) . pack('v', $recLen) . str_repeat("\0", 20);
    foreach ($fields as [$name, $len]) {
        $dbf .= str_pad($name, 11, "\0") . 'C' . str_repeat("\0", 4) . pack('C', $len) . "\0" . str_repeat("\0", 14);
    }
    $dbf .= "\x0D";
    foreach ($names as [$a, $b]) {
        $dbf .= ' ' . str_pad($a, 20) . str_pad($b, 20);
    }
    $dbf .= "\x1A";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('parcelles/test.shp', $shp);
    $zip->addFromString('parcelles/test.dbf', $dbf);
    if ($prj !== null) {
        $zip->addFromString('parcelles/test.prj', $prj);
    }
    $zip->close();
}

