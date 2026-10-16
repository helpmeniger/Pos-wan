<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf("[%s] %s: %s%s\n", date('Y-m-d H:i:s'), strtoupper($level), $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        $file = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
}
