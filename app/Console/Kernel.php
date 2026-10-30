<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\StaffAccountService;
use App\Services\Install\Migrator;
use App\Support\Phone;
use Throwable;

/** Commandes d'exploitation : php bin/console <commande> [options] */
final class Kernel
{
    private const COMMANDS = [
        'migrate' => 'Crée le schéma si besoin, applique les migrations et les données de référence (--fresh : recrée la base, DÉVELOPPEMENT uniquement)',
        'db:check' => 'Vérifie la connexion à la base et l\'intégrité du grand livre',
        'admin:create' => 'Crée un compte SUPER_ADMIN avec mot de passe temporaire (--phone= --first= --last= [--email=] [--role=])',
        'key:generate' => 'Génère APP_KEY dans le fichier .env',
        'schedule:run' => 'Tâches planifiées (à lancer toutes les 5 minutes)',
        'audit:verify' => 'Vérifie le chaînage du journal d\'audit',
    ];

    private string $root;

    public function __construct(private readonly Container $container)
    {
        $this->root = (string) $container->get(Config::class)->get('app.root');
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = $this->parseOptions(array_slice($argv, 2));
        try {
            return match ($command) {
                'migrate' => $this->migrate(isset($options['fresh'])),
                'db:check' => $this->dbCheck(),
                'admin:create' => $this->adminCreate($options),
                'key:generate' => $this->keyGenerate(isset($options['force'])),
                'schedule:run' => $this->scheduleRun(),
                'audit:verify' => $this->auditVerify(),
                default => $this->help(),
            };
        } catch (Throwable $e) {
            $this->line("\033[31mErreur : " . $e->getMessage() . "\033[0m");
            return 1;
        }
    }

    private function help(): int
    {
        $this->line("JOB NIGER — console\n\nUsage : php bin/console <commande> [options]\n");
        foreach (self::COMMANDS as $name => $desc) {
            $this->line(sprintf('  %-14s %s', $name, $desc));
        }
        return 0;
    }

    private function migrate(bool $fresh): int
    {
        $config = $this->container->get(Config::class);
        $dbConfig = (array) $config->get('database');
        if ($fresh && $config->get('app.env') === 'production') {
            throw new \RuntimeException('--fresh est interdit en production.');
        }
        $migrator = new Migrator($this->root);
        $migrator->createDatabase($dbConfig, $fresh);
        if ($fresh) {
            $this->line("Base « {$dbConfig['database']} » recréée.");
        }
        $count = $migrator->migrate($this->container->get(Database::class), fn (string $m) => $this->line($m));
        $this->line($count > 0 ? "\033[32m{$count} migration(s) appliquée(s).\033[0m" : 'Base à jour.');
        return 0;
    }

    private function dbCheck(): int
    {
        $db = $this->container->get(Database::class);
        $version = $db->fetchValue('SELECT VERSION()');
        $this->line("Connexion OK — serveur {$version}");
        $tables = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
        $this->line("Tables : {$tables}");
        $unbalanced = (int) $db->fetchValue('SELECT COUNT(*) FROM v_ledger_unbalanced_journals');
        $this->line($unbalanced === 0 ? 'Grand livre : équilibré.' : "\033[31mGrand livre : {$unbalanced} journal(aux) déséquilibré(s) !\033[0m");
        $tz = $db->fetchValue('SELECT @@session.time_zone');
        $this->line("Fuseau de session SQL : {$tz}");
        return $unbalanced === 0 ? 0 : 2;
    }

    /** @param array<string, string|bool> $o */
    private function adminCreate(array $o): int
    {
        $phone = (string) ($o['phone'] ?? $this->ask('Téléphone (+227…) : '));
        $first = (string) ($o['first'] ?? $this->ask('Prénom : '));
        $last = (string) ($o['last'] ?? $this->ask('Nom : '));
        $email = isset($o['email']) && is_string($o['email']) ? $o['email'] : null;
        $role = strtoupper((string) ($o['role'] ?? 'SUPER_ADMIN'));

        $result = $this->container->get(StaffAccountService::class)->create($phone, $first, $last, $email, $role);
        $this->line("\033[32mCompte {$role} créé.\033[0m");
        $this->line('Identifiant : ' . Phone::format((string) Phone::normalize($phone)));
        $this->line('Mot de passe TEMPORAIRE : ' . $result['temporary_password']);
        $this->line('Il ne sera plus affiché. Son changement est imposé à la première connexion.');
        return 0;
    }

    private function keyGenerate(bool $force): int
    {
        $env = $this->root . '/.env';
        if (!is_file($env)) {
            if (!copy($this->root . '/.env.example', $env)) {
                throw new \RuntimeException('Impossible de créer .env');
            }
            $this->line('.env créé à partir de .env.example');
        }
        $content = (string) file_get_contents($env);
        if (preg_match('/^APP_KEY=(.+)$/m', $content, $m) && trim($m[1]) !== '' && !$force) {
            $this->line('APP_KEY existe déjà (utilisez --force pour la remplacer).');
            return 0;
        }
        $key = 'base64:' . base64_encode(random_bytes(32));
        $content = preg_match('/^APP_KEY=.*$/m', $content)
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content)
            : $content . "\nAPP_KEY={$key}\n";
        file_put_contents($env, $content);
        @chmod($env, 0600);
        $this->line('APP_KEY générée.');
        return 0;
    }

    private function scheduleRun(): int
    {
        $db = $this->container->get(Database::class);
        $tasks = [
            'Limites de débit expirées' => 'DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY',
            'Liens de réinitialisation expirés' => 'DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY',
            'Codes OTP expirés' => 'DELETE FROM otp_codes WHERE expires_at < NOW() - INTERVAL 7 DAY',
            'Annonces arrivées à échéance' => "UPDATE job_posts SET status = 'EXPIREE', closed_at = NOW()
                 WHERE status IN ('PUBLIEE','CANDIDATURES_OUVERTES') AND application_deadline < NOW() AND positions_filled = 0",
        ];
        foreach ($tasks as $label => $sql) {
            $n = $db->query($sql)->rowCount();
            $this->line(sprintf('%-36s %d', $label, $n));
        }
        $this->line(sprintf('%-36s %d', 'Fiches de paie générées', $this->container->get(\App\Services\Employment\PayrollService::class)->generateDue()));
        // Sessions PHP expirées (fichiers)
        $dir = $this->root . '/storage/sessions';
        $ttl = (int) $this->container->get(Config::class)->get('security.session.idle_minutes', 120) * 60;
        $purged = 0;
        foreach (glob($dir . '/sess_*') ?: [] as $f) {
            if (filemtime($f) < time() - $ttl) {
                @unlink($f) && $purged++;
            }
        }
        $this->line(sprintf('%-36s %d', 'Sessions expirées supprimées', $purged));
        return 0;
    }

    private function auditVerify(): int
    {
        $r = $this->container->get(AuditLogger::class)->verifyChain();
        $this->line($r['ok'] ? "Journal d'audit intègre ({$r['checked']} entrées)." : "\033[31mRupture du chaînage à l'entrée #{$r['broken_at']} !\033[0m");
        return $r['ok'] ? 0 : 2;
    }

    /** @param list<string> $args @return array<string, string|bool> */
    private function parseOptions(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $arg, $m)) {
                $out[$m[1]] = $m[2] ?? true;
            }
        }
        return $out;
    }

    private function ask(string $question): string
    {
        echo $question;
        return trim((string) fgets(STDIN));
    }

    private function line(string $text): void
    {
        echo $text . PHP_EOL;
    }
}
