<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private ?array $json = null;

    public function __construct(public array $params = [])
    {
    }

    public function method(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($m === 'POST' && isset($_POST['_method']) && in_array(strtoupper($_POST['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            return strtoupper($_POST['_method']);
        }
        return $m;
    }

    public function path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->input($key, $this->query($key));
        return is_numeric($v) ? (int) $v : $default;
    }

    public function all(): array
    {
        return $_POST + $this->json();
    }

    public function json(): array
    {
        if ($this->json === null) {
            $this->json = [];
            if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
                $raw = file_get_contents('php://input', false, null, 0, 20 * 1024 * 1024);
                $data = json_decode((string) $raw, true);
                $this->json = is_array($data) ? $data : [];
            }
        }
        return $this->json;
    }

    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        return is_array($f) ? $f : null;
    }

    /** Normalise un champ de fichiers multiples en liste. */
    public function files(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f)) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
        return $out;
    }

    public function ip(): string
    {
        // Volontairement pas de X-Forwarded-For : non fiable sans proxy de confiance.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }
}
