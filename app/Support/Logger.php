<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Journal technique fichier (une ligne JSON par événement, un fichier par jour).
 * Les données sensibles (mots de passe, jetons, codes) sont masquées avant écriture.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5];
    private const SECRET_KEYS = ['password', 'password_confirmation', 'current_password', 'token', '_token', 'code', 'secret', 'pin', 'otp', 'authorization'];

    public function __construct(private readonly string $dir, private readonly string $minLevel = 'info')
    {
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        $line = json_encode([
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($this->dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function info(string $m, array $c = []): void
    {
        $this->log('info', $m, $c);
    }

    public function warning(string $m, array $c = []): void
    {
        $this->log('warning', $m, $c);
    }

    public function error(string $m, array $c = []): void
    {
        $this->log('error', $m, $c);
    }

    /** @param array<mixed> $data @return array<mixed> */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $data[$key] = '[masqué]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif ($value instanceof \Throwable) {
                $data[$key] = get_class($value) . ': ' . $value->getMessage() . ' @ ' . $value->getFile() . ':' . $value->getLine();
            }
        }
        return $data;
    }
}
