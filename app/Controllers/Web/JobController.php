<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\CatalogRepository;
use App\Repositories\CityRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;
use App\Services\Jobs\ApplicationService;
use App\Services\Jobs\JobPostService;

/** Offres publiques : recherche, détail, candidature en un clic. */
final class JobController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly JobPostService $posts,
        private readonly ApplicationService $applications,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly CityRepository $cities,
        private readonly CatalogRepository $catalog,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr($request->str('q'), 0, 100),
            'city' => (int) $request->query('ville', 0),
            'category' => (int) $request->query('categorie', 0),
        ];
        return $this->render('jobs/index', [
            'title' => 'Offres d\'emploi et missions',
            'result' => $this->posts->search($filters, max(1, (int) $request->query('page', 1))),
            'filters' => $filters,
            'cities' => $this->cities->active(),
            'categories' => $this->catalog->categoriesWithCounts(),
        ]);
    }

    public function show(Request $request): Response
    {
        $post = $this->posts->findByUuid((string) $request->param('uuid')) ?? throw new HttpException(404);
        $user = $this->auth->user();
        $isOwner = $user !== null && (int) $user['id'] === (int) $post['client_user_id'];
        // Annonce non publiée : visible seulement par son auteur
        if (!$isOwner && !in_array($post['status'], ['PUBLIEE', 'CANDIDATURES_OUVERTES', 'ATTRIBUEE', 'TERMINEE', 'EXPIREE'], true)) {
            throw new HttpException(404);
        }
        $applied = $user !== null ? $this->applications->hasApplied((int) $user['id'], (int) $post['id']) : null;
        return $this->render('jobs/show', [
            'title' => $post['title'],
            'post' => $post,
            'user' => $user,
            'isOwner' => $isOwner,
            'isWorker' => $user !== null && $this->permissions->hasRole((int) $user['id'], 'WORKER'),
            'open' => $this->posts->isOpen($post),
            'applied' => $applied,
            'needsBirthDate' => $user !== null && empty($user['birth_date']),
        ]);
    }

    public function apply(Request $request): Response
    {
        $uuid = (string) $request->param('uuid');
        $data = Validator::validate($request->all(), ['message' => 'nullable|string|max:2000', 'birth_date' => 'nullable|date'], ['message' => 'message', 'birth_date' => 'date de naissance']);
        $this->applications->apply((int) $this->auth->id(), $uuid, $data['message'], $data['birth_date']);
        return $this->redirect('/candidatures', 'Candidature envoyée ! Le recruteur est prévenu. Vous serez notifié(e) de sa réponse.');
    }
}
