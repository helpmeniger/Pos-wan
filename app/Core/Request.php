<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** Paramètres de route et attributs ajoutés par les middlewares. */
    public array $params = [];
    public array $attributes = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $files = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
        public readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = rtrim((string) parse_url((string) Config::get('app.url', ''), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        // Accès direct via /public/index.php (WampServer sans VirtualHost)
        $uri = preg_replace('#^/public(?=/|$)#', '', $uri);
        $uri = preg_replace('#^/index\.php(?=/|$)#', '', (string) $uri);
        $path = '/' . trim(rawurldecode((string) $uri), '/');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $raw = (string) file_get_contents('php://input');
        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json') && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw HttpException::badRequest('Corps JSON invalide.', 'invalid_json');
            }
            $body = $decoded;
        }

        return new self($method, $path, $_GET, $body, $headers, $_FILES, $_SERVER, $_COOKIE, $raw);
    }

    /** Fabrique utilisée par les tests pour simuler une requête HTTP. */
    public static function create(string $method, string $path, array $body = [], array $headers = [], array $query = []): self
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        $h['content-type'] ??= 'application/json';
        return new self(strtoupper($method), $path, $query, $body, $h, [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'tests'], [], json_encode($body) ?: '');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    public function intParam(string $name): int
    {
        $v = $this->params[$name] ?? '';
        if (!ctype_digit((string) $v)) {
            throw HttpException::notFound();
        }
        return (int) $v;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization', '');
        return preg_match('/^Bearer\s+([A-Za-z0-9._~-]{20,200})$/', (string) $h, $m) ? $m[1] : null;
    }

    public function ip(): string
    {
        // Ne faire confiance à X-Forwarded-For que derrière un proxy déclaré.
        $trusted = (array) Config::get('app.trusted_proxies', []);
        $remote = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($trusted && in_array($remote, $trusted, true) && !empty($this->headers['x-forwarded-for'])) {
            return trim(explode(',', $this->headers['x-forwarded-for'])[0]);
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/') || $this->path === '/api';
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $f : null;
    }
}
