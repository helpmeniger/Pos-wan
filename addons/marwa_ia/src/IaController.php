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

    /**
     * Question posée par la bulle (réponse en un bloc). Même moteur que la page /ia (MARWA AI), même mémoire :
     * la bulle garde sa conversation en session. Format de réponse 1.x conservé (html, kpis, table, actions, open).
     */
    public function ask(): void
    {
        $q = mb_substr(trim((string) (Request::json()['q'] ?? Request::input('q', ''))), 0, 500);
        $cid = (int) Session::get('marwa_ai_conv', 0) ?: null;
        if ($cid && !DB::value('SELECT 1 FROM ai_conversations WHERE id = ? AND user_id = ?', [$cid, Auth::id()])) {
            $cid = null;
        }
        $r = \Addons\MarwaIa\Brain\Brain::handle($q, $cid, static function (string $e, array $d): void {
        });
        Session::set('marwa_ai_conv', $r['conversation_id']);
        if ($q !== '') {
            DB::insert('ia_history', ['user_id' => Auth::id(), 'question' => $q, 'kind' => $r['legacy']['spec']['kind'] ?? ($r['legacy']['type'] ?? $r['intent'])]);
            if (random_int(1, 50) === 1) {
                DB::run('DELETE FROM ia_history WHERE created_at < NOW() - INTERVAL 90 DAY');
                \Addons\MarwaIa\Brain\Memory::purge();
            }
        }
        json_response(self::compat($r));
    }

    /** Réponse MARWA AI → format de la bulle 1.x (texte échappé ici ; le HTML du moteur est déjà échappé côté serveur). */
    public static function compat(array $r): array
    {
        if ($r['legacy']) {
            $out = $r['legacy'];
            unset($out['spec']);
            $out['message_id'] = $r['message_id'];
            return $out;
        }
        $html = $r['html'] ?? '';
        if ($html === '' || $html === null) {
            $html = implode('', array_map(fn($p) => '<p>' . nl2br(e($p)) . '</p>', array_filter(preg_split('/\n{2,}/', (string) $r['text']))));
        }
        $out = ['ok' => true, 'type' => $r['intent'] ? 'answer' : 'help', 'title' => '', 'html' => $html, 'kpis' => [], 'table' => null, 'actions' => [], 'suggestions' => array_values(array_unique($r['suggestions'])),
            'message_id' => $r['message_id'], 'intent' => $r['intent'], 'sources' => array_values(array_unique($r['sources']))];
        foreach ($r['blocks'] as $b) {
            if ($b['type'] === 'cards') {
                foreach ($b['items'] as $c) {
                    $out['kpis'][] = ['label' => $c['label'], 'value' => $c['value']];
                }
            } elseif ($b['type'] === 'table' && !$out['table']) {
                $out['table'] = ['headers' => $b['headers'], 'rows' => $b['rows'], 'more' => $b['more'] ?? 0];
            } elseif ($b['type'] === 'note') {
                $out['html'] .= '<p class="small text-secondary">' . e($b['text']) . '</p>';
            }
        }
        foreach ($r['links'] as $l) {
            $out['actions'][] = ['kind' => 'link', 'label' => $l[0], 'url' => url($l[1])];
        }
        if ($r['proposal']) {
            $out['html'] .= '<p class="small"><a href="' . e(url('/ia')) . '">Ouvrez MARWA AI en grand</a> pour confirmer cette opération.</p>';
        }
        return $out;
    }

    /** Plan marketing personnalisé (PDF affiché avec view=1, sinon téléchargé). */
    public function marketing(): void
    {
        $pdf = Marketing::pdf();
        Audit::log('ia.marketing', null, null, ['activity' => Marketing::activity()]);
        $name = 'plan_marketing_' . date('Ymd') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (Request::query('view') === '1' ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $pdf;
        exit;
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
        Session::forget('marwa_ai_conv');
        flash('success', t('Historique de vos questions effacé.'));
        redirect('/ia');
    }

    public function settings(): void
    {
        view('marwa_ia::reglages', ['title' => 'MARWA AI — réglages', 'bubble' => setting('ia_bubble', '1') === '1', 'retention' => \Addons\MarwaIa\Brain\Memory::retentionDays(),
            'phrasing' => setting('ai_llm_phrasing', '0') === '1', 'models' => \Addons\MarwaIa\Brain\Providers::chain()]);
    }

    public function settingsSave(): void
    {
        $this->attempt(function () {
            $days = (int) Request::input('retention', '90');
            if ($days < 1 || $days > 3650) {
                throw new BusinessException('Durée de conservation : entre 1 et 3650 jours.');
            }
            setting_set('ia_bubble', Request::input('bubble') === '1' ? '1' : '0');
            setting_set('ai_retention_days', (string) $days);
            setting_set('ai_llm_phrasing', Request::input('phrasing') === '1' ? '1' : '0');
            Audit::log('ia.settings', null, null, ['bubble' => Request::input('bubble') === '1', 'retention' => $days, 'phrasing' => Request::input('phrasing') === '1']);
            $purged = \Addons\MarwaIa\Brain\Memory::purge();
            if ($purged) {
                flash('info', $purged . ' ' . t('conversation(s) plus ancienne(s) que la durée de conservation effacée(s).'));
            }
        }, '/ia/reglages');
        flash('success', t('Réglages de MARWA AI enregistrés.'));
        redirect('/ia/reglages');
    }

    /** Exemples de questions, selon les droits de l'utilisateur. */
    public static function examples(): array
    {
        $all = [
            'Marketing et visibilité' => [['Mon plan marketing en PDF', null], ['Comment marche le marketing ?', null], ['Comment être visible sur les réseaux sociaux ?', null], ['Comment lancer une campagne publicitaire ?', null],
                ['Comment être trouvé sur Google Maps ?', null], ['Écris un post pour mon article vedette', null], ['Propose-moi un slogan', null]],
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
