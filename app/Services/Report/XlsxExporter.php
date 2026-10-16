<?php
declare(strict_types=1);

namespace App\Services\Report;

use RuntimeException;
use ZipArchive;

/** Classeur Excel .xlsx minimal (Office Open XML) généré sans dépendance externe. */
final class XlsxExporter
{
    public static function export(array $report): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('L\'extension PHP zip est requise pour l\'export Excel (activez-la dans WampServer).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mwx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Rapport" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF0B6E4F"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B6E4F"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="5"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf fontId="2" applyFont="1"/><xf fontId="0" numFmtId="164" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>');

        $cols = array_keys($report['columns']);
        $rows = [];
        $r = 1;
        $rows[] = self::row($r++, [$report['title']], 3);
        $rows[] = self::row($r++, [$report['subtitle'] ?? '']);
        $r++;
        $rows[] = self::row($r++, array_values($report['columns']), 1);
        foreach ($report['rows'] as $row) {
            $rows[] = self::row($r++, array_map(static fn ($k) => $row[$k] ?? '', $cols));
        }
        if (!empty($report['totals'])) {
            $rows[] = self::row($r++, array_map(static fn ($k) => $report['totals'][$k] ?? '', $cols));
        }
        $widths = '';
        foreach ($cols as $i => $c) {
            $widths .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($i === 0 ? 28 : 18) . '" customWidth="1"/>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>' . $widths . '</cols><sheetData>'
            . implode('', $rows) . '</sheetData></worksheet>');
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function row(int $r, array $values, int $style = 0): string
    {
        $cells = '';
        foreach (array_values($values) as $i => $v) {
            $ref = self::col($i) . $r;
            if ((is_int($v) || is_float($v)) && $style === 0) {
                $cells .= '<c r="' . $ref . '" s="2"><v>' . $v . '</v></c>';
            } else {
                $s = htmlspecialchars(CsvExporter::cell($v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . $s . '</t></is></c>';
            }
        }
        return '<row r="' . $r . '">' . $cells . '</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }
}
