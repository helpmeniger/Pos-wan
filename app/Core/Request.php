<?php

declare(strict_types=1);

namespace App\Core;

/** Requête HTTP entrante (immuable sauf paramètres de route et attributs). */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, string> $cookies
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Application installée dans un sous-dossier (ex : http://localhost/job-niger/public/)
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base !== '' && $base !== '.' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $raw = (string) file_get_contents('php://input');
        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json') && $raw !== '') {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        // Méthodes PUT/PATCH/DELETE depuis un formulaire HTML (_method)
        if ($method === 'POST' && isset($body['_method'])) {
            $override = strtoupper((string) $body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        return new self($method, '/' . trim($path, '/'), $_GET, $body, $_SERVER, $_FILES, $_COOKIE, $raw);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path === '' ? '/' : $this->path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /** @param list<string> $keys @return array<string, mixed> */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->input($k);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        if ($value === null && strtolower($name) === 'content-type') {
            $value = $this->server['CONTENT_TYPE'] ?? null;
        }
        return $value !== null ? (string) $value : $default;
    }

    public function cookie(string $name): ?string
    {
        return isset($this->cookies[$name]) ? (string) $this->cookies[$name] : null;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return is_array($f) ? $f : null;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function ip(): string
    {
        // Derrière un proxy de confiance uniquement (TRUSTED_PROXIES) on lit X-Forwarded-For.
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))));
        if ($trusted && in_array($remote, $trusted, true) && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $this->server['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        return ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            && in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))), true);
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path(), '/api/')
            || str_contains((string) $this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function isMethodSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
