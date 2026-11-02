<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\BusinessException;

/**
 * Modèle de langage local FACULTATIF (Ollama installé sur le PC de la boutique ou sur le
 * réseau local). Il reformule les réponses d'aide à partir des extraits de la documentation
 * de POS Marwa. Seules les adresses locales sont acceptées : aucune question ni donnée ne
 * part sur Internet. Sans ce réglage, MARWA IA fonctionne entièrement avec son moteur intégré.
 */
final class Llm
{
    public static function enabled(): bool
    {
        return setting('ia_llm_url', '') !== '' && setting('ia_llm_model', '') !== '';
    }

    /** Adresse locale uniquement : localhost, 127.x, 10.x, 172.16-31.x, 192.168.x, nom sans domaine. */
    public static function validateUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }
        $p = parse_url($url);
        if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            throw new BusinessException('Adresse du modèle invalide (ex. http://127.0.0.1:11434).');
        }
        $h = strtolower($p['host']);
        $local = $h === 'localhost' || !str_contains($h, '.') || preg_match('/\.(local|lan)$/', $h)
            || (filter_var($h, FILTER_VALIDATE_IP) && (str_starts_with($h, '127.') || !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)));
        if (!$local) {
            throw new BusinessException('Pour la confidentialité des données, seul un modèle installé sur ce PC ou le réseau local est accepté.');
        }
        return $url;
    }

    /** Réponse du modèle à partir du contexte documentaire ; null si indisponible (le moteur intégré répond alors). */
    public static function answer(string $question, string $context, int $timeout = 40): ?string
    {
        try {
            $url = self::validateUrl((string) setting('ia_llm_url', ''));
        } catch (BusinessException) {
            return null;
        }
        if ($url === '') {
            return null;
        }
        $system = 'Tu es MARWA IA, l\'assistant du logiciel de caisse et de gestion POS Marwa (Niger, FCFA). Réponds en français, de façon claire, '
            . 'professionnelle et brève (au plus 8 phrases ou une liste d\'étapes numérotées). Appuie-toi UNIQUEMENT sur les extraits de la documentation '
            . 'fournis. Si la réponse n\'y figure pas, dis-le simplement et conseille de contacter High Tech Niger au +227 92 08 12 82. N\'invente aucun menu ni bouton.';
        $body = json_encode(['model' => (string) setting('ia_llm_model', ''), 'stream' => false, 'options' => ['temperature' => 0.2],
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => "Extraits de la documentation :\n" . mb_substr($context, 0, 6000) . "\n\nQuestion : " . $question]]], JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url . '/api/chat', false, $ctx);
        if ($raw === false) {
            return null;
        }
        $d = json_decode($raw, true);
        $txt = trim((string) ($d['message']['content'] ?? ''));
        return $txt !== '' ? mb_substr($txt, 0, 4000) : null;
    }

    /** Essai depuis les réglages. */
    public static function test(): string
    {
        $r = self::answer('Réponds seulement : « MARWA IA prête ».', 'Test de connexion.', 20);
        if ($r === null) {
            throw new BusinessException('Le modèle local ne répond pas : vérifiez qu\'Ollama est lancé, l\'adresse et le nom du modèle (ex. llama3.2, qwen2.5).');
        }
        return $r;
    }
}
