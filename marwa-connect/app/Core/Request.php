<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Représentation de la requête HTTP courante.
 */
final class Request
{
    private array $json = [];
    public array $routeParams = [];
    public array $attributes = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $files = []
    ) {
        $type = $this->server['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        return new self($method, self::resolvePath(), $_GET, $_POST, $_SERVER, $_FILES);
    }

    /**
     * Détermine le chemin applicatif quel que soit le mode d'accès :
     *  - /marwa-connect/login            (réécriture Apache active)
     *  - /marwa-connect/public/login
     *  - /marwa-connect/public/index.php/login (sans mod_rewrite)
     */
    private static function resolvePath(): string
    {
        $uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $base = Url::basePath();
        foreach ([$script, $base] as $prefix) {
            if ($prefix !== '' && $prefix !== '/' && str_starts_with($uri, $prefix)) {
                $uri = substr($uri, strlen($prefix));
                break;
            }
        }
        $path = '/' . trim($uri, '/');
        return $path === '/index.php' ? '/' : $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json);
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function ip(): string
    {
        // On ne fait pas confiance à X-Forwarded-For par défaut (usurpation possible).
        if (config('security.trust_proxy') && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $this->server['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isAjax(): bool
    {
        return ($this->header('X-Requested-With') === 'XMLHttpRequest')
            || str_contains((string) $this->header('Accept'), 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function isSecure(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || (($this->server['SERVER_PORT'] ?? null) == 443);
    }
}
