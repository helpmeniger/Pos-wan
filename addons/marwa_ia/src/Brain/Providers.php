<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Llm;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Env;
use App\Services\LicenceService;

/**
 * Gestionnaire des modèles IA : Ollama (local), OpenAI, Anthropic Claude, Google Gemini, API compatible OpenAI.
 *
 * - Les clés sont chiffrées (libsodium, clé dérivée de APP_KEY) et jamais renvoyées au navigateur.
 * - Un fournisseur externe ne peut être activé qu'après l'accord explicite de l'administrateur (external_ack) :
 *   seuls la question et les faits déjà calculés et autorisés pour l'utilisateur lui sont envoyés, jamais la base.
 * - Modèle par défaut + modèle de secours ; limites journalières (jetons, requêtes par utilisateur) ; coût estimé.
 * - Streaming réel : chaque fournisseur renvoie ses jetons au fur et à mesure (SSE ou NDJSON), relayés tels quels.
 * L'API Claude est appelée en HTTP direct (pas de SDK) car POS Marwa s'installe sans Composer.
 */
final class Providers
{
    public const PROVIDERS = [
        'ollama' => ['Ollama (modèle installé sur ce PC ou le réseau local)', 'http://127.0.0.1:11434', 'llama3.2'],
        'openai' => ['OpenAI', 'https://api.openai.com/v1', ''],
        'anthropic' => ['Anthropic Claude', 'https://api.anthropic.com', 'claude-opus-5-5'],
        'gemini' => ['Google Gemini', 'https://generativelanguage.googleapis.com', ''],
        'openai_compatible' => ['API compatible OpenAI (autre fournisseur)', '', ''],
    ];
    public const ANTHROPIC_VERSION = '2026-10-21';

    // ------------------------------------------------------------------ secrets

    private static function key(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        if (strlen($k) < 32) {
            throw new BusinessException('APP_KEY absente : la clé du fournisseur ne peut pas être chiffrée.');
        }
        return hash('sha256', 'marwa-ai|' . $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(24);
        return 'A1.' . base64_encode($nonce . LicenceService::sodium('crypto_secretbox', $plain, $nonce, self::key()));
    }

    public static function decrypt(?string $enc): ?string
    {
        if (!$enc) {
            return null;
        }
        $raw = str_starts_with($enc, 'A1.') ? base64_decode(substr($enc, 3), true) : false;
        $p = $raw !== false && strlen($raw) > 24 ? LicenceService::sodium('crypto_secretbox_open', substr($raw, 24), substr($raw, 0, 24), self::key()) : false;
        return $p === false ? null : (string) $p;
    }

    // ------------------------------------------------------------------ configuration

    public static function all(): array
    {
        return DB::all('SELECT * FROM ai_model_settings ORDER BY is_default DESC, is_fallback DESC, name');
    }

    public static function get(int $id): array
    {
        $m = DB::one('SELECT * FROM ai_model_settings WHERE id = ?', [$id]);
        if (!$m) {
            throw new BusinessException('Modèle introuvable.');
        }
        return $m;
    }

    /** Modèles utilisables dans l'ordre : défaut, puis secours. */
    public static function chain(): array
    {
        return DB::all('SELECT * FROM ai_model_settings WHERE active = 1 AND (is_default = 1 OR is_fallback = 1) ORDER BY is_default DESC, id');
    }

    public static function available(): bool
    {
        return (bool) self::chain();
    }

    public static function validateUrl(string $provider, string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            $url = self::PROVIDERS[$provider][1];
        }
        if ($url === '') {
            throw new BusinessException('Adresse de l\'API obligatoire.');
        }
        if ($provider === 'ollama') {
            return Llm::validateUrl($url); // réseau local uniquement, comme avant
        }
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        $local = $host === 'localhost' || str_starts_with($host, '127.') || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE));
        if (!in_array($p['scheme'] ?? '', ['https', 'http'], true) || $host === '' || (($p['scheme'] ?? '') === 'http' && !$local)) {
            throw new BusinessException('HTTPS obligatoire pour un fournisseur externe.');
        }
        return $url;
    }

    public static function save(array $in, ?int $id = null): int
    {
        $before = $id ? self::get($id) : null;
        $prov = (string) ($in['provider'] ?? '');
        if (!isset(self::PROVIDERS[$prov])) {
            throw new BusinessException('Fournisseur inconnu.');
        }
        $name = trim((string) ($in['name'] ?? ''));
        $model = trim((string) ($in['model'] ?? ''));
        if ($name === '' || $model === '' || !preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $model)) {
            throw new BusinessException('Nom et identifiant du modèle obligatoires (lettres, chiffres, . _ : / -).');
        }
        $url = self::validateUrl($prov, (string) ($in['base_url'] ?? ''));
        $external = $prov !== 'ollama' && !preg_match('#^https?://(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)#', $url);
        $ack = !empty($in['external_ack']) ? 1 : 0;
        $num = fn(string $k, ?float $def = null) => ($v = str_replace(',', '.', trim((string) ($in[$k] ?? '')))) === '' ? $def : (is_numeric($v) && (float) $v >= 0 ? (float) $v : throw new BusinessException('Valeur invalide : ' . $k));
        $data = ['name' => mb_substr($name, 0, 120), 'provider' => $prov, 'base_url' => $url, 'model' => $model, 'external_ack' => $external ? $ack : 1,
            'max_output_tokens' => max(64, min(64000, (int) ($in['max_output_tokens'] ?? 1024))), 'daily_token_limit' => ($v = $num('daily_token_limit')) !== null ? (int) $v : null,
            'user_daily_requests' => ($v = $num('user_daily_requests')) !== null ? (int) $v : null, 'input_cost_per_mtok' => $num('input_cost_per_mtok'), 'output_cost_per_mtok' => $num('output_cost_per_mtok'),
            'timeout_s' => max(5, min(180, (int) ($in['timeout_s'] ?? 40))), 'updated_at' => date('Y-m-d H:i:s')];
        $key = trim((string) ($in['api_key'] ?? ''));
        if ($key !== '') {
            $data['api_key_enc'] = self::encrypt($key);
            $data['api_key_hint'] = mb_strlen($key) >= 12 ? '…' . mb_substr($key, -4) : '…';
        } elseif (!empty($in['clear_key'])) {
            $data['api_key_enc'] = null;
            $data['api_key_hint'] = null;
        }
        if ($id) {
            DB::update('ai_model_settings', $data + ['status' => 'unknown'], 'id = ?', [$id]);
        } else {
            $id = DB::insert('ai_model_settings', $data + ['active' => 0]);
        }
        \App\Core\Audit::log($before ? 'ai.model_update' : 'ai.model_create', 'ai_model_setting', $id, ['provider' => $prov, 'model' => $model, 'external' => $external, 'key_changed' => $key !== '']);
        return $id;
    }

    public static function setRole(int $id, string $role): void
    {
        $m = self::get($id);
        if ($role === 'off') {
            DB::update('ai_model_settings', ['active' => 0, 'is_default' => 0, 'is_fallback' => 0], 'id = ?', [$id]);
        } else {
            if (!(int) $m['external_ack']) {
                throw new BusinessException('Fournisseur externe : cochez d\'abord l\'accord sur l\'envoi des questions et des faits calculés à ce fournisseur.');
            }
            if ($m['provider'] !== 'ollama' && !$m['api_key_enc']) {
                throw new BusinessException('Clé d\'API manquante.');
            }
            DB::run('UPDATE ai_model_settings SET ' . ($role === 'default' ? 'is_default' : 'is_fallback') . ' = 0');
            DB::update('ai_model_settings', ['active' => 1, $role === 'default' ? 'is_default' : 'is_fallback' => 1, $role === 'default' ? 'is_fallback' : 'is_default' => 0], 'id = ?', [$id]);
        }
        \App\Core\Audit::log('ai.model_role', 'ai_model_setting', $id, ['role' => $role]);
    }

    // ------------------------------------------------------------------ limites et coûts

    private static function allowed(array $m): ?string
    {
        if ($m['daily_token_limit'] !== null) {
            $used = (int) DB::value('SELECT COALESCE(SUM(input_tokens + output_tokens),0) FROM ai_usage_logs WHERE model_setting_id = ? AND created_at >= CURDATE()', [$m['id']]);
            if ($used >= (int) $m['daily_token_limit']) {
                return 'Limite journalière de jetons atteinte pour ' . $m['name'] . '.';
            }
        }
        if ($m['user_daily_requests'] !== null && Auth::id()) {
            $n = (int) DB::value('SELECT COUNT(*) FROM ai_usage_logs WHERE model_setting_id = ? AND user_id = ? AND created_at >= CURDATE()', [$m['id'], Auth::id()]);
            if ($n >= (int) $m['user_daily_requests']) {
                return 'Limite journalière de requêtes atteinte pour votre compte.';
            }
        }
        return null;
    }

    private static function logUsage(array $m, string $purpose, int $in, int $out, int $ms, bool $ok, ?string $err): void
    {
        $cost = ((float) ($m['input_cost_per_mtok'] ?? 0) * $in + (float) ($m['output_cost_per_mtok'] ?? 0) * $out) / 1_000_000;
        DB::insert('ai_usage_logs', ['model_setting_id' => $m['id'], 'user_id' => Auth::id(), 'purpose' => $purpose, 'input_tokens' => $in, 'output_tokens' => $out,
            'cost' => round($cost, 6), 'duration_ms' => $ms, 'ok' => $ok ? 1 : 0, 'error' => $err !== null ? mb_substr($err, 0, 255) : null]);
    }

    // ------------------------------------------------------------------ appel

    /**
     * Génère une réponse (texte) avec le modèle par défaut, puis le modèle de secours en cas d'échec.
     * @param callable|null $onDelta reçoit chaque morceau de texte dès qu'il arrive (streaming réel)
     * @return array{text: string, model: string}|null null si aucun modèle n'a pu répondre
     */
    public static function generate(string $system, array $messages, string $purpose, ?callable $onDelta = null, ?int $maxTokens = null): ?array
    {
        foreach (self::chain() as $m) {
            if ($why = self::allowed($m)) {
                self::logUsage($m, $purpose, 0, 0, 0, false, $why);
                continue;
            }
            $t = microtime(true);
            $sent = false;
            $wrapped = $onDelta ? function (string $d) use ($onDelta, &$sent) {
                $sent = true;
                $onDelta($d);
            } : null;
            try {
                $r = self::call($m, $system, $messages, $wrapped, $maxTokens);
                self::logUsage($m, $purpose, $r['in'], $r['out'], (int) round((microtime(true) - $t) * 1000), true, null);
                return ['text' => $r['text'], 'model' => $m['name']];
            } catch (\Throwable $e) {
                self::logUsage($m, $purpose, 0, 0, (int) round((microtime(true) - $t) * 1000), false, $e->getMessage());
                if ($sent) {
                    return null; // une partie a déjà été affichée : on ne mélange pas deux modèles
                }
            }
        }
        return null;
    }

    public static function test(int $id): string
    {
        $m = self::get($id);
        $t = microtime(true);
        try {
            $r = self::call($m, 'Tu es un test de connexion.', [['role' => 'user', 'content' => 'Réponds seulement : MARWA AI prête.']], null, 64);
            self::logUsage($m, 'test', $r['in'], $r['out'], (int) round((microtime(true) - $t) * 1000), true, null);
            DB::update('ai_model_settings', ['status' => 'ok', 'last_test_at' => date('Y-m-d H:i:s'), 'last_message' => mb_substr($r['text'], 0, 255)], 'id = ?', [$id]);
            return $r['text'];
        } catch (\Throwable $e) {
            self::logUsage($m, 'test', 0, 0, (int) round((microtime(true) - $t) * 1000), false, $e->getMessage());
            DB::update('ai_model_settings', ['status' => 'error', 'last_test_at' => date('Y-m-d H:i:s'), 'last_message' => mb_substr($e->getMessage(), 0, 255)], 'id = ?', [$id]);
            throw new BusinessException('Le modèle ne répond pas : ' . $e->getMessage());
        }
    }

    /** Requête vers un fournisseur ; lève une exception en cas d'erreur. @return array{text: string, in: int, out: int} */
    private static function call(array $m, string $system, array $messages, ?callable $onDelta, ?int $maxTokens): array
    {
        $key = self::decrypt($m['api_key_enc']);
        $max = $maxTokens ?? (int) $m['max_output_tokens'];
        $base = rtrim((string) $m['base_url'], '/');
        $opts = json_decode((string) ($m['options_json'] ?? ''), true) ?: [];
        $text = '';
        $in = 0;
        $out = 0;
        $error = null;
        switch ($m['provider']) {
            case 'anthropic':
                $url = $base . '/v1/messages';
                $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: ' . self::ANTHROPIC_VERSION];
                $body = ['model' => $m['model'], 'max_tokens' => $max, 'system' => $system, 'messages' => $messages, 'stream' => true];
                // Modèles récents : effort de réflexion réglable (conversation courte : « low » par défaut) et repli serveur en cas de refus
                if (preg_match('/^claude-(opus-5|fable-5|sonnet-5-5|haiku-5-5|mythos-5)/', $m['model'])) {
                    $body['output_config'] = ['effort' => (string) ($opts['effort'] ?? 'low')];
                }
                if (preg_match('/^claude-(opus-5|fable-5-[12]|sonnet-5-5)/', $m['model']) && ($opts['fallbacks'] ?? true)) {
                    $body['fallbacks'] = 'default';
                    $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
                }
                $parse = function (array $ev) use (&$text, &$in, &$out, &$error, $onDelta) {
                    $type = $ev['type'] ?? '';
                    if ($type === 'message_start') {
                        $in += (int) ($ev['message']['usage']['input_tokens'] ?? 0);
                    } elseif ($type === 'content_block_delta' && ($ev['delta']['type'] ?? '') === 'text_delta') {
                        $d = (string) $ev['delta']['text'];
                        $text .= $d;
                        $onDelta && $onDelta($d);
                    } elseif ($type === 'message_delta') {
                        $out = (int) ($ev['usage']['output_tokens'] ?? $out);
                        if (($ev['delta']['stop_reason'] ?? '') === 'refusal') {
                            $error = 'Le modèle a refusé de répondre.';
                        }
                    } elseif ($type === 'error') {
                        $error = (string) ($ev['error']['message'] ?? 'erreur');
                    }
                };
                break;
            case 'gemini':
                $url = $base . '/v1beta/models/' . rawurlencode($m['model']) . ':streamGenerateContent?alt=sse';
                $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $key];
                $body = ['systemInstruction' => ['parts' => [['text' => $system]]], 'generationConfig' => ['maxOutputTokens' => $max],
                    'contents' => array_map(fn($x) => ['role' => $x['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $x['content']]]], $messages)];
                $parse = function (array $ev) use (&$text, &$in, &$out, $onDelta) {
                    foreach ((array) ($ev['candidates'][0]['content']['parts'] ?? []) as $p) {
                        if (isset($p['text'])) {
                            $text .= $p['text'];
                            $onDelta && $onDelta((string) $p['text']);
                        }
                    }
                    if (isset($ev['usageMetadata'])) {
                        $in = (int) ($ev['usageMetadata']['promptTokenCount'] ?? $in);
                        $out = (int) ($ev['usageMetadata']['candidatesTokenCount'] ?? $out);
                    }
                };
                break;
            case 'ollama':
                $url = $base . '/api/chat';
                $headers = ['Content-Type: application/json'];
                $body = ['model' => $m['model'], 'stream' => true, 'options' => ['num_predict' => $max], 'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages)];
                $parse = function (array $ev) use (&$text, &$in, &$out, &$error, $onDelta) {
                    if (isset($ev['error'])) {
                        $error = (string) $ev['error'];
                    }
                    $d = (string) ($ev['message']['content'] ?? '');
                    if ($d !== '') {
                        $text .= $d;
                        $onDelta && $onDelta($d);
                    }
                    if (!empty($ev['done'])) {
                        $in = (int) ($ev['prompt_eval_count'] ?? 0);
                        $out = (int) ($ev['eval_count'] ?? 0);
                    }
                };
                break;
            default: // openai, openai_compatible
                $url = $base . '/chat/completions';
                $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
                $body = ['model' => $m['model'], 'stream' => true, 'stream_options' => ['include_usage' => true], 'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages)];
                $body[$m['provider'] === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $max;
                $parse = function (array $ev) use (&$text, &$in, &$out, &$error, $onDelta) {
                    if (isset($ev['error'])) {
                        $error = (string) ($ev['error']['message'] ?? 'erreur');
                    }
                    $d = (string) ($ev['choices'][0]['delta']['content'] ?? '');
                    if ($d !== '') {
                        $text .= $d;
                        $onDelta && $onDelta($d);
                    }
                    if (isset($ev['usage'])) {
                        $in = (int) ($ev['usage']['prompt_tokens'] ?? 0);
                        $out = (int) ($ev['usage']['completion_tokens'] ?? 0);
                    }
                };
        }
        if ($m['provider'] !== 'ollama' && !$key) {
            throw new \RuntimeException('Clé d\'API manquante.');
        }
        self::stream($url, $headers, $body, (int) $m['timeout_s'], $parse);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        if (trim($text) === '') {
            throw new \RuntimeException('Réponse vide du modèle.');
        }
        return ['text' => $text, 'in' => $in, 'out' => $out];
    }

    /** POST en flux : lignes SSE (« data: … ») ou NDJSON, transmises une à une à $parse. */
    private static function stream(string $url, array $headers, array $body, int $timeout, callable $parse): void
    {
        $buf = '';
        $status = 0;
        $raw = '';
        $feed = function (string $line) use ($parse, &$raw) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'event:') || str_starts_with($line, ':')) {
                return;
            }
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }
            if ($line === '[DONE]') {
                return;
            }
            $j = json_decode($line, true);
            if (is_array($j)) {
                $parse($j);
            } else {
                $raw .= $line;
            }
        };
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, $feed, &$status, &$raw) {
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($status >= 400) {
                    $raw .= $chunk;
                    return strlen($chunk);
                }
                $buf .= $chunk;
                while (($p = strpos($buf, "\n")) !== false) {
                    $feed(substr($buf, 0, $p));
                    $buf = substr($buf, $p + 1);
                }
                return strlen($chunk);
            }]);
        curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($buf !== '' && $status < 400) {
            $feed($buf);
        }
        if ($err !== null) {
            throw new \RuntimeException('Connexion impossible : ' . $err);
        }
        if ($status >= 400) {
            $j = json_decode($raw, true);
            $msg = is_array($j) ? (string) ($j['error']['message'] ?? $j['error'] ?? json_encode($j)) : mb_substr($raw, 0, 160);
            throw new \RuntimeException('HTTP ' . $status . ' : ' . mb_substr($msg, 0, 200));
        }
    }
}
