<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CatalogRepository;
use App\Repositories\CityRepository;

final class HomeController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly CatalogRepository $catalog,
        private readonly CityRepository $cities,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('public/home', [
            'title' => 'Un travail, une opportunité, un revenu',
            'categories' => $this->catalog->categoriesWithCounts(),
            'tradesCount' => $this->catalog->countTrades(),
            'cities' => $this->cities->active(),
        ]);
    }

    public function categories(Request $request): Response
    {
        return $this->render('public/categories', [
            'title' => 'Catégories de métiers',
            'categories' => $this->catalog->categoriesWithCounts(),
            'tradesCount' => $this->catalog->countTrades(),
        ]);
    }

    public function category(Request $request): Response
    {
        $category = $this->catalog->categoryBySlug((string) $request->param('slug'));
        if ($category === null) {
            throw new HttpException(404);
        }
        return $this->render('public/category', [
            'title' => $category['name'],
            'category' => $category,
            'subcategories' => $this->catalog->subcategories((int) $category['id']),
        ]);
    }
}
