<?php
declare(strict_types=1);

namespace Addons\Comptabilite;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;
use App\Services\BackupService;

/**
 * Comptabilité générale en partie double.
 *
 * Règles tenues ici (et non dans les écrans) :
 *  - une écriture (pièce) est équilibrée au centime : total débit = total crédit ;
 *  - elle est saisie au brouillard (modifiable, supprimable), puis validée : elle reçoit alors son
 *    numéro définitif, chronologique et sans trou dans son journal et son exercice, et ne peut plus
 *    être ni modifiée ni supprimée — une erreur se corrige par une extourne (écriture inverse) ;
 *  - aucune écriture dans un exercice clôturé ni à une date verrouillée (période close) ;
 *  - la clôture reporte les soldes des comptes de bilan (classes 1 à 5) en à-nouveaux dans
 *    l'exercice suivant et le résultat (classes 6 à 8) au compte de résultat ; elle est précédée
 *    d'une sauvegarde complète de la base et n'efface rien.
 */
final class Compta
{
    public const JOURNAL_TYPES = ['achats' => 'Achats', 'ventes' => 'Ventes', 'tresorerie' => 'Trésorerie (caisse, banque, mobile money)', 'od' => 'Opérations diverses', 'an' => 'À-nouveaux'];
    public const TIERS_TYPES = ['client' => 'Client', 'fournisseur' => 'Fournisseur', 'salarie' => 'Salarié', 'autre' => 'Autre'];
    public const TIERS_PREFIX = ['client' => 'C', 'fournisseur' => 'F', 'salarie' => 'S', 'autre' => 'T'];
    public const CLASSES = [1 => 'Ressources durables', 2 => 'Actif immobilisé', 3 => 'Stocks', 4 => 'Tiers', 5 => 'Trésorerie', 6 => 'Charges des activités ordinaires', 7 => 'Produits des activités ordinaires', 8 => 'Autres charges et autres produits (HAO)', 9 => 'Engagements hors bilan, analytique'];
    public const LIAISONS = [
        'caisse' => 'Caisse (espèces)', 'coffre' => 'Coffre / fonds en lieu sûr', 'banque' => 'Banque (carte, virement)', 'mobile' => 'Mobile money', 'cheques' => 'Chèques à encaisser',
        'clients' => 'Clients (collectif)', 'fournisseurs' => 'Fournisseurs (collectif)', 'ventes' => 'Ventes de marchandises', 'services' => 'Prestations de services (clinique, pressing, établissement…)',
        'tva_collectee' => 'TVA facturée (collectée)', 'achats' => 'Achats de marchandises', 'frais_achats' => 'Frais sur achats', 'charges_diverses' => 'Dépenses sans compte précisé',
        'produits_divers' => 'Recettes diverses', 'salaires' => 'Salaires', 'frais_bancaires' => 'Frais bancaires', 'interets' => 'Intérêts reçus', 'virements_internes' => 'Virements de fonds (transit)',
    ];

    // ------------------------------------------------------------------ outils

    public static function referentiel(): string
    {
        return setting('compta_referentiel', '') === 'pcg' ? 'pcg' : 'syscohada';
    }

    public static function ready(): bool
    {
        return (bool) DB::value('SELECT COUNT(*) FROM cpt_exercices');
    }

    /** « 12 500,50 », « 12500.5 », « 12 500 » → 12500.5 */
    public static function amount(mixed $v): float
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim((string) $v));
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s) || (float) $s < 0 || (float) $s > 9999999999999) {
            throw new BusinessException('Montant invalide : « ' . mb_substr((string) $v, 0, 30) . ' ».');
        }
        return round((float) $s, 2);
    }

    /** « 4111 — Clients » → « 4111 » */
    public static function leadToken(mixed $v): string
    {
        return preg_match('/^\s*([0-9A-Za-z]+)/u', (string) $v, $m) ? strtoupper($m[1]) : '';
    }

    public static function num(float $v): string
    {
        return abs($v) < 0.005 ? '' : number_format($v, abs($v - round($v)) > 0.004 ? 2 : 0, ',', ' ');
    }

    public static function pieceNo(array $e): string
    {
        return $e['numero'] ? ($e['journal_code'] ?? '') . '-' . str_pad((string) $e['numero'], 5, '0', STR_PAD_LEFT) : 'Brouillard n° ' . $e['id'];
    }

    // ------------------------------------------------------------------ mise en place

    /** Première mise en place : plan proposé, comptes de liaison, journaux, premier exercice. */
    public static function setup(string $plan, array $in): int
    {
        if (!isset(Plans::PLANS[$plan])) {
            throw new BusinessException('Choisissez un plan comptable.');
        }
        if (self::ready()) {
            throw new BusinessException('La comptabilité est déjà en place.');
        }
        return DB::transaction(function () use ($plan, $in) {
            $ref = Plans::referentiel($plan);
            setting_set('compta_referentiel', $ref);
            self::loadPlan($plan);
            foreach (Plans::LIAISONS[$ref] as $k => $n) {
                setting_set('compta_cpt_' . $k, $n);
            }
            $exId = self::saveExercice($in);
            self::defaultJournals();
            $from = (string) ($in['generation_depuis'] ?? '');
            setting_set('compta_generation_depuis', Validator::isDate($from) ? $from : (string) DB::value('SELECT date_debut FROM cpt_exercices WHERE id = ?', [$exId]));
            Audit::log('compta.setup', 'cpt_exercice', $exId, ['plan' => $plan]);
            return $exId;
        });
    }

    /** Ajoute au plan les comptes manquants d'un plan proposé (jamais de modification ni de suppression). */
    public static function loadPlan(string $plan): int
    {
        $ref = Plans::referentiel($plan);
        $added = 0;
        foreach (Plans::accounts($plan) as $n => $label) {
            if (DB::value('SELECT id FROM cpt_comptes WHERE numero = ?', [(string) $n])) {
                continue;
            }
            DB::insert('cpt_comptes', ['numero' => (string) $n, 'libelle' => mb_substr($label, 0, 150), 'classe' => (int) substr((string) $n, 0, 1),
                'tiers_type' => Plans::tiersType((string) $n, $ref), 'lettrable' => Plans::lettrable((string) $n) ? 1 : 0]);
            $added++;
        }
        if ($added) {
            Audit::log('compta.plan_load', 'cpt_compte', null, ['plan' => $plan, 'ajoutes' => $added]);
        }
        return $added;
    }

    /** Journaux usuels créés à la mise en place (modifiables ensuite). */
    public static function defaultJournals(): void
    {
        $id = fn(string $key) => self::compteId(self::liaisonNumero($key));
        $defs = [
            ['AC', 'Journal des achats', 'achats', null], ['VT', 'Journal des ventes', 'ventes', null],
            ['CA', 'Journal de caisse', 'tresorerie', $id('caisse')], ['BQ', 'Journal de banque', 'tresorerie', $id('banque')],
            ['MM', 'Journal mobile money', 'tresorerie', $id('mobile')], ['OD', 'Opérations diverses', 'od', null], ['AN', 'À-nouveaux', 'an', null],
        ];
        foreach ($defs as [$code, $label, $type, $compte]) {
            if (!DB::value('SELECT id FROM cpt_journaux WHERE code = ?', [$code])) {
                DB::insert('cpt_journaux', ['code' => $code, 'libelle' => $label, 'type' => $type, 'compte_id' => $compte]);
            }
        }
    }

    /** Import d'un plan CSV « numéro;libellé » (séparateur ; , ou tabulation, en-tête facultatif). */
    public static function importCsv(string $content, bool $updateLabels): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $ref = self::referentiel();
        $added = $updated = 0;
        $errors = [];
        DB::transaction(function () use ($content, $updateLabels, $ref, &$added, &$updated, &$errors) {
            foreach (preg_split('/\r\n|\r|\n/', $content) as $i => $line) {
                if (trim($line) === '') {
                    continue;
                }
                $sep = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
                $cols = str_getcsv($line, $sep);
                $n = preg_replace('/\s+/', '', (string) ($cols[0] ?? ''));
                $label = trim((string) ($cols[1] ?? ''));
                if (!preg_match('/^[1-9][0-9]{1,19}$/', $n)) {
                    if ($i > 0) {
                        $errors[] = 'Ligne ' . ($i + 1) . ' ignorée : numéro de compte « ' . mb_substr($n, 0, 20) . ' » invalide.';
                    }
                    continue;
                }
                if ($label === '') {
                    $errors[] = 'Ligne ' . ($i + 1) . ' ignorée : libellé vide.';
                    continue;
                }
                $id = DB::value('SELECT id FROM cpt_comptes WHERE numero = ?', [$n]);
                if ($id) {
                    if ($updateLabels) {
                        $updated += DB::update('cpt_comptes', ['libelle' => mb_substr($label, 0, 150)], 'id = ?', [$id]) ? 1 : 0;
                    }
                    continue;
                }
                DB::insert('cpt_comptes', ['numero' => $n, 'libelle' => mb_substr($label, 0, 150), 'classe' => (int) $n[0],
                    'tiers_type' => Plans::tiersType($n, $ref), 'lettrable' => Plans::lettrable($n) ? 1 : 0]);
                $added++;
            }
        });
        Audit::log('compta.plan_import', 'cpt_compte', null, ['ajoutes' => $added, 'modifies' => $updated, 'erreurs' => count($errors)]);
        return ['added' => $added, 'updated' => $updated, 'errors' => $errors];
    }

    // ------------------------------------------------------------------ exercices

    public static function exercices(): array
    {
        return DB::all('SELECT x.*, (SELECT COUNT(*) FROM cpt_ecritures e WHERE e.exercice_id = x.id) AS nb,
            (SELECT COUNT(*) FROM cpt_ecritures e WHERE e.exercice_id = x.id AND e.statut = \'brouillard\') AS nb_brouillard
            FROM cpt_exercices x ORDER BY x.date_debut DESC');
    }

    public static function exercice(int $id): array
    {
        return DB::one('SELECT * FROM cpt_exercices WHERE id = ?', [$id]) ?? throw new BusinessException('Exercice introuvable.');
    }

    /** Exercice proposé par défaut : celui qui contient aujourd'hui, sinon le dernier ouvert, sinon le dernier. */
    public static function current(): ?array
    {
        return DB::one('SELECT * FROM cpt_exercices WHERE ? BETWEEN date_debut AND date_fin', [date('Y-m-d')])
            ?? DB::one('SELECT * FROM cpt_exercices WHERE statut = \'ouvert\' ORDER BY date_debut DESC LIMIT 1')
            ?? DB::one('SELECT * FROM cpt_exercices ORDER BY date_debut DESC LIMIT 1');
    }

    /** Exercice choisi dans la requête (?exercice=ID), sinon l'exercice courant. */
    public static function selected(?int $id): ?array
    {
        return ($id ? DB::one('SELECT * FROM cpt_exercices WHERE id = ?', [$id]) : null) ?? self::current();
    }

    public static function previous(array $ex): ?array
    {
        return DB::one('SELECT * FROM cpt_exercices WHERE date_fin < ? ORDER BY date_fin DESC LIMIT 1', [$ex['date_debut']]);
    }

    public static function saveExercice(array $in, ?int $id = null): int
    {
        $debut = (string) ($in['date_debut'] ?? '');
        $fin = (string) ($in['date_fin'] ?? '');
        if (!Validator::isDate($debut) || !Validator::isDate($fin) || $fin <= $debut) {
            throw new BusinessException('Dates de l\'exercice invalides : la date de fin doit suivre la date de début.');
        }
        if ((strtotime($fin) - strtotime($debut)) / 86400 > 731) {
            throw new BusinessException('Un exercice ne peut pas dépasser 24 mois.');
        }
        $code = trim((string) ($in['code'] ?? '')) ?: substr($fin, 0, 4);
        if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $code)) {
            throw new BusinessException('Code d\'exercice invalide (ex. 2026).');
        }
        $libelle = trim((string) ($in['libelle'] ?? '')) ?: 'Exercice ' . $code;
        if (DB::value('SELECT id FROM cpt_exercices WHERE code = ? AND id <> ?', [$code, (int) $id])) {
            throw new BusinessException('Un exercice porte déjà ce code.');
        }
        if ($o = DB::one('SELECT code FROM cpt_exercices WHERE id <> ? AND date_debut <= ? AND date_fin >= ?', [(int) $id, $fin, $debut])) {
            throw new BusinessException('Ces dates chevauchent l\'exercice ' . $o['code'] . '.');
        }
        $data = ['code' => $code, 'libelle' => mb_substr($libelle, 0, 100), 'date_debut' => $debut, 'date_fin' => $fin];
        if ($id) {
            $ex = self::exercice($id);
            if ($ex['statut'] === 'cloture') {
                throw new BusinessException('Un exercice clôturé ne se modifie plus.');
            }
            if (DB::value('SELECT COUNT(*) FROM cpt_ecritures WHERE exercice_id = ? AND (date_ecriture < ? OR date_ecriture > ?)', [$id, $debut, $fin])) {
                throw new BusinessException('Des écritures de cet exercice tomberaient hors des nouvelles dates.');
            }
            DB::update('cpt_exercices', $data, 'id = ?', [$id]);
            Audit::log('compta.exercice_update', 'cpt_exercice', $id, $data);
            return $id;
        }
        $id = DB::insert('cpt_exercices', $data);
        Audit::log('compta.exercice_create', 'cpt_exercice', $id, $data);
        return $id;
    }

    /** Verrouillage de période (clôture mensuelle) : plus aucune écriture jusqu'à cette date incluse. */
    public static function setVerrou(int $id, ?string $date): void
    {
        $ex = self::exercice($id);
        if ($ex['statut'] === 'cloture') {
            throw new BusinessException('Exercice déjà clôturé.');
        }
        if ($date !== null && $date !== '') {
            if (!Validator::isDate($date) || $date < $ex['date_debut'] || $date > $ex['date_fin']) {
                throw new BusinessException('La date de verrouillage doit être dans l\'exercice.');
            }
            if ($n = (int) DB::value('SELECT COUNT(*) FROM cpt_ecritures WHERE exercice_id = ? AND statut = \'brouillard\' AND date_ecriture <= ?', [$id, $date])) {
                throw new BusinessException("$n écriture(s) au brouillard avant cette date : validez-les ou supprimez-les d'abord.");
            }
        } else {
            $date = null;
        }
        DB::update('cpt_exercices', ['verrou_au' => $date], 'id = ?', [$id]);
        Audit::log('compta.verrou', 'cpt_exercice', $id, ['jusqu_au' => $date]);
    }

    /** Exercice ouvert qui accepte une écriture à cette date. */
    public static function exerciceFor(string $date): array
    {
        if (!Validator::isDate($date)) {
            throw new BusinessException('Date d\'écriture invalide.');
        }
        $ex = DB::one('SELECT * FROM cpt_exercices WHERE ? BETWEEN date_debut AND date_fin', [$date]);
        if (!$ex) {
            throw new BusinessException('Aucun exercice ne couvre le ' . fdate($date, false) . ' : créez-le dans « Exercices et clôture ».');
        }
        self::assertWritable($ex, $date);
        return $ex;
    }

    private static function assertWritable(array $ex, string $date): void
    {
        if ($ex['statut'] === 'cloture') {
            throw new BusinessException('L\'exercice ' . $ex['code'] . ' est clôturé : aucune écriture ne peut y être ajoutée ou modifiée.');
        }
        if ($ex['verrou_au'] && $date <= $ex['verrou_au']) {
            throw new BusinessException('Période verrouillée jusqu\'au ' . fdate($ex['verrou_au'], false) . ' : aucune écriture à cette date.');
        }
    }

    // ------------------------------------------------------------------ plan comptable

    public static function comptes(array $f = []): array
    {
        $w = ['1 = 1'];
        $p = [];
        if (($f['classe'] ?? '') !== '' && ctype_digit((string) $f['classe'])) {
            $w[] = 'c.classe = ?';
            $p[] = (int) $f['classe'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $w[] = '(c.numero LIKE ? OR c.libelle LIKE ?)';
            $p[] = $q . '%';
            $p[] = '%' . $q . '%';
        }
        if (($f['actif'] ?? '') === '1') {
            $w[] = 'c.actif = 1';
        }
        return DB::all('SELECT c.*, (SELECT COUNT(*) FROM cpt_lignes l WHERE l.compte_id = c.id) AS nb FROM cpt_comptes c WHERE ' . implode(' AND ', $w) . ' ORDER BY c.numero', $p);
    }

    public static function compte(int $id): array
    {
        return DB::one('SELECT * FROM cpt_comptes WHERE id = ?', [$id]) ?? throw new BusinessException('Compte introuvable.');
    }

    public static function compteId(string $numero): ?int
    {
        $id = DB::value('SELECT id FROM cpt_comptes WHERE numero = ?', [$numero]);
        return $id ? (int) $id : null;
    }

    /** Comptes où l'on peut imputer : actifs, au moins 3 chiffres, sans sous-compte actif. */
    public static function imputables(): array
    {
        $all = DB::all('SELECT id, numero, libelle, tiers_type, lettrable FROM cpt_comptes WHERE actif = 1 ORDER BY numero');
        $nums = array_column($all, 'numero');
        $out = [];
        foreach ($all as $i => $c) {
            $next = $nums[$i + 1] ?? '';
            if (strlen($c['numero']) >= 3 && !($next !== '' && str_starts_with($next, $c['numero']))) {
                $out[$c['numero']] = $c;
            }
        }
        return $out;
    }

    public static function isImputable(array $c): bool
    {
        return (int) $c['actif'] === 1 && strlen($c['numero']) >= 3
            && !DB::value('SELECT 1 FROM cpt_comptes WHERE actif = 1 AND numero LIKE ? AND numero <> ? LIMIT 1', [$c['numero'] . '%', $c['numero']]);
    }

    public static function saveCompte(array $in, ?int $id = null): int
    {
        $numero = preg_replace('/\s+/', '', (string) ($in['numero'] ?? ''));
        $libelle = trim((string) ($in['libelle'] ?? ''));
        if (!preg_match('/^[1-9][0-9]{1,19}$/', $numero)) {
            throw new BusinessException('Numéro de compte invalide : chiffres uniquement, au moins 2 (ex. 6011, 4111).');
        }
        if ($libelle === '' || mb_strlen($libelle) > 150) {
            throw new BusinessException('Libellé du compte obligatoire (150 caractères au plus).');
        }
        $tt = (string) ($in['tiers_type'] ?? '');
        if ($tt !== '' && !isset(self::TIERS_TYPES[$tt])) {
            throw new BusinessException('Type de tiers inconnu.');
        }
        if (DB::value('SELECT id FROM cpt_comptes WHERE numero = ? AND id <> ?', [$numero, (int) $id])) {
            throw new BusinessException('Le compte ' . $numero . ' existe déjà.');
        }
        $data = ['numero' => $numero, 'libelle' => $libelle, 'classe' => (int) $numero[0], 'tiers_type' => $tt,
            'lettrable' => !empty($in['lettrable']) ? 1 : 0, 'actif' => isset($in['actif']) ? (!empty($in['actif']) ? 1 : 0) : 1];
        if ($id) {
            $old = self::compte($id);
            $used = (int) DB::value('SELECT COUNT(*) FROM cpt_lignes WHERE compte_id = ?', [$id]);
            if ($used && $old['numero'] !== $numero) {
                throw new BusinessException('Ce compte porte déjà des écritures : son numéro ne peut plus changer (le libellé, oui).');
            }
            if ($old['tiers_type'] !== '' && $tt === '' && DB::value('SELECT COUNT(*) FROM cpt_tiers WHERE compte_id = ?', [$id])) {
                throw new BusinessException('Des tiers sont rattachés à ce compte collectif.');
            }
            DB::update('cpt_comptes', $data, 'id = ?', [$id]);
            Audit::log('compta.compte_update', 'cpt_compte', $id, ['numero' => $numero, 'libelle' => $libelle]);
            return $id;
        }
        $id = DB::insert('cpt_comptes', $data);
        Audit::log('compta.compte_create', 'cpt_compte', $id, ['numero' => $numero, 'libelle' => $libelle]);
        return $id;
    }

    /** Suppression d'un compte jamais utilisé (sinon : le désactiver). */
    public static function deleteCompte(int $id): void
    {
        $c = self::compte($id);
        if (DB::value('SELECT COUNT(*) FROM cpt_lignes WHERE compte_id = ?', [$id]) || DB::value('SELECT COUNT(*) FROM cpt_tiers WHERE compte_id = ?', [$id])
            || DB::value('SELECT COUNT(*) FROM cpt_journaux WHERE compte_id = ?', [$id])) {
            throw new BusinessException('Le compte ' . $c['numero'] . ' est utilisé (écritures, tiers ou journal) : désactivez-le plutôt.');
        }
        DB::run('DELETE FROM cpt_comptes WHERE id = ?', [$id]);
        Audit::log('compta.compte_delete', 'cpt_compte', $id, ['numero' => $c['numero'], 'libelle' => $c['libelle']]);
    }

    /** Numéro du compte de liaison (paramètre), vérifié dans le plan. */
    public static function liaisonNumero(string $key): string
    {
        return (string) setting('compta_cpt_' . $key, Plans::LIAISONS[self::referentiel()][$key] ?? '');
    }

    public static function liaison(string $key): array
    {
        $n = self::liaisonNumero($key);
        $c = $n !== '' ? DB::one('SELECT * FROM cpt_comptes WHERE numero = ?', [$n]) : null;
        if (!$c || !(int) $c['actif']) {
            throw new BusinessException('Compte de liaison « ' . (self::LIAISONS[$key] ?? $key) . ' » (' . ($n ?: 'non défini') . ') absent ou désactivé dans le plan : corrigez-le dans Paramètres comptables.');
        }
        return $c;
    }

    // ------------------------------------------------------------------ tiers

    public static function tiersList(array $f = []): array
    {
        $w = ['1 = 1'];
        $p = [];
        if (isset(self::TIERS_TYPES[$f['type'] ?? ''])) {
            $w[] = 't.type = ?';
            $p[] = $f['type'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $w[] = '(t.code LIKE ? OR t.nom LIKE ? OR t.telephone LIKE ?)';
            array_push($p, $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        return DB::all('SELECT t.*, c.numero AS compte_numero, (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM cpt_lignes l WHERE l.tiers_id = t.id) AS solde
            FROM cpt_tiers t JOIN cpt_comptes c ON c.id = t.compte_id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.type, t.nom', $p);
    }

    public static function tiers(int $id): array
    {
        return DB::one('SELECT t.*, c.numero AS compte_numero FROM cpt_tiers t JOIN cpt_comptes c ON c.id = t.compte_id WHERE t.id = ?', [$id]) ?? throw new BusinessException('Tiers introuvable.');
    }

    public static function saveTiers(array $in, ?int $id = null): int
    {
        $type = (string) ($in['type'] ?? 'client');
        if (!isset(self::TIERS_TYPES[$type])) {
            throw new BusinessException('Type de tiers inconnu.');
        }
        $nom = trim((string) ($in['nom'] ?? ''));
        if (mb_strlen($nom) < 2 || mb_strlen($nom) > 150) {
            throw new BusinessException('Nom du tiers obligatoire.');
        }
        $compte = DB::one('SELECT * FROM cpt_comptes WHERE numero = ?', [self::leadToken($in['compte'] ?? '')]);
        if (!$compte || $compte['tiers_type'] === '') {
            throw new BusinessException('Choisissez un compte collectif (compte marqué « tiers » dans le plan, ex. 4111 clients, 4011 fournisseurs).');
        }
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($code === '') {
            $code = self::nextTiersCode($type);
        } elseif (!preg_match('/^[A-Z0-9_-]{1,20}$/', $code)) {
            throw new BusinessException('Code tiers invalide (lettres, chiffres, 20 caractères au plus).');
        }
        if (DB::value('SELECT id FROM cpt_tiers WHERE code = ? AND id <> ?', [$code, (int) $id])) {
            throw new BusinessException('Un tiers porte déjà le code ' . $code . '.');
        }
        $data = ['code' => $code, 'nom' => $nom, 'type' => $type, 'compte_id' => $compte['id'],
            'telephone' => mb_substr(trim((string) ($in['telephone'] ?? '')), 0, 40) ?: null, 'nif' => mb_substr(trim((string) ($in['nif'] ?? '')), 0, 40) ?: null,
            'adresse' => mb_substr(trim((string) ($in['adresse'] ?? '')), 0, 190) ?: null, 'actif' => isset($in['actif']) ? (!empty($in['actif']) ? 1 : 0) : 1];
        if ($id) {
            self::tiers($id);
            DB::update('cpt_tiers', $data, 'id = ?', [$id]);
            Audit::log('compta.tiers_update', 'cpt_tiers', $id, ['code' => $code, 'nom' => $nom]);
            return $id;
        }
        $id = DB::insert('cpt_tiers', $data);
        Audit::log('compta.tiers_create', 'cpt_tiers', $id, ['code' => $code, 'nom' => $nom]);
        return $id;
    }

    public static function deleteTiers(int $id): void
    {
        $t = self::tiers($id);
        if (DB::value('SELECT COUNT(*) FROM cpt_lignes WHERE tiers_id = ?', [$id])) {
            throw new BusinessException('Ce tiers porte des écritures : désactivez-le plutôt.');
        }
        DB::run('DELETE FROM cpt_tiers WHERE id = ?', [$id]);
        Audit::log('compta.tiers_delete', 'cpt_tiers', $id, ['code' => $t['code'], 'nom' => $t['nom']]);
    }

    public static function nextTiersCode(string $type): string
    {
        $prefix = self::TIERS_PREFIX[$type] ?? 'T';
        $n = (int) DB::value('SELECT COALESCE(MAX(CAST(SUBSTRING(code, 2) AS UNSIGNED)), 0) FROM cpt_tiers WHERE code REGEXP ?', ['^' . $prefix . '[0-9]+$']);
        return $prefix . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Tiers comptable d'un client ou d'un fournisseur de POS Marwa (créé au besoin). */
    public static function tiersFor(string $source, int $sourceId): int
    {
        $id = DB::value('SELECT id FROM cpt_tiers WHERE source = ? AND source_id = ?', [$source, $sourceId]);
        if ($id) {
            return (int) $id;
        }
        $row = $source === 'customer' ? DB::one('SELECT name, phone FROM customers WHERE id = ?', [$sourceId]) : DB::one('SELECT name, phone FROM suppliers WHERE id = ?', [$sourceId]);
        $type = $source === 'customer' ? 'client' : 'fournisseur';
        $compte = self::liaison($source === 'customer' ? 'clients' : 'fournisseurs');
        $id = DB::insert('cpt_tiers', ['code' => self::nextTiersCode($type), 'nom' => mb_substr((string) ($row['name'] ?? ($type . ' n° ' . $sourceId)), 0, 150), 'type' => $type,
            'compte_id' => $compte['id'], 'telephone' => $row['phone'] ?? null, 'source' => $source, 'source_id' => $sourceId]);
        return $id;
    }

    // ------------------------------------------------------------------ journaux

    public static function journaux(bool $activeOnly = false): array
    {
        return DB::all('SELECT j.*, c.numero AS compte_numero, (SELECT COUNT(*) FROM cpt_ecritures e WHERE e.journal_id = j.id) AS nb
            FROM cpt_journaux j LEFT JOIN cpt_comptes c ON c.id = j.compte_id' . ($activeOnly ? ' WHERE j.actif = 1' : '') . ' ORDER BY FIELD(j.type, \'achats\', \'ventes\', \'tresorerie\', \'od\', \'an\'), j.code');
    }

    public static function journal(int $id): array
    {
        return DB::one('SELECT j.*, c.numero AS compte_numero FROM cpt_journaux j LEFT JOIN cpt_comptes c ON c.id = j.compte_id WHERE j.id = ?', [$id]) ?? throw new BusinessException('Journal introuvable.');
    }

    public static function journalByType(string $type, ?string $preferCode = null): array
    {
        if ($preferCode && ($j = DB::one('SELECT * FROM cpt_journaux WHERE code = ? AND actif = 1', [$preferCode]))) {
            return $j;
        }
        return DB::one('SELECT * FROM cpt_journaux WHERE type = ? AND actif = 1 ORDER BY id LIMIT 1', [$type])
            ?? throw new BusinessException('Aucun journal actif de type « ' . (self::JOURNAL_TYPES[$type] ?? $type) . ' » : créez-le dans « Journaux ».');
    }

    public static function saveJournal(array $in, ?int $id = null): int
    {
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{1,8}$/', $code)) {
            throw new BusinessException('Code journal : 1 à 8 lettres ou chiffres (ex. VT, AC, BQ1).');
        }
        $libelle = trim((string) ($in['libelle'] ?? ''));
        if ($libelle === '') {
            throw new BusinessException('Libellé du journal obligatoire.');
        }
        $type = (string) ($in['type'] ?? 'od');
        if (!isset(self::JOURNAL_TYPES[$type])) {
            throw new BusinessException('Type de journal inconnu.');
        }
        $compteId = null;
        if (($n = self::leadToken($in['compte'] ?? '')) !== '') {
            $compteId = self::compteId($n) ?? throw new BusinessException('Compte de contrepartie ' . $n . ' introuvable.');
        }
        if (DB::value('SELECT id FROM cpt_journaux WHERE code = ? AND id <> ?', [$code, (int) $id])) {
            throw new BusinessException('Le journal ' . $code . ' existe déjà.');
        }
        $data = ['code' => $code, 'libelle' => mb_substr($libelle, 0, 100), 'type' => $type, 'compte_id' => $compteId, 'actif' => isset($in['actif']) ? (!empty($in['actif']) ? 1 : 0) : 1];
        if ($id) {
            $old = self::journal($id);
            if ($old['code'] !== $code && DB::value('SELECT COUNT(*) FROM cpt_ecritures WHERE journal_id = ?', [$id])) {
                throw new BusinessException('Ce journal contient des écritures : son code ne peut plus changer.');
            }
            DB::update('cpt_journaux', $data, 'id = ?', [$id]);
            Audit::log('compta.journal_update', 'cpt_journal', $id, $data);
            return $id;
        }
        $id = DB::insert('cpt_journaux', $data);
        Audit::log('compta.journal_create', 'cpt_journal', $id, $data);
        return $id;
    }

    // ------------------------------------------------------------------ écritures

    public static function ecriture(int $id): array
    {
        $e = DB::one('SELECT e.*, j.code AS journal_code, j.libelle AS journal_libelle, j.type AS journal_type, x.code AS exercice_code, x.statut AS exercice_statut,
            u.full_name AS auteur, v.full_name AS valideur FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id JOIN cpt_exercices x ON x.id = e.exercice_id
            LEFT JOIN users u ON u.id = e.created_by LEFT JOIN users v ON v.id = e.valide_par WHERE e.id = ?', [$id]);
        if (!$e) {
            throw new BusinessException('Écriture introuvable.');
        }
        $e['lignes'] = DB::all('SELECT l.*, c.numero, c.libelle AS compte_libelle, t.code AS tiers_code, t.nom AS tiers_nom FROM cpt_lignes l
            JOIN cpt_comptes c ON c.id = l.compte_id LEFT JOIN cpt_tiers t ON t.id = l.tiers_id WHERE l.ecriture_id = ? ORDER BY l.ordre, l.id', [$id]);
        return $e;
    }

    /**
     * Contrôle et normalise une écriture.
     * @param array $in [journal_id, date_ecriture, piece, libelle, lignes => [[compte, tiers, libelle, debit, credit, echeance], …]]
     * @return array{0: array, 1: array, 2: array} [en-tête, lignes, exercice]
     */
    public static function prepare(array $in, bool $system = false): array
    {
        $journal = self::journal((int) ($in['journal_id'] ?? 0));
        if (!(int) $journal['actif']) {
            throw new BusinessException('Le journal ' . $journal['code'] . ' est désactivé.');
        }
        $date = (string) ($in['date_ecriture'] ?? '');
        $ex = self::exerciceFor($date);
        $libelle = trim((string) ($in['libelle'] ?? ''));
        $piece = mb_substr(trim((string) ($in['piece'] ?? '')), 0, 40);
        $imputables = self::imputables();
        $lines = [];
        $td = $tc = 0.0;
        foreach (array_values((array) ($in['lignes'] ?? [])) as $i => $l) {
            $n = self::leadToken($l['compte'] ?? '');
            $debit = self::amount($l['debit'] ?? '');
            $credit = self::amount($l['credit'] ?? '');
            if ($n === '' && $debit == 0 && $credit == 0) {
                continue; // ligne vide
            }
            $no = 'Ligne ' . ($i + 1) . ' : ';
            if ($n === '') {
                throw new BusinessException($no . 'compte manquant.');
            }
            $c = $imputables[$n] ?? ($system ? DB::one('SELECT id, numero, libelle, tiers_type, lettrable FROM cpt_comptes WHERE numero = ?', [$n]) : null);
            if (!$c) {
                $exists = DB::one('SELECT actif FROM cpt_comptes WHERE numero = ?', [$n]);
                throw new BusinessException($no . ($exists ? ((int) $exists['actif'] ? 'le compte ' . $n . ' a des sous-comptes : imputez un compte de détail.' : 'le compte ' . $n . ' est désactivé.') : 'le compte ' . $n . ' n\'existe pas dans le plan.'));
            }
            if ($debit > 0 && $credit > 0) {
                throw new BusinessException($no . 'un montant au débit OU au crédit, pas les deux.');
            }
            if ($debit == 0 && $credit == 0) {
                throw new BusinessException($no . 'montant manquant.');
            }
            $tiersId = null;
            $tc0 = self::leadToken($l['tiers'] ?? '');
            if ($c['tiers_type'] !== '' || ($system && $tc0 !== '')) {
                if ($tc0 === '') {
                    throw new BusinessException($no . 'le compte ' . $n . ' est un compte collectif : indiquez le tiers (client, fournisseur…).');
                }
                $t = DB::one('SELECT id, actif FROM cpt_tiers WHERE code = ?', [$tc0]);
                if (!$t) {
                    throw new BusinessException($no . 'tiers « ' . $tc0 . ' » introuvable.');
                }
                $tiersId = (int) $t['id'];
            }
            $ech = trim((string) ($l['echeance'] ?? ''));
            if ($ech !== '' && !Validator::isDate($ech)) {
                throw new BusinessException($no . 'date d\'échéance invalide.');
            }
            $lib = trim((string) ($l['libelle'] ?? '')) ?: $libelle;
            if ($lib === '') {
                throw new BusinessException($no . 'libellé manquant.');
            }
            $lines[] = ['compte_id' => (int) $c['id'], 'tiers_id' => $tiersId, 'libelle' => mb_substr($lib, 0, 190), 'debit' => $debit, 'credit' => $credit, 'echeance' => $ech ?: null];
            $td += $debit;
            $tc += $credit;
        }
        if (count($lines) < 2) {
            throw new BusinessException('Une écriture compte au moins deux lignes (un débit et un crédit).');
        }
        if (abs(round($td - $tc, 2)) >= 0.01) {
            throw new BusinessException('Écriture déséquilibrée : débit ' . self::num($td) . ' ≠ crédit ' . self::num($tc) . ' (écart ' . self::num(abs($td - $tc)) . ').');
        }
        if ($libelle === '') {
            $libelle = $lines[0]['libelle'];
        }
        $head = ['exercice_id' => (int) $ex['id'], 'journal_id' => (int) $journal['id'], 'date_ecriture' => $date, 'piece' => $piece ?: null,
            'libelle' => mb_substr($libelle, 0, 190), 'total' => round($td, 2)];
        return [$head, $lines, $ex];
    }

    /** Création (ou modification d'un brouillard) ; validation immédiate si demandée. */
    public static function save(array $in, ?int $id = null, bool $validate = false, array $origin = []): int
    {
        return DB::transaction(function () use ($in, $id, $validate, $origin) {
            // Extourne et à-nouveaux reprennent des comptes existants, même devenus non imputables
            [$head, $lines] = self::prepare($in, in_array($origin['origine'] ?? '', ['extourne', 'cloture'], true));
            if ($id) {
                $old = DB::one('SELECT * FROM cpt_ecritures WHERE id = ? FOR UPDATE', [$id]) ?? throw new BusinessException('Écriture introuvable.');
                if ($old['statut'] !== 'brouillard') {
                    throw new BusinessException('Une écriture validée ne se modifie plus : passez une extourne puis une nouvelle écriture.');
                }
                self::assertWritable(self::exercice((int) $old['exercice_id']), $old['date_ecriture']);
                DB::update('cpt_ecritures', $head + ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                DB::run('DELETE FROM cpt_lignes WHERE ecriture_id = ?', [$id]);
                Audit::log('compta.ecriture_update', 'cpt_ecriture', $id, ['total' => $head['total']]);
            } else {
                $id = DB::insert('cpt_ecritures', $head + ['statut' => 'brouillard', 'created_by' => Auth::id(),
                    'origine' => $origin['origine'] ?? 'manuel', 'origine_ref' => $origin['ref'] ?? null, 'extourne_de' => $origin['extourne_de'] ?? null]);
                Audit::log('compta.ecriture_create', 'cpt_ecriture', $id, ['journal' => $head['journal_id'], 'date' => $head['date_ecriture'], 'total' => $head['total'], 'origine' => $origin['origine'] ?? 'manuel']);
            }
            foreach ($lines as $k => $l) {
                DB::insert('cpt_lignes', $l + ['ecriture_id' => $id, 'ordre' => $k + 1]);
            }
            if ($validate) {
                self::validate($id);
            }
            return $id;
        });
    }

    /** Validation : numéro définitif dans le journal et l'exercice, écriture figée. */
    public static function validate(int $id): void
    {
        DB::transaction(function () use ($id) {
            $e = DB::one('SELECT * FROM cpt_ecritures WHERE id = ? FOR UPDATE', [$id]) ?? throw new BusinessException('Écriture introuvable.');
            if ($e['statut'] === 'valide') {
                return;
            }
            self::assertWritable(self::exercice((int) $e['exercice_id']), $e['date_ecriture']);
            $sum = DB::one('SELECT COUNT(*) AS n, COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c FROM cpt_lignes WHERE ecriture_id = ?', [$id]);
            if ((int) $sum['n'] < 2 || abs((float) $sum['d'] - (float) $sum['c']) >= 0.01) {
                throw new BusinessException('Écriture n° ' . $id . ' déséquilibrée : validation impossible.');
            }
            DB::one('SELECT id FROM cpt_journaux WHERE id = ? FOR UPDATE', [$e['journal_id']]);
            $numero = (int) DB::value('SELECT COALESCE(MAX(numero), 0) + 1 FROM cpt_ecritures WHERE journal_id = ? AND exercice_id = ?', [$e['journal_id'], $e['exercice_id']]);
            DB::update('cpt_ecritures', ['statut' => 'valide', 'numero' => $numero, 'valide_le' => date('Y-m-d H:i:s'), 'valide_par' => Auth::id()], 'id = ?', [$id]);
            Audit::log('compta.ecriture_validate', 'cpt_ecriture', $id, ['numero' => $numero]);
        });
    }

    /** @return array{0:int,1:array} [nombre validé, erreurs] */
    public static function validateMany(array $ids): array
    {
        $ok = 0;
        $errors = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                self::validate($id);
                $ok++;
            } catch (BusinessException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return [$ok, $errors];
    }

    public static function deleteDraft(int $id): void
    {
        DB::transaction(function () use ($id) {
            $e = DB::one('SELECT * FROM cpt_ecritures WHERE id = ? FOR UPDATE', [$id]) ?? throw new BusinessException('Écriture introuvable.');
            if ($e['statut'] !== 'brouillard') {
                throw new BusinessException('Une écriture validée ne se supprime pas : passez une extourne.');
            }
            self::assertWritable(self::exercice((int) $e['exercice_id']), $e['date_ecriture']);
            DB::run('DELETE FROM cpt_ecritures WHERE id = ?', [$id]);
            Audit::log('compta.ecriture_delete', 'cpt_ecriture', $id, ['libelle' => $e['libelle'], 'total' => $e['total'], 'origine' => $e['origine'], 'ref' => $e['origine_ref']]);
        });
    }

    /** Extourne : écriture inverse validée, datée au choix, liée à l'écriture d'origine. */
    public static function extourne(int $id, string $date, string $motif): int
    {
        $motif = trim($motif);
        if (mb_strlen($motif) < 3) {
            throw new BusinessException('Motif de l\'extourne obligatoire.');
        }
        return DB::transaction(function () use ($id, $date, $motif) {
            $e = self::ecriture($id);
            DB::one('SELECT id FROM cpt_ecritures WHERE id = ? FOR UPDATE', [$id]);
            if ($e['statut'] !== 'valide') {
                throw new BusinessException('Seule une écriture validée s\'extourne (un brouillard se modifie ou se supprime).');
            }
            if ($e['extournee_par'] || DB::value('SELECT id FROM cpt_ecritures WHERE extourne_de = ?', [$id])) {
                throw new BusinessException('Cette écriture a déjà été extournée.');
            }
            if ($e['extourne_de']) {
                throw new BusinessException('Une extourne ne s\'extourne pas : passez une nouvelle écriture.');
            }
            $lines = [];
            foreach ($e['lignes'] as $l) {
                $lines[] = ['compte' => $l['numero'], 'tiers' => (string) $l['tiers_code'], 'libelle' => mb_substr('Extourne : ' . $l['libelle'], 0, 190),
                    'debit' => (string) $l['credit'], 'credit' => (string) $l['debit'], 'echeance' => ''];
            }
            $newId = self::save(['journal_id' => $e['journal_id'], 'date_ecriture' => $date, 'piece' => $e['piece'],
                'libelle' => mb_substr('Extourne de ' . self::pieceNo($e) . ' — ' . $motif, 0, 190), 'lignes' => $lines],
                null, true, ['origine' => 'extourne', 'extourne_de' => $id]);
            DB::update('cpt_ecritures', ['extournee_par' => $newId], 'id = ?', [$id]);
            Audit::log('compta.ecriture_extourne', 'cpt_ecriture', $id, ['extourne' => $newId, 'motif' => $motif]);
            return $newId;
        });
    }

    /** Liste filtrée des écritures (en-têtes). */
    public static function search(array $f, int $limit = 300): array
    {
        [$where, $p] = self::filters($f);
        return DB::all('SELECT e.*, j.code AS journal_code, u.full_name AS auteur FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id
            LEFT JOIN users u ON u.id = e.created_by WHERE ' . $where . ' ORDER BY e.date_ecriture DESC, e.id DESC LIMIT ' . $limit, $p);
    }

    /** Lignes des écritures filtrées (export). */
    public static function searchLines(array $f): array
    {
        [$where, $p] = self::filters($f);
        return DB::all('SELECT e.id, e.numero, e.date_ecriture, e.piece, e.statut, e.libelle AS ecriture_libelle, j.code AS journal_code, c.numero AS compte, c.libelle AS compte_libelle,
            t.code AS tiers_code, l.libelle, l.debit, l.credit, l.lettrage, l.echeance FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id
            JOIN cpt_lignes l ON l.ecriture_id = e.id JOIN cpt_comptes c ON c.id = l.compte_id LEFT JOIN cpt_tiers t ON t.id = l.tiers_id
            WHERE ' . $where . ' ORDER BY e.date_ecriture, j.code, e.numero, e.id, l.ordre', $p);
    }

    private static function filters(array $f): array
    {
        $w = ['1 = 1'];
        $p = [];
        if (!empty($f['exercice_id'])) {
            $w[] = 'e.exercice_id = ?';
            $p[] = (int) $f['exercice_id'];
        }
        if (!empty($f['journal_id'])) {
            $w[] = 'e.journal_id = ?';
            $p[] = (int) $f['journal_id'];
        }
        if (in_array($f['statut'] ?? '', ['brouillard', 'valide'], true)) {
            $w[] = 'e.statut = ?';
            $p[] = $f['statut'];
        }
        if (!empty($f['from']) && Validator::isDate($f['from'])) {
            $w[] = 'e.date_ecriture >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to']) && Validator::isDate($f['to'])) {
            $w[] = 'e.date_ecriture <= ?';
            $p[] = $f['to'];
        }
        if (($f['origine'] ?? '') !== '') {
            $w[] = ($f['origine'] === 'auto' ? 'e.origine NOT IN (\'manuel\', \'extourne\', \'cloture\')' : 'e.origine = ?');
            if ($f['origine'] !== 'auto') {
                $p[] = (string) $f['origine'];
            }
        }
        if (($n = self::leadToken($f['compte'] ?? '')) !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM cpt_lignes l2 JOIN cpt_comptes c2 ON c2.id = l2.compte_id WHERE l2.ecriture_id = e.id AND c2.numero LIKE ?)';
            $p[] = $n . '%';
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $w[] = '(e.libelle LIKE ? OR e.piece LIKE ? OR EXISTS (SELECT 1 FROM cpt_lignes l3 WHERE l3.ecriture_id = e.id AND l3.libelle LIKE ?))';
            array_push($p, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        if (($m = self::amountFilter($f['montant'] ?? '')) !== null) {
            $w[] = 'e.total = ?';
            $p[] = $m;
        }
        return [implode(' AND ', $w), $p];
    }

    private static function amountFilter(mixed $v): ?float
    {
        try {
            $a = self::amount($v);
            return $a > 0 ? $a : null;
        } catch (BusinessException) {
            return null;
        }
    }

    // ------------------------------------------------------------------ lettrage

    /** Lignes d'un compte (et d'un tiers) pour la consultation et le lettrage, avec solde progressif. */
    public static function accountLines(int $compteId, ?int $tiersId, array $f): array
    {
        $w = ['l.compte_id = ?'];
        $p = [$compteId];
        if ($tiersId) {
            $w[] = 'l.tiers_id = ?';
            $p[] = $tiersId;
        }
        if (!empty($f['exercice_id'])) {
            $w[] = 'e.exercice_id = ?';
            $p[] = (int) $f['exercice_id'];
        }
        if (($f['mode'] ?? '') === 'non_lettrees') {
            $w[] = 'l.lettrage IS NULL';
        }
        if (($f['statut'] ?? '') === 'valide') {
            $w[] = 'e.statut = \'valide\'';
        }
        return DB::all('SELECT l.*, e.date_ecriture, e.numero, e.piece, e.statut, e.id AS ecriture_id, j.code AS journal_code, t.code AS tiers_code, t.nom AS tiers_nom
            FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_journaux j ON j.id = e.journal_id LEFT JOIN cpt_tiers t ON t.id = l.tiers_id
            WHERE ' . implode(' AND ', $w) . ' ORDER BY e.date_ecriture, e.id, l.ordre', $p);
    }

    /** Lettre un ensemble de lignes validées d'un même compte (et d'un même tiers) dont débit = crédit. */
    public static function lettrer(array $lineIds): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $lineIds))));
        if (count($ids) < 2) {
            throw new BusinessException('Cochez au moins deux lignes à lettrer.');
        }
        return DB::transaction(function () use ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::all("SELECT l.*, e.statut FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id WHERE l.id IN ($in) FOR UPDATE", $ids);
            if (count($rows) !== count($ids)) {
                throw new BusinessException('Ligne introuvable.');
            }
            $compte = (int) $rows[0]['compte_id'];
            $tiers = $rows[0]['tiers_id'];
            $d = $c = 0.0;
            foreach ($rows as $r) {
                if ((int) $r['compte_id'] !== $compte || $r['tiers_id'] !== $tiers) {
                    throw new BusinessException('Le lettrage rapproche des lignes d\'un même compte et d\'un même tiers.');
                }
                if ($r['statut'] !== 'valide') {
                    throw new BusinessException('Seules les écritures validées se lettrent.');
                }
                if ($r['lettrage']) {
                    throw new BusinessException('Une des lignes est déjà lettrée (' . $r['lettrage'] . ').');
                }
                $d += (float) $r['debit'];
                $c += (float) $r['credit'];
            }
            if (abs(round($d - $c, 2)) >= 0.01) {
                throw new BusinessException('Lettrage impossible : débit ' . self::num($d) . ' ≠ crédit ' . self::num($c) . '.');
            }
            $code = self::nextLettrage($compte);
            DB::run("UPDATE cpt_lignes SET lettrage = ?, lettre_le = NOW() WHERE id IN ($in)", array_merge([$code], $ids));
            Audit::log('compta.lettrage', 'cpt_compte', $compte, ['code' => $code, 'lignes' => $ids]);
            return $code;
        });
    }

    public static function delettrer(int $compteId, string $code): int
    {
        if (!preg_match('/^[A-Z]{1,8}$/', $code)) {
            throw new BusinessException('Code de lettrage invalide.');
        }
        $n = DB::update('cpt_lignes', ['lettrage' => null, 'lettre_le' => null], 'compte_id = ? AND lettrage = ?', [$compteId, $code]);
        if (!$n) {
            throw new BusinessException('Lettrage introuvable.');
        }
        Audit::log('compta.delettrage', 'cpt_compte', $compteId, ['code' => $code, 'lignes' => $n]);
        return $n;
    }

    /** Codes de lettrage : A, B, … Z, AA, AB, … (par compte). */
    private static function nextLettrage(int $compteId): string
    {
        $used = array_flip(array_column(DB::all('SELECT DISTINCT lettrage FROM cpt_lignes WHERE compte_id = ? AND lettrage IS NOT NULL', [$compteId]), 'lettrage'));
        for ($i = 0; ; $i++) {
            $s = '';
            $n = $i;
            do {
                $s = chr(65 + $n % 26) . $s;
                $n = intdiv($n, 26) - 1;
            } while ($n >= 0);
            if (!isset($used[$s])) {
                return $s;
            }
        }
    }

    // ------------------------------------------------------------------ clôture

    /** Points à régler avant la clôture (liste vide : clôture possible). */
    public static function clotureChecks(array $ex): array
    {
        $issues = [];
        if ($ex['statut'] === 'cloture') {
            return ['Exercice déjà clôturé.'];
        }
        if ($n = (int) DB::value('SELECT COUNT(*) FROM cpt_ecritures WHERE exercice_id = ? AND statut = \'brouillard\'', [$ex['id']])) {
            $issues[] = "$n écriture(s) encore au brouillard : validez-les ou supprimez-les.";
        }
        $s = DB::one('SELECT COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id WHERE e.exercice_id = ? AND e.statut = \'valide\'', [$ex['id']]);
        if (abs((float) $s['d'] - (float) $s['c']) >= 0.01) {
            $issues[] = 'Balance déséquilibrée (débit ' . self::num((float) $s['d']) . ' / crédit ' . self::num((float) $s['c']) . ').';
        }
        if ($p = self::previous($ex)) {
            if ($p['statut'] !== 'cloture') {
                $issues[] = 'L\'exercice précédent (' . $p['code'] . ') doit être clôturé d\'abord.';
            }
        }
        $next = DB::one('SELECT * FROM cpt_exercices WHERE date_debut > ? ORDER BY date_debut LIMIT 1', [$ex['date_fin']]);
        if ($next && $next['date_debut'] !== date('Y-m-d', strtotime($ex['date_fin'] . ' +1 day'))) {
            $issues[] = 'L\'exercice suivant (' . $next['code'] . ') ne commence pas le lendemain de la fin de celui-ci.';
        }
        return $issues;
    }

    /** Clôture : à-nouveaux dans l'exercice suivant (créé au besoin), résultat, exercice figé. */
    public static function cloturer(int $id): array
    {
        $ex = self::exercice($id);
        if ($issues = self::clotureChecks($ex)) {
            throw new BusinessException(implode(' ', $issues));
        }
        BackupService::create('avant_cloture_compta_' . $ex['code']);
        return DB::transaction(function () use ($id) {
            $ex = DB::one('SELECT * FROM cpt_exercices WHERE id = ? FOR UPDATE', [$id]);
            if ($ex['statut'] === 'cloture') {
                throw new BusinessException('Exercice déjà clôturé.');
            }
            $start = date('Y-m-d', strtotime($ex['date_fin'] . ' +1 day'));
            $next = DB::one('SELECT * FROM cpt_exercices WHERE date_debut = ?', [$start]);
            if (!$next) {
                $code = ctype_digit($ex['code']) ? (string) ((int) $ex['code'] + 1) : substr(date('Y', strtotime($start . ' +11 months')), 0, 4);
                if (DB::value('SELECT id FROM cpt_exercices WHERE code = ?', [$code])) {
                    $code .= '-' . substr($start, 0, 4);
                }
                $nid = self::saveExercice(['code' => $code, 'date_debut' => $start, 'date_fin' => date('Y-m-d', strtotime($start . ' +1 year -1 day'))]);
                $next = self::exercice($nid);
            }
            if ($next['statut'] === 'cloture') {
                throw new BusinessException('L\'exercice suivant est déjà clôturé.');
            }
            if (DB::value('SELECT id FROM cpt_ecritures WHERE origine = \'cloture\' AND origine_ref = ?', [(string) $id])) {
                throw new BusinessException('Les à-nouveaux de cet exercice existent déjà.');
            }
            // Soldes par compte et par tiers
            $rows = DB::all('SELECT l.compte_id, l.tiers_id, c.numero, c.classe, c.lettrable, t.code AS tiers_code, SUM(l.debit) AS d, SUM(l.credit) AS c
                FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_comptes c ON c.id = l.compte_id LEFT JOIN cpt_tiers t ON t.id = l.tiers_id
                WHERE e.exercice_id = ? AND e.statut = \'valide\' GROUP BY l.compte_id, l.tiers_id, c.numero, c.classe, c.lettrable, t.code ORDER BY c.numero', [$id]);
            $lines = [];
            $resultat = 0.0;
            foreach ($rows as $r) {
                $solde = round((float) $r['d'] - (float) $r['c'], 2);
                if ((int) $r['classe'] >= 6 && (int) $r['classe'] <= 8) {
                    $resultat -= $solde;
                    continue;
                }
                if ((int) $r['classe'] > 5 || abs($solde) < 0.01) {
                    continue;
                }
                if ((int) $r['lettrable']) {
                    // À-nouveaux détaillés : chaque ligne non lettrée est reprise (pour la lettrer l'an prochain)
                    $open = DB::all('SELECT l.libelle, l.debit, l.credit, l.echeance, e.date_ecriture FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id
                        WHERE e.exercice_id = ? AND e.statut = \'valide\' AND l.compte_id = ? AND l.tiers_id <=> ? AND l.lettrage IS NULL ORDER BY e.date_ecriture, l.id', [$id, $r['compte_id'], $r['tiers_id']]);
                    foreach ($open as $o) {
                        $lines[] = ['compte' => $r['numero'], 'tiers' => (string) $r['tiers_code'], 'libelle' => mb_substr('AN ' . fdate($o['date_ecriture'], false) . ' ' . $o['libelle'], 0, 190),
                            'debit' => (string) $o['debit'], 'credit' => (string) $o['credit'], 'echeance' => (string) $o['echeance']];
                    }
                    continue;
                }
                $lines[] = ['compte' => $r['numero'], 'tiers' => (string) $r['tiers_code'], 'libelle' => 'À-nouveau ' . $ex['code'],
                    'debit' => $solde > 0 ? (string) $solde : '', 'credit' => $solde < 0 ? (string) -$solde : '', 'echeance' => ''];
            }
            $resultat = round($resultat, 2);
            if (abs($resultat) >= 0.01) {
                [$benef, $perte] = Plans::RESULTAT[self::referentiel()];
                $num = $resultat > 0 ? $benef : $perte;
                if (!self::compteId($num)) {
                    DB::insert('cpt_comptes', ['numero' => $num, 'libelle' => $resultat > 0 ? 'Résultat net : bénéfice' : 'Résultat net : perte', 'classe' => (int) $num[0]]);
                } elseif (!(int) DB::value('SELECT actif FROM cpt_comptes WHERE numero = ?', [$num])) {
                    DB::run('UPDATE cpt_comptes SET actif = 1 WHERE numero = ?', [$num]);
                }
                $lines[] = ['compte' => $num, 'tiers' => '', 'libelle' => 'Résultat de l\'exercice ' . $ex['code'],
                    'debit' => $resultat < 0 ? (string) -$resultat : '', 'credit' => $resultat > 0 ? (string) $resultat : '', 'echeance' => ''];
            }
            $anId = null;
            if (count($lines) >= 2) {
                $journal = self::journalByType('an', 'AN');
                $anId = self::save(['journal_id' => $journal['id'], 'date_ecriture' => $next['date_debut'], 'piece' => 'CLOT-' . $ex['code'],
                    'libelle' => 'À-nouveaux de l\'exercice ' . $ex['code'], 'lignes' => $lines], null, true, ['origine' => 'cloture', 'ref' => (string) $id]);
            }
            DB::update('cpt_exercices', ['statut' => 'cloture', 'resultat' => $resultat, 'cloture_le' => date('Y-m-d H:i:s'), 'cloture_par' => Auth::id(),
                'verrou_au' => $ex['date_fin']], 'id = ?', [$id]);
            Audit::log('compta.cloture', 'cpt_exercice', $id, ['resultat' => $resultat, 'a_nouveaux' => $anId, 'exercice_suivant' => $next['code']]);
            return ['resultat' => $resultat, 'an' => $anId, 'next' => $next];
        });
    }
}
