<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Journalisation applicative (fichiers quotidiens dans storage/logs).
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5];

    public static function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        $min = (string) config('app.log_level', 'info');
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$min] ?? 1)) {
            return;
        }
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $context = self::scrub($context);
        $line = sprintf(
            "[%s] %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            $channel,
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents($dir . '/' . $channel . '-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** Masque les secrets avant écriture. */
    private static function scrub(array $context): array
    {
        foreach ($context as $k => $v) {
            if (is_array($v)) {
                $context[$k] = self::scrub($v);
            } elseif (is_string($k) && preg_match('/pass|secret|token|key|pwd/i', $k)) {
                $context[$k] = '***';
            }
        }
        return $context;
    }

    public static function debug(string $m, array $c = [], string $ch = 'app'): void { self::log('debug', $m, $c, $ch); }
    public static function info(string $m, array $c = [], string $ch = 'app'): void { self::log('info', $m, $c, $ch); }
    public static function warning(string $m, array $c = [], string $ch = 'app'): void { self::log('warning', $m, $c, $ch); }
    public static function error(string $m, array $c = [], string $ch = 'app'): void { self::log('error', $m, $c, $ch); }
    public static function critical(string $m, array $c = [], string $ch = 'app'): void { self::log('critical', $m, $c, $ch); }
}
