<?php
declare(strict_types=1);

namespace Verifia\Analysis;

use Verifia\Settings;

/** OCR via Tesseract (optionnel). Si l'outil n'est pas configuré, le statut l'indique clairement. */
final class Ocr
{
    public static function run(string $imagePath): array
    {
        $bin = Settings::get('tesseract_path', '');
        if ($bin === '' || !is_file($bin)) {
            return ['status' => 'unavailable', 'text' => null];
        }
        $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($imagePath) . ' stdout -l fra+eng';
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['status' => 'error', 'text' => null];
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            return ['status' => 'error', 'text' => null];
        }
        $text = trim((string) $out);
        return ['status' => $text === '' ? 'no_text' : 'done', 'text' => $text === '' ? null : mb_substr($text, 0, 5000)];
    }
}
