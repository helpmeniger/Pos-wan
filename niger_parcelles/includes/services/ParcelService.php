<?php
/**
 * Logique métier des parcelles : validation, création avec référence unique,
 * versionnage des géométries, détection des chevauchements, statuts,
 * historique. Utilisée par les pages, les API et l'importation.
 */
declare(strict_types=1);

final class ParcelService
{
    /** Champs descriptifs modifiables (hors géométrie et statut). */
    public const FIELDS = [
        'cadastral_reference'  => ['str', 80],
        'administrative_area_id' => ['area', 0],
        'locality_description' => ['str', 255],
        'address'              => ['str', 255],
        'category'             => ['enum', 'category'],
        'survey_level'         => ['enum', 'survey_level'],
        'declared_status'      => ['enum', 'declared_status'],
        'title_reference'      => ['str', 80],
        'tenure_document_type' => ['str', 100],
        'tenure_document_date' => ['date', 0],
        'issuing_authority'    => ['str', 150],
        'restrictions'         => ['text', 2000],
        'observations'         => ['text', 2000],
        'description'          => ['text', 2000],
    ];

    /**
     * Nettoie et valide les attributs fournis. Seules les clés présentes dans
     * $in sont retournées (mise à jour partielle).
     */
    public static function sanitizeAttributes(array $in): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [$type, $opt]) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $v = $in[$field];
            if (is_array($v)) {
                throw new UserFacingException("Valeur invalide pour « $field ».");
            }
            $v = $v === null ? null : trim((string) $v);
            if ($v === '') {
                $v = null;
            }
            switch ($type) {
                case 'str':
                case 'text':
                    $out[$field] = $v === null ? null : mb_substr($v, 0, (int) $opt);
                    break;
                case 'enum':
                    if ($v === null) {
                        break; // valeur par défaut de la base
                    }
                    if (!array_key_exists($v, labels((string) $opt))) {
                        throw new UserFacingException("Valeur non autorisée pour « $field ».");
                    }
                    $out[$field] = $v;
                    break;
                case 'date':
                    if ($v !== null) {
                        $d = DateTime::createFromFormat('!Y-m-d', $v);
                        if (!$d || $d->format('Y-m-d') !== $v) {
                            throw new UserFacingException("Date invalide pour « $field » (AAAA-MM-JJ).");
                        }
                    }
                    $out[$field] = $v;
                    break;
                case 'area':
                    if ($v === null) {
                        $out[$field] = null;
                        break;
                    }
                    $id = filter_var($v, FILTER_VALIDATE_INT);
                    if ($id === false || !db_value('SELECT 1 FROM administrative_areas WHERE id = ?', [$id])) {
                        throw new UserFacingException('Zone administrative inconnue.');
                    }
                    $out[$field] = (int) $id;
                    break;
            }
        }
        return $out;
    }

    /**
     * Normalise et valide une géométrie. Retourne [anneaux, mesures].
     * @throws UserFacingException avec la liste des erreurs
     */
    public static function prepareGeometry($geojson): array
    {
        try {
            $rings = Geo::normalizePolygon($geojson);
        } catch (InvalidArgumentException $e) {
            throw new UserFacingException($e->getMessage(), 422, ['errors' => [$e->getMessage()]]);
        }
        $errors = Geo::validate($rings, geo_bounds(), (float) setting('min_parcel_area_m2', 1), (float) setting('max_parcel_area_m2', 5e7));
        if ($errors) {
            throw new UserFacingException('Géométrie invalide : ' . implode(' ', $errors), 422, ['errors' => $errors]);
        }
        return [$rings, Geo::measure($rings)];
    }

    /** Génère la prochaine référence unique (à appeler dans une transaction). */
    public static function nextReference(?int $areaId, ?int $year = null): string
    {
        $year = $year ?? (int) date('Y');
        // Incrément atomique : verrou de ligne jusqu'à la fin de la transaction
        db_query('INSERT INTO parcel_reference_sequences (year, seq_value) VALUES (?, LAST_INSERT_ID(1))
                  ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1)', [$year]);
        $n = (int) db_value('SELECT LAST_INSERT_ID()');
        $code = self::locationCode($areaId);
        return $code !== null
            ? sprintf('NIG-%s-%04d-%06d', $code, $year, $n)
            : sprintf('NIG-%04d-%06d', $year, $n);
    }

    /** Code de localisation configuré (zone ou ancêtre le plus proche, hors pays). */
    public static function locationCode(?int $areaId): ?string
    {
        foreach (admin_area_chain($areaId) as $a) {
            if ($a['area_type'] !== 'pays' && $a['code'] !== null && preg_match('/^[A-Z]{2,5}$/', (string) $a['code'])) {
                return (string) $a['code'];
            }
        }
        return null;
    }

    /**
     * Crée une parcelle et sa première géométrie.
     *
     * @param bool $acknowledge true si l'utilisateur a confirmé malgré les
     *                          avertissements (chevauchements, doublons)
     * @return array{id:int, parcel_reference:string, overlaps:array, warnings:array}
     */
    public static function create(array $attrs, $geojson, int $userId, bool $acknowledge = false,
                                  string $source = 'dessin', ?string $sourceCrs = null, bool $isDemo = false): array
    {
        $data = self::sanitizeAttributes($attrs);
        [$rings, $m] = self::prepareGeometry($geojson);

        $overlaps = self::findOverlaps($rings, null);
        $warnings = self::warningsFor($overlaps, $data['cadastral_reference'] ?? null, null);
        if ($warnings && !$acknowledge) {
            throw new UserFacingException('Des avertissements doivent être confirmés avant l\'enregistrement.', 409,
                ['warnings' => $warnings, 'overlaps' => $overlaps]);
        }

        return db_transaction(static function () use ($data, $rings, $m, $userId, $overlaps, $warnings, $source, $sourceCrs, $isDemo) {
            $ref = self::nextReference($data['administrative_area_id'] ?? null);
            $cols = array_merge($data, [
                'parcel_reference' => $ref,
                'area_m2' => $m['area_m2'], 'perimeter_m' => $m['perimeter_m'],
                'centroid_lat' => $m['centroid_lat'], 'centroid_lng' => $m['centroid_lng'],
                'min_lat' => $m['min_lat'], 'max_lat' => $m['max_lat'], 'min_lng' => $m['min_lng'], 'max_lng' => $m['max_lng'],
                'current_geometry_version' => 1, 'verification_status' => 'brouillon',
                'has_overlap_warning' => $overlaps ? 1 : 0, 'is_demo' => $isDemo ? 1 : 0,
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $names = array_keys($cols);
            db_query('INSERT INTO parcels (' . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')',
                array_values($cols));
            $id = (int) db()->lastInsertId();

            self::insertGeometry($id, 1, $rings, $m, $userId, $source, $sourceCrs, 'Création');
            self::recordOverlaps($id, $overlaps);
            self::history($id, $userId, $source === 'import' ? 'import' : 'creation', null,
                ['parcel_reference' => $ref] + $data + ['area_m2' => $m['area_m2'], 'perimeter_m' => $m['perimeter_m'],
                 'geometrie' => Geo::toGeoJson($rings)]);
            if ($overlaps) {
                self::history($id, $userId, 'chevauchement', null, ['parcelles' => array_column($overlaps, 'parcel_reference')]);
            }
            audit_log('parcelle_creation', 'parcel', $id, ['reference' => $ref, 'source' => $source]);
            return ['id' => $id, 'parcel_reference' => $ref, 'overlaps' => $overlaps, 'warnings' => $warnings,
                    'area_m2' => $m['area_m2'], 'perimeter_m' => $m['perimeter_m']];
        });
    }

    /** Met à jour les attributs descriptifs (avec historique avant/après). */
    public static function updateAttributes(int $id, array $attrs, int $userId): array
    {
        $parcel = self::getOrFail($id);
        $data = self::sanitizeAttributes($attrs);
        $changes = [];
        $before = [];
        foreach ($data as $k => $v) {
            $old = $parcel[$k];
            if ((string) ($old ?? '') !== (string) ($v ?? '')) {
                $changes[$k] = $v;
                $before[$k] = $old;
            }
        }
        if (!$changes) {
            return ['changed' => false];
        }
        db_transaction(static function () use ($id, $changes, $before, $userId, $parcel) {
            $set = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($changes)));
            db_query("UPDATE parcels SET $set, updated_by = ? WHERE id = ?", array_merge(array_values($changes), [$userId, $id]));
            self::history($id, $userId, 'modification', $before, $changes);
            audit_log('parcelle_modification', 'parcel', $id, ['reference' => $parcel['parcel_reference'], 'champs' => array_keys($changes)]);
        });
        return ['changed' => true, 'fields' => array_keys($changes)];
    }

    /** Enregistre une nouvelle version de la géométrie (l'ancienne est conservée). */
    public static function updateGeometry(int $id, $geojson, int $userId, ?string $reason = null, bool $acknowledge = false,
                                          string $source = 'dessin'): array
    {
        $parcel = self::getOrFail($id);
        [$rings, $m] = self::prepareGeometry($geojson);
        $overlaps = self::findOverlaps($rings, $id);
        $warnings = self::warningsFor($overlaps, null, $id);
        if ($warnings && !$acknowledge) {
            throw new UserFacingException('Des avertissements doivent être confirmés avant l\'enregistrement.', 409,
                ['warnings' => $warnings, 'overlaps' => $overlaps]);
        }
        $old = self::currentGeometry($id);
        return db_transaction(static function () use ($id, $parcel, $rings, $m, $userId, $reason, $overlaps, $old, $source) {
            $version = (int) db_value('SELECT COALESCE(MAX(geometry_version), 0) + 1 FROM parcel_geometries WHERE parcel_id = ?', [$id]);
            self::insertGeometry($id, $version, $rings, $m, $userId, $source, null, $reason);
            db_query('UPDATE parcels SET area_m2 = ?, perimeter_m = ?, centroid_lat = ?, centroid_lng = ?, min_lat = ?, max_lat = ?,
                      min_lng = ?, max_lng = ?, current_geometry_version = ?, updated_by = ? WHERE id = ?',
                [$m['area_m2'], $m['perimeter_m'], $m['centroid_lat'], $m['centroid_lng'], $m['min_lat'], $m['max_lat'],
                 $m['min_lng'], $m['max_lng'], $version, $userId, $id]);
            self::recordOverlaps($id, $overlaps);
            self::history($id, $userId, 'geometrie',
                ['version' => (int) $parcel['current_geometry_version'], 'area_m2' => (float) $parcel['area_m2'],
                 'perimeter_m' => (float) $parcel['perimeter_m'], 'geometrie' => $old],
                ['version' => $version, 'area_m2' => $m['area_m2'], 'perimeter_m' => $m['perimeter_m'],
                 'motif' => $reason, 'geometrie' => Geo::toGeoJson($rings)]);
            audit_log('parcelle_geometrie', 'parcel', $id, ['reference' => $parcel['parcel_reference'], 'version' => $version]);
            return ['version' => $version, 'overlaps' => $overlaps, 'area_m2' => $m['area_m2'], 'perimeter_m' => $m['perimeter_m']];
        });
    }

    /** Change le statut de vérification après contrôle des transitions autorisées. */
    public static function changeStatus(int $id, string $status, int $userId, ?string $comment = null): void
    {
        $parcel = self::getOrFail($id);
        if (!in_array($status, allowed_status_transitions($parcel), true)) {
            throw new UserFacingException('Ce changement de statut n\'est pas autorisé pour votre rôle ou pour l\'état actuel du dossier.', 403);
        }
        db_transaction(static function () use ($id, $parcel, $status, $userId, $comment) {
            if ($status === 'verifiee') {
                db_query('UPDATE parcels SET verification_status = ?, verified_by = ?, verified_at = NOW(), updated_by = ? WHERE id = ?',
                    [$status, $userId, $userId, $id]);
            } else {
                db_query('UPDATE parcels SET verification_status = ?, updated_by = ? WHERE id = ?', [$status, $userId, $id]);
            }
            self::history($id, $userId, 'statut', ['verification_status' => $parcel['verification_status']],
                ['verification_status' => $status, 'commentaire' => $comment]);
            audit_log('parcelle_statut', 'parcel', $id, ['de' => $parcel['verification_status'], 'vers' => $status]);
        });
    }

    /** Archive une parcelle (jamais supprimée : la référence reste réservée). */
    public static function archive(int $id, int $userId, string $reason): void
    {
        $parcel = self::getOrFail($id);
        db_transaction(static function () use ($id, $userId, $reason, $parcel) {
            db_query('UPDATE parcels SET is_archived = 1, updated_by = ? WHERE id = ?', [$userId, $id]);
            self::history($id, $userId, 'archivage', ['is_archived' => (int) $parcel['is_archived']], ['is_archived' => 1, 'motif' => $reason]);
            audit_log('parcelle_archivage', 'parcel', $id, ['motif' => $reason]);
        });
    }

    // ------------------------------------------------------------------
    // Chevauchements
    // ------------------------------------------------------------------

    /**
     * Recherche les parcelles dont la géométrie chevauche $rings.
     * Pré-filtre SQL par boîte englobante (index), puis test géométrique exact.
     */
    public static function findOverlaps(array $rings, ?int $excludeId): array
    {
        $b = Geo::bbox($rings);
        $sql = 'SELECT p.id, p.parcel_reference, g.geojson FROM parcels p
                JOIN parcel_geometries g ON g.parcel_id = p.id AND g.geometry_version = p.current_geometry_version
                WHERE p.is_archived = 0 AND p.min_lat <= ? AND p.max_lat >= ? AND p.min_lng <= ? AND p.max_lng >= ?';
        $params = [$b['max_lat'], $b['min_lat'], $b['max_lng'], $b['min_lng']];
        if ($excludeId) {
            $sql .= ' AND p.id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 500';
        $tol = (float) setting('overlap_tolerance_m', 0.10);
        $found = [];
        foreach (db_all($sql, $params) as $row) {
            $other = json_decode($row['geojson'], true)['coordinates'] ?? null;
            if (!$other) {
                continue;
            }
            $rel = Geo::compare($rings, $other, $tol);
            if ($rel !== 'none') {
                $found[] = ['parcel_id' => (int) $row['id'], 'parcel_reference' => $row['parcel_reference'],
                            'type' => $rel === 'duplicate' ? 'doublon_probable' : 'chevauchement'];
            }
        }
        return $found;
    }

    private static function warningsFor(array $overlaps, ?string $cadastral, ?int $excludeId): array
    {
        $w = [];
        foreach ($overlaps as $o) {
            $w[] = $o['type'] === 'doublon_probable'
                ? "Doublon probable : la géométrie est quasi identique à celle de la parcelle {$o['parcel_reference']}."
                : "Chevauchement potentiel avec la parcelle {$o['parcel_reference']} (copropriété, servitude ou erreur de délimitation à vérifier).";
        }
        if ($cadastral) {
            $dup = db_value('SELECT parcel_reference FROM parcels WHERE cadastral_reference = ? AND id <> ? LIMIT 1', [$cadastral, $excludeId ?? 0]);
            if ($dup) {
                $w[] = "La référence cadastrale « $cadastral » est déjà associée à la parcelle $dup.";
            }
        }
        return $w;
    }

    /** Enregistre les chevauchements détectés et met à jour les indicateurs. */
    public static function recordOverlaps(int $parcelId, array $overlaps): void
    {
        $currentIds = [];
        foreach ($overlaps as $o) {
            $a = min($parcelId, $o['parcel_id']);
            $b = max($parcelId, $o['parcel_id']);
            $currentIds[] = $o['parcel_id'];
            db_query("INSERT INTO parcel_overlaps (parcel_a_id, parcel_b_id, overlap_type, status) VALUES (?, ?, ?, 'a_verifier')
                      ON DUPLICATE KEY UPDATE overlap_type = VALUES(overlap_type),
                        status = IF(status = 'corrige', 'a_verifier', status), detected_at = IF(status = 'corrige', NOW(), detected_at)",
                [$a, $b, $o['type']]);
        }
        // Les paires qui ne se chevauchent plus sont marquées corrigées
        $stale = db_all("SELECT id, parcel_a_id, parcel_b_id FROM parcel_overlaps WHERE status = 'a_verifier' AND (parcel_a_id = ? OR parcel_b_id = ?)",
            [$parcelId, $parcelId]);
        $touched = [$parcelId];
        foreach ($stale as $s) {
            $other = (int) $s['parcel_a_id'] === $parcelId ? (int) $s['parcel_b_id'] : (int) $s['parcel_a_id'];
            if (!in_array($other, $currentIds, true)) {
                db_query("UPDATE parcel_overlaps SET status = 'corrige', resolved_at = NOW(), notes = 'Géométrie modifiée : plus de chevauchement' WHERE id = ?", [$s['id']]);
            }
            $touched[] = $other;
        }
        foreach (array_unique(array_merge($touched, $currentIds)) as $pid) {
            self::refreshOverlapFlag((int) $pid);
        }
    }

    public static function refreshOverlapFlag(int $parcelId): void
    {
        db_query("UPDATE parcels SET has_overlap_warning = EXISTS(SELECT 1 FROM parcel_overlaps o
                  WHERE o.status = 'a_verifier' AND (o.parcel_a_id = ? OR o.parcel_b_id = ?)) WHERE id = ?",
            [$parcelId, $parcelId, $parcelId]);
    }

    /** Décision sur un chevauchement (légitime, corrigé, à vérifier). */
    public static function resolveOverlap(int $overlapId, string $status, ?string $notes, int $userId): void
    {
        if (!array_key_exists($status, labels('overlap_status'))) {
            throw new UserFacingException('Statut de chevauchement invalide.');
        }
        $o = db_one('SELECT * FROM parcel_overlaps WHERE id = ?', [$overlapId]);
        if (!$o) {
            throw new UserFacingException('Chevauchement introuvable.', 404);
        }
        db_transaction(static function () use ($o, $status, $notes, $userId) {
            db_query('UPDATE parcel_overlaps SET status = ?, notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?',
                [$status, $notes ? mb_substr($notes, 0, 255) : null, $userId, $o['id']]);
            foreach ([(int) $o['parcel_a_id'], (int) $o['parcel_b_id']] as $pid) {
                self::refreshOverlapFlag($pid);
                self::history($pid, $userId, 'chevauchement_statut', ['status' => $o['status']], ['status' => $status, 'notes' => $notes,
                    'paire' => [(int) $o['parcel_a_id'], (int) $o['parcel_b_id']]]);
            }
            audit_log('chevauchement_decision', 'overlap', (int) $o['id'], ['status' => $status]);
        });
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    public static function get(int $id): ?array
    {
        return db_one('SELECT p.*, a.name AS area_name, a.area_type,
                              uc.full_name AS created_by_name, uv.full_name AS verified_by_name
                       FROM parcels p
                       LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id
                       LEFT JOIN users uc ON uc.id = p.created_by
                       LEFT JOIN users uv ON uv.id = p.verified_by
                       WHERE p.id = ?', [$id]);
    }

    public static function getOrFail(int $id): array
    {
        $p = self::get($id);
        if (!$p) {
            throw new UserFacingException('Parcelle introuvable.', 404);
        }
        return $p;
    }

    public static function currentGeometry(int $id): ?array
    {
        $g = db_value('SELECT g.geojson FROM parcel_geometries g JOIN parcels p ON p.id = g.parcel_id
                       AND g.geometry_version = p.current_geometry_version WHERE p.id = ?', [$id]);
        return $g ? json_decode((string) $g, true) : null;
    }

    public static function geometryVersions(int $id): array
    {
        return db_all('SELECT g.id, g.geometry_version, g.area_m2, g.perimeter_m, g.vertex_count, g.source, g.source_crs,
                              g.change_reason, g.created_at, u.full_name AS created_by_name, g.geojson
                       FROM parcel_geometries g LEFT JOIN users u ON u.id = g.created_by
                       WHERE g.parcel_id = ? ORDER BY g.geometry_version DESC', [$id]);
    }

    public static function historyEntries(int $id): array
    {
        return db_all('SELECT h.*, u.full_name AS user_name FROM parcel_history h LEFT JOIN users u ON u.id = h.user_id
                       WHERE h.parcel_id = ? ORDER BY h.created_at DESC, h.id DESC', [$id]);
    }

    public static function overlapsOf(int $id): array
    {
        return db_all('SELECT o.*, p.id AS other_id, p.parcel_reference AS other_reference, u.full_name AS resolved_by_name
                       FROM parcel_overlaps o
                       JOIN parcels p ON p.id = IF(o.parcel_a_id = ?, o.parcel_b_id, o.parcel_a_id)
                       LEFT JOIN users u ON u.id = o.resolved_by
                       WHERE o.parcel_a_id = ? OR o.parcel_b_id = ? ORDER BY o.detected_at DESC', [$id, $id, $id]);
    }

    /** Ajoute une entrée d'historique (JSON avant / après). */
    public static function history(int $parcelId, ?int $userId, string $action, $previous, $new): void
    {
        $enc = static fn ($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        db_query('INSERT INTO parcel_history (parcel_id, user_id, action_type, previous_data, new_data) VALUES (?, ?, ?, ?, ?)',
            [$parcelId, $userId, $action, $enc($previous), $enc($new)]);
    }

    private static function insertGeometry(int $parcelId, int $version, array $rings, array $m, int $userId,
                                           string $source, ?string $sourceCrs, ?string $reason): void
    {
        if (!in_array($source, ['dessin', 'import', 'gps', 'autre'], true)) {
            $source = 'autre';
        }
        db_query('INSERT INTO parcel_geometries (parcel_id, geojson, geometry_version, vertex_count, area_m2, perimeter_m,
                  source, source_crs, change_reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $parcelId, json_encode(Geo::toGeoJson($rings)), $version, $m['vertex_count'], $m['area_m2'], $m['perimeter_m'],
            $source, $sourceCrs, $reason ? mb_substr($reason, 0, 255) : null, $userId,
        ]);
    }
}
