<?php
/**
 * Score de risque des dossiers (aide à la lutte contre la fraude foncière).
 *
 * Chaque indicateur est une règle simple, explicable et vérifiable ; le score
 * (0 à 100) sert uniquement à PRIORISER le travail des vérificateurs. Un score
 * élevé n'est pas une preuve de fraude : il signale un dossier à examiner.
 */
declare(strict_types=1);

final class RiskService
{
    /** code => [poids, libellé, explication] */
    public const RULES = [
        'double_vente' => [35, 'Ventes successives rapprochées', 'Au moins deux ventes enregistrées sur moins de 12 mois (risque de double vente).'],
        'doublon' => [35, 'Doublon probable non résolu', 'Une autre parcelle a une géométrie quasi identique.'],
        'chevauchement' => [25, 'Chevauchement non résolu', 'Les limites empiètent sur une autre parcelle (à vérifier).'],
        'conflit_proprietaires' => [20, 'Chevauchement entre propriétaires différents', 'La zone qui se chevauche est revendiquée par des personnes différentes.'],
        'conteste' => [25, 'Droit contesté', 'Un propriétaire ou un lien de propriété est marqué « contesté ».'],
        'titre_sans_reference' => [20, 'Titre foncier sans référence', 'La situation déclarée est « titre foncier » mais aucune référence de titre n\'est saisie.'],
        'titre_indicatif' => [15, 'Titre déclaré, limites indicatives', 'Un titre ou un permis est déclaré alors que les limites ne sont qu\'indicatives.'],
        'verifiee_sans_document' => [20, 'Vérifiée sans pièce justificative', 'Le dossier est validé mais ne contient aucun document actif.'],
        'document_rejete' => [20, 'Document rejeté', 'Au moins une pièce du dossier a été rejetée lors de la vérification.'],
        'auto_validation' => [15, 'Validée par son auteur', 'La même personne a créé et validé le dossier (séparation des tâches non respectée).'],
        'limite_apres_validation' => [15, 'Limites modifiées après validation', 'Les limites ont été changées après la validation du dossier.'],
        'quotes_parts' => [10, 'Quotes-parts incomplètes', 'Les quotes-parts documentées des copropriétaires ne totalisent pas 100 %.'],
        'fiches_repetees' => [10, 'Émissions de fiches répétées', 'Plus de 5 fiches PDF émises en 30 jours pour cette parcelle.'],
    ];

    /**
     * Calcule les risques des parcelles non archivées.
     * @return array<int, array{score:int, level:string, reasons:string[]}> indexé par id de parcelle
     */
    public static function compute(?array $parcelIds = null): array
    {
        $hits = [];
        $add = static function (string $rule, array $ids) use (&$hits): void {
            foreach ($ids as $id) {
                $hits[(int) $id][$rule] = true;
            }
        };
        $col = static fn (string $sql, array $p = []) => array_column(db_all($sql, $p), 'id');
        $live = 'p.is_archived = 0';

        $add('double_vente', $col("SELECT m1.parcel_id AS id FROM mutations m1 JOIN mutations m2 ON m2.parcel_id = m1.parcel_id AND m2.id > m1.id
            AND m1.mutation_type = 'vente' AND m2.mutation_type = 'vente' AND ABS(DATEDIFF(m2.mutation_date, m1.mutation_date)) <= 365
            JOIN parcels p ON p.id = m1.parcel_id WHERE $live GROUP BY m1.parcel_id"));
        foreach (['doublon' => "o.overlap_type = 'doublon_probable'", 'chevauchement' => "o.overlap_type = 'chevauchement'"] as $rule => $cond) {
            $add($rule, $col("SELECT p.id FROM parcels p JOIN parcel_overlaps o ON (o.parcel_a_id = p.id OR o.parcel_b_id = p.id)
                WHERE $live AND o.status = 'a_verifier' AND $cond GROUP BY p.id"));
        }
        // Chevauchement entre parcelles dont les propriétaires actuels sont tous différents
        $pairs = db_all("SELECT o.parcel_a_id, o.parcel_b_id FROM parcel_overlaps o WHERE o.status = 'a_verifier'");
        foreach ($pairs as $pr) {
            $oa = array_column(db_all('SELECT owner_id FROM parcel_owners WHERE parcel_id = ? AND valid_to IS NULL', [$pr['parcel_a_id']]), 'owner_id');
            $ob = array_column(db_all('SELECT owner_id FROM parcel_owners WHERE parcel_id = ? AND valid_to IS NULL', [$pr['parcel_b_id']]), 'owner_id');
            if ($oa && $ob && !array_intersect($oa, $ob)) {
                $add('conflit_proprietaires', [$pr['parcel_a_id'], $pr['parcel_b_id']]);
            }
        }
        $add('conteste', $col("SELECT p.id FROM parcels p JOIN parcel_owners po ON po.parcel_id = p.id AND po.valid_to IS NULL
            JOIN owners o ON o.id = po.owner_id WHERE $live AND (po.verification_status = 'conteste' OR o.info_status = 'conteste') GROUP BY p.id"));
        $add('titre_sans_reference', $col("SELECT p.id FROM parcels p WHERE $live AND p.declared_status = 'titre_foncier'
            AND (p.title_reference IS NULL OR p.title_reference = '')"));
        $add('titre_indicatif', $col("SELECT p.id FROM parcels p WHERE $live AND p.declared_status IN ('titre_foncier', 'permis_urbain')
            AND p.survey_level = 'indicative'"));
        $add('verifiee_sans_document', $col("SELECT p.id FROM parcels p WHERE $live AND p.verification_status = 'verifiee'
            AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.parcel_id = p.id AND d.is_archived = 0)"));
        $add('document_rejete', $col("SELECT p.id FROM parcels p JOIN documents d ON d.parcel_id = p.id AND d.is_archived = 0
            WHERE $live AND d.verification_status = 'rejete' GROUP BY p.id"));
        $add('auto_validation', $col("SELECT p.id FROM parcels p WHERE $live AND p.verification_status = 'verifiee'
            AND p.verified_by IS NOT NULL AND p.verified_by = p.created_by"));
        $add('limite_apres_validation', $col("SELECT p.id FROM parcels p JOIN parcel_history h ON h.parcel_id = p.id AND h.action_type = 'geometrie'
            WHERE $live AND p.verified_at IS NOT NULL AND h.created_at > p.verified_at GROUP BY p.id"));
        $add('quotes_parts', $col("SELECT p.id FROM parcels p JOIN parcel_owners po ON po.parcel_id = p.id AND po.valid_to IS NULL
            WHERE $live AND po.share_percentage IS NOT NULL GROUP BY p.id HAVING ABS(SUM(po.share_percentage) - 100) > 0.5"));
        $add('fiches_repetees', $col("SELECT p.id FROM parcels p JOIN issued_documents d ON d.parcel_id = p.id
            WHERE $live AND d.issued_at >= ? GROUP BY p.id HAVING COUNT(*) > 5", [date('Y-m-d H:i:s', time() - 30 * 86400)]));

        $out = [];
        foreach ($hits as $id => $rules) {
            if ($parcelIds !== null && !in_array($id, $parcelIds, true)) {
                continue;
            }
            $score = min(100, array_sum(array_map(static fn ($r) => self::RULES[$r][0], array_keys($rules))));
            $out[$id] = ['score' => $score, 'level' => self::level($score), 'reasons' => array_keys($rules)];
        }
        uasort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    public static function level(int $score): string
    {
        return $score >= 60 ? 'eleve' : ($score >= 30 ? 'moyen' : 'faible');
    }

    public static function forParcel(int $id): ?array
    {
        return self::compute([$id])[$id] ?? null;
    }

    /** Dossiers en attente de vérification depuis plus de N jours. */
    public static function overdue(int $days): array
    {
        return db_all("SELECT p.id, p.parcel_reference, p.verification_status, a.name AS area_name, u.full_name AS agent,
                              COALESCE((SELECT MAX(h.created_at) FROM parcel_history h WHERE h.parcel_id = p.id AND h.action_type = 'statut'), p.created_at) AS since
                       FROM parcels p LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id LEFT JOIN users u ON u.id = p.created_by
                       WHERE p.is_archived = 0 AND p.verification_status IN ('soumise', 'en_verification')
                       HAVING since < ? ORDER BY since", [date('Y-m-d H:i:s', time() - $days * 86400)]);
    }
}
