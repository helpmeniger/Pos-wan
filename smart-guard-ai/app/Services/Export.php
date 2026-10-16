<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Exports sans dépendance externe : CSV (UTF-8 avec BOM, séparateur « ; » pour Excel FR),
 * XLSX (Office Open XML via ZipArchive) et PDF (générateur intégré, polices standard).
 * $sections : liste de ['title' => string, 'headers' => [...], 'rows' => [[...], ...]]
 */
final class Export
{
    public static function csv(array $sections): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($sections as $i => $s) {
            if ($i > 0) {
                fwrite($fh, "\r\n");
            }
            if (!empty($s['title'])) {
                fputcsv($fh, [self::safeCell($s['title'])], ';', '"', '');
            }
            fputcsv($fh, array_map([self::class, 'safeCell'], $s['headers']), ';', '"', '');
            foreach ($s['rows'] as $row) {
                fputcsv($fh, array_map([self::class, 'safeCell'], $row), ';', '"', '');
            }
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }

    /** Neutralise l'injection de formules dans les tableurs (=, +, -, @). */
    private static function safeCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    }

    public static function xlsx(array $sections): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Extension PHP « zip » requise pour l\'export Excel (activez-la dans WampServer).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sgx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $sheetsXml = '';
        $rels = '';
        $ctypes = '';
        $names = [];
        foreach ($sections as $i => $s) {
            $n = $i + 1;
            $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', (string) ($s['sheet'] ?? $s['title'] ?? 'Feuille ' . $n)), 0, 31);
            while (in_array($name, $names, true)) {
                $name = mb_substr($name, 0, 28) . ' ' . $n;
            }
            $names[] = $name;
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", self::sheet($s));
            $sheetsXml .= '<sheet name="' . self::x($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $ctypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $n = count($sections) + 1;
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ctypes . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheetsXml . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function sheet(array $s): string
    {
        $rows = [];
        if (!empty($s['title'])) {
            $rows[] = [[$s['title'], 1]];
            $rows[] = [];
        }
        $rows[] = array_map(static fn ($h) => [$h, 1], $s['headers']);
        foreach ($s['rows'] as $r) {
            $rows[] = array_map(static fn ($v) => [$v, 0], $r);
        }
        $xml = '';
        foreach ($rows as $ri => $cells) {
            $xml .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($cells) as $ci => [$v, $bold]) {
                $ref = self::col($ci) . ($ri + 1);
                $style = $bold ? ' s="1"' : '';
                if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) && strlen($v) < 15)) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                } elseif ($v !== null && $v !== '') {
                    $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function pdf(string $title, array $sections, array $meta = []): string
    {
        $pdf = new PdfWriter();
        $pdf->title($title);
        foreach ($meta as $line) {
            $pdf->text($line, 9);
        }
        foreach ($sections as $s) {
            $pdf->space(8);
            if (!empty($s['title'])) {
                $pdf->heading($s['title']);
            }
            if (!empty($s['headers'])) {
                $pdf->table($s['headers'], $s['rows']);
            }
            foreach ($s['notes'] ?? [] as $n) {
                $pdf->text($n, 8);
            }
        }
        return $pdf->output();
    }
}
