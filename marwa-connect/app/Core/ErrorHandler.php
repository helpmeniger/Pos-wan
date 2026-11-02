<?php
declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Gestion centralisée des erreurs et exceptions.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', config('app.debug') ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', STORAGE_PATH . '/logs/php-errors.log');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::report($e);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, '[ERREUR] ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
                exit(1);
            }
            self::render($e, null)->send();
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::critical($err['message'], ['file' => $err['file'], 'line' => $err['line']]);
            }
        });
    }

    public static function report(Throwable $e): void
    {
        if ($e instanceof HttpException && $e->status < 500) {
            return;
        }
        Logger::error($e->getMessage(), [
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    public static function render(Throwable $e, ?Request $request): Response
    {
        $status = $e instanceof HttpException ? $e->status : 500;
        $message = $e instanceof HttpException && $e->getMessage() !== ''
            ? $e->getMessage()
            : ($status === 500 ? 'Une erreur interne est survenue.' : 'Erreur');
        $debug = (bool) config('app.debug');

        if ($request && $request->isAjax()) {
            $payload = ['success' => false, 'message' => $message];
            if ($debug && $status === 500) {
                $payload['debug'] = ['exception' => $e::class, 'error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()];
            }
            $resp = Response::json($payload, $status);
        } else {
            try {
                $view = in_array($status, [403, 404, 405, 419, 429], true) ? 'errors/' . $status : 'errors/500';
                if ($status === 405) {
                    $view = 'errors/404';
                }
                $html = View::render($view, [
                    'status' => $status,
                    'message' => $message,
                    'exception' => $debug && $status === 500 ? $e : null,
                ], 'layouts/error');
            } catch (Throwable) {
                $html = '<h1>' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>';
            }
            $resp = Response::html($html, $status);
        }
        if ($e instanceof HttpException) {
            foreach ($e->headers as $k => $v) {
                $resp->withHeader($k, $v);
            }
        }
        return $resp;
    }
}
