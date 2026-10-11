<?php
declare(strict_types=1);

namespace Addons\Livraisons;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/** Règles métier du module : saisie contrôlée, statuts autorisés, journal d'audit. */
final class LivraisonService
{
    public const STATUSES = ['a_livrer' => 'À livrer', 'en_route' => 'En route', 'livree' => 'Livrée', 'annulee' => 'Annulée'];
    private const NEXT = ['a_livrer' => ['en_route', 'livree', 'annulee'], 'en_route' => ['livree', 'a_livrer', 'annulee'], 'livree' => [], 'annulee' => ['a_livrer']];

    public static function create(array $d): int
    {
        $name = trim((string) ($d['customer_name'] ?? ''));
        $phone = trim((string) ($d['phone'] ?? ''));
        $address = trim((string) ($d['address'] ?? ''));
        if ($name === '' || $phone === '' || $address === '') {
            throw new BusinessException('Client, téléphone et adresse sont obligatoires.');
        }
        $fee = (float) str_replace([' ', ','], ['', '.'], (string) ($d['fee'] ?? '0'));
        if ($fee < 0 || $fee > 10000000) {
            throw new BusinessException('Frais de livraison invalides.');
        }
        $saleId = null;
        if (trim((string) ($d['sale_number'] ?? '')) !== '') {
            $saleId = DB::value('SELECT id FROM sales WHERE number = ?', [trim((string) $d['sale_number'])]);
            if (!$saleId) {
                throw new BusinessException('Vente introuvable : vérifiez le numéro du ticket.');
            }
        }
        $id = DB::insert('livraisons', [
            'sale_id' => $saleId, 'customer_name' => mb_substr($name, 0, 150), 'phone' => mb_substr($phone, 0, 50), 'address' => mb_substr($address, 0, 255),
            'driver' => mb_substr(trim((string) ($d['driver'] ?? '')), 0, 100) ?: null, 'fee' => round($fee, 2),
            'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id(),
        ]);
        Audit::log('livraison.create', 'livraison', $id);
        return $id;
    }

    public static function setStatus(int $id, string $to): void
    {
        DB::transaction(function () use ($id, $to) {
            $cur = DB::value('SELECT status FROM livraisons WHERE id = ? FOR UPDATE', [$id]);
            if ($cur === null || !in_array($to, self::NEXT[$cur] ?? [], true)) {
                throw new BusinessException('Changement de statut non autorisé.');
            }
            DB::update('livraisons', ['status' => $to, 'delivered_at' => $to === 'livree' ? date('Y-m-d H:i:s') : null], 'id = ?', [$id]);
            Audit::log('livraison.status', 'livraison', $id, ['from' => $cur, 'to' => $to]);
        });
    }

    public static function next(string $status): array
    {
        return self::NEXT[$status] ?? [];
    }
}
