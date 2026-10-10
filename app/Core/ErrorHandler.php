<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Les erreurs sont journalisées dans storage/logs ; l'utilisateur ne voit
 * qu'un message générique (sauf en mode debug sur un poste de développement).
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(Throwable $e): void
    {
        $status = $e instanceof HttpException ? $e->status : 500;
        if ($status >= 500) {
            Logger::error(get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $e->getMessage() . PHP_EOL . (self::$debug ? $e->getTraceAsString() . PHP_EOL : ''));
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code($status);
        }
        $message = match (true) {
            $e instanceof HttpException && $e->getMessage() !== '' => $e->getMessage(),
            $status === 403 => 'Accès refusé.',
            $status === 404 => 'Page introuvable.',
            $status === 419 => 'Session expirée ou jeton de sécurité invalide. Rechargez la page.',
            default => 'Une erreur interne est survenue. Elle a été enregistrée.',
        };
        $detail = self::$debug ? $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() : null;
        if (Request::wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $message, 'debug' => $detail], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            echo View::render('errors/error', ['status' => $status, 'message' => $message, 'detail' => $detail], null);
        } catch (Throwable) {
            echo '<h1>' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>';
        }
    }
}
