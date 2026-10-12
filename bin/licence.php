<?php
/**
 * Outil de l'AUTEUR (Mahamadou Hamidou) — ne pas confier aux clients.
 *
 *   Créer une paire de clés (une seule fois) :
 *     php bin/licence.php keygen --out="D:\cles-poswan"
 *     → poswan_licence_PRIVEE.key (à garder secrète, hors du logiciel) + clé publique à mettre
 *       dans config/licence.php ('public_key') avant de distribuer le logiciel.
 *
 *   Délivrer une licence :
 *     php bin/licence.php generer --cle="D:\cles-poswan\poswan_licence_PRIVEE.key"
 *         --client="Pharmacie Espoir" --installation=PW-1A2B-3C4D-5E6F-7A8B [--fin=2027-12-31] [--numero=L2026-001]
 *
 *   Vérifier une clé : php bin/licence.php verifier --installation=PW-... --licence=PWL1....
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\LicenceService;

$cmd = $argv[1] ?? '';
$o = [];
foreach (array_slice($argv, 2) as $arg) { // --nom=valeur (getopt s'arrête après la commande)
    if (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m)) {
        $o[$m[1]] = $m[2];
    }
}
try {
    if ($cmd === 'keygen') {
        $dir = rtrim((string) ($o['out'] ?? ''), '/\\');
        if ($dir === '' || !is_dir($dir)) {
            throw new RuntimeException('Indiquez un dossier existant avec --out (hors du dossier du logiciel).');
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'poswan_licence_PRIVEE.key';
        if (is_file($file)) {
            throw new RuntimeException('Une clé privée existe déjà dans ce dossier : elle n\'est pas écrasée.');
        }
        $kp = LicenceService::sodium('crypto_sign_keypair');
        file_put_contents($file, base64_encode(LicenceService::sodium('crypto_sign_secretkey', $kp)));
        @chmod($file, 0600);
        echo "Clé privée : $file (À GARDER SECRÈTE — quiconque la possède peut créer des licences)\n";
        echo "Clé publique à copier dans config/licence.php ('public_key') :\n" . base64_encode(LicenceService::sodium('crypto_sign_publickey', $kp)) . "\n";
    } elseif ($cmd === 'generer') {
        $sk = is_file((string) ($o['cle'] ?? '')) ? (string) file_get_contents($o['cle']) : '';
        $client = trim((string) ($o['client'] ?? ''));
        $inst = strtoupper(trim((string) ($o['installation'] ?? '')));
        $fin = (string) ($o['fin'] ?? '');
        if ($sk === '' || $client === '' || !preg_match('/^PW(-[A-F0-9]{4}){4}$/', $inst) || ($fin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))) {
            throw new RuntimeException('Paramètres : --cle=fichier --client="Nom" --installation=PW-XXXX-XXXX-XXXX-XXXX [--fin=AAAA-MM-JJ] [--numero=...]');
        }
        $key = LicenceService::sign(['n' => (string) ($o['numero'] ?? ('L' . date('YmdHis'))), 'c' => $client, 'i' => $inst, 'e' => $fin ?: null, 't' => date('Y-m-d')], $sk);
        // Contrôle avec la clé publique correspondante
        $pub = base64_encode(LicenceService::sodium('crypto_sign_publickey_from_secretkey', base64_decode(trim($sk))));
        if ($pub !== config('licence.public_key')) {
            fwrite(STDERR, "Attention : cette clé privée ne correspond pas à la clé publique de config/licence.php.\n");
        }
        echo "Licence pour « $client » ($inst)" . ($fin ? " jusqu'au $fin" : ' sans limite de durée') . " :\n\n$key\n";
    } elseif ($cmd === 'verifier') {
        $p = LicenceService::verify((string) ($o['licence'] ?? ''), strtoupper((string) ($o['installation'] ?? '')));
        echo "Licence valide : " . json_encode($p, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Usage : php bin/licence.php keygen|generer|verifier (voir l'en-tête du fichier)\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
