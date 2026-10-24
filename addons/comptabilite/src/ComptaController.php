<?php
declare(strict_types=1);

namespace Addons\Comptabilite;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\Request;
use App\Core\Validator;

final class ComptaController extends Controller
{
    /** Tant que la comptabilité n'est pas mise en place, toutes les pages renvoient vers l'assistant. */
    private function ready(): void
    {
        if (!Compta::ready()) {
            flash('warning', t('Mettez d\'abord la comptabilité en place : plan comptable et premier exercice.'));
            redirect('/compta');
        }
    }

    private function exercice(): array
    {
        $ex = Compta::selected(Request::query('exercice') ? (int) Request::query('exercice') : null);
        if (!$ex) {
            redirect('/compta');
        }
        return $ex;
    }

    /** Période demandée, bornée à l'exercice. */
    private function period(array $ex): array
    {
        $from = (string) Request::query('from', '');
        $to = (string) Request::query('to', '');
        $from = Validator::isDate($from) && $from >= $ex['date_debut'] && $from <= $ex['date_fin'] ? $from : $ex['date_debut'];
        $to = Validator::isDate($to) && $to >= $ex['date_debut'] && $to <= $ex['date_fin'] ? $to : $ex['date_fin'];
        return $from > $to ? [$to, $from] : [$from, $to];
    }

    private function validOnly(): bool
    {
        return Request::query('statut') === 'valide';
    }

    // ------------------------------------------------------------------ tableau de bord et mise en place

    public function index(): void
    {
        if (!Compta::ready()) {
            view('comptabilite::setup', ['title' => 'Comptabilité — mise en place', 'year' => (int) date('Y')]);
        }
        $ex = $this->exercice();
        $gen = can('compta.valider') ? Integration::status() : null;
        view('comptabilite::index', ['title' => 'Tableau de bord comptable', 'ex' => $ex, 'd' => Reports::dashboard($ex), 'exercices' => Compta::exercices(),
            'pendingDays' => $gen ? array_sum(array_map(fn($s) => count($s['pending']), $gen['sources'])) : 0]);
    }

    public function setup(): void
    {
        $plan = Request::str('plan');
        $this->attempt(fn() => Compta::setup($plan, $_POST), '/compta');
        flash('success', t('Comptabilité en place : plan comptable, journaux et exercice créés.'));
        redirect('/compta');
    }

    // ------------------------------------------------------------------ saisie

    public function saisie(): void
    {
        $this->ready();
        $e = null;
        if ($id = (int) Request::query('id')) {
            $e = $this->attempt(fn() => Compta::ecriture($id), '/compta/ecritures');
            if ($e['statut'] !== 'brouillard') {
                flash('warning', t('Une écriture validée ne se modifie plus : extournez-la puis saisissez la correction.'));
                redirect('/compta/ecritures/' . $id);
            }
        } elseif ($copy = (int) Request::query('copie')) {
            $e = $this->attempt(fn() => Compta::ecriture($copy), '/compta/ecritures');
            $e['id'] = null;
            $e['statut'] = 'brouillard';
            $e['date_ecriture'] = date('Y-m-d');
        }
        $journalId = (int) (Request::query('journal') ?: ($e['journal_id'] ?? setting('compta_dernier_journal', '0')));
        view('comptabilite::saisie', ['title' => $e && $e['id'] ? 'Modifier une écriture' : 'Saisie des écritures', 'e' => $e,
            'journaux' => Compta::journaux(true), 'journalId' => $journalId, 'comptes' => Compta::imputables(),
            'tiers' => DB::all('SELECT t.code, t.nom, t.type, c.numero AS compte FROM cpt_tiers t JOIN cpt_comptes c ON c.id = t.compte_id WHERE t.actif = 1 ORDER BY t.nom'),
            'ex' => Compta::current()]);
    }

    private function entryInput(): array
    {
        return ['journal_id' => Request::int('journal_id'), 'date_ecriture' => Request::str('date_ecriture'), 'piece' => Request::str('piece'),
            'libelle' => Request::str('libelle'), 'lignes' => (array) ($_POST['lignes'] ?? [])];
    }

    public function store(): void
    {
        $this->ready();
        $validate = Request::str('action') === 'valider' && can('compta.valider');
        $in = $this->entryInput();
        $id = $this->attempt(fn() => Compta::save($in, null, $validate), '/compta/saisie?journal=' . $in['journal_id']);
        setting_set('compta_dernier_journal', (string) $in['journal_id']);
        flash('success', $validate ? t('Écriture enregistrée et validée.') : t('Écriture enregistrée au brouillard.'));
        redirect('/compta/saisie', ['journal' => $in['journal_id'], 'derniere' => $id]);
    }

    public function update(int $id): void
    {
        $this->ready();
        $validate = Request::str('action') === 'valider' && can('compta.valider');
        $this->attempt(fn() => Compta::save($this->entryInput(), $id, $validate), '/compta/saisie?id=' . $id);
        flash('success', $validate ? t('Écriture modifiée et validée.') : t('Écriture modifiée.'));
        redirect('/compta/ecritures/' . $id);
    }

    // ------------------------------------------------------------------ écritures

    private function entryFilters(array $ex): array
    {
        return ['exercice_id' => $ex['id'], 'journal_id' => (int) Request::query('journal'), 'statut' => (string) Request::query('statut', ''),
            'from' => (string) Request::query('from', ''), 'to' => (string) Request::query('to', ''), 'compte' => (string) Request::query('compte', ''),
            'q' => (string) Request::query('q', ''), 'origine' => (string) Request::query('origine', ''), 'montant' => (string) Request::query('montant', '')];
    }

    public function ecritures(): void
    {
        $this->ready();
        $ex = $this->exercice();
        $f = $this->entryFilters($ex);
        if (Export::requested() && can('reports.export')) {
            $rows = array_map(fn($r) => [$r['date_ecriture'], $r['journal_code'], $r['numero'] ? Compta::pieceNo($r) : 'Brouillard ' . $r['id'], (string) $r['piece'], $r['compte'],
                (string) $r['tiers_code'], $r['libelle'], Compta::num((float) $r['debit']), Compta::num((float) $r['credit']), (string) $r['lettrage'], $r['statut']], Compta::searchLines($f));
            Export::download('ecritures_' . $ex['code'] . '.csv', 'Écritures comptables — exercice ' . $ex['code'],
                ['Date', 'Journal', 'N°', 'Pièce', 'Compte', 'Tiers', 'Libellé', 'Débit', 'Crédit', 'Lettrage', 'Statut'], $rows, ['landscape' => true]);
        }
        view('comptabilite::ecritures', ['title' => 'Écritures et brouillard', 'ex' => $ex, 'f' => $f, 'rows' => Compta::search($f, 500),
            'journaux' => Compta::journaux(), 'exercices' => Compta::exercices()]);
    }

    public function show(int $id): void
    {
        $this->ready();
        $e = $this->attempt(fn() => Compta::ecriture($id), '/compta/ecritures');
        if (Request::query('print')) {
            view('comptabilite::ecriture_print', ['title' => 'Écriture ' . Compta::pieceNo($e), 'e' => $e, 'backUrl' => url('/compta/ecritures/' . $id)], 'layouts/print');
        }
        $ext = $e['extournee_par'] ? DB::one('SELECT e.*, j.code AS journal_code FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id WHERE e.id = ?', [$e['extournee_par']]) : null;
        $orig = $e['extourne_de'] ? DB::one('SELECT e.*, j.code AS journal_code FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id WHERE e.id = ?', [$e['extourne_de']]) : null;
        view('comptabilite::ecriture', ['title' => 'Écriture ' . Compta::pieceNo($e), 'e' => $e, 'ext' => $ext, 'orig' => $orig]);
    }

    public function validate(int $id): void
    {
        $this->attempt(fn() => Compta::validate($id), '/compta/ecritures/' . $id);
        flash('success', t('Écriture validée : elle ne peut plus être modifiée.'));
        redirect('/compta/ecritures/' . $id);
    }

    public function validateMany(): void
    {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (Request::str('tout') === '1') {
            $ex = Compta::exercice(Request::int('exercice_id'));
            $ids = array_map('intval', array_column(DB::all('SELECT id FROM cpt_ecritures WHERE exercice_id = ? AND statut = \'brouillard\' ORDER BY date_ecriture, id', [$ex['id']]), 'id'));
        }
        [$ok, $errors] = Compta::validateMany($ids);
        if ($ok) {
            flash('success', t('{n} écriture(s) validée(s).', ['n' => $ok]));
        }
        if ($errors) {
            flash('danger', implode(' ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' …' : ''));
        }
        if (!$ok && !$errors) {
            flash('warning', t('Aucune écriture sélectionnée.'));
        }
        back('/compta/ecritures');
    }

    public function delete(int $id): void
    {
        $this->attempt(fn() => Compta::deleteDraft($id), '/compta/ecritures/' . $id);
        flash('success', t('Brouillard supprimé.'));
        redirect('/compta/ecritures');
    }

    public function extourne(int $id): void
    {
        $newId = $this->attempt(fn() => Compta::extourne($id, Request::str('date'), Request::str('motif')), '/compta/ecritures/' . $id);
        flash('success', t('Extourne enregistrée et validée.'));
        redirect('/compta/ecritures/' . $newId);
    }

    // ------------------------------------------------------------------ consultation et lettrage

    public function lettrage(): void
    {
        $this->ready();
        $ex = $this->exercice();
        $compte = null;
        $tiers = null;
        $lines = [];
        if (($n = Compta::leadToken(Request::query('compte', ''))) !== '') {
            $compte = DB::one('SELECT * FROM cpt_comptes WHERE numero = ?', [$n]);
            if (!$compte) {
                flash('warning', t('Compte {n} introuvable.', ['n' => $n]));
            }
        }
        if ($compte && ($tc = Compta::leadToken(Request::query('tiers', ''))) !== '') {
            $tiers = DB::one('SELECT * FROM cpt_tiers WHERE code = ?', [$tc]);
        }
        $mode = (string) Request::query('mode', 'toutes');
        if ($compte) {
            $lines = Compta::accountLines((int) $compte['id'], $tiers ? (int) $tiers['id'] : null, ['exercice_id' => Request::query('tous') ? null : $ex['id'], 'mode' => $mode]);
        }
        view('comptabilite::lettrage', ['title' => 'Consultation et lettrage', 'ex' => $ex, 'compte' => $compte, 'tiers' => $tiers, 'lines' => $lines, 'mode' => $mode,
            'comptes' => DB::all('SELECT numero, libelle, lettrable, tiers_type FROM cpt_comptes WHERE actif = 1 AND CHAR_LENGTH(numero) >= 3 ORDER BY numero'),
            'tiersList' => $compte && $compte['tiers_type'] !== '' ? DB::all('SELECT code, nom FROM cpt_tiers WHERE compte_id = ? ORDER BY nom', [$compte['id']]) : [],
            'exercices' => Compta::exercices()]);
    }

    public function lettrer(): void
    {
        $code = $this->attempt(fn() => Compta::lettrer((array) ($_POST['lignes'] ?? [])), '/compta/lettrage?' . Request::str('retour'));
        flash('success', t('Lignes lettrées (code {c}).', ['c' => $code]));
        redirect('/compta/lettrage?' . Request::str('retour'));
    }

    public function delettrer(): void
    {
        $n = $this->attempt(fn() => Compta::delettrer(Request::int('compte_id'), Request::str('code')), '/compta/lettrage?' . Request::str('retour'));
        flash('success', t('Lettrage supprimé ({n} ligne(s)).', ['n' => $n]));
        redirect('/compta/lettrage?' . Request::str('retour'));
    }

    // ------------------------------------------------------------------ états

    public function etats(): void
    {
        $this->ready();
        $ex = $this->exercice();
        view('comptabilite::etats', ['title' => 'États comptables', 'ex' => $ex, 'exercices' => Compta::exercices(), 'journaux' => Compta::journaux()]);
    }

    /** Affichage d'un état : écran, impression (?print=1) ou export CSV / PDF. */
    private function report(string $view, string $title, array $data, ?callable $export = null): never
    {
        if ($export && Export::requested() && can('reports.export')) {
            $export();
        }
        $data += ['title' => $title];
        if (Request::query('print')) {
            view('comptabilite::' . $view, $data + ['print' => true, 'bodyClass' => 'a4', 'backUrl' => url(strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/compta/etats') . query_with(['print' => null])], 'layouts/print');
        }
        view('comptabilite::' . $view, $data + ['print' => false, 'exercices' => Compta::exercices()]);
    }

    public function etatJournal(): void
    {
        $this->ready();
        $ex = $this->exercice();
        [$from, $to] = $this->period($ex);
        $jid = (int) Request::query('journal') ?: null;
        $entries = Reports::journal((int) $ex['id'], $jid, $from, $to, $this->validOnly());
        $this->report('etat_journal', 'Journal', ['ex' => $ex, 'from' => $from, 'to' => $to, 'entries' => $entries, 'journaux' => Compta::journaux(), 'jid' => $jid],
            function () use ($entries, $ex, $from, $to) {
                $rows = [];
                foreach ($entries as $e) {
                    foreach ($e['lignes'] as $l) {
                        $rows[] = [$e['date_ecriture'], $e['journal_code'], Compta::pieceNo($e), (string) $e['piece'], $l['compte'], (string) $l['tiers_code'], $l['libelle'], Compta::num((float) $l['debit']), Compta::num((float) $l['credit'])];
                    }
                }
                Export::download('journal_' . $ex['code'] . '.csv', 'Journal', ['Date', 'Journal', 'N°', 'Pièce', 'Compte', 'Tiers', 'Libellé', 'Débit', 'Crédit'], $rows,
                    ['subtitle' => Export::period($from, $to), 'landscape' => true, 'footer' => ['', '', '', '', '', '', 'TOTAL', Compta::num(array_sum(array_column($entries, 'total'))), Compta::num(array_sum(array_column($entries, 'total')))]]);
            });
    }

    public function etatCentralisateur(): void
    {
        $this->ready();
        $ex = $this->exercice();
        $rows = Reports::centralisateur((int) $ex['id'], $this->validOnly());
        $this->report('etat_centralisateur', 'Journal centralisateur', ['ex' => $ex, 'rows' => $rows], function () use ($rows, $ex) {
            Export::download('centralisateur_' . $ex['code'] . '.csv', 'Journal centralisateur — exercice ' . $ex['code'], ['Journal', 'Libellé', 'Mois', 'Écritures', 'Débit', 'Crédit'],
                array_map(fn($r) => [$r['code'], $r['libelle'], $r['mois'], $r['nb'], Compta::num((float) $r['debit']), Compta::num((float) $r['credit'])], $rows));
        });
    }

    public function etatGrandLivre(): void
    {
        $this->ready();
        $ex = $this->exercice();
        [$from, $to] = $this->period($ex);
        $cFrom = (string) Request::query('du_compte', '');
        $cTo = (string) Request::query('au_compte', '');
        $gl = Reports::grandLivre((int) $ex['id'], $from, $to, $cFrom, $cTo, $this->validOnly());
        $this->report('etat_grand_livre', 'Grand livre', ['ex' => $ex, 'from' => $from, 'to' => $to, 'gl' => $gl, 'cFrom' => $cFrom, 'cTo' => $cTo], function () use ($gl, $ex, $from, $to) {
            $rows = [];
            foreach ($gl['comptes'] as $a) {
                $rows[] = ['_bold' => 1, $a['numero'], $a['libelle'], '', '', '', 'Report', $a['report'] > 0 ? Compta::num($a['report']) : '', $a['report'] < 0 ? Compta::num(-$a['report']) : '', Compta::num($a['report'])];
                foreach ($a['lignes'] as $l) {
                    $rows[] = [$a['numero'], fdate($l['date_ecriture'], false), $l['journal_code'], $l['piece_no'] ? $l['journal_code'] . '-' . $l['piece_no'] : 'Br.', (string) $l['tiers_code'], $l['libelle'],
                        Compta::num((float) $l['debit']), Compta::num((float) $l['credit']), Compta::num((float) $l['solde'])];
                }
                $rows[] = ['_bold' => 1, $a['numero'], 'Total ' . $a['numero'], '', '', '', '', Compta::num($a['debit']), Compta::num($a['credit']), Compta::num($a['solde'])];
            }
            Export::download('grand_livre_' . $ex['code'] . '.csv', 'Grand livre', ['Compte', 'Date', 'Jnl', 'N°', 'Tiers', 'Libellé', 'Débit', 'Crédit', 'Solde'], $rows,
                ['subtitle' => Export::period($from, $to), 'landscape' => true]);
        });
    }

    public function etatBalance(): void
    {
        $this->ready();
        $ex = $this->exercice();
        [$from, $to] = $this->period($ex);
        $cFrom = (string) Request::query('du_compte', '');
        $cTo = (string) Request::query('au_compte', '');
        $niveau = in_array(Request::query('niveau'), ['compte', 'groupe', 'classe'], true) ? (string) Request::query('niveau') : 'compte';
        $b = Reports::balance((int) $ex['id'], $from, $to, $cFrom, $cTo, $this->validOnly(), $niveau);
        $this->report('etat_balance', 'Balance générale', ['ex' => $ex, 'from' => $from, 'to' => $to, 'b' => $b, 'cFrom' => $cFrom, 'cTo' => $cTo, 'niveau' => $niveau],
            function () use ($b, $ex, $from, $to) {
                $n = fn($v) => Compta::num((float) $v);
                $rows = array_map(fn($l) => [$l['numero'], $l['libelle'], $n($l['od']), $n($l['oc']), $n($l['md']), $n($l['mc']), $n($l['fd']), $n($l['fc'])], $b['lignes']);
                $t = $b['total'];
                Export::download('balance_' . $ex['code'] . '.csv', 'Balance générale', ['Compte', 'Libellé', 'Ouverture débit', 'Ouverture crédit', 'Mouvements débit', 'Mouvements crédit', 'Solde débiteur', 'Solde créditeur'],
                    $rows, ['subtitle' => Export::period($from, $to), 'landscape' => true, 'footer' => ['', 'TOTAL', $n($t['od']), $n($t['oc']), $n($t['md']), $n($t['mc']), $n($t['fd']), $n($t['fc'])]]);
            });
    }

    public function etatBalanceTiers(): void
    {
        $this->ready();
        $ex = $this->exercice();
        [, $to] = $this->period($ex);
        $type = (string) Request::query('type', '');
        $rows = Reports::balanceTiers((int) $ex['id'], $type, $to, $this->validOnly());
        $this->report('etat_balance_tiers', 'Balance des tiers', ['ex' => $ex, 'to' => $to, 'rows' => $rows, 'type' => $type], function () use ($rows, $ex, $to) {
            Export::download('balance_tiers_' . $ex['code'] . '.csv', 'Balance des tiers au ' . fdate($to, false), ['Code', 'Tiers', 'Type', 'Compte', 'Débit', 'Crédit', 'Solde', 'Non lettré', 'Plus ancienne échéance'],
                array_map(fn($r) => [$r['code'], $r['nom'], Compta::TIERS_TYPES[$r['type']] ?? $r['type'], $r['compte'], Compta::num((float) $r['debit']), Compta::num((float) $r['credit']),
                    Compta::num((float) $r['debit'] - (float) $r['credit']), Compta::num((float) $r['non_lettre']), $r['plus_ancien'] ? fdate($r['plus_ancien'], false) : ''], $rows), ['landscape' => true]);
        });
    }

    public function etatBilan(): void
    {
        $this->ready();
        $ex = $this->exercice();
        [, $to] = $this->period($ex);
        $prev = Compta::previous($ex);
        $syscohada = Compta::referentiel() === 'syscohada';
        if ($syscohada) {
            $data = ['b' => Reports::bilan((int) $ex['id'], $to, $this->validOnly()), 'bp' => $prev ? Reports::bilan((int) $prev['id'], $prev['date_fin'], true) : null];
        } else {
            $data = ['s' => Reports::syntheseSimple((int) $ex['id'], $to, $this->validOnly())];
        }
        $this->report($syscohada ? 'etat_bilan' : 'etat_synthese', 'Bilan', $data + ['ex' => $ex, 'to' => $to, 'prev' => $prev], $syscohada ? function () use ($data, $ex, $to) {
            $b = $data['b'];
            $rows = [];
            foreach (Reports::BILAN_ACTIF as $r) {
                $rows[] = (isset($r[2]) ? ['_bold' => 1] : []) + ['ACTIF', $r[0], $r[1], Compta::num($b['brut'][$r[0]]), Compta::num($b['amort'][$r[0]]), Compta::num($b['net'][$r[0]])];
            }
            foreach (Reports::BILAN_PASSIF as $r) {
                $rows[] = (isset($r[2]) ? ['_bold' => 1] : []) + ['PASSIF', $r[0], $r[1], '', '', Compta::num($b['passif'][$r[0]])];
            }
            Export::download('bilan_' . $ex['code'] . '.csv', 'Bilan SYSCOHADA au ' . fdate($to, false), ['', 'Réf', 'Poste', 'Brut', 'Amort. / dépréc.', 'Net'], $rows);
        } : null);
    }

    public function etatResultat(): void
    {
        $this->ready();
        $ex = $this->exercice();
        [, $to] = $this->period($ex);
        $prev = Compta::previous($ex);
        if (Compta::referentiel() !== 'syscohada') {
            redirect('/compta/etats/bilan', ['exercice' => $ex['id']]);
        }
        $r = Reports::resultat((int) $ex['id'], $to, $this->validOnly());
        $rp = $prev ? Reports::resultat((int) $prev['id'], $prev['date_fin'], true) : null;
        $this->report('etat_resultat', 'Compte de résultat', ['ex' => $ex, 'to' => $to, 'r' => $r, 'rp' => $rp, 'prev' => $prev], function () use ($r, $rp, $ex, $to) {
            $rows = array_map(fn($l) => ($l[2] === null ? ['_bold' => 1] : []) + [$l[0], $l[1], Compta::num($r['valeurs'][$l[0]]), $rp ? Compta::num($rp['valeurs'][$l[0]]) : ''], Reports::RESULTAT);
            Export::download('compte_resultat_' . $ex['code'] . '.csv', 'Compte de résultat SYSCOHADA au ' . fdate($to, false), ['Réf', 'Libellé', 'Exercice N', 'Exercice N-1'], $rows);
        });
    }

    // ------------------------------------------------------------------ génération depuis la caisse

    public function generation(): void
    {
        $this->ready();
        view('comptabilite::generation', ['title' => 'Écritures de la caisse', 'st' => Integration::status(),
            'last' => DB::all('SELECT e.*, j.code AS journal_code FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id WHERE e.origine LIKE \'pos\\_%\' ORDER BY e.id DESC LIMIT 15')]);
    }

    public function generate(): void
    {
        $this->ready();
        $sources = array_values(array_intersect(array_keys(Integration::SOURCES), (array) ($_POST['sources'] ?? [])));
        if (!$sources) {
            $this->fail(t('Cochez au moins une source.'), '/compta/generation');
        }
        $r = Integration::run($sources, Request::str('valider') === '1');
        flash($r['created'] ? 'success' : 'info', t('{n} écriture(s) générée(s).', ['n' => $r['created']]) . ($r['created'] && Request::str('valider') !== '1' ? ' ' . t('Elles sont au brouillard : contrôlez-les puis validez-les.') : ''));
        if ($r['errors']) {
            flash('danger', implode(' ', array_slice($r['errors'], 0, 4)) . (count($r['errors']) > 4 ? ' …' : ''));
        }
        if ($r['warnings']) {
            flash('warning', implode(' ', array_slice(array_unique($r['warnings']), 0, 4)));
        }
        redirect('/compta/generation');
    }

    // ------------------------------------------------------------------ plan comptable

    public function comptes(): void
    {
        $this->ready();
        $f = ['classe' => (string) Request::query('classe', ''), 'q' => (string) Request::query('q', '')];
        $rows = Compta::comptes($f);
        if (Export::requested() && can('reports.export')) {
            Export::download('plan_comptable.csv', 'Plan comptable', ['Numéro', 'Libellé', 'Classe', 'Tiers', 'Lettrable', 'Actif', 'Lignes'],
                array_map(fn($c) => [$c['numero'], $c['libelle'], $c['classe'], $c['tiers_type'], $c['lettrable'] ? 'oui' : '', $c['actif'] ? 'oui' : 'non', $c['nb']], $rows));
        }
        $edit = Request::query('edit') ? DB::one('SELECT * FROM cpt_comptes WHERE id = ?', [(int) Request::query('edit')]) : null;
        view('comptabilite::comptes', ['title' => 'Plan comptable', 'rows' => $rows, 'f' => $f, 'edit' => $edit, 'imputables' => Compta::imputables()]);
    }

    public function compteStore(): void
    {
        $id = $this->attempt(fn() => Compta::saveCompte($_POST), '/compta/comptes?nouveau=1');
        flash('success', t('Compte créé.'));
        redirect('/compta/comptes', ['q' => (string) DB::value('SELECT numero FROM cpt_comptes WHERE id = ?', [$id])]);
    }

    public function compteUpdate(int $id): void
    {
        $this->attempt(fn() => Compta::saveCompte($_POST, $id), '/compta/comptes?edit=' . $id);
        flash('success', t('Compte mis à jour.'));
        redirect('/compta/comptes', ['q' => Request::str('numero')]);
    }

    public function compteDelete(int $id): void
    {
        $this->attempt(fn() => Compta::deleteCompte($id), '/compta/comptes?edit=' . $id);
        flash('success', t('Compte supprimé.'));
        redirect('/compta/comptes');
    }

    public function planLoad(): void
    {
        $n = $this->attempt(fn() => isset(Plans::PLANS[Request::str('plan')]) ? Compta::loadPlan(Request::str('plan')) : throw new BusinessException('Plan inconnu.'), '/compta/comptes');
        flash('success', t('{n} compte(s) ajouté(s) au plan (les comptes existants sont conservés tels quels).', ['n' => $n]));
        redirect('/compta/comptes');
    }

    public function planImport(): void
    {
        $f = $_FILES['fichier'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 2 * 1024 * 1024) {
            $this->fail(t('Choisissez un fichier CSV de 2 Mo au plus.'), '/compta/comptes');
        }
        $r = $this->attempt(fn() => Compta::importCsv((string) file_get_contents($f['tmp_name']), Request::str('maj') === '1'), '/compta/comptes');
        flash('success', t('Import : {a} compte(s) ajouté(s), {m} libellé(s) mis à jour.', ['a' => $r['added'], 'm' => $r['updated']]));
        if ($r['errors']) {
            flash('warning', implode(' ', array_slice($r['errors'], 0, 5)) . (count($r['errors']) > 5 ? ' …' : ''));
        }
        redirect('/compta/comptes');
    }

    // ------------------------------------------------------------------ tiers

    public function tiers(): void
    {
        $this->ready();
        $f = ['type' => (string) Request::query('type', ''), 'q' => (string) Request::query('q', '')];
        $edit = Request::query('edit') ? $this->attempt(fn() => Compta::tiers((int) Request::query('edit')), '/compta/tiers') : null;
        view('comptabilite::tiers', ['title' => 'Tiers', 'rows' => Compta::tiersList($f), 'f' => $f, 'edit' => $edit,
            'collectifs' => DB::all('SELECT numero, libelle, tiers_type FROM cpt_comptes WHERE tiers_type <> \'\' AND actif = 1 ORDER BY numero')]);
    }

    public function tiersStore(): void
    {
        $this->attempt(fn() => Compta::saveTiers($_POST), '/compta/tiers?nouveau=1');
        flash('success', t('Tiers créé.'));
        redirect('/compta/tiers');
    }

    public function tiersUpdate(int $id): void
    {
        $this->attempt(fn() => Compta::saveTiers($_POST, $id), '/compta/tiers?edit=' . $id);
        flash('success', t('Tiers mis à jour.'));
        redirect('/compta/tiers');
    }

    public function tiersDelete(int $id): void
    {
        $this->attempt(fn() => Compta::deleteTiers($id), '/compta/tiers?edit=' . $id);
        flash('success', t('Tiers supprimé.'));
        redirect('/compta/tiers');
    }

    /** Crée les comptes de tiers des clients et fournisseurs de POS Marwa qui n'en ont pas encore. */
    public function tiersImport(): void
    {
        $n = $this->attempt(function () {
            return DB::transaction(function () {
                $n = 0;
                foreach (DB::all('SELECT id FROM customers WHERE id NOT IN (SELECT source_id FROM cpt_tiers WHERE source = \'customer\')') as $c) {
                    Compta::tiersFor('customer', (int) $c['id']);
                    $n++;
                }
                foreach (DB::all('SELECT id FROM suppliers WHERE id NOT IN (SELECT source_id FROM cpt_tiers WHERE source = \'supplier\')') as $s) {
                    Compta::tiersFor('supplier', (int) $s['id']);
                    $n++;
                }
                return $n;
            });
        }, '/compta/tiers');
        flash('success', t('{n} tiers créé(s) depuis les clients et fournisseurs.', ['n' => $n]));
        redirect('/compta/tiers');
    }

    // ------------------------------------------------------------------ journaux

    public function journaux(): void
    {
        $this->ready();
        $edit = Request::query('edit') ? $this->attempt(fn() => Compta::journal((int) Request::query('edit')), '/compta/journaux') : null;
        view('comptabilite::journaux', ['title' => 'Journaux', 'rows' => Compta::journaux(), 'edit' => $edit, 'comptes' => Compta::imputables()]);
    }

    public function journalStore(): void
    {
        $this->attempt(fn() => Compta::saveJournal($_POST), '/compta/journaux');
        flash('success', t('Journal créé.'));
        redirect('/compta/journaux');
    }

    public function journalUpdate(int $id): void
    {
        $this->attempt(fn() => Compta::saveJournal($_POST, $id), '/compta/journaux?edit=' . $id);
        flash('success', t('Journal mis à jour.'));
        redirect('/compta/journaux');
    }

    // ------------------------------------------------------------------ exercices

    public function exercices(): void
    {
        $this->ready();
        $rows = Compta::exercices();
        $checks = [];
        foreach ($rows as $x) {
            $checks[$x['id']] = Compta::clotureChecks($x);
        }
        $edit = Request::query('edit') ? $this->attempt(fn() => Compta::exercice((int) Request::query('edit')), '/compta/exercices') : null;
        $last = $rows[0] ?? null;
        view('comptabilite::exercices', ['title' => 'Exercices et clôture', 'rows' => $rows, 'checks' => $checks, 'edit' => $edit,
            'nextStart' => $last ? date('Y-m-d', strtotime($last['date_fin'] . ' +1 day')) : date('Y-01-01')]);
    }

    public function exerciceStore(): void
    {
        $this->attempt(fn() => Compta::saveExercice($_POST), '/compta/exercices');
        flash('success', t('Exercice créé.'));
        redirect('/compta/exercices');
    }

    public function exerciceUpdate(int $id): void
    {
        $this->attempt(fn() => Compta::saveExercice($_POST, $id), '/compta/exercices?edit=' . $id);
        flash('success', t('Exercice mis à jour.'));
        redirect('/compta/exercices');
    }

    public function verrou(int $id): void
    {
        $this->attempt(fn() => Compta::setVerrou($id, Request::str('verrou_au') ?: null), '/compta/exercices');
        flash('success', Request::str('verrou_au') ? t('Période verrouillée jusqu\'au {d}.', ['d' => fdate(Request::str('verrou_au'), false)]) : t('Verrouillage levé.'));
        redirect('/compta/exercices');
    }

    public function cloturer(int $id): void
    {
        if (Request::str('confirmation') !== 'CLOTURER') {
            $this->fail(t('Tapez CLOTURER pour confirmer la clôture (irréversible).'), '/compta/exercices');
        }
        $r = $this->attempt(fn() => Compta::cloturer($id), '/compta/exercices');
        flash('success', t('Exercice clôturé (une sauvegarde complète a été faite juste avant). Résultat : {r}. À-nouveaux passés dans l\'exercice {x}.',
            ['r' => money($r['resultat']), 'x' => $r['next']['code']]));
        redirect('/compta/exercices');
    }

    // ------------------------------------------------------------------ paramètres

    public function parametres(): void
    {
        $this->ready();
        view('comptabilite::parametres', ['title' => 'Paramètres comptables', 'comptes' => Compta::imputables(),
            'categories' => DB::all('SELECT * FROM expense_categories ORDER BY type, name'),
            'banks' => DB::value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'bank_accounts\'') ? DB::all('SELECT * FROM bank_accounts ORDER BY name') : []]);
    }

    public function parametresSave(): void
    {
        $this->ready();
        $imputables = Compta::imputables();
        $check = function (string $v, string $label) use ($imputables): string {
            $n = Compta::leadToken($v);
            if ($n !== '' && !isset($imputables[$n])) {
                $this->fail(t('« {l} » : le compte {n} n\'existe pas ou n\'est pas imputable.', ['l' => $label, 'n' => $n]), '/compta/parametres');
            }
            return $n;
        };
        foreach (Compta::LIAISONS as $k => $label) {
            $n = $check((string) ($_POST['cpt'][$k] ?? ''), $label);
            if ($n === '') {
                $this->fail(t('« {l} » : compte obligatoire.', ['l' => $label]), '/compta/parametres');
            }
            setting_set('compta_cpt_' . $k, $n);
        }
        foreach ((array) ($_POST['cat'] ?? []) as $id => $v) {
            setting_set('compta_cat_' . (int) $id, $check((string) $v, 'Catégorie ' . (int) $id) ?: null);
        }
        foreach ((array) ($_POST['bank'] ?? []) as $id => $v) {
            setting_set('compta_bank_' . (int) $id, $check((string) $v, 'Compte bancaire ' . (int) $id) ?: null);
        }
        $from = Request::str('generation_depuis');
        if ($from !== '' && !Validator::isDate($from)) {
            $this->fail(t('Date de début de génération invalide.'), '/compta/parametres');
        }
        setting_set('compta_generation_depuis', $from ?: null);
        setting_set('compta_validation_directe', Request::str('validation_directe') === '1' ? '1' : '0');
        \App\Core\Audit::log('compta.parametres', 'setting', null, ['liaisons' => array_map(fn($k) => setting('compta_cpt_' . $k), array_combine(array_keys(Compta::LIAISONS), array_keys(Compta::LIAISONS)))]);
        flash('success', t('Paramètres comptables enregistrés.'));
        redirect('/compta/parametres');
    }
}
