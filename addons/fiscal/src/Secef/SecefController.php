<?php
declare(strict_types=1);

namespace Addons\Fiscal\Secef;

use Addons\Fiscal\DriverRegistry;
use Addons\Fiscal\FiscalEngine;
use Addons\Fiscal\Secrets;
use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/**
 * Paramètres → Certification fiscale → MARWA SECEF CONNECT, et suivi de la certification depuis la caisse.
 */
final class SecefController extends Controller
{
    // ------------------------------------------------------------------ connecteur et profil

    /** Connecteur Fiscal Connect du pont (créé à la première configuration). */
    public static function connector(): ?array
    {
        return DB::one('SELECT * FROM fiscal_connectors WHERE driver = ? ORDER BY id LIMIT 1', [Secef::DRIVER]);
    }

    private static function ensureConnector(bool $active): array
    {
        $c = self::connector();
        if (!$c) {
            $id = DB::insert('fiscal_connectors', ['name' => 'MARWA SECEF CONNECT (e-SECeF Niger)', 'kind' => 'cloud', 'driver' => Secef::DRIVER,
                'transport' => 'http', 'environment' => 'prod', 'active' => 0, 'is_default' => 0, 'timeout_s' => 120, 'max_retries' => 5,
                'config_json' => '{}', 'created_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s')]);
            $c = DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$id]);
        }
        DB::update('fiscal_connectors', ['active' => $active ? 1 : 0, 'timeout_s' => 120, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$c['id']]);
        $country = (int) DB::value("SELECT id FROM fiscal_countries WHERE code = 'NE'");
        $profile = DB::one('SELECT * FROM fiscal_profiles WHERE connector_id = ?', [$c['id']]);
        if (!$profile && $country) {
            DB::insert('fiscal_profiles', ['name' => 'Niger — e-SECeF', 'country_id' => $country, 'store_id' => null, 'environment' => 'prod',
                'seller_nif' => null, 'seller_regime' => null, 'connector_id' => $c['id'], 'print_uncertified' => 'mark', 'active' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }
        DB::run('UPDATE fiscal_profiles SET active = ?, updated_at = NOW() WHERE connector_id = ?', [$active ? 1 : 0, $c['id']]);
        return DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$c['id']]);
    }

    private static function pont(?array $c = null): PontClient
    {
        $c ??= self::connector();
        return new PontClient((string) Secef::config()['url_pont'], $c ? (string) (Secrets::get((int) $c['id'], 'jeton_pont') ?? '') : '');
    }

    private static function acces(array $c): array
    {
        $id = Secrets::get((int) $c['id'], 'identifiant');
        $pw = Secrets::get((int) $c['id'], 'mot_de_passe');
        if ($id === null || $pw === null) {
            throw new BusinessException('Enregistrez d\'abord l\'identifiant et le mot de passe e-SECeF.');
        }
        return ['identifiant' => $id, 'mot_de_passe' => $pw];
    }

    private static function requireConnector(): array
    {
        return self::connector() ?? throw new BusinessException('Enregistrez d\'abord la configuration de MARWA SECEF CONNECT.');
    }

    // ------------------------------------------------------------------ pages

    public function index(): void
    {
        $c = self::connector();
        $etat = null;
        if ($c && Secrets::get((int) $c['id'], 'jeton_pont')) {
            $r = self::pont($c)->call('GET', '/etat', null, 4);
            $etat = $r['ok'] ? $r['data'] : ['erreur' => $r['error'] ?? 'pont injoignable'];
        }
        $others = DB::all('SELECT p.name FROM fiscal_profiles p WHERE p.active = 1 AND (p.connector_id IS NULL OR p.connector_id <> ?)', [$c['id'] ?? 0]);
        view('fiscal::secef/index', ['title' => 'MARWA SECEF CONNECT', 'tab' => 'secef', 'cfg' => Secef::config(), 'connector' => $c,
            'hints' => $c ? Secrets::hints((int) $c['id']) : [], 'etat' => $etat, 'choix' => setting('fiscal_choix_caisse', 'toutes'),
            'otherProfiles' => $others, 'stats' => self::stats(), 'jeton' => \App\Core\Session::get('secef_jeton_affiche')]);
    }

    private static function stats(): array
    {
        $s = ['articles' => (int) DB::value("SELECT COUNT(*) FROM products WHERE status = 'active'")];
        try {
            $s['programmes'] = (int) DB::value("SELECT COUNT(*) FROM secef_articles WHERE statut = 'programme'");
            $s['portail'] = (int) DB::value('SELECT COUNT(*) FROM secef_portail_articles');
            $s['releve'] = DB::value('SELECT MAX(releve_le) FROM secef_portail_articles');
            $s['certifiees'] = (int) DB::value("SELECT COUNT(*) FROM fiscal_certifications fc JOIN fiscal_connectors c ON c.id = fc.connector_id WHERE c.driver = ? AND fc.status = 'certified'", [Secef::DRIVER]);
        } catch (\Throwable) {
        }
        return $s;
    }

    public function save(): void
    {
        $in = $_POST; // formulaire de configuration (jeton CSRF déjà vérifié par le routeur)
        $cfg = $this->attempt(fn() => Secef::saveConfig([
            'actif' => !empty($in['actif']), 'url_portail' => (string) ($in['url_portail'] ?? ''), 'url_pont' => (string) ($in['url_pont'] ?? ''),
            'regime' => (string) ($in['regime'] ?? 'IS'), 'groupe_defaut' => (string) ($in['groupe_defaut'] ?? 'H'), 'taux' => (array) ($in['taux'] ?? []),
            'paiements' => (array) ($in['paiements'] ?? []), 'type_defaut' => (string) ($in['type_defaut'] ?? 'FV'), 'mode_prix' => (string) ($in['mode_prix'] ?? 'TTC'),
            'case_cochee' => !empty($in['case_cochee']), 'creer_articles' => !empty($in['creer_articles']), 'fenetre_visible' => !empty($in['fenetre_visible']),
            'reference_commentaire' => !empty($in['reference_commentaire']), 'client_avec_nif' => (string) ($in['client_avec_nif'] ?? 'morale'),
            'client_sans_nif' => (string) ($in['client_sans_nif'] ?? 'physique'),
        ]), '/fiscal/secef');
        setting_set('fiscal_choix_caisse', ($in['choix_caisse'] ?? 'au_choix') === 'toutes' ? 'toutes' : 'au_choix');
        $this->attempt(fn() => self::ensureConnector($cfg['actif']), '/fiscal/secef');
        Audit::log('secef.config', null, null, ['actif' => $cfg['actif'], 'regime' => $cfg['regime'], 'creer_articles' => $cfg['creer_articles']]);
        flash('success', 'Configuration de MARWA SECEF CONNECT enregistrée.' . ($cfg['actif'] ? ' Module actif.' : ' Module inactif : aucune vente n\'est transmise.'));
        redirect('/fiscal/secef');
    }

    /**
     * Autorisation de certifier réellement : action séparée, avec mot de passe administrateur et confirmation écrite.
     * Tant qu'elle n'est pas donnée, le pont prépare et vérifie la facture, puis l'abandonne sans certifier.
     */
    public function authorize(): void
    {
        $u = $this->user();
        $on = Request::str('autoriser') === '1';
        if ($on) {
            if (!password_verify((string) Request::input('password', ''), $u['password_hash'])) {
                $this->fail('Mot de passe incorrect.', '/fiscal/secef');
            }
            if (Request::str('confirmation') !== 'CERTIFIER') {
                $this->fail('Tapez CERTIFIER pour confirmer.', '/fiscal/secef');
            }
        }
        Secef::saveConfig(['certification_autorisee' => $on]);
        Audit::log($on ? 'secef.certification_autorisee' : 'secef.certification_suspendue', null, null, []);
        flash($on ? 'warning' : 'success', $on ? 'Certification réelle AUTORISÉE : chaque vente cochée « Facture certifiée DGI » sera certifiée sur l\'e-SECeF.' : 'Certification suspendue : les factures sont seulement préparées et vérifiées.');
        redirect('/fiscal/secef');
    }

    public function saveAccess(): void
    {
        $c = $this->attempt(fn() => self::ensureConnector((bool) Secef::config()['actif']), '/fiscal/secef');
        $id = trim(Request::str('identifiant'));
        $pw = (string) Request::input('mot_de_passe', '');
        if ($id !== '') {
            if (!preg_match('/^[A-Za-z0-9._\-]{3,40}$/', $id)) {
                $this->fail('Identifiant invalide (le NIF, en principe).', '/fiscal/secef');
            }
            Secrets::put((int) $c['id'], 'identifiant', $id, Auth::id());
        }
        if ($pw !== '') {
            Secrets::put((int) $c['id'], 'mot_de_passe', $pw, Auth::id());
        }
        Audit::log('secef.acces', null, null, ['identifiant' => $id !== '' ? 'modifié' : 'inchangé', 'mot_de_passe' => $pw !== '' ? 'modifié' : 'inchangé']);
        flash('success', 'Identifiants e-SECeF enregistrés (chiffrés ; jamais réaffichés).');
        redirect('/fiscal/secef');
    }

    /** Nouveau jeton du pont : affiché une seule fois, pour démarrer le pont avec. */
    public function token(): void
    {
        $c = $this->attempt(fn() => self::ensureConnector((bool) Secef::config()['actif']), '/fiscal/secef');
        $tok = bin2hex(random_bytes(24));
        Secrets::put((int) $c['id'], 'jeton_pont', $tok, Auth::id());
        \App\Core\Session::set('secef_jeton_affiche', $tok);
        Audit::log('secef.jeton', null, null, []);
        flash('success', 'Nouveau jeton du pont créé : copiez-le maintenant (il ne sera plus affiché), puis redémarrez le pont avec.');
        redirect('/fiscal/secef');
    }

    public function hideToken(): void
    {
        \App\Core\Session::set('secef_jeton_affiche', null);
        redirect('/fiscal/secef');
    }

    public function test(): void
    {
        $c = $this->attempt(fn() => self::requireConnector(), '/fiscal/secef');
        $driver = DriverRegistry::make($c);
        $r = FiscalEngine::call($driver, $c, null, 'testConnection', fn() => $driver->testConnection());
        DB::update('fiscal_connectors', ['status' => $r->isOk() ? 'ok' : 'error', 'last_test_at' => date('Y-m-d H:i:s'), 'last_test_message' => mb_substr($r->message, 0, 255)], 'id = ?', [$c['id']]);
        flash($r->isOk() ? 'success' : 'danger', $r->isOk() ? 'Connexion à l\'e-SECeF réussie : ' . $r->message : 'Test échoué : ' . $r->message);
        redirect('/fiscal/secef');
    }

    public function logout(): void
    {
        $c = $this->attempt(fn() => self::requireConnector(), '/fiscal/secef');
        $r = self::pont($c)->call('POST', '/session/fermer', [], 60);
        flash($r['ok'] ? 'success' : 'warning', $r['ok'] ? 'Session e-SECeF fermée.' : 'Fermeture de session : ' . ($r['error'] ?? 'pont injoignable'));
        redirect('/fiscal/secef');
    }

    // ------------------------------------------------------------------ articles

    public function articles(): void
    {
        $q = trim(Request::str('q'));
        $filtre = Request::str('filtre');
        $where = "p.status = 'active'";
        $args = [];
        if ($q !== '') {
            $where .= ' AND (p.name LIKE ? OR p.reference LIKE ? OR sa.designation LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        if ($filtre === 'a_programmer') {
            $where .= " AND (sa.id IS NULL OR sa.statut <> 'programme')";
        } elseif ($filtre === 'programme') {
            $where .= " AND sa.statut = 'programme'";
        }
        $rows = DB::all("SELECT p.id, p.reference, p.name, p.sale_price, p.tax_rate, p.category_id, c.name AS category, sa.designation, sa.groupe, sa.statut, sa.derniere_erreur, sa.prix AS prix_portail
            FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN secef_articles sa ON sa.product_id = p.id
            WHERE $where ORDER BY p.name LIMIT 300", $args);
        $cfg = Secef::config();
        foreach ($rows as &$r) {
            $g = Secef::groupFor((int) $r['id'], (float) $r['tax_rate'], $cfg);
            $r['groupe_effectif'] = $g['groupe'];
            $r['source'] = $g['source'];
            $r['alerte'] = $g['groupe'] ? Secef::rateWarning($g['groupe'], (float) $r['tax_rate']) : null;
        }
        unset($r);
        view('fiscal::secef/articles', ['title' => 'MARWA SECEF CONNECT — articles', 'tab' => 'secef', 'rows' => $rows, 'q' => $q, 'filtre' => $filtre, 'cfg' => $cfg,
            'categories' => DB::all('SELECT c.id, c.name, sc.groupe FROM categories c LEFT JOIN secef_categories sc ON sc.category_id = c.id ORDER BY c.name'),
            'portail' => DB::all('SELECT designation, groupe, prix_ttc, prix_ht, mode FROM secef_portail_articles ORDER BY designation LIMIT 1000'), 'stats' => self::stats()]);
    }

    /** Correspondance d'un article : désignation sur l'e-SFE, groupe de taxation, ou « ne jamais certifier ». */
    public function saveArticle(): void
    {
        $pid = Request::int('product_id');
        $p = DB::one('SELECT id, name, sale_price FROM products WHERE id = ?', [$pid]) ?? $this->fail('Article introuvable.', '/fiscal/secef/articles');
        $designation = Secef::clean(Request::str('designation') ?: $p['name']);
        $groupe = Request::str('groupe');
        $groupe = $groupe === '' ? null : $this->attempt(fn() => Secef::groupe($groupe), '/fiscal/secef/articles');
        $statut = in_array(Request::str('statut'), ['a_programmer', 'programme', 'ignore'], true) ? Request::str('statut') : 'a_programmer';
        DB::run('INSERT INTO secef_articles (product_id, designation, groupe, prix, statut, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE designation = VALUES(designation), groupe = VALUES(groupe), statut = VALUES(statut), updated_at = NOW()',
            [$pid, $designation, $groupe, (float) $p['sale_price'], $statut]);
        Audit::log('secef.article', 'product', $pid, ['designation' => $designation, 'groupe' => $groupe, 'statut' => $statut]);
        flash('success', 'Correspondance enregistrée pour « ' . $p['name'] . ' ».');
        redirect('/fiscal/secef/articles?' . http_build_query(['q' => Request::str('q'), 'filtre' => Request::str('filtre')]));
    }

    public function saveCategories(): void
    {
        foreach ((array) Request::input('groupes', []) as $cid => $g) {
            $cid = (int) $cid;
            if ($cid <= 0) {
                continue;
            }
            if ($g === '' || $g === null) {
                DB::run('DELETE FROM secef_categories WHERE category_id = ?', [$cid]);
            } else {
                $g = $this->attempt(fn() => Secef::groupe((string) $g), '/fiscal/secef/articles');
                DB::run('INSERT INTO secef_categories (category_id, groupe) VALUES (?, ?) ON DUPLICATE KEY UPDATE groupe = VALUES(groupe)', [$cid, $g]);
            }
        }
        Audit::log('secef.categories', null, null, []);
        flash('success', 'Groupes de taxation des catégories enregistrés.');
        redirect('/fiscal/secef/articles');
    }

    /**
     * Relevé des articles programmés sur l'e-SFE (lecture seule sur le portail), puis rapprochement : un article
     * POS Marwa dont la désignation est identique (casse et espaces ignorés) est marqué « programmé ».
     */
    public function syncPortal(): void
    {
        $c = $this->attempt(fn() => self::requireConnector(), '/fiscal/secef/articles');
        @set_time_limit(600);
        $r = self::pont($c)->call('POST', '/articles/lister', ['acces' => self::acces($c)] + ['url_portail' => Secef::config()['url_portail'], 'fenetre_visible' => (bool) Secef::config()['fenetre_visible']], 480);
        if (!$r['ok']) {
            $this->fail('Relevé impossible : ' . ($r['error'] ?? $r['data']['message'] ?? 'pont injoignable'), '/fiscal/secef/articles');
        }
        $n = self::storePortal((array) ($r['data']['articles'] ?? []));
        flash('success', $n['releves'] . ' article(s) relevé(s) sur l\'e-SFE ; ' . $n['rapproches'] . ' article(s) POS Marwa rapproché(s) par leur désignation.');
        redirect('/fiscal/secef/articles');
    }

    /** @return array{releves: int, rapproches: int} */
    public static function storePortal(array $articles): array
    {
        $now = date('Y-m-d H:i:s');
        $keep = [];
        DB::transaction(function () use ($articles, $now, &$keep) {
            DB::run('DELETE FROM secef_portail_articles');
            foreach ($articles as $a) {
                $d = Secef::clean((string) ($a['designation'] ?? ''));
                if ($d === '') {
                    continue;
                }
                $g = strtoupper(substr((string) ($a['groupe'] ?? ''), 0, 1));
                DB::insert('secef_portail_articles', ['designation' => $d, 'groupe' => isset(Secef::GROUPES[$g]) ? $g : null,
                    'prix_ttc' => is_numeric($a['prix_ttc'] ?? null) ? (float) $a['prix_ttc'] : null, 'prix_ht' => is_numeric($a['prix_ht'] ?? null) ? (float) $a['prix_ht'] : null,
                    'mode' => mb_substr((string) ($a['mode'] ?? ''), 0, 5) ?: null, 'taxe_specifique' => mb_substr((string) ($a['ts'] ?? ''), 0, 60) ?: null, 'releve_le' => $now]);
                $keep[mb_strtolower($d)] = ['designation' => $d, 'groupe' => isset(Secef::GROUPES[$g]) ? $g : null, 'prix' => $a['prix_ttc'] ?? null];
            }
        });
        $matched = 0;
        foreach (DB::all("SELECT p.id, p.name, sa.designation, sa.statut FROM products p LEFT JOIN secef_articles sa ON sa.product_id = p.id WHERE p.status = 'active'") as $p) {
            $key = mb_strtolower(Secef::clean((string) ($p['designation'] ?: $p['name'])));
            if (!isset($keep[$key]) || $p['statut'] === 'ignore') {
                if ($p['statut'] === 'programme' && !isset($keep[$key])) { // n'existe plus sur le portail
                    DB::run("UPDATE secef_articles SET statut = 'a_programmer', updated_at = NOW() WHERE product_id = ?", [$p['id']]);
                }
                continue;
            }
            $k = $keep[$key];
            DB::run("INSERT INTO secef_articles (product_id, designation, groupe, prix, statut, programme_le, updated_at) VALUES (?, ?, ?, ?, 'programme', NOW(), NOW())
                ON DUPLICATE KEY UPDATE designation = VALUES(designation), groupe = COALESCE(secef_articles.groupe, VALUES(groupe)), prix = VALUES(prix), statut = 'programme', derniere_erreur = NULL, updated_at = NOW()",
                [$p['id'], $k['designation'], $k['groupe'], is_numeric($k['prix']) ? (float) $k['prix'] : null]);
            $matched++;
        }
        return ['releves' => count($keep), 'rapproches' => $matched];
    }

    /** Programme sur l'e-SFE un article POS Marwa absent du portail (écriture sur le portail, confirmée par l'utilisateur). */
    public function programArticle(int $id): void
    {
        $c = $this->attempt(fn() => self::requireConnector(), '/fiscal/secef/articles');
        $p = DB::one('SELECT id, reference, name, sale_price, tax_rate FROM products WHERE id = ?', [$id]) ?? $this->fail('Article introuvable.', '/fiscal/secef/articles');
        $cfg = Secef::config();
        $g = Secef::groupFor($id, (float) $p['tax_rate'], $cfg);
        if (!$g['groupe']) {
            $this->fail('Choisissez d\'abord le groupe de taxation de « ' . $p['name'] . ' ».', '/fiscal/secef/articles');
        }
        $designation = Secef::designationFor($id, $p['name']);
        @set_time_limit(600);
        $r = self::pont($c)->call('POST', '/articles/programmer', ['acces' => self::acces($c), 'url_portail' => $cfg['url_portail'], 'fenetre_visible' => (bool) $cfg['fenetre_visible'],
            'article' => ['designation' => $designation, 'code' => (string) $p['reference'], 'groupe' => $g['groupe'], 'mode' => $cfg['mode_prix'], 'prix' => round((float) $p['sale_price'], 2)]], 480);
        $ok = $r['ok'] && ($r['data']['etat'] ?? '') === 'programme';
        DB::run('INSERT INTO secef_articles (product_id, designation, groupe, prix, statut, derniere_erreur, programme_le, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE designation = VALUES(designation), groupe = VALUES(groupe), prix = VALUES(prix), statut = VALUES(statut), derniere_erreur = VALUES(derniere_erreur), programme_le = COALESCE(VALUES(programme_le), secef_articles.programme_le), updated_at = NOW()',
            [$id, $designation, $g['groupe'], round((float) $p['sale_price'], 2), $ok ? 'programme' : 'erreur', $ok ? null : mb_substr((string) ($r['error'] ?? $r['data']['message'] ?? 'pont injoignable'), 0, 255), $ok ? date('Y-m-d H:i:s') : null]);
        Audit::log('secef.programmer_article', 'product', $id, ['designation' => $designation, 'groupe' => $g['groupe'], 'ok' => $ok]);
        flash($ok ? 'success' : 'danger', $ok ? '« ' . $designation . ' » programmé sur l\'e-SFE (groupe ' . $g['groupe'] . ').' : 'Programmation impossible : ' . ($r['error'] ?? $r['data']['message'] ?? ''));
        redirect('/fiscal/secef/articles');
    }

    // ------------------------------------------------------------------ caisse

    /** Lancement / poursuite de la certification d'une vente (appelé par la caisse ; peut durer jusqu'à 2 minutes). */
    public function certifySale(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            @set_time_limit(300);
            $cert = FiscalEngine::forSale($id);
            if (!$cert) {
                throw new BusinessException('Cette vente n\'est pas soumise à certification.');
            }
            // « Réessayer » du caissier : une facture en échec est relancée ; le moteur redemande d'abord son état
            // au pont (jamais de second envoi d'une facture peut-être certifiée).
            $manual = !empty(Request::json()['relancer']) && $cert['status'] === 'failed';
            $r = in_array($cert['status'], ['pending', 'sending'], true) || $manual ? FiscalEngine::attempt((int) $cert['id'], $manual) : ['status' => $cert['status'], 'message' => '', 'certification' => $cert];
            return self::saleState($id, $r['message']);
        });
    }

    public function saleStatus(int $id): void
    {
        $this->jsonAttempt(fn() => self::saleState($id, ''));
    }

    private static function saleState(int $saleId, string $message): array
    {
        $c = FiscalEngine::forSale($saleId);
        if (!$c) {
            return ['etat' => 'not_required', 'message' => 'Vente non soumise à certification.'];
        }
        return ['etat' => $c['status'], 'message' => $message ?: (string) ($c['last_error'] ?? ''), 'fiscal_number' => $c['status'] === 'certified' ? $c['fiscal_number'] : null,
            'nim' => $c['status'] === 'certified' ? $c['device_id'] : null, 'attempts' => (int) $c['attempts'],
            'ticket_url' => url('/sales/' . $saleId . '/ticket', ['auto' => 1]), 'invoice_url' => url('/sales/' . $saleId . '/invoice')];
    }
}
