<?php
declare(strict_types=1);

namespace Addons\Livraisons;

use App\Controllers\Controller;
use App\Core\Request;

final class LivraisonController extends Controller
{
    public function index(): void
    {
        $st = (string) Request::query('status', '');
        $where = isset(LivraisonService::STATUSES[$st]) ? 'WHERE l.status = ?' : '';
        $p = paginate("FROM livraisons l LEFT JOIN sales s ON s.id = l.sale_id $where", $where ? [$st] : [], 'l.*, s.number AS sale_number', "FIELD(l.status, 'en_route', 'a_livrer', 'livree', 'annulee'), l.id DESC");
        view('livraisons::index', ['title' => 'Livraisons', 'p' => $p, 'status' => $st, 'scripts' => []]);
    }

    public function store(): void
    {
        $this->attempt(fn() => LivraisonService::create($_POST), '/livraisons');
        flash('success', t('Livraison enregistrée.'));
        redirect('/livraisons');
    }

    public function status(int $id): void
    {
        $this->attempt(fn() => LivraisonService::setStatus($id, Request::str('status')), '/livraisons');
        flash('success', t('Statut mis à jour.'));
        redirect('/livraisons');
    }
}
