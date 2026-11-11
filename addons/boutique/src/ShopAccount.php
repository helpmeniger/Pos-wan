<?php
declare(strict_types=1);

namespace Addons\Boutique;

use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;
use App\Core\Session;

/**
 * Comptes clients de la boutique en ligne : coordonnées enregistrées, historique des commandes,
 * reçus. Session séparée de celle du personnel (clé « shop_account ») : un compte client ne donne
 * aucun accès à POS Marwa. Identifiant = numéro de téléphone ; mot de passe haché.
 */
final class ShopAccount
{
    private const KEY = 'shop_account';

    public static function phoneKey(string $phone): string
    {
        return substr(ltrim(preg_replace('/\D/', '', $phone) ?? '', '0'), -15); // sans les zéros de tête (0022796… = 22796…)
    }

    public static function current(): ?array
    {
        $id = (int) Session::get(self::KEY, 0);
        if (!$id) {
            return null;
        }
        $a = DB::one('SELECT id, name, phone, email, address, created_at FROM shop_accounts WHERE id = ?', [$id]);
        if (!$a) {
            Session::forget(self::KEY);
        }
        return $a;
    }

    private static function password(string $pwd): string
    {
        if (mb_strlen($pwd) < 8) {
            throw new BusinessException('Le mot de passe doit contenir au moins 8 caractères.');
        }
        if (preg_match('/^(\d)\1+$|^(12345678|password|motdepasse|azertyui|qwertyui)/i', $pwd)) {
            throw new BusinessException('Ce mot de passe est trop simple.');
        }
        return password_hash($pwd, PASSWORD_DEFAULT);
    }

    private static function fields(array $d): array
    {
        $name = trim(mb_substr((string) ($d['name'] ?? ''), 0, 150));
        $phone = trim(mb_substr((string) ($d['phone'] ?? ''), 0, 50));
        $email = trim(mb_substr((string) ($d['email'] ?? ''), 0, 150));
        if (mb_strlen($name) < 2) {
            throw new BusinessException('Indiquez votre nom.');
        }
        if (strlen(self::phoneKey($phone)) < 8) {
            throw new BusinessException('Indiquez un numéro de téléphone valide (8 chiffres au moins).');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('Adresse e-mail invalide.');
        }
        return ['name' => $name, 'phone' => $phone, 'phone_key' => self::phoneKey($phone), 'email' => $email ?: null, 'address' => trim(mb_substr((string) ($d['address'] ?? ''), 0, 255)) ?: null];
    }

    public static function register(array $d): int
    {
        if (trim((string) ($d['website'] ?? '')) !== '') {
            throw new BusinessException('Inscription refusée.'); // champ piège
        }
        $f = self::fields($d);
        $hash = self::password((string) ($d['password'] ?? ''));
        $ip = Request::ip();
        if ((int) DB::value('SELECT COUNT(*) FROM shop_accounts WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip]) >= 5) {
            throw new BusinessException('Trop d\'inscriptions depuis cette connexion. Réessayez dans une heure.');
        }
        if (DB::value('SELECT id FROM shop_accounts WHERE phone_key = ?', [$f['phone_key']])) {
            throw new BusinessException('Un compte existe déjà avec ce numéro : connectez-vous.');
        }
        $id = DB::insert('shop_accounts', $f + ['password_hash' => $hash, 'ip' => $ip, 'last_login_at' => date('Y-m-d H:i:s')]);
        self::open($id);
        return $id;
    }

    private static function open(int $id): void
    {
        Session::regenerate();
        Session::set(self::KEY, $id);
    }

    public static function login(string $phone, string $pwd): void
    {
        // Le client peut saisir son numéro avec ou sans indicatif : numéro exact, sinon numéro qui se termine ainsi (un seul compte)
        $key = self::phoneKey($phone);
        $found = strlen($key) >= 8 ? DB::all('SELECT * FROM shop_accounts WHERE phone_key = ? OR RIGHT(phone_key, ?) = ? LIMIT 2', [$key, strlen($key), $key]) : [];
        $a = count($found) === 1 ? $found[0] : null;
        if ($a && $a['locked_until'] && $a['locked_until'] > date('Y-m-d H:i:s')) {
            throw new BusinessException('Compte bloqué quelques minutes après plusieurs essais. Réessayez plus tard.');
        }
        // Vérification à durée comparable même si le compte n'existe pas
        $ok = password_verify($pwd, $a['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG') && $a;
        if (!$ok) {
            if ($a) {
                $n = (int) $a['failed_attempts'] + 1;
                DB::update('shop_accounts', ['failed_attempts' => $n >= 5 ? 0 : $n, 'locked_until' => $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null], 'id = ?', [$a['id']]);
            }
            usleep(random_int(200000, 500000));
            throw new BusinessException('Numéro de téléphone ou mot de passe incorrect.');
        }
        DB::update('shop_accounts', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$a['id']]);
        self::open((int) $a['id']);
    }

    public static function logout(): void
    {
        Session::forget(self::KEY);
    }

    public static function update(int $id, array $d): void
    {
        $f = self::fields($d);
        if (DB::value('SELECT id FROM shop_accounts WHERE phone_key = ? AND id <> ?', [$f['phone_key'], $id])) {
            throw new BusinessException('Ce numéro est déjà utilisé par un autre compte.');
        }
        $new = (string) ($d['new_password'] ?? '');
        if ($new !== '') {
            $cur = (string) DB::value('SELECT password_hash FROM shop_accounts WHERE id = ?', [$id]);
            if (!password_verify((string) ($d['current_password'] ?? ''), $cur)) {
                throw new BusinessException('Mot de passe actuel incorrect.');
            }
            $f['password_hash'] = self::password($new);
        }
        DB::update('shop_accounts', $f, 'id = ?', [$id]);
    }

    /** Suppression demandée par le client (loi n° 2017-28) : le compte disparaît, les commandes restent sans lien. */
    public static function delete(int $id, string $pwd): void
    {
        if (!password_verify($pwd, (string) DB::value('SELECT password_hash FROM shop_accounts WHERE id = ?', [$id]))) {
            throw new BusinessException('Mot de passe incorrect.');
        }
        DB::transaction(function () use ($id) {
            DB::run('UPDATE shop_orders SET account_id = NULL WHERE account_id = ?', [$id]);
            DB::run('DELETE FROM shop_accounts WHERE id = ?', [$id]);
        });
        self::logout();
    }

    /** Historique du client : commandes, état, reçu, ticket de la vente une fois encaissée. */
    public static function orders(int $id): array
    {
        $rows = DB::all('SELECT o.*, s.number AS sale_number FROM shop_orders o LEFT JOIN sales s ON s.id = o.sale_id WHERE o.account_id = ? ORDER BY o.id DESC LIMIT 200', [$id]);
        foreach ($rows as &$o) {
            $o['items'] = ShopService::items((int) $o['id']);
        }
        return $rows;
    }

    public static function stats(int $id): array
    {
        return DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status IN ('paid','delivered') THEN total ELSE 0 END), 0) AS spent,
            SUM(status IN ('new','confirmed')) AS pending FROM shop_orders WHERE account_id = ?", [$id]);
    }
}
