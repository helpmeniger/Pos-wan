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
use App\Repositories\CityRepository;
use App\Services\Auth\AuthService;
use App\Services\Profile\ProfileService;

final class ProfileController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly ProfileService $profiles,
        private readonly CityRepository $cities,
        private readonly Database $db,
    ) {
        parent::__construct($view, $session);
    }

    public function edit(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        return $this->render('profile/edit', [
            'title' => 'Mon profil',
            'p' => $this->profiles->get($userId),
            'cities' => $this->cities->active(),
            'trades' => $this->db->fetchAll('SELECT s.id, s.name, c.name AS category FROM job_subcategories s JOIN job_categories c ON c.id = s.category_id WHERE s.is_active = 1 ORDER BY c.sort_order, s.sort_order'),
            'isWorker' => has_role('WORKER'),
        ]);
    }

    public function update(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'first_name' => 'required|string|min:2|max:80',
            'last_name' => 'required|string|min:2|max:80',
            'email' => 'nullable|email',
            'city_id' => 'required|integer',
            'neighborhood_id' => 'nullable|integer',
            'birth_date' => 'nullable|date',
            'headline' => 'nullable|string|max:160',
            'bio' => 'nullable|string|max:2000',
            'daily_rate' => 'nullable|integer|min:0|max:5000000',
            'years_experience' => 'nullable|integer|between:0,60',
            'is_available' => 'nullable|string',
        ], ['first_name' => 'prénom', 'last_name' => 'nom', 'city_id' => 'ville', 'headline' => 'métier principal', 'bio' => 'présentation', 'daily_rate' => 'tarif journalier']);
        if (!$this->cities->exists((int) $data['city_id'])) {
            throw new \App\Core\ValidationException(['city_id' => ['Choisissez une ville.']], $request->all());
        }
        $trades = array_map('intval', array_filter((array) $request->input('trades', []), 'is_scalar'));
        $this->profiles->update((int) $this->auth->id(), $data, $trades);
        return $this->redirect('/profil', 'Profil enregistré.');
    }
}
