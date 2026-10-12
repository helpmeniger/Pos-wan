<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Response;
use App\Services\Report\CsvExporter;
use App\Services\Report\PdfExporter;
use App\Services\Report\XlsxExporter;

final class ExportResponder
{
    public static function respond(array $report, string $format, string $basename): Response
    {
        $name = 'marwa-' . preg_replace('/[^a-z0-9-]/i', '-', $basename);
        return match ($format) {
            'csv' => Response::download(CsvExporter::export($report), $name . '.csv', 'text/csv; charset=utf-8'),
            'xlsx' => Response::download(XlsxExporter::export($report), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            'pdf' => Response::download(PdfExporter::export($report), $name . '.pdf', 'application/pdf'),
            default => throw HttpException::badRequest('Format d\'export inconnu (csv, xlsx ou pdf).'),
        };
    }
}
