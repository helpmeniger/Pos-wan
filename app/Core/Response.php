<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public ?string $filePath = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self($status, ['Location' => $to]);
    }

    public static function download(string $content, string $filename, string $mime): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self(200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store',
        ], $content);
    }

    public static function file(string $path, string $mime, bool $inline = true): self
    {
        return new self(200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . basename($path) . '"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], '', $path);
    }

    public function json_data(): mixed
    {
        return json_decode($this->body, true);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($this->filePath !== null) {
            header('Content-Length: ' . filesize($this->filePath));
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
