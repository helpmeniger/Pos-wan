<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\IngestService;
use App\Services\SaleService;

final class ApiController extends Controller
{
    /** Santé publique minimale (aucune information sensible). */
    public function health(Request $r): void
    {
        try {
            Database::value('SELECT 1');
            $db = true;
        } catch (\Throwable $e) {
            $db = false;
        }
        Response::json(['ok' => $db, 'app' => 'SMART GUARD AI', 'database' => $db], $db ? 200 : 503);
    }

    /** Réception des événements du service IA (jeton X-AI-Token + IP autorisée). */
    public function aiEvents(Request $r): void
    {
        $events = $r->json()['events'] ?? null;
        if (!is_array($events)) {
            Response::json(['ok' => false, 'error' => 'Champ events manquant.'], 422);
        }
        $res = IngestService::handle($events);
        Response::json(['ok' => true] + $res);
    }

    /**
     * API de ventes pour un logiciel de caisse tiers (Authorization: Bearer SALES_API_TOKEN).
     * Corps : {"store_code":"MAG-001","external_ref":"F-123","sold_at":"2024-05-12 10:42:00","payment_method":"especes",
     *          "items":[{"barcode":"600...","quantity":2,"unit_price":4250}]}
     */
    public function sales(Request $r): void
    {
        $b = $r->json();
        $store = Database::fetch('SELECT id FROM stores WHERE code = :c AND is_active = 1', ['c' => (string) ($b['store_code'] ?? '')]);
        if (!$store || empty($b['external_ref']) || !is_array($b['items'] ?? null)) {
            Response::json(['ok' => false, 'error' => 'store_code, external_ref et items sont obligatoires.'], 422);
        }
        $items = [];
        foreach ($b['items'] as $it) {
            $pid = Database::value('SELECT id FROM products WHERE store_id = :s AND deleted_at IS NULL AND (barcode = :b OR reference = :r)',
                ['s' => $store['id'], 'b' => (string) ($it['barcode'] ?? ''), 'r' => (string) ($it['reference'] ?? '')]);
            if (!$pid) {
                Response::json(['ok' => false, 'error' => 'Produit inconnu : ' . ($it['barcode'] ?? $it['reference'] ?? '?')], 422);
            }
            $items[] = ['product_id' => (int) $pid, 'quantity' => (float) ($it['quantity'] ?? 0)] + (isset($it['unit_price']) ? ['unit_price' => (float) $it['unit_price']] : []);
        }
        try {
            $res = SaleService::create((int) $store['id'], $items, ['source' => 'api', 'external_ref' => (string) $b['external_ref'],
                'sold_at' => isset($b['sold_at']) ? date('Y-m-d H:i:s', (int) strtotime((string) $b['sold_at'])) : null,
                'payment_method' => (string) ($b['payment_method'] ?? 'especes'), 'cashier_id' => null]);
        } catch (\DomainException $e) {
            Response::json(['ok' => true, 'duplicate' => true, 'message' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        Logger::info('Vente reçue par API', ['ref' => $b['external_ref']]);
        Response::json(['ok' => true, 'sale_id' => $res['id'], 'receipt' => $res['receipt'], 'matched_exits' => $res['matched_exits']], 201);
    }

    /** Synchronisation des produits vers un logiciel de caisse tiers. */
    public function products(Request $r): void
    {
        $store = Database::fetch('SELECT id FROM stores WHERE code = :c', ['c' => (string) $r->query('store_code', '')]);
        if (!$store) {
            Response::json(['ok' => false, 'error' => 'store_code inconnu.'], 404);
        }
        Response::json(['ok' => true, 'products' => Database::fetchAll('SELECT uuid, name, brand, reference, barcode, sale_price, unit, is_active FROM products
            WHERE store_id = :s AND deleted_at IS NULL ORDER BY name', ['s' => $store['id']])]);
    }
}
