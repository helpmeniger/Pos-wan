<?php
/**
 * Fausse API de modèles de langage pour les tests (php -S 127.0.0.1:PORT tests/fake-llm-api.php).
 * Formats de streaming : OpenAI (chat/completions), Anthropic (v1/messages), Gemini (streamGenerateContent?alt=sse),
 * Ollama (api/chat, NDJSON). Chaque requête est consignée dans FAKE_LLM_LOG (en-têtes + corps) pour vérification.
 * Le texte renvoyé se règle par le message utilisateur : « [ECHO-CHIFFRE] » renvoie un chiffre inventé, « [PANNE] » une erreur 500,
 * « [CLASSE:xxx] » renvoie le code xxx.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: [];
$headers = function_exists('getallheaders') ? getallheaders() : [];
$log = getenv('FAKE_LLM_LOG') ?: sys_get_temp_dir() . '/fake-llm.log';
file_put_contents($log, json_encode(['path' => $path, 'headers' => array_change_key_case($headers, CASE_LOWER), 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

$last = '';
foreach ((array) ($body['messages'] ?? []) as $m) {
    if (($m['role'] ?? '') === 'user') {
        $last = (string) $m['content'];
    }
}
foreach ((array) ($body['contents'] ?? []) as $c) {
    $last = (string) ($c['parts'][0]['text'] ?? $last);
}
if (str_contains($last, '[PANNE]')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'panne simulée']]);
    exit;
}
if (preg_match('/\[CLASSE:([a-z_]+)\]/', $last, $m)) {
    $parts = [$m[1]];
} elseif (str_contains($last, '[ECHO-CHIFFRE]')) {
    $parts = ['Le total ', 'est de ', '999 999 F.'];
} elseif (preg_match('/Réponse calculée : (.+)$/s', $last, $m)) {
    $parts = ['En résumé : ', trim($m[1])];
} else {
    $parts = ['Voici ', 'la procédure : ', "ouvrez l'écran concerné, ", 'puis suivez les étapes.'];
}

$auth = array_change_key_case($headers, CASE_LOWER);
$deny = function () {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'clé invalide']]);
    exit;
};

if (str_ends_with($path, '/chat/completions')) {
    if (($auth['authorization'] ?? '') !== 'Bearer sk-test-openai') {
        $deny();
    }
    header('Content-Type: text/event-stream');
    foreach ($parts as $p) {
        echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $p]]]]) . "\n\n";
        flush();
    }
    echo 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30]]) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}
if ($path === '/v1/messages') {
    if (($auth['x-api-key'] ?? '') !== 'sk-ant-test' || ($auth['anthropic-version'] ?? '') === '') {
        $deny();
    }
    header('Content-Type: text/event-stream');
    $ev = function (string $t, array $d) {
        echo "event: $t\ndata: " . json_encode(['type' => $t] + $d) . "\n\n";
        flush();
    };
    $ev('message_start', ['message' => ['usage' => ['input_tokens' => 210, 'output_tokens' => 1]]]);
    $ev('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    foreach ($parts as $p) {
        $ev('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $p]]);
    }
    $ev('content_block_stop', ['index' => 0]);
    $ev('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 42]]);
    $ev('message_stop', []);
    exit;
}
if (str_contains($path, ':streamGenerateContent')) {
    if (($auth['x-goog-api-key'] ?? '') !== 'gm-test') {
        $deny();
    }
    header('Content-Type: text/event-stream');
    foreach ($parts as $i => $p) {
        $d = ['candidates' => [['content' => ['parts' => [['text' => $p]], 'role' => 'model']]]];
        if ($i === count($parts) - 1) {
            $d['usageMetadata'] = ['promptTokenCount' => 90, 'candidatesTokenCount' => 25];
        }
        echo 'data: ' . json_encode($d) . "\n\n";
        flush();
    }
    exit;
}
if ($path === '/api/chat') {
    header('Content-Type: application/x-ndjson');
    foreach ($parts as $p) {
        echo json_encode(['message' => ['role' => 'assistant', 'content' => $p], 'done' => false]) . "\n";
        flush();
    }
    echo json_encode(['done' => true, 'prompt_eval_count' => 80, 'eval_count' => 20]) . "\n";
    exit;
}
http_response_code(404);
echo 'inconnu';
