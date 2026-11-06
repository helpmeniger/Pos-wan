<?php
/**
 * Fiches authentifiables : chaque fiche PDF émise est enregistrée avec un
 * jeton aléatoire (128 bits) imprimé en QR code, une empreinte SHA-256 de son
 * contenu et une signature HMAC (clé secrète du serveur, storage/secret.key).
 *
 * La page publique verifier.php indique, sans aucune donnée personnelle :
 *  - si la fiche a bien été émise par l'application (sinon : fausse fiche) ;
 *  - si elle a été révoquée ;
 *  - si la situation a changé depuis (limites, propriétaires, statut,
 *    archivage, fiche plus récente) : une fiche périmée ne doit plus servir.
 */
declare(strict_types=1);

final class IssuedDocumentService
{
    /** Faits enregistrés au moment de l'émission (données non personnelles + empreintes). */
    public static function parcelSnapshot(int $parcelId): array
    {
        $p = ParcelService::getOrFail($parcelId);
        $a = admin_area_breakdown($p['administrative_area_id'] ? (int) $p['administrative_area_id'] : null);
        $geom = ParcelService::currentGeometry($parcelId);
        $owners = db_all('SELECT id, owner_id, ownership_role, share_percentage, verification_status FROM parcel_owners
                          WHERE parcel_id = ? AND valid_to IS NULL ORDER BY id', [$parcelId]);
        return [
            'reference' => $p['parcel_reference'],
            'cadastral_reference' => $p['cadastral_reference'],
            'region' => $a['region'], 'commune' => $a['commune'], 'localite' => $a['quartier'] ?? $a['village'],
            'area_m2' => (float) $p['area_m2'],
            'perimeter_m' => (float) $p['perimeter_m'],
            'centroid' => [(float) $p['centroid_lat'], (float) $p['centroid_lng']],
            'geometry_version' => (int) $p['current_geometry_version'],
            'geometry_sha256' => hash('sha256', json_encode($geom)),
            'verification_status' => $p['verification_status'],
            'survey_level' => $p['survey_level'],
            'declared_status' => $p['declared_status'],
            'title_reference' => $p['title_reference'],
            'owner_count' => count($owners),
            'owners_sha256' => hash('sha256', json_encode($owners)),
            'is_demo' => (int) $p['is_demo'] === 1,
        ];
    }

    private static function sign(string $token, string $hash): string
    {
        return hash_hmac('sha256', $token . '|' . $hash, MigrationService::secretKey());
    }

    /** Enregistre une fiche émise ; retourne [token, code court, URL de vérification]. */
    public static function issueParcelSheet(int $parcelId, ?int $userId): array
    {
        $snap = self::parcelSnapshot($parcelId);
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $token = bin2hex(random_bytes(16));
        db_query('INSERT INTO issued_documents (token, doc_type, parcel_id, snapshot, snapshot_hash, signature, issued_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?)', [$token, 'fiche_parcelle', $parcelId, $json, $hash, self::sign($token, $hash), $userId]);
        $id = (int) db()->lastInsertId();
        audit_log('fiche_emise', 'issued_document', $id, ['parcel_id' => $parcelId]);
        return ['id' => $id, 'token' => $token, 'code' => self::shortCode($token), 'url' => self::verifyUrl($token)];
    }

    /** Code court lisible imprimé sous le QR code (12 premiers caractères du jeton). */
    public static function shortCode(string $token): string
    {
        return strtoupper(implode('-', str_split(substr($token, 0, 12), 4)));
    }

    public static function verifyUrl(string $token): string
    {
        return public_url('verifier.php', ['t' => $token]);
    }

    /** Retrouve un document par jeton complet ou par code court. */
    public static function find(string $input): ?array
    {
        if (preg_match('/[?&]t=([0-9a-fA-F]{32})/', $input, $m)) {
            $input = $m[1]; // adresse complète collée
        }
        $t = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $input) ?? '');
        if (strlen($t) === 32) {
            return db_one('SELECT * FROM issued_documents WHERE token = ?', [$t]);
        }
        if (strlen($t) === 12) {
            $rows = db_all('SELECT * FROM issued_documents WHERE token LIKE ? LIMIT 2', [$t . '%']);
            return count($rows) === 1 ? $rows[0] : null;
        }
        return null;
    }

    /**
     * Vérifie un document. Retourne :
     *  state : 'authentique' | 'perimee' | 'revoquee' | 'falsifiee' | 'inconnue'
     *  facts : faits non personnels à afficher ; changes : écarts avec la situation actuelle
     */
    public static function verify(string $input): array
    {
        $doc = self::find($input);
        if (!$doc) {
            return ['state' => 'inconnue', 'changes' => [], 'facts' => null, 'doc' => null];
        }
        $integrity = hash_equals(self::sign($doc['token'], $doc['snapshot_hash']), $doc['signature'])
            && hash_equals(hash('sha256', $doc['snapshot']), $doc['snapshot_hash']);
        db_query('UPDATE issued_documents SET verify_count = verify_count + 1, last_verified_at = NOW() WHERE id = ?', [$doc['id']]);
        if (!$integrity) {
            audit_log('verification_falsifiee', 'issued_document', (int) $doc['id']);
            return ['state' => 'falsifiee', 'changes' => [], 'facts' => null, 'doc' => $doc];
        }
        $facts = json_decode($doc['snapshot'], true);
        if ($doc['revoked_at']) {
            return ['state' => 'revoquee', 'changes' => [], 'facts' => $facts, 'doc' => $doc];
        }
        $changes = [];
        $p = $doc['parcel_id'] ? ParcelService::get((int) $doc['parcel_id']) : null;
        if (!$p) {
            $changes[] = 'La parcelle n\'existe plus dans le registre.';
        } else {
            $now = self::parcelSnapshot((int) $doc['parcel_id']);
            if ((int) $p['is_archived'] === 1) {
                $changes[] = 'La parcelle a été archivée.';
            }
            if ($now['geometry_sha256'] !== $facts['geometry_sha256']) {
                $changes[] = 'Les limites de la parcelle ont été modifiées (version ' . $facts['geometry_version'] . ' → ' . $now['geometry_version'] . ').';
            } elseif (abs($now['area_m2'] - $facts['area_m2']) >= 0.01) {
                $changes[] = 'La superficie calculée a été mise à jour (' . fmt_number($facts['area_m2'], 2) . ' → ' . fmt_number($now['area_m2'], 2) . ' m²).';
            }
            if ($now['owners_sha256'] !== $facts['owners_sha256']) {
                $changes[] = 'La situation des propriétaires a changé (mutation, ajout ou fin de droit, statut).';
            }
            if ($now['verification_status'] !== $facts['verification_status']) {
                $changes[] = 'Statut de vérification : « ' . label('verification_status', $facts['verification_status']) . ' » → « '
                    . label('verification_status', $now['verification_status']) . ' ».';
            }
            if ($now['declared_status'] !== $facts['declared_status'] || $now['title_reference'] !== $facts['title_reference']
                || $now['cadastral_reference'] !== $facts['cadastral_reference']) {
                $changes[] = 'Les informations foncières (titre, référence cadastrale, situation déclarée) ont changé.';
            }
            $newer = db_value("SELECT MAX(issued_at) FROM issued_documents WHERE parcel_id = ? AND id > ? AND revoked_at IS NULL", [$doc['parcel_id'], $doc['id']]);
            if ($newer && !$changes) {
                $changes[] = 'Une fiche plus récente a été émise le ' . fmt_date((string) $newer, true) . ' (le contenu est identique).';
            }
        }
        $state = $changes && !(count($changes) === 1 && strpos($changes[0], 'contenu est identique') !== false) ? 'perimee' : 'authentique';
        return ['state' => $state, 'changes' => $changes, 'facts' => $facts, 'doc' => $doc];
    }

    public static function revoke(int $id, int $userId, string $reason): void
    {
        $doc = db_one('SELECT * FROM issued_documents WHERE id = ?', [$id]);
        if (!$doc) {
            throw new UserFacingException('Fiche introuvable.', 404);
        }
        if ($doc['revoked_at']) {
            throw new UserFacingException('Cette fiche est déjà révoquée.');
        }
        db_query('UPDATE issued_documents SET revoked_at = NOW(), revoked_by = ?, revoke_reason = ? WHERE id = ?', [$userId, mb_substr($reason, 0, 255), $id]);
        if ($doc['parcel_id']) {
            ParcelService::history((int) $doc['parcel_id'], $userId, 'fiche_revoquee', null, ['fiche' => self::shortCode($doc['token']), 'motif' => $reason]);
        }
        audit_log('fiche_revoquee', 'issued_document', $id, ['motif' => $reason]);
    }

    public static function forParcel(int $parcelId): array
    {
        return db_all('SELECT d.*, u.full_name AS issued_by_name, r.full_name AS revoked_by_name FROM issued_documents d
                       LEFT JOIN users u ON u.id = d.issued_by LEFT JOIN users r ON r.id = d.revoked_by
                       WHERE d.parcel_id = ? ORDER BY d.id DESC', [$parcelId]);
    }
}
