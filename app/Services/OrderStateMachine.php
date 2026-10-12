<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Machine à états stricte par flux de commande.
 * Chaque transition est : autorisée selon le rôle de l'acteur, vérifiée côté serveur,
 * appliquée par une mise à jour conditionnelle (statut + version), horodatée et historisée.
 */
final class OrderStateMachine
{
    private const AFTERCARE = [
        'paid' => ['disputed' => ['customer', 'admin'], 'refund_pending' => ['admin']],
        'disputed' => ['completed' => ['admin'], 'delivered' => ['admin'], 'paid' => ['admin'], 'refund_pending' => ['admin']],
        'refund_pending' => ['refunded' => ['admin', 'system'], 'paid' => ['admin']],
    ];

    public const TRANSITIONS = [
        'ride' => [
            'created' => ['searching' => ['system'], 'negotiating' => ['system']],
            'searching' => ['assigned' => ['system'], 'expired' => ['system'], 'cancelled' => ['customer', 'admin']],
            'negotiating' => ['assigned' => ['system'], 'expired' => ['system'], 'cancelled' => ['customer', 'admin']],
            'assigned' => ['en_route_pickup' => ['provider'], 'searching' => ['system'], 'negotiating' => ['system'], 'cancelled' => ['customer', 'admin']],
            'en_route_pickup' => ['arrived_pickup' => ['provider'], 'searching' => ['system'], 'negotiating' => ['system'], 'cancelled' => ['customer', 'admin']],
            'arrived_pickup' => ['in_progress' => ['provider'], 'cancelled' => ['customer', 'provider', 'admin']],
            'in_progress' => ['completed' => ['provider', 'admin'], 'disputed' => ['admin']],
            'completed' => ['paid' => ['system'], 'disputed' => ['customer', 'admin']],
        ] + self::AFTERCARE,
        'courier' => [
            'created' => ['searching' => ['system'], 'negotiating' => ['system']],
            'searching' => ['assigned' => ['system'], 'expired' => ['system'], 'cancelled' => ['customer', 'admin']],
            'negotiating' => ['assigned' => ['system'], 'expired' => ['system'], 'cancelled' => ['customer', 'admin']],
            'assigned' => ['en_route_pickup' => ['provider'], 'searching' => ['system'], 'negotiating' => ['system'], 'cancelled' => ['customer', 'admin']],
            'en_route_pickup' => ['arrived_pickup' => ['provider'], 'searching' => ['system'], 'negotiating' => ['system'], 'cancelled' => ['customer', 'admin']],
            'arrived_pickup' => ['picked_up' => ['provider'], 'cancelled' => ['customer', 'provider', 'admin']],
            'picked_up' => ['in_delivery' => ['provider']],
            'in_delivery' => ['delivered' => ['provider', 'admin'], 'delivery_failed' => ['provider']],
            'delivery_failed' => ['in_delivery' => ['provider'], 'returning' => ['provider', 'admin'], 'cancelled' => ['admin']],
            'returning' => ['returned' => ['provider', 'admin']],
            'returned' => ['paid' => ['system'], 'disputed' => ['customer', 'admin']],
            'delivered' => ['paid' => ['system'], 'disputed' => ['customer', 'admin']],
        ] + self::AFTERCARE,
        'merchant' => [
            'created' => ['pending' => ['system'], 'cancelled' => ['system', 'customer', 'admin']],
            'pending' => ['accepted' => ['merchant'], 'refused' => ['merchant'], 'cancelled' => ['customer', 'admin'], 'expired' => ['system']],
            'accepted' => ['preparing' => ['merchant'], 'cancelled' => ['merchant', 'admin']],
            'preparing' => ['ready' => ['merchant'], 'cancelled' => ['admin']],
            'ready' => ['picked_up' => ['provider', 'merchant'], 'cancelled' => ['admin']],
            'picked_up' => ['in_delivery' => ['provider', 'merchant']],
            'in_delivery' => ['delivered' => ['provider', 'merchant', 'admin'], 'delivery_failed' => ['provider', 'merchant']],
            'delivery_failed' => ['in_delivery' => ['provider', 'merchant'], 'returning' => ['provider', 'merchant', 'admin'], 'cancelled' => ['admin']],
            'returning' => ['returned' => ['provider', 'merchant', 'admin']],
            'returned' => ['paid' => ['system'], 'disputed' => ['customer', 'admin']],
            'delivered' => ['paid' => ['system'], 'disputed' => ['customer', 'admin']],
        ] + self::AFTERCARE,
    ];

    public const LABELS = [
        'created' => 'Demande créée',
        'searching' => 'Recherche d\'un prestataire',
        'negotiating' => 'Négociation du prix',
        'pending' => 'En attente du commerce',
        'accepted' => 'Acceptée par le commerce',
        'refused' => 'Refusée par le commerce',
        'preparing' => 'En préparation',
        'ready' => 'Prête',
        'assigned' => 'Prestataire affecté',
        'en_route_pickup' => 'En route vers le point de départ',
        'arrived_pickup' => 'Arrivé au point de départ',
        'in_progress' => 'Course en cours',
        'picked_up' => 'Récupérée par le livreur',
        'in_delivery' => 'En livraison',
        'delivered' => 'Livrée',
        'delivery_failed' => 'Livraison échouée',
        'returning' => 'Retour en cours',
        'returned' => 'Retournée à l\'expéditeur',
        'completed' => 'Course terminée',
        'paid' => 'Paiement confirmé',
        'cancelled' => 'Annulée',
        'expired' => 'Expirée',
        'disputed' => 'Contestée',
        'refund_pending' => 'Remboursement en cours',
        'refunded' => 'Remboursée',
    ];

    public const TERMINAL = ['cancelled', 'expired', 'refused', 'refunded'];
    public const FINISHED = ['completed', 'delivered', 'returned', 'paid', 'cancelled', 'expired', 'refused', 'refunded', 'disputed', 'refund_pending'];
    /** Statuts pendant lesquels la position du prestataire est partagée avec le client. */
    public const TRACKABLE = ['assigned', 'en_route_pickup', 'arrived_pickup', 'in_progress', 'picked_up', 'in_delivery', 'delivery_failed', 'returning', 'accepted', 'preparing', 'ready'];

    public static function can(string $flow, string $from, string $to, string $actorRole): bool
    {
        return in_array($actorRole, self::TRANSITIONS[$flow][$from][$to] ?? [], true);
    }

    public static function next(string $flow, string $from, string $actorRole): array
    {
        $out = [];
        foreach (self::TRANSITIONS[$flow][$from] ?? [] as $to => $roles) {
            if (in_array($actorRole, $roles, true)) {
                $out[] = $to;
            }
        }
        return $out;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /**
     * Applique une transition. Le verrouillage optimiste (version) empêche deux
     * changements concurrents de s'écraser.
     */
    public static function transition(array $order, string $to, string $actorRole, ?int $actorId, ?string $note = null, array $extra = []): array
    {
        $from = (string) $order['status'];
        if (!self::can((string) $order['flow'], $from, $to, $actorRole)) {
            throw HttpException::conflict(sprintf('Changement de statut impossible : « %s » → « %s ».', self::label($from), self::label($to)), 'invalid_transition');
        }
        $data = $extra + ['status' => $to, 'version' => (int) $order['version'] + 1];
        if (in_array($to, ['completed', 'delivered', 'returned'], true)) {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        if ($to === 'picked_up' || ($to === 'in_progress')) {
            $data['picked_up_at'] = date('Y-m-d H:i:s');
        }
        $n = Database::update('orders', $data, 'id = :id AND status = :from AND version = :v',
            ['id' => (int) $order['id'], 'from' => $from, 'v' => (int) $order['version']]);
        if ($n !== 1) {
            throw HttpException::conflict('La commande a été modifiée entre-temps. Actualisez et réessayez.', 'stale_order');
        }
        Database::insert('order_status_history', [
            'order_id' => (int) $order['id'],
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
        return array_merge($order, $data);
    }
}
