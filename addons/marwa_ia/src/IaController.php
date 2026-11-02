<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\Request;
use App\Core\Session;
use App\Services\Pdf;

/** MARWA IA : page de l'assistant, questions, rapports PDF / CSV, réglages. */
final class IaController extends Controller
{
    public function index(): void
    {
        $history = DB::all('SELECT question, created_at FROM ia_history WHERE user_id = ? ORDER BY id DESC LIMIT 12', [Auth::id()]);
        view('marwa_ia::index', ['title' => 'MARWA IA — assistant', 'history' => $history, 'examples' => self::examples()]);
    }

    /** État pour la bulle d'assistance (affichée sur toutes les pages si autorisée). */
    public function state(): void
    {
        header('Cache-Control: no-store');
        $u = Auth::user();
        json_response(['ok' => true, 'bubble' => setting('ia_bubble', '1') === '1', 'name' => explode(' ', (string) ($u['full_name'] ?? ''))[0], 'page' => url('/ia'), 'ask' => url('/ia/ask')]);
    }

    public function ask(): void
    {
        $q = trim((string) (Request::json()['q'] ?? Request::input('q', '')));
        $q = mb_substr($q, 0, 500);
        $ctx = Session::get('marwa_ia_ctx');
        $r = Engine::answer($q, is_array($ctx) ? $ctx : null);
        if (!empty($r['spec'])) {
            Session::set('marwa_ia_ctx', $r['spec']);
        }
        if ($q !== '') {
            DB::insert('ia_history', ['user_id' => Auth::id(), 'question' => $q, 'kind' => $r['spec']['kind'] ?? ($r['type'] ?? null)]);
            if (random_int(1, 50) === 1) {
                DB::run('DELETE FROM ia_history WHERE created_at < NOW() - INTERVAL 90 DAY');
            }
        }
        unset($r['spec']);
        json_response($r);
    }

    /** Rapport complet : PDF affiché (view=1), PDF téléchargé ou CSV. */
    public function rapport(): void
    {
        try {
            $spec = Engine::fromQuery($_GET);
            $r = Engine::run($spec);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/ia');
        }
        Audit::log('ia.report', null, null, ['kind' => $spec['kind'], 'format' => Export::requested()]);
        $opts = ['subtitle' => $r['subtitle'] . ' · MARWA IA · ' . date('d/m/Y H:i'), 'landscape' => $r['landscape'] ?? null];
        if (!empty($r['footer'])) {
            $opts['footer'] = $r['footer'];
        }
        $opts = array_filter($opts, fn($v) => $v !== null);
        $file = ($r['file'] ?? 'rapport') . '_' . date('Ymd_Hi') . '.csv';
        if (Export::requested() === 'pdf' && Request::query('view') === '1') {
            $rows = $r['rows'];
            $pdf = Pdf::table($r['title'], $r['headers'], $rows, $opts);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . preg_replace('/\.csv$/', '.pdf', $file) . '"');
            header('Content-Length: ' . strlen($pdf));
            header('X-Content-Type-Options: nosniff');
            echo $pdf;
            exit;
        }
        if (!Export::requested()) {
            redirect('/ia');
        }
        Export::download($file, $r['title'], $r['headers'], $r['rows'], $opts);
    }

    public function forget(): void
    {
        DB::run('DELETE FROM ia_history WHERE user_id = ?', [Auth::id()]);
        Session::forget('marwa_ia_ctx');
        flash('success', t('Historique de vos questions effacé.'));
        redirect('/ia');
    }

    public function settings(): void
    {
        view('marwa_ia::reglages', ['title' => 'MARWA IA — réglages', 'url' => (string) setting('ia_llm_url', ''), 'model' => (string) setting('ia_llm_model', ''), 'bubble' => setting('ia_bubble', '1') === '1']);
    }

    public function settingsSave(): void
    {
        $this->attempt(function () {
            $url = Llm::validateUrl(Request::str('llm_url'));
            $model = mb_substr(preg_replace('/[^A-Za-z0-9_.:\/-]/', '', Request::str('llm_model')), 0, 80);
            setting_set('ia_llm_url', $url);
            setting_set('ia_llm_model', $url === '' ? '' : $model);
            setting_set('ia_bubble', Request::input('bubble') === '1' ? '1' : '0');
            Audit::log('ia.settings', null, null, ['llm' => $url !== '', 'bubble' => Request::input('bubble') === '1']);
            if ($url !== '' && Request::input('test') === '1') {
                flash('info', t('Réponse du modèle local') . ' : ' . Llm::test());
            }
        }, '/ia/reglages');
        flash('success', t('Réglages de MARWA IA enregistrés.'));
        redirect('/ia/reglages');
    }

    /** Exemples de questions, selon les droits de l'utilisateur. */
    public static function examples(): array
    {
        $all = [
            'Conseils et idées' => [['Mon point du jour', null], ['Comment augmenter mes ventes ?', 'benefice'], ['Idées pour fidéliser mes clients', null], ['Comment réduire les pertes et les vols ?', null], ['Conseils pour ma trésorerie', null]],
            'Formation' => [['Ma formation', null], ['Forme-moi à la caisse', null], ['Quiz : clôturer la caisse', null], ['Vérifie ma configuration', null]],
            'Calculs et lexique' => [['Prix de vente pour un achat à 2 500 F avec 30 % de marge', null], ['Acheté 1 000, vendu 1 250 : quelle marge ?', null], ['10 000 F HT en TTC', null], ['Seuil de rentabilité avec 300 000 F de charges', null], ['C\'est quoi le CMP ?', null], ['Un proverbe africain', null]],
            'Rapports' => [['Bilan de la journée', 'bilan'], ['Ventes d\'hier en PDF', 'ventes'], ['Toutes les transactions du mois en PDF', 'ventes'], ['Ventes payées en mobile money cette semaine', 'ventes'],
                ['Bénéfice du mois dernier', 'benefice'], ['Dépenses du mois', 'depenses'], ['Écarts de caisse de la semaine', 'caisse']],
            'Clients et fournisseurs' => [['Qui me doit de l\'argent ?', 'creances'], ['Meilleurs clients de l\'année', 'meilleurs_clients'], ['Rapport du client … en PDF', 'client'], ['Combien je dois au fournisseur … ?', 'fournisseur']],
            'Articles et stock' => [['Meilleures ventes du mois', 'top'], ['Que dois-je commander ?', 'commande'], ['Articles sous le seuil', 'rupture'], ['Articles qui ne se vendent pas', 'dormants'], ['Produits qui périment bientôt', 'peremption'], ['Valeur du stock', 'stock']],
            'Analyses' => [['Prévision du mois', 'prevision'], ['Heure de pointe ce mois-ci', 'heures'], ['Ventes par vendeur cette semaine', 'vendeurs'], ['Ventes par catégorie', 'categories']],
            'Utiliser POS Marwa' => [['Comment vendre au carton ?', null], ['Comment clôturer la caisse ?', null], ['Comment faire un inventaire ?', null], ['Que faire en cas de coupure d\'Internet ?', null]],
        ];
        $out = [];
        foreach ($all as $g => $items) {
            $ok = array_values(array_filter($items, fn($i) => $i[1] === null || Auth::can(Reports::KINDS[$i[1]][0])));
            if ($ok) {
                $out[$g] = array_column($ok, 0);
            }
        }
        return $out;
    }
}
