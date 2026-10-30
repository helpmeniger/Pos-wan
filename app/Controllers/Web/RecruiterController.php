<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\CatalogRepository;
use App\Repositories\CityRepository;
use App\Services\Auth\AuthService;
use App\Services\Employment\EmploymentService;
use App\Services\Jobs\ApplicationService;
use App\Services\Jobs\InterviewService;
use App\Services\Jobs\JobPostService;

/** Espace recruteur : offres, candidatures reçues, entretiens, embauche. */
final class RecruiterController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly JobPostService $posts,
        private readonly ApplicationService $applications,
        private readonly InterviewService $interviews,
        private readonly EmploymentService $employments,
        private readonly CityRepository $cities,
        private readonly CatalogRepository $catalog,
        private readonly Database $db,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('recruiter/posts', ['title' => 'Mes offres', 'posts' => $this->posts->forRecruiter((int) $this->auth->id())]);
    }

    public function create(Request $request): Response
    {
        $trades = $this->db->fetchAll(
            'SELECT s.id, s.name, c.name AS category FROM job_subcategories s JOIN job_categories c ON c.id = s.category_id
             WHERE s.is_active = 1 AND c.is_active = 1 ORDER BY c.sort_order, s.sort_order'
        );
        return $this->render('recruiter/post-form', [
            'title' => 'Publier une offre',
            'cities' => $this->cities->active(),
            'trades' => $trades,
            'contractTypes' => $this->db->fetchAll('SELECT code, name FROM mission_types WHERE is_active = 1 ORDER BY sort_order'),
            'verified' => $this->posts->isVerifiedRecruiter((int) $this->auth->id()),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'title' => 'required|string|min:5|max:160',
            'subcategory_id' => 'required|integer',
            'contract_type' => 'required|string|max:40',
            'description' => 'required|string|min:20|max:5000',
            'city_id' => 'required|integer',
            'neighborhood_id' => 'nullable|integer',
            'address' => 'nullable|string|max:255',
            'positions' => 'required|integer|between:1,200',
            'pay_rate' => 'required|string|max:15',
            'pay_unit' => 'required|in:HEURE,JOUR,TACHE,FORFAIT,MOIS',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'application_deadline' => 'nullable|date',
            'daily_start_time' => 'nullable|string|max:5',
            'daily_end_time' => 'nullable|string|max:5',
            'schedule_notes' => 'nullable|string|max:500',
        ], [
            'title' => 'intitulé', 'subcategory_id' => 'métier', 'contract_type' => 'type de contrat', 'description' => 'description',
            'city_id' => 'ville', 'positions' => 'nombre de postes', 'pay_rate' => 'rémunération', 'pay_unit' => 'unité',
            'start_date' => 'date de début', 'end_date' => 'date de fin', 'application_deadline' => 'date limite',
        ]);
        foreach (['daily_start_time', 'daily_end_time'] as $t) {
            if (!empty($data[$t]) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $data[$t])) {
                throw new \App\Core\ValidationException([$t => ['Heure invalide (ex : 08:00).']], $request->all());
            }
        }
        try {
            $data['pay_rate'] = \App\Support\Money::parse((string) $data['pay_rate'])->amount();
        } catch (\InvalidArgumentException) {
            throw new \App\Core\ValidationException(['pay_rate' => ['Montant invalide (francs CFA, sans centimes).']], $request->all());
        }
        $result = $this->posts->create((int) $this->auth->id(), $data);
        return $this->redirect('/recruteur/offres/' . $result['uuid'], $result['status'] === 'EN_MODERATION'
            ? 'Offre enregistrée : elle sera publiée après vérification par l\'équipe JOB NIGER.'
            : 'Offre publiée ! Les candidats peuvent postuler dès maintenant.');
    }

    public function show(Request $request): Response
    {
        $post = $this->posts->ownedBy((string) $request->param('uuid'), (int) $this->auth->id());
        $this->applications->markViewed((int) $post['id']);
        return $this->render('recruiter/post', [
            'title' => $post['title'],
            'post' => $post,
            'applications' => $this->applications->forPost((int) $post['id']),
        ]);
    }

    public function toggle(Request $request): Response
    {
        $post = $this->posts->ownedBy((string) $request->param('uuid'), (int) $this->auth->id());
        $action = $request->str('action');
        if ($action === 'close' && in_array($post['status'], ['PUBLIEE', 'CANDIDATURES_OUVERTES', 'EN_MODERATION', 'ATTRIBUEE'], true)) {
            $this->posts->setStatus((int) $post['id'], 'TERMINEE', (int) $this->auth->id(), 'Clôturée par le recruteur');
            return $this->redirect('/recruteur/offres/' . $post['uuid'], 'Offre clôturée : elle n\'accepte plus de candidatures.');
        }
        if ($action === 'reopen' && in_array($post['status'], ['TERMINEE', 'EXPIREE'], true) && $post['moderation_flags'] === null
            && (int) $post['positions_filled'] < (int) $post['positions_total']) {
            $this->db->query('UPDATE job_posts SET application_deadline = GREATEST(application_deadline, NOW() + INTERVAL 15 DAY) WHERE id = ?', [(int) $post['id']]);
            $this->posts->setStatus((int) $post['id'], 'CANDIDATURES_OUVERTES', (int) $this->auth->id(), 'Réouverte par le recruteur');
            return $this->redirect('/recruteur/offres/' . $post['uuid'], 'Offre rouverte pour 15 jours.');
        }
        return $this->back('/recruteur/offres/' . $post['uuid'], 'Action impossible pour cette offre.');
    }

    public function refuse(Request $request): Response
    {
        $note = mb_substr($request->str('note'), 0, 500);
        $a = $this->applications->findForParty((string) $request->param('uuid'), (int) $this->auth->id());
        $this->applications->refuse((int) $this->auth->id(), (string) $request->param('uuid'), $note ?: null);
        return $this->redirect('/recruteur/offres/' . $a['post_uuid'], 'Candidature refusée ; le candidat est prévenu.');
    }

    public function interviewForm(Request $request): Response
    {
        $a = $this->applications->findForParty((string) $request->param('uuid'), (int) $this->auth->id());
        if ((int) $a['client_user_id'] !== (int) $this->auth->id()) {
            throw new \App\Core\HttpException(404);
        }
        $candidate = $this->db->fetch('SELECT first_name, last_name FROM users WHERE id = ?', [(int) $a['worker_user_id']]);
        return $this->render('recruiter/interview-form', ['title' => 'Proposer un entretien', 'application' => $a, 'candidate' => $candidate]);
    }

    public function scheduleInterview(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'mode' => 'required|in:EN_LIGNE,PRESENTIEL',
            'date' => 'required|date',
            'time' => 'required|string|max:5',
            'duration' => 'required|integer|between:10,240',
            'meeting_link' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ], ['mode' => 'type d\'entretien', 'date' => 'date', 'time' => 'heure', 'duration' => 'durée', 'meeting_link' => 'lien', 'location' => 'adresse']);
        $a = $this->applications->findForParty((string) $request->param('uuid'), (int) $this->auth->id());
        $this->interviews->schedule((int) $this->auth->id(), (string) $request->param('uuid'), $data);
        return $this->redirect('/recruteur/offres/' . $a['post_uuid'], 'Entretien proposé : le candidat est prévenu et doit confirmer.');
    }

    public function concludeInterview(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['result' => 'required|in:CONCLUANT,NON_CONCLUANT,ANNULE', 'note' => 'nullable|string|max:1000']);
        $postUuid = (string) ($this->db->fetchValue('SELECT p.uuid FROM interviews i JOIN job_posts p ON p.id = i.job_post_id WHERE i.uuid = ? AND i.recruiter_user_id = ?', [(string) $request->param('uuid'), (int) $this->auth->id()]) ?? throw new \App\Core\HttpException(404));
        $this->interviews->conclude((int) $this->auth->id(), (string) $request->param('uuid'), (string) $data['result'], $data['note']);
        return $this->redirect('/recruteur/offres/' . $postUuid, 'Résultat de l\'entretien enregistré.');
    }

    public function hireForm(Request $request): Response
    {
        $a = $this->applications->findForParty((string) $request->param('uuid'), (int) $this->auth->id());
        if ((int) $a['client_user_id'] !== (int) $this->auth->id()) {
            throw new \App\Core\HttpException(404);
        }
        $post = $this->posts->findByUuid((string) $a['post_uuid']);
        $candidate = $this->db->fetch('SELECT first_name, last_name FROM users WHERE id = ?', [(int) $a['worker_user_id']]);
        $rate = (string) ($this->db->fetchValue("SELECT worker_rate FROM commission_rules WHERE scope = 'GLOBAL' AND is_active = 1 ORDER BY priority DESC, id DESC LIMIT 1") ?? '0');
        return $this->render('recruiter/hire-form', [
            'title' => 'Embaucher', 'application' => $a, 'post' => $post, 'candidate' => $candidate,
            'commissionRate' => app(\App\Repositories\SettingRepository::class)->get('payroll.commission_enabled', true) ? $rate : '0.00',
        ]);
    }

    public function hire(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'position_title' => 'required|string|min:3|max:160',
            'duties' => 'nullable|string|max:5000',
            'work_location' => 'nullable|string|max:255',
            'work_days' => 'required|string|min:3|max:120',
            'daily_start_time' => 'nullable|string|max:5',
            'daily_end_time' => 'nullable|string|max:5',
            'hours_per_week' => 'required|numeric|between:1,84',
            'pay_period' => 'required|in:MENSUEL,PRESTATION',
            'salary_amount' => 'required|string|max:15',
            'payment_method' => 'required|in:MOBILE_MONEY,ESPECES',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
        ], [
            'position_title' => 'emploi / poste', 'work_days' => 'jours de travail', 'hours_per_week' => 'heures par semaine',
            'pay_period' => 'périodicité', 'salary_amount' => 'salaire', 'payment_method' => 'mode de paiement',
            'start_date' => 'date de début', 'end_date' => 'date de fin',
        ]);
        foreach (['daily_start_time', 'daily_end_time'] as $t) {
            if (!empty($data[$t]) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $data[$t])) {
                throw new \App\Core\ValidationException([$t => ['Heure invalide (ex : 08:00).']], $request->all());
            }
        }
        try {
            \App\Support\Money::parse((string) $data['salary_amount']);
        } catch (\InvalidArgumentException) {
            throw new \App\Core\ValidationException(['salary_amount' => ['Montant invalide (francs CFA, sans centimes).']], $request->all());
        }
        $data['hours_per_week'] = number_format((float) $data['hours_per_week'], 2, '.', '');
        $uuid = $this->employments->propose((int) $this->auth->id(), (string) $request->param('uuid'), $data);
        return $this->redirect('/emplois/' . $uuid, 'Contrat proposé : il devient actif dès que l\'employé l\'accepte dans l\'application.');
    }
}
