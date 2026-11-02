<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Réponse HTTP.
 */
class Response
{
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        protected array $headers = []
    ) {
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        if (!preg_match('#^https?://#i', $url) && !str_starts_with($url, Url::basePath() . '/') && !str_contains($url, '/index.php')) {
            $url = Url::to($url);
        }
        return new self('', $status, ['Location' => $url]);
    }

    public static function download(string $file, string $name, string $mime = 'application/octet-stream'): self
    {
        $r = new self((string) file_get_contents($file), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $name) . '"',
            'Content-Length' => (string) filesize($file),
        ]);
        return $r;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            $defaults = [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Referrer-Policy' => 'same-origin',
                'Permissions-Policy' => 'geolocation=(), camera=(), microphone=()',
                'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'",
            ];
            foreach (array_merge($defaults, $this->headers) as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->content;
    }
}
