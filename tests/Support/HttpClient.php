<?php

declare(strict_types=1);

namespace Tests\Support;

/** Client HTTP minimal pour les tests : cookies, suivi manuel des redirections, jeton CSRF. */
final class HttpClient
{
    /** @var array<string, string> */
    public array $cookies = [];
    public string $lastBody = '';

    public function __construct(private readonly string $base)
    {
    }

    /** @param array<string, mixed> $data @param array<string, string> $headers
     *  @return array{status: int, headers: array<string, string>, body: string} */
    public function request(string $method, string $path, array $data = [], array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "{$k}: {$v}";
        }
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $parsed = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if (strtolower($k) === 'set-cookie') {
                [$pair] = explode(';', $v, 2);
                [$ck, $cv] = array_pad(explode('=', $pair, 2), 2, '');
                if ($cv === '' || $cv === 'deleted') {
                    unset($this->cookies[$ck]);
                } else {
                    $this->cookies[$ck] = $cv;
                }
            }
            $parsed[strtolower($k)] = $v;
        }
        $this->lastBody = $body;
        return ['status' => $status, 'headers' => $parsed, 'body' => $body];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST avec le jeton CSRF obtenu sur $formPath. */
    public function submit(string $formPath, string $action, array $data, array $headers = []): array
    {
        $this->get($formPath);
        $data['_token'] = $this->csrf();
        return $this->request('POST', $action, $data, $headers);
    }

    public function csrf(): string
    {
        return preg_match('/name="_token" value="([a-f0-9]{64})"/', $this->lastBody, $m) ? $m[1] : '';
    }

    /** Suit les redirections (GET) et renvoie la page finale. */
    public function follow(array $response, int $max = 5): array
    {
        while ($max-- > 0 && in_array($response['status'], [301, 302, 303], true)) {
            $location = (string) ($response['headers']['location'] ?? '/');
            $path = (string) parse_url($location, PHP_URL_PATH) . (parse_url($location, PHP_URL_QUERY) ? '?' . parse_url($location, PHP_URL_QUERY) : '');
            $response = $this->get($path);
        }
        return $response;
    }
}
