<?php
/**
 * Propriétaires et liens parcelle <-> propriétaire : création, doublons,
 * copropriété, mutations (avec conservation intégrale de l'historique).
 *
 * Rappel : un nom enregistré ne constitue jamais une preuve juridique de
 * propriété ; les statuts (déclaré, documenté, vérifié, contesté) l'indiquent.
 */
declare(strict_types=1);

final class OwnerService
{
    public const FIELDS = [
        'owner_type' => ['enum', 'owner_type'],
        'first_name' => ['str', 100],
        'last_name' => ['str', 100],
        'organization_name' => ['str', 200],
        'id_document_type' => ['str', 60],
        'id_document_number' => ['str', 80],
        'phone' => ['phone', 40],
        'email' => ['email', 190],
        'address' => ['str', 255],
        'file_reference' => ['str', 80],
        'info_status' => ['enum', 'owner_info_status'],
        'notes' => ['str', 2000],
    ];

    public static function sanitize(array $in, bool $requireName = true): array
    {
        $out = [];
        foreach (self::FIELDS as $f => [$type, $opt]) {
            if (!array_key_exists($f, $in)) {
                continue;
            }
            $v = is_array($in[$f]) ? null : trim((string) ($in[$f] ?? ''));
            $v = $v === '' ? null : $v;
            switch ($type) {
                case 'enum':
                    if ($v !== null && !array_key_exists($v, labels((string) $opt))) {
                        throw new UserFacingException("Valeur non autorisée pour « $f ».");
                    }
                    if ($v !== null) {
                        $out[$f] = $v;
                    }
                    break;
                case 'phone':
                    if ($v !== null && !preg_match('/^[0-9 +().\-]{6,40}$/', $v)) {
                        throw new UserFacingException('Numéro de téléphone invalide (chiffres, espaces, +, - uniquement).');
                    }
                    $out[$f] = $v;
                    break;
                case 'email':
                    if ($v !== null && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        throw new UserFacingException('Adresse e-mail invalide.');
                    }
                    $out[$f] = $v;
                    break;
                default:
                    $out[$f] = $v === null ? null : mb_substr($v, 0, (int) $opt);
            }
        }
        if ($requireName) {
            $type = $out['owner_type'] ?? 'personne_physique';
            if ($type === 'personne_physique' && empty($out['last_name'])) {
                throw new UserFacingException('Le nom de famille est obligatoire pour une personne physique.');
            }
            if ($type !== 'personne_physique' && empty($out['organization_name'])) {
                throw new UserFacingException('La dénomination est obligatoire pour une personne morale, une collectivité ou un groupement.');
            }
        }
        return $out;
    }

    public static function create(array $in, int $userId, bool $isDemo = false): int
    {
        $d = self::sanitize($in);
        $d['created_by'] = $userId;
        $d['is_demo'] = $isDemo ? 1 : 0;
        if (($d['info_status'] ?? '') === 'verifie') {
            $d['last_verified_at'] = date('Y-m-d H:i:s');
            $d['last_verified_by'] = $userId;
        }
        $cols = array_keys($d);
        db_query('INSERT INTO owners (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($d));
        $id = (int) db()->lastInsertId();
        audit_log('proprietaire_creation', 'owner', $id);
        return $id;
    }

    public static function update(int $id, array $in, int $userId): void
    {
        $owner = self::getOrFail($id);
        $merged = array_merge(array_intersect_key($owner, self::FIELDS), $in);
        $d = self::sanitize($merged);
        $d = array_intersect_key($d, $in + ['owner_type' => 1]);
        if (($d['info_status'] ?? null) === 'verifie' && $owner['info_status'] !== 'verifie') {
            $d['last_verified_at'] = date('Y-m-d H:i:s');
            $d['last_verified_by'] = $userId;
        }
        if (!$d) {
            return;
        }
        $changed = [];
        foreach ($d as $k => $v) {
            if ((string) ($owner[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = ['avant' => $owner[$k] ?? null, 'apres' => $v];
            }
        }
        $set = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($d)));
        db_query("UPDATE owners SET $set WHERE id = ?", array_merge(array_values($d), [$id]));
        audit_log('proprietaire_modification', 'owner', $id, $changed);
    }

    public static function setArchived(int $id, bool $archived): void
    {
        self::getOrFail($id);
        db_query('UPDATE owners SET is_archived = ?, archived_at = ? WHERE id = ?', [$archived ? 1 : 0, $archived ? date('Y-m-d H:i:s') : null, $id]);
        audit_log($archived ? 'proprietaire_archivage' : 'proprietaire_desarchivage', 'owner', $id);
    }

    public static function get(int $id): ?array
    {
        return db_one('SELECT o.*, u.full_name AS verified_by_name FROM owners o LEFT JOIN users u ON u.id = o.last_verified_by WHERE o.id = ?', [$id]);
    }

    public static function getOrFail(int $id): array
    {
        $o = self::get($id);
        if (!$o) {
            throw new UserFacingException('Propriétaire introuvable.', 404);
        }
        return $o;
    }

    /** Doublons potentiels : même téléphone, même pièce d'identité ou même nom complet. */
    public static function findDuplicates(array $d, ?int $excludeId = null): array
    {
        $conds = [];
        $params = [];
        if (!empty($d['phone'])) {
            $digits = preg_replace('/\D/', '', (string) $d['phone']);
            if (strlen($digits) >= 6) {
                $conds[] = "REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?";
                $params[] = '%' . substr($digits, -8);
            }
        }
        if (!empty($d['id_document_number'])) {
            $conds[] = 'id_document_number = ?';
            $params[] = $d['id_document_number'];
        }
        if (!empty($d['last_name'])) {
            $conds[] = '(last_name = ? AND COALESCE(first_name, \'\') = ?)';
            $params[] = $d['last_name'];
            $params[] = (string) ($d['first_name'] ?? '');
        }
        if (!empty($d['organization_name'])) {
            $conds[] = 'organization_name = ?';
            $params[] = $d['organization_name'];
        }
        if (!$conds) {
            return [];
        }
        $sql = 'SELECT id, owner_type, first_name, last_name, organization_name, phone, id_document_number, is_archived
                FROM owners WHERE (' . implode(' OR ', $conds) . ')';
        if ($excludeId) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        return db_all($sql . ' LIMIT 10', $params);
    }

    /** Liste globale des groupes de doublons potentiels (même nom ou même téléphone). */
    public static function duplicateGroups(int $limit = 50): array
    {
        return db_all("SELECT 'nom' AS critere, CONCAT(last_name, ' ', COALESCE(first_name, '')) AS valeur,
                              GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS n
                       FROM owners WHERE last_name IS NOT NULL AND is_archived = 0
                       GROUP BY last_name, first_name HAVING COUNT(*) > 1
                       UNION ALL
                       SELECT 'telephone', phone, GROUP_CONCAT(id ORDER BY id), COUNT(*)
                       FROM owners WHERE phone IS NOT NULL AND phone <> '' AND is_archived = 0
                       GROUP BY phone HAVING COUNT(*) > 1
                       LIMIT " . (int) $limit);
    }

    // ------------------------------------------------------------------
    // Liens parcelle / propriétaire
    // ------------------------------------------------------------------

    public static function sanitizeLink(array $in): array
    {
        $role = (string) ($in['ownership_role'] ?? 'proprietaire');
        if (!array_key_exists($role, labels('ownership_role'))) {
            throw new UserFacingException('Rôle de propriété invalide.');
        }
        $status = (string) ($in['verification_status'] ?? 'declare');
        if (!array_key_exists($status, labels('link_status'))) {
            throw new UserFacingException('Statut du lien invalide.');
        }
        $share = $in['share_percentage'] ?? null;
        if ($share === '' || $share === null) {
            $share = null;
        } else {
            $share = filter_var(str_replace(',', '.', (string) $share), FILTER_VALIDATE_FLOAT);
            if ($share === false || $share <= 0 || $share > 100) {
                throw new UserFacingException('La quote-part doit être comprise entre 0 et 100 %.');
            }
        }
        $from = $in['valid_from'] ?? null;
        if ($from !== null && $from !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', (string) $from);
            if (!$d || $d->format('Y-m-d') !== $from) {
                throw new UserFacingException('Date de début invalide (AAAA-MM-JJ).');
            }
        } else {
            $from = null;
        }
        return [
            'ownership_role' => $role,
            'verification_status' => $status,
            'share_percentage' => $share,
            'valid_from' => $from,
            'document_reference' => isset($in['document_reference']) && trim((string) $in['document_reference']) !== ''
                ? mb_substr(trim((string) $in['document_reference']), 0, 100) : null,
            'notes' => isset($in['notes']) && trim((string) $in['notes']) !== '' ? mb_substr(trim((string) $in['notes']), 0, 255) : null,
        ];
    }

    /** Associe un propriétaire à une parcelle. Retourne l'id du lien. */
    public static function link(int $parcelId, int $ownerId, array $in, int $userId, ?int $mutationId = null): int
    {
        ParcelService::getOrFail($parcelId);
        $owner = self::getOrFail($ownerId);
        if ((int) $owner['is_archived'] === 1) {
            throw new UserFacingException('Ce propriétaire est archivé : désarchivez-le avant de l\'associer.');
        }
        $l = self::sanitizeLink($in);
        $exists = db_value('SELECT id FROM parcel_owners WHERE parcel_id = ? AND owner_id = ? AND valid_to IS NULL', [$parcelId, $ownerId]);
        if ($exists) {
            throw new UserFacingException('Ce propriétaire est déjà associé à la parcelle (lien en cours).');
        }
        if ($l['share_percentage'] !== null) {
            $sum = (float) db_value('SELECT COALESCE(SUM(share_percentage), 0) FROM parcel_owners WHERE parcel_id = ? AND valid_to IS NULL', [$parcelId]);
            if ($sum + $l['share_percentage'] > 100.0001) {
                throw new UserFacingException(sprintf('La somme des quotes-parts dépasserait 100 %% (déjà attribué : %s %%).', fmt_number($sum, 2)));
            }
        }
        return db_transaction(static function () use ($parcelId, $ownerId, $l, $userId, $mutationId, $owner) {
            db_query('INSERT INTO parcel_owners (parcel_id, owner_id, ownership_role, share_percentage, valid_from, verification_status,
                      document_reference, notes, mutation_in_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $parcelId, $ownerId, $l['ownership_role'], $l['share_percentage'], $l['valid_from'], $l['verification_status'],
                $l['document_reference'], $l['notes'], $mutationId, $userId,
            ]);
            $linkId = (int) db()->lastInsertId();
            ParcelService::history($parcelId, $userId, 'proprietaire_ajout', null, ['proprietaire_id' => $ownerId,
                'proprietaire' => owner_display_name($owner)] + $l);
            audit_log('lien_proprietaire_ajout', 'parcel', $parcelId, ['owner_id' => $ownerId, 'link_id' => $linkId]);
            return $linkId;
        });
    }

    /** Clôt un lien (fin de validité) sans le supprimer. */
    public static function endLink(int $linkId, string $validTo, int $userId, ?int $mutationId = null, ?string $reason = null): void
    {
        $link = db_one('SELECT * FROM parcel_owners WHERE id = ?', [$linkId]);
        if (!$link) {
            throw new UserFacingException('Lien introuvable.', 404);
        }
        if ($link['valid_to'] !== null) {
            throw new UserFacingException('Ce lien est déjà clos.');
        }
        $d = DateTime::createFromFormat('!Y-m-d', $validTo);
        if (!$d || $d->format('Y-m-d') !== $validTo) {
            throw new UserFacingException('Date de fin invalide.');
        }
        if ($link['valid_from'] && $validTo < $link['valid_from']) {
            throw new UserFacingException('La date de fin est antérieure à la date de début du lien.');
        }
        db_transaction(static function () use ($link, $validTo, $userId, $mutationId, $reason) {
            db_query('UPDATE parcel_owners SET valid_to = ?, mutation_out_id = ? WHERE id = ?', [$validTo, $mutationId, $link['id']]);
            ParcelService::history((int) $link['parcel_id'], $userId, 'proprietaire_fin', ['lien_id' => (int) $link['id'], 'valid_to' => null],
                ['lien_id' => (int) $link['id'], 'proprietaire_id' => (int) $link['owner_id'], 'valid_to' => $validTo, 'motif' => $reason]);
            audit_log('lien_proprietaire_fin', 'parcel', (int) $link['parcel_id'], ['link_id' => (int) $link['id']]);
        });
    }

    /** Met à jour le statut de vérification d'un lien. */
    public static function setLinkStatus(int $linkId, string $status, int $userId): void
    {
        if (!array_key_exists($status, labels('link_status'))) {
            throw new UserFacingException('Statut invalide.');
        }
        $link = db_one('SELECT * FROM parcel_owners WHERE id = ?', [$linkId]);
        if (!$link) {
            throw new UserFacingException('Lien introuvable.', 404);
        }
        db_query('UPDATE parcel_owners SET verification_status = ? WHERE id = ?', [$status, $linkId]);
        ParcelService::history((int) $link['parcel_id'], $userId, 'proprietaire_modif',
            ['lien_id' => $linkId, 'verification_status' => $link['verification_status']],
            ['lien_id' => $linkId, 'verification_status' => $status]);
    }

    /**
     * Enregistre une mutation : clôture des liens en cours désignés et création
     * des nouveaux liens, le tout dans une transaction.
     *
     * @param array $newOwners liste de ['owner_id'=>, 'ownership_role'=>, 'share_percentage'=>, 'verification_status'=>]
     * @param int[]|null $closeLinkIds liens à clore (null = tous les liens en cours)
     */
    public static function recordMutation(int $parcelId, array $m, array $newOwners, ?array $closeLinkIds, int $userId): int
    {
        $parcel = ParcelService::getOrFail($parcelId);
        $type = (string) ($m['mutation_type'] ?? '');
        if (!array_key_exists($type, labels('mutation_type'))) {
            throw new UserFacingException('Type de mutation invalide.');
        }
        $date = (string) ($m['mutation_date'] ?? '');
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new UserFacingException('Date de mutation invalide (AAAA-MM-JJ).');
        }
        if ($date > date('Y-m-d')) {
            throw new UserFacingException('La date de mutation ne peut pas être dans le futur.');
        }
        if (!$newOwners) {
            throw new UserFacingException('Indiquez au moins un nouveau propriétaire.');
        }
        $docRef = isset($m['document_reference']) && trim((string) $m['document_reference']) !== '' ? mb_substr(trim((string) $m['document_reference']), 0, 100) : null;
        $notes = isset($m['notes']) && trim((string) $m['notes']) !== '' ? mb_substr(trim((string) $m['notes']), 0, 2000) : null;

        return db_transaction(static function () use ($parcelId, $parcel, $type, $date, $docRef, $notes, $newOwners, $closeLinkIds, $userId) {
            db_query('INSERT INTO mutations (parcel_id, mutation_type, mutation_date, document_reference, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$parcelId, $type, $date, $docRef, $notes, $userId]);
            $mutationId = (int) db()->lastInsertId();

            $active = db_all('SELECT id, owner_id FROM parcel_owners WHERE parcel_id = ? AND valid_to IS NULL', [$parcelId]);
            $closed = [];
            foreach ($active as $link) {
                if ($closeLinkIds === null || in_array((int) $link['id'], array_map('intval', $closeLinkIds), true)) {
                    self::endLink((int) $link['id'], $date, $userId, $mutationId, 'Mutation : ' . label('mutation_type', $type));
                    $closed[] = (int) $link['owner_id'];
                }
            }
            $created = [];
            foreach ($newOwners as $no) {
                $oid = (int) ($no['owner_id'] ?? 0);
                if ($oid <= 0) {
                    throw new UserFacingException('Nouveau propriétaire invalide.');
                }
                $created[] = self::link($parcelId, $oid, ($no + ['valid_from' => $date, 'document_reference' => $docRef]), $userId, $mutationId);
            }
            ParcelService::history($parcelId, $userId, 'mutation', ['proprietaires' => $closed],
                ['mutation_id' => $mutationId, 'type' => $type, 'date' => $date, 'document' => $docRef,
                 'nouveaux_proprietaires' => array_map(static fn ($n) => (int) $n['owner_id'], $newOwners)]);
            audit_log('mutation', 'parcel', $parcelId, ['mutation_id' => $mutationId, 'reference' => $parcel['parcel_reference']]);
            return $mutationId;
        });
    }

    public static function currentOwners(int $parcelId): array
    {
        return db_all('SELECT po.*, o.owner_type, o.first_name, o.last_name, o.organization_name, o.phone, o.address,
                              o.file_reference, o.info_status, o.last_verified_at
                       FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
                       WHERE po.parcel_id = ? AND po.valid_to IS NULL ORDER BY po.id', [$parcelId]);
    }

    public static function pastOwners(int $parcelId): array
    {
        return db_all('SELECT po.*, o.owner_type, o.first_name, o.last_name, o.organization_name
                       FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
                       WHERE po.parcel_id = ? AND po.valid_to IS NOT NULL ORDER BY po.valid_to DESC, po.id DESC', [$parcelId]);
    }

    public static function mutations(int $parcelId): array
    {
        return db_all('SELECT m.*, u.full_name AS created_by_name FROM mutations m LEFT JOIN users u ON u.id = m.created_by
                       WHERE m.parcel_id = ? ORDER BY m.mutation_date DESC, m.id DESC', [$parcelId]);
    }

    public static function parcelsOf(int $ownerId, bool $includePast = true): array
    {
        $sql = 'SELECT po.*, p.parcel_reference, p.category, p.area_m2, p.verification_status, p.is_archived
                FROM parcel_owners po JOIN parcels p ON p.id = po.parcel_id WHERE po.owner_id = ?';
        if (!$includePast) {
            $sql .= ' AND po.valid_to IS NULL';
        }
        return db_all($sql . ' ORDER BY po.valid_to IS NULL DESC, po.created_at DESC', [$ownerId]);
    }
}
