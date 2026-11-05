<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lecture et écriture de classeurs Excel .xlsx sans bibliothèque externe (fonctionne hors ligne
 * sous WampServer). Lecture : première feuille, textes partagés, nombres, dates (numéro de
 * série Excel), cellules vides. Écriture : une feuille, en-tête en gras, colonnes ajustées.
 * Protections : taille décompressée limitée (bombe zip), aucune entité XML externe.
 */
final class Xlsx
{
    private const MAX_XML = 40 * 1048576;

    /** @return array<int, array<int, string>> lignes de cellules (texte), sans index de colonne manquant */
    public static function read(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new BusinessException('Lecture des fichiers Excel impossible : l\'extension PHP « zip » n\'est pas activée. Enregistrez le fichier en CSV.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new BusinessException('Fichier Excel illisible (.xlsx attendu ; un ancien .xls doit être enregistré en .xlsx ou CSV).');
        }
        try {
            $get = function (string $name) use ($zip): ?string {
                $st = $zip->statName($name);
                if ($st === false) {
                    return null;
                }
                if ($st['size'] > self::MAX_XML) {
                    throw new BusinessException('Classeur trop volumineux.');
                }
                $s = $zip->getFromName($name);
                return $s === false ? null : $s;
            };
            $xml = fn(?string $s) => $s === null ? null : (simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT) ?: null);
            // Première feuille du classeur
            $sheetPath = 'xl/worksheets/sheet1.xml';
            $wb = $xml($get('xl/workbook.xml'));
            $rels = $xml($get('xl/_rels/workbook.xml.rels'));
            if ($wb && $rels && isset($wb->sheets->sheet[0])) {
                $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rels->Relationship as $r) {
                    if ((string) $r['Id'] === $rid) {
                        $t = ltrim((string) $r['Target'], '/');
                        $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
            $shared = [];
            if ($ss = $xml($get('xl/sharedStrings.xml'))) {
                foreach ($ss->si as $si) {
                    $shared[] = self::text($si);
                }
            }
            $dateStyles = self::dateStyles($xml($get('xl/styles.xml')));
            $sheet = $xml($get($sheetPath));
            if (!$sheet) {
                throw new BusinessException('Feuille de calcul introuvable dans ce fichier.');
            }
            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $col = self::colIndex(preg_replace('/\d+/', '', (string) $c['r']) ?: 'A');
                    $type = (string) $c['t'];
                    $v = (string) $c->v;
                    $val = match ($type) {
                        's' => $shared[(int) $v] ?? '',
                        'inlineStr' => self::text($c->is),
                        'b' => $v === '1' ? 'VRAI' : 'FAUX',
                        default => $v,
                    };
                    // Date au format Excel (nombre de jours depuis 1900) → AAAA-MM-JJ
                    if (in_array($type, ['', 'n'], true) && is_numeric($v) && isset($dateStyles[(int) $c['s']])) {
                        $val = gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
                    }
                    $cells[$col] = trim($val);
                }
                if ($cells && implode('', $cells) !== '') {
                    $max = max(array_keys($cells));
                    $rows[] = array_map(fn($i) => $cells[$i] ?? '', range(0, $max));
                }
                if (count($rows) > 20001) {
                    throw new BusinessException('20 000 lignes au plus par fichier.');
                }
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        if (!$node) {
            return '';
        }
        if (isset($node->t)) {
            $s = (string) $node->t;
            foreach ($node->r as $r) {
                $s .= (string) $r->t;
            }
            return $s;
        }
        $s = '';
        foreach ($node->r as $r) {
            $s .= (string) $r->t;
        }
        return $s;
    }

    /** Index des styles de cellule qui affichent une date. */
    private static function dateStyles(?\SimpleXMLElement $st): array
    {
        if (!$st) {
            return [];
        }
        $custom = [];
        foreach ($st->numFmts->numFmt ?? [] as $f) {
            if (preg_match('/[dy]/i', preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $f['formatCode']))) {
                $custom[(int) $f['numFmtId']] = true;
            }
        }
        $out = [];
        $i = 0;
        foreach ($st->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || isset($custom[$id])) {
                $out[$i] = true;
            }
            $i++;
        }
        return $out;
    }

    public static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    public static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    /** Écrit un classeur d'une feuille et renvoie son contenu binaire. */
    public static function build(string $sheetName, array $headers, iterable $rows): string
    {
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $all = [$headers];
        foreach ($rows as $r) {
            $all[] = array_values($r);
        }
        $widths = [];
        $xmlRows = '';
        foreach ($all as $ri => $r) {
            $xmlRows .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = self::colName($ci) . ($ri + 1);
                $widths[$ci] = min(60, max($widths[$ci] ?? 8, mb_strlen((string) $v) + 2));
                if ($ri > 0 && (is_int($v) || is_float($v))) {
                    $xmlRows .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } else {
                    $xmlRows .= '<c r="' . $ref . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $esc(Csv::safeCell($v)) . '</t></is></c>';
                }
            }
            $xmlRows .= '</row>';
        }
        $cols = '';
        foreach ($widths as $ci => $w) {
            $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>' . $xmlRows . '</sheetData></worksheet>';
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }
}
