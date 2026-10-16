<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CatalogRepository;
use App\Repositories\CityRepository;

/** Référentiels publics en JSON (formulaires dynamiques, future application Android). */
final class MetaController
{
    public function __construct(
        private readonly Database $db,
        private readonly CityRepository $cities,
        private readonly CatalogRepository $catalog,
    ) {
    }

    public function health(Request $request): Response
    {
        try {
            $this->db->fetchValue('SELECT 1');
            $db = 'ok';
        } catch (\Throwable) {
            $db = 'indisponible';
        }
        return Response::json(['status' => $db === 'ok' ? 'ok' : 'degraded', 'database' => $db, 'time' => date('c')], $db === 'ok' ? 200 : 503);
    }

    public function cities(Request $request): Response
    {
        return Response::json(['data' => $this->cities->active()]);
    }

    public function neighborhoods(Request $request): Response
    {
        $cityId = (int) $request->param('id');
        if (!$this->cities->exists($cityId)) {
            throw new HttpException(404);
        }
        return Response::json(['data' => $this->cities->neighborhoods($cityId)]);
    }

    public function categories(Request $request): Response
    {
        $out = [];
        foreach ($this->catalog->categoriesWithCounts() as $c) {
            $c['subcategories'] = $this->catalog->subcategories((int) $c['id']);
            $out[] = $c;
        }
        return Response::json(['data' => $out]);
    }
}
