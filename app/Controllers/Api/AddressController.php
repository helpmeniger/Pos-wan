<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class AddressController extends Controller
{
    private const RULES = ['label' => 'required|string|max:60', 'address_text' => 'required|string|max:255', 'landmark' => 'nullable|string|max:150',
        'instructions' => 'nullable|string|max:255', 'lat' => 'nullable|lat', 'lng' => 'nullable|lng'];

    public function index(Request $r): Response
    {
        return $this->json(Database::select('SELECT id, label, address_text, landmark, instructions, lat, lng FROM addresses WHERE user_id = :u ORDER BY label', ['u' => $this->user()['id']]));
    }

    public function store(Request $r): Response
    {
        $u = $this->user();
        if ((int) Database::value('SELECT COUNT(*) FROM addresses WHERE user_id = :u', ['u' => $u['id']]) >= 30) {
            throw HttpException::badRequest('Nombre maximal d\'adresses atteint (30).');
        }
        $v = $this->validate($r, self::RULES);
        $id = Database::insert('addresses', $v + ['user_id' => $u['id']]);
        return $this->json(Database::first('SELECT id, label, address_text, landmark, instructions, lat, lng FROM addresses WHERE id = :id', ['id' => $id]), 201);
    }

    public function update(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, self::RULES);
        if (Database::update('addresses', $v, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $this->user()['id']]) === 0
            && !Database::value('SELECT 1 FROM addresses WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $this->user()['id']])) {
            throw HttpException::notFound();
        }
        return $this->ok('Adresse mise à jour.');
    }

    public function destroy(Request $r): Response
    {
        if (Database::execute('DELETE FROM addresses WHERE id = :id AND user_id = :u', ['id' => $r->intParam('id'), 'u' => $this->user()['id']]) === 0) {
            throw HttpException::notFound();
        }
        return $this->ok('Adresse supprimée.');
    }
}
