<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;

/**
 * Création et suivi des alertes. Une alerte est une demande de VÉRIFICATION, jamais une
 * accusation : aucun libellé ne mentionne un « vol » et aucune personne n'est identifiée.
 */
final class AlertService
{
    public const STATUSES = ['nouvelle', 'en_examen', 'mouvement_normal', 'vente_retrouvee', 'erreur_detection', 'anomalie_confirmee', 'non_concluante', 'cloturee'];
    public const CLOSING = ['mouvement_normal', 'vente_retrouvee', 'erreur_detection', 'anomalie_confirmee', 'non_concluante', 'cloturee'];

    /** Détermine type, niveau et indices d'une sortie, puis crée l'alerte correspondante. */
    public static function createForExit(array $exit, array $recon, int $trackFrames = 0): ?int
    {
        $storeId = (int) $exit['store_id'];
        $product = $exit['product_id'] ? Database::fetch('SELECT name FROM products WHERE id = :id', ['id' => $exit['product_id']]) : null;
        $pname = $product['name'] ?? 'Produit non identifié';
        $conf = $exit['confidence'] !== null ? (float) $exit['confidence'] : null;
        $indicators = [];

        switch ($exit['direction']) {
            case 'entree':
                return null; // franchissement vers l'intérieur : simplement enregistré
            case 'ambigu':
                $type = 'franchissement_ambigu';
                $level = (string) Settings::get('alerts.ambiguous_level', 'a_verifier');
                $title = "Franchissement ambigu ({$pname}) — vérification requise";
                $indicators[] = 'suivi trop court pour établir le sens de passage avec certitude';
                break;
            case 'perte_suivi':
                $type = 'perte_suivi';
                $level = (string) Settings::get('alerts.track_lost_level', 'a_verifier');
                $title = "Suivi incomplet près de la sortie ({$pname})";
                $indicators[] = 'l\'objet a disparu du champ près de la ligne (occultation possible) : le système ne conclut pas';
                break;
            default:
                if (!$exit['product_id']) {
                    $type = 'objet_non_identifie';
                    $level = (string) Settings::get('alerts.unidentified_level', 'a_verifier');
                    $title = 'Objet non identifié ayant franchi la sortie — à examiner';
                    $indicators[] = 'reconnaissance insuffisante pour nommer le produit';
                    break;
                }
                if ($recon['status'] === 'vente_trouvee') {
                    if (!Settings::get('alerts.info_for_matched_exits', true)) {
                        return null;
                    }
                    $type = 'sortie_observee';
                    $level = 'information';
                    $title = "Sortie observée : {$pname} (vente rapprochée)";
                    $indicators[] = $recon['note'];
                    break;
                }
                if ($recon['status'] === 'non_applicable') {
                    $type = 'sortie_observee';
                    $level = 'information';
                    $title = "Sortie observée : {$pname}";
                    break;
                }
                $type = 'sortie_sans_vente';
                $title = "Sortie observée sans vente correspondante identifiée — vérification requise ({$pname})";
                $indicators[] = 'aucune vente correspondante dans la fenêtre de rapprochement';
                $minConf = (float) Settings::get('alerts.priority_min_confidence', 0.85);
                $strong = $conf !== null && $conf >= $minConf;
                if ($strong) {
                    $indicators[] = sprintf('reconnaissance confiante (%d %%)', (int) round($conf * 100));
                }
                $clean = $trackFrames >= 5;
                if ($clean) {
                    $indicators[] = 'franchissement net, suivi continu';
                }
                $noSaleAtAll = (int) ($recon['sales_nearby'] ?? 0) === 0;
                if ($noSaleAtAll) {
                    $indicators[] = 'aucune vente de ce produit à proximité temporelle élargie';
                }
                $level = ($strong && $clean && $noSaleAtAll) ? 'prioritaire' : 'a_verifier';
        }
        if (!in_array($level, ['information', 'a_verifier', 'prioritaire'], true)) {
            $level = 'a_verifier';
        }
        $id = Database::insert('alerts', [
            'uuid' => uuid4(), 'store_id' => $storeId, 'camera_id' => $exit['camera_id'], 'exit_event_id' => $exit['id'],
            'product_id' => $exit['product_id'], 'level' => $level, 'alert_type' => $type, 'title' => mb_substr($title, 0, 200),
            'description' => 'Fait observé par la caméra. Cette alerte ne constitue pas une preuve : elle doit être vérifiée par un responsable.',
            'confidence' => $conf, 'indicators_json' => json_encode($indicators, JSON_UNESCAPED_UNICODE),
            'status' => $level === 'information' ? 'cloturee' : 'nouvelle', 'occurred_at' => $exit['occurred_at'],
            'closed_at' => $level === 'information' ? date('Y-m-d H:i:s') : null,
        ]);
        if ($level !== 'information') {
            // Protéger les médias de l'alerte contre la purge automatique tant qu'elle est ouverte
            Database::execute('UPDATE video_records SET is_protected = 1 WHERE id IN (:a, :b)', ['a' => $exit['snapshot_id'] ?? 0, 'b' => $exit['clip_id'] ?? 0]);
            NotificationService::notify($storeId, 'alerte', $title, $level === 'prioritaire' ? 'danger' : 'warning',
                'Événement à vérifier.', 'alerts/' . $id);
        }
        return $id;
    }

    /** Une vente a été trouvée après coup : l'alerte ouverte passe à « Vente retrouvée ». */
    public static function onSaleFound(int $exitId): void
    {
        $alerts = Database::fetchAll("SELECT id, status FROM alerts WHERE exit_event_id = :e AND alert_type = 'sortie_sans_vente' AND status IN ('nouvelle','en_examen')", ['e' => $exitId]);
        foreach ($alerts as $a) {
            Database::update('alerts', ['status' => 'vente_retrouvee', 'level' => 'information', 'closed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $a['id']]);
            Database::insert('alert_reviews', ['alert_id' => $a['id'], 'user_id' => null, 'old_status' => $a['status'], 'new_status' => 'vente_retrouvee',
                'comment' => 'Rapprochement automatique : une vente correspondante a été enregistrée en caisse.']);
            self::unprotectMedia((int) $a['id']);
        }
    }

    public static function review(array $alert, string $newStatus, ?string $comment, ?array $stock = null): void
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Statut invalide.');
        }
        Database::transaction(static function () use ($alert, $newStatus, $comment, $stock) {
            $movementId = null;
            if ($stock && $alert['product_id']) {
                $movementId = StockService::record((int) $alert['store_id'], (int) $alert['product_id'], $stock['type'], (float) $stock['quantity'],
                    ['alert_id' => $alert['id'], 'comment' => 'Suite à la vérification de l\'alerte #' . $alert['id'] . '. ' . ($comment ?? '')]);
            }
            $closing = in_array($newStatus, self::CLOSING, true);
            Database::update('alerts', ['status' => $newStatus, 'closed_at' => $closing ? date('Y-m-d H:i:s') : null,
                'assigned_to' => $alert['assigned_to'] ?: Auth::id()], 'id = :id', ['id' => $alert['id']]);
            Database::insert('alert_reviews', ['alert_id' => $alert['id'], 'user_id' => Auth::id(), 'old_status' => $alert['status'],
                'new_status' => $newStatus, 'comment' => $comment, 'stock_movement_id' => $movementId]);
            if ($closing && $newStatus !== 'anomalie_confirmee') {
                self::unprotectMedia((int) $alert['id']);
            }
        });
        Audit::log('alerte_verification', 'alert', $alert['id'], ['de' => $alert['status'], 'vers' => $newStatus]);
    }

    private static function unprotectMedia(int $alertId): void
    {
        Database::execute('UPDATE video_records v JOIN exit_events e ON (v.id = e.snapshot_id OR v.id = e.clip_id)
            JOIN alerts a ON a.exit_event_id = e.id SET v.is_protected = 0 WHERE a.id = :a', ['a' => $alertId]);
    }
}
