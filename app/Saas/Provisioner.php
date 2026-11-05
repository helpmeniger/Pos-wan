<?php
declare(strict_types=1);

namespace App\Saas;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Logger;
use App\Services\Catalog;
use App\Services\InstallService;

/**
 * Création d'un espace client.
 *  1. Inscription (site central) : contrôles, fiche client, base de données attribuée :
 *     - mode « create » : CREATE DATABASE + utilisateur MySQL dédié limité à cette base ;
 *     - mode « pool »   : une base vide créée à l'avance dans cPanel (o2switch ne permet pas
 *       de créer une base depuis PHP) ; s'il n'en reste aucune, l'espace attend
 *       (« waiting_db ») et l'exploitant en ajoute une depuis la console.
 *  2. Première visite du sous-domaine : installation de POS Marwa DANS l'espace (schéma,
 *     rôles, boutique, caisse, compte administrateur avec le mot de passe choisi).
 */
final class Provisioner
{
    /** @return array tenant */
    public static function signup(array $d): array
    {
        $slug = strtolower(trim((string) ($d['slug'] ?? '')));
        $d = array_map(fn($v) => is_string($v) ? trim($v) : $v, $d) + ['company' => '', 'contact_name' => '', 'phone' => '', 'email' => '', 'city' => '', 'business_type' => ''];
        $errors = [];
        if (mb_strlen((string) ($d['company'] ?? '')) < 2) {
            $errors[] = 'Indiquez le nom de votre commerce.';
        }
        if (!isset(Catalog::BUSINESS_TYPES[$d['business_type'] ?? ''])) {
            $errors[] = 'Choisissez votre type d\'activité.';
        }
        if (mb_strlen((string) ($d['contact_name'] ?? '')) < 2) {
            $errors[] = 'Indiquez votre nom.';
        }
        if (!preg_match('/^\+?[0-9 ]{8,20}$/', (string) ($d['phone'] ?? ''))) {
            $errors[] = 'Numéro de téléphone invalide (chiffres, 8 à 20).';
        }
        if (($d['email'] ?? '') !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse e-mail invalide.';
        }
        if (!Saas::validSlug($slug) || Saas::reserved($slug)) {
            $errors[] = 'Adresse de l\'espace invalide : 3 à 40 caractères, lettres minuscules, chiffres et tirets (ex. boutique-awa).';
        } elseif (Central::value('SELECT 1 FROM saas_tenants WHERE slug = ?', [$slug])) {
            $errors[] = 'L\'adresse « ' . $slug . ' » est déjà prise : choisissez-en une autre.';
        }
        $username = (string) ($d['admin_username'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $username)) {
            $errors[] = 'Identifiant invalide (3 à 50 caractères : lettres, chiffres, . _ -).';
        }
        $pwd = (string) ($d['admin_password'] ?? '');
        if ($err = Auth::passwordPolicyError($pwd, $username)) {
            $errors[] = $err;
        } elseif ($pwd !== (string) ($d['admin_password_confirm'] ?? '')) {
            $errors[] = 'Les deux mots de passe ne sont pas identiques.';
        }
        $plan = Central::one('SELECT * FROM saas_plans WHERE id = ? AND active = 1 AND public = 1', [(int) ($d['plan_id'] ?? 0)]);
        if (!$plan) {
            $errors[] = 'Choisissez une offre.';
        }
        if (empty($d['accept'])) {
            $errors[] = 'Vous devez accepter les conditions du service.';
        }
        if ($errors) {
            throw new BusinessException(implode(' ', $errors));
        }
        $trial = (int) (Central::setting('trial_days') ?: config('saas.trial_days'));
        $id = Central::insert('saas_tenants', [
            'slug' => $slug, 'company' => mb_substr($d['company'], 0, 150), 'business_type' => $d['business_type'],
            'contact_name' => mb_substr($d['contact_name'], 0, 120), 'phone' => $d['phone'], 'email' => $d['email'] ?: null,
            'city' => mb_substr((string) ($d['city'] ?? ''), 0, 80) ?: null, 'plan_id' => (int) $plan['id'], 'state' => 'waiting_db',
            'trial_ends' => date('Y-m-d', strtotime('+' . ($trial - 1) . ' days')),
            'pending_enc' => Saas::encrypt((string) json_encode([
                'username' => $username, 'name' => $d['contact_name'], 'hash' => Auth::hash($pwd),
            ])),
            'signup_ip' => PHP_SAPI === 'cli' ? 'cli' : \App\Core\Request::ip(),
        ]);
        Central::audit('tenant.signup', $id, ['slug' => $slug, 'plan' => $plan['code']], 0);
        self::allocate($id);
        return Central::one('SELECT * FROM saas_tenants WHERE id = ?', [$id]);
    }

    /** Attribue une base à un espace en attente. @return bool base attribuée */
    public static function allocate(int $tenantId): bool
    {
        $t = Central::one("SELECT * FROM saas_tenants WHERE id = ? AND state = 'waiting_db'", [$tenantId]);
        if (!$t) {
            return false;
        }
        if (config('saas.provision') === 'pool') {
            $ok = Central::transaction(function () use ($t) {
                $db = Central::one('SELECT * FROM saas_db_pool WHERE tenant_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE');
                if (!$db) {
                    return false;
                }
                Central::update('saas_db_pool', ['tenant_id' => $t['id'], 'assigned_at' => date('Y-m-d H:i:s')], 'id = ?', [$db['id']]);
                Central::update('saas_tenants', ['db_name' => $db['db_name'], 'db_user' => $db['db_user'], 'db_pass_enc' => $db['db_pass_enc'], 'state' => 'provisioning'], 'id = ?', [$t['id']]);
                return true;
            });
            if (!$ok) {
                Logger::info('SaaS : aucune base libre dans la réserve pour ' . $t['slug']);
            }
            return $ok;
        }
        // Mode « create » : base et utilisateur MySQL dédiés (le compte central doit avoir les
        // droits CREATE, CREATE USER et GRANT OPTION — serveur VPS ou dédié)
        $name = config('saas.db_prefix') . $t['id'];
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
            throw new \RuntimeException('Préfixe SAAS_DB_PREFIX invalide.');
        }
        $pass = rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'Aa'), '=');
        $host = (string) config('saas.db_user_host');
        $pdo = Central::connection();
        $user = $pdo->quote($name) . '@' . $pdo->quote($host);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE USER IF NOT EXISTS $user IDENTIFIED BY " . $pdo->quote($pass));
        $pdo->exec("ALTER USER $user IDENTIFIED BY " . $pdo->quote($pass));
        $pdo->exec("GRANT ALL PRIVILEGES ON `$name`.* TO $user");
        Central::update('saas_tenants', ['db_name' => $name, 'db_user' => $name, 'db_pass_enc' => Saas::encrypt($pass), 'state' => 'provisioning'], 'id = ?', [$t['id']]);
        return true;
    }

    /** Exécuté DANS l'espace (sous-domaine) : installe POS Marwa dans sa base. */
    public static function finish(): void
    {
        $t = Saas::tenant();
        $lock = 'pm_provision_' . $t['id'];
        if ((int) Central::value('SELECT GET_LOCK(?, 60)', [$lock]) !== 1) {
            throw new \RuntimeException('Création de l\'espace déjà en cours.');
        }
        try {
            $t = Saas::refreshTenant();
            if ($t['state'] !== 'provisioning' || InstallService::isInstalled()) {
                return;
            }
            $p = json_decode(Saas::decrypt((string) $t['pending_enc']), true);
            @set_time_limit(300);
            // Mot de passe provisoire aléatoire, remplacé aussitôt par l'empreinte du mot de passe
            // choisi à l'inscription (celui-ci n'a jamais été conservé en clair)
            InstallService::install([
                'company' => $t['company'], 'business_type' => $t['business_type'], 'admin_username' => $p['username'],
                'admin_name' => $p['name'], 'admin_password' => 'Tmp' . bin2hex(random_bytes(12)) . '9', 'store_name' => $t['company'], 'demo' => false,
            ]);
            DB::update('users', ['password_hash' => $p['hash'], 'password_changed_at' => date('Y-m-d H:i:s')], 'username = ?', [$p['username']]);
            setting_set('company_phone', (string) $t['phone']);
            if ($t['city']) {
                setting_set('company_address', (string) $t['city']);
            }
            Central::update('saas_tenants', ['state' => 'ready', 'pending_enc' => null, 'provision_error' => null, 'provisioned_at' => date('Y-m-d H:i:s')], 'id = ?', [$t['id']]);
            Central::audit('tenant.provisioned', (int) $t['id'], ['db' => $t['db_name']], 0);
            Saas::refreshTenant();
        } catch (\Throwable $e) {
            Logger::error('SaaS : création de l\'espace ' . $t['slug'] . ' impossible : ' . $e->getMessage());
            Central::update('saas_tenants', ['provision_error' => mb_substr($e->getMessage(), 0, 500)], 'id = ?', [$t['id']]);
            throw $e;
        } finally {
            Central::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
