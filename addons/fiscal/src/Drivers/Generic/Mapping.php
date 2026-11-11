<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Generic;

/** Outils communs aux pilotes génériques : lecture par chemin, gabarits, correspondance des réponses. */
trait Mapping
{
    /** Valeur à un chemin « a.b.0.c » (null si absente). */
    protected static function at(mixed $data, string $path): mixed
    {
        if ($path === '') {
            return $data;
        }
        foreach (explode('.', $path) as $k) {
            if (is_array($data) && array_key_exists($k, $data)) {
                $data = $data[$k];
            } else {
                return null;
            }
        }
        return $data;
    }

    /**
     * Gabarit : {{chemin}} remplacé par la valeur de la facture, {{json:chemin}} par sa forme JSON.
     * $escape : 'json' (valeur encodée en chaîne JSON), 'xml' (échappement XML), 'raw'.
     */
    protected static function render(string $tpl, array $vars, string $escape = 'raw'): string
    {
        return (string) preg_replace_callback('/\{\{\s*(json:)?([a-zA-Z0-9_.]*)\s*\}\}/', function ($m) use ($vars, $escape) {
            $v = self::at($vars, $m[2]);
            if ($m[1] === 'json:') {
                return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            }
            $s = is_scalar($v) ? (string) $v : ($v === null ? '' : (string) json_encode($v, JSON_UNESCAPED_UNICODE));
            return match ($escape) {
                'xml' => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                'json' => substr((string) json_encode($s, JSON_UNESCAPED_UNICODE), 1, -1),
                default => $s,
            };
        }, $tpl);
    }

    /**
     * Réponse décodée → données fiscales selon config.response_map {champ: chemin}.
     * Champs reconnus par le moteur : fiscal_number, device_id, signature, qr_payload, counters.
     */
    protected function mapResponse(array $decoded): array
    {
        $out = [];
        foreach ((array) ($this->config['response_map'] ?? []) as $field => $path) {
            $v = self::at($decoded, (string) $path);
            if ($v !== null && $v !== '') {
                $out[$field] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        return $out;
    }

    /** Succès métier : config.success = {path, values[]} ; sans configuration, un code 2xx suffit. */
    protected function businessSuccess(array $decoded): ?bool
    {
        $s = $this->config['success'] ?? null;
        if (!is_array($s) || empty($s['path'])) {
            return null;
        }
        $v = self::at($decoded, (string) $s['path']);
        return in_array(is_scalar($v) ? (string) $v : '', array_map('strval', (array) ($s['values'] ?? [])), true);
    }
}
