<?php
/**
 * Fiches et rapports PDF (fiche parcellaire, fiche propriétaire, rapports tabulaires).
 */
declare(strict_types=1);

final class PdfService
{
    private const ORANGE = [224, 82, 6];
    private const GREEN = [13, 139, 62];
    private const GRAY = [92, 102, 112];
    private const LIGHT = [238, 240, 243];
    private const M = 40; // marge

    private SimplePdf $pdf;
    private float $y = 0;
    private string $docTitle;

    private function __construct(string $title)
    {
        $this->docTitle = $title;
        $this->pdf = new SimplePdf($title);
        $edited = 'Édité le ' . date('d/m/Y à H:i') . ' par ' . (current_user()['full_name'] ?? 'Niger Parcelles');
        $this->pdf->setFooter(static function (SimplePdf $p, int $n) use ($edited): void {
            $p->line(self::M, SimplePdf::H - 42, SimplePdf::W - self::M, SimplePdf::H - 42);
            $p->text(self::M, SimplePdf::H - 30, 'Niger Parcelles - document de gestion, ne constitue pas à lui seul un titre foncier officiel.', 7.5, false, self::GRAY);
            $p->text(self::M, SimplePdf::H - 20, $edited, 7.5, false, self::GRAY);
            $p->text(SimplePdf::W - self::M, SimplePdf::H - 20, 'Page ' . $n, 8, false, self::GRAY, 'R');
        });
        $this->newPage();
    }

    private function newPage(): void
    {
        $this->pdf->addPage();
        $this->pdf->rect(0, 0, SimplePdf::W, 6, self::ORANGE);
        $this->pdf->rect(0, 6, SimplePdf::W, 2, [255, 255, 255]);
        $this->pdf->rect(0, 8, SimplePdf::W, 3, self::GREEN);
        $this->pdf->text(self::M, 34, (string) setting('organisation_name', 'Niger Parcelles'), 9, true, self::GRAY);
        $this->pdf->text(SimplePdf::W - self::M, 34, $this->docTitle, 9, false, self::GRAY, 'R');
        $this->y = 56;
    }

    private function ensure(float $h): void
    {
        if ($this->y + $h > SimplePdf::H - 60) {
            $this->newPage();
        }
    }

    private function section(string $title): void
    {
        $this->ensure(40);
        $this->y += 8;
        $this->pdf->rect(self::M, $this->y - 11, SimplePdf::W - 2 * self::M, 17, self::LIGHT);
        $this->pdf->rect(self::M, $this->y - 11, 3, 17, self::ORANGE);
        $this->pdf->text(self::M + 9, $this->y + 1, $title, 10.5, true);
        $this->y += 18;
    }

    /** Lignes clé / valeur sur une largeur donnée. */
    private function kv(array $rows, float $x = self::M, float $width = 515, float $keyW = 160): void
    {
        foreach ($rows as [$k, $v]) {
            $v = ($v === null || $v === '') ? '—' : (string) $v;
            $lines = SimplePdf::wrap($v, $width - $keyW, 9);
            $this->ensure(12 * count($lines) + 2);
            $this->pdf->text($x, $this->y, $k, 9, true, self::GRAY);
            foreach ($lines as $i => $l) {
                $this->pdf->text($x + $keyW, $this->y + $i * 12, $l, 9);
            }
            $this->y += 12 * count($lines) + 2;
        }
    }

    private function table(array $headers, array $rows, array $widths, float $size = 8): void
    {
        $x0 = self::M;
        $drawHead = function () use ($headers, $widths, $x0, $size): void {
            $this->pdf->rect($x0, $this->y - 9, array_sum($widths), 14, self::ORANGE);
            $x = $x0 + 3;
            foreach ($headers as $i => $h) {
                $this->pdf->text($x, $this->y + 1, $h, $size, true, [255, 255, 255]);
                $x += $widths[$i];
            }
            $this->y += 14;
        };
        $this->ensure(30);
        $drawHead();
        foreach ($rows as $ri => $row) {
            $cells = [];
            $maxLines = 1;
            foreach (array_values($row) as $i => $v) {
                $txt = is_float($v) ? fmt_number($v, 2) : (string) $v;
                $cells[$i] = SimplePdf::wrap($txt, $widths[$i] - 6, $size);
                $maxLines = max($maxLines, count($cells[$i]));
            }
            $h = $maxLines * ($size + 2.5) + 3;
            if ($this->y + $h > SimplePdf::H - 60) {
                $this->newPage();
                $drawHead();
            }
            if ($ri % 2 === 1) {
                $this->pdf->rect($x0, $this->y - 8, array_sum($widths), $h, [248, 249, 250]);
            }
            $x = $x0 + 3;
            foreach ($cells as $i => $lines) {
                foreach ($lines as $li => $l) {
                    $this->pdf->text($x, $this->y + $li * ($size + 2.5), $l, $size);
                }
                $x += $widths[$i];
            }
            $this->y += $h;
        }
        $this->y += 6;
    }

    /** Dessin du polygone dans un cadre, avec flèche du nord et échelle graphique. */
    private function drawParcelMap(array $geometry, float $x, float $y, float $w, float $h): void
    {
        $this->pdf->rect($x, $y, $w, $h, [250, 250, 248], [180, 186, 193]);
        $ring = $geometry['coordinates'][0] ?? [];
        if (count($ring) < 4) {
            return;
        }
        $origin = $ring[0];
        $pts = array_map(static fn ($p) => Geo::toLocal($p, $origin), $ring);
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);
        $minX = min($xs); $maxX = max($xs); $minY = min($ys); $maxY = max($ys);
        $spanX = max($maxX - $minX, 1e-6);
        $spanY = max($maxY - $minY, 1e-6);
        $pad = 30;
        $scale = min(($w - 2 * $pad) / $spanX, ($h - 2 * $pad - 16) / $spanY);
        $ox = $x + ($w - $spanX * $scale) / 2;
        $oy = $y + ($h - 16 - $spanY * $scale) / 2 + $spanY * $scale;
        $page = array_map(static fn ($p) => [$ox + ($p[0] - $minX) * $scale, $oy - ($p[1] - $minY) * $scale], $pts);
        $this->pdf->polygon($page, [253, 226, 208], self::ORANGE, 1.6);
        foreach (array_slice($page, 0, -1) as $i => $p) {
            $this->pdf->circle($p[0], $p[1], 2.2, self::GREEN);
            if (count($page) <= 30) {
                $this->pdf->text($p[0] + 3, $p[1] - 3, (string) ($i + 1), 7, true, self::GREEN);
            }
        }
        // Flèche du nord
        $nx = $x + $w - 18;
        $ny = $y + 14;
        $this->pdf->polygon([[$nx, $ny], [$nx - 5, $ny + 14], [$nx + 5, $ny + 14]], self::GRAY, self::GRAY, 0.5);
        $this->pdf->text($nx, $ny + 24, 'N', 8, true, self::GRAY, 'C');
        // Échelle graphique (valeur « ronde »)
        $target = ($w * 0.3) / $scale;
        $nice = 1;
        foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000, 10000] as $c) {
            if ($c <= $target) {
                $nice = $c;
            }
        }
        $len = $nice * $scale;
        $sy = $y + $h - 12;
        $this->pdf->rect($x + 10, $sy - 3, $len, 4, self::GRAY);
        $this->pdf->text($x + 10 + $len + 4, $sy + 1, $nice >= 1000 ? ($nice / 1000) . ' km' : $nice . ' m', 7, false, self::GRAY);
    }

    // ------------------------------------------------------------------
    public static function parcelSheet(int $id): string
    {
        $p = ParcelService::getOrFail($id);
        $s = new self('Fiche parcellaire ' . $p['parcel_reference']);
        $pdf = $s->pdf;
        $geom = ParcelService::currentGeometry($id);
        $a = admin_area_breakdown($p['administrative_area_id'] ? (int) $p['administrative_area_id'] : null);

        $pdf->text(self::M, $s->y + 6, 'FICHE PARCELLAIRE', 17, true, self::ORANGE);
        $pdf->text(self::M, $s->y + 26, $p['parcel_reference'], 14, true);
        $pdf->text(SimplePdf::W - self::M, $s->y + 6, label('verification_status', $p['verification_status']), 10, true, self::GREEN, 'R');
        $pdf->text(SimplePdf::W - self::M, $s->y + 20, label('survey_level', $p['survey_level']), 8.5, false, self::GRAY, 'R');
        $s->y += 40;
        if ((int) $p['is_demo'] === 1) {
            $pdf->rect(self::M, $s->y - 10, 515, 16, [255, 236, 179]);
            $pdf->text(self::M + 6, $s->y + 1, 'DONNÉE DE DÉMONSTRATION FICTIVE - sans aucune valeur.', 9, true, [140, 70, 0]);
            $s->y += 20;
        }
        $pdf->rect(self::M, $s->y - 10, 515, 28, [255, 248, 225], [255, 224, 138]);
        $s->y = $pdf->paragraph(self::M + 6, $s->y + 1, 503, 'Cette fiche est un document de gestion produit par l\'application. Elle ne constitue pas, à elle seule, '
            . 'un titre foncier officiel et l\'enregistrement d\'un nom ne prouve pas la propriété.', 8, false, [90, 70, 0]) + 10;

        $s->section('A. Identification et localisation');
        $top = $s->y;
        $s->drawParcelMap($geom ?? [], self::M + 290, $top - 6, 225, 210);
        $s->kv([
            ['Référence application', $p['parcel_reference']],
            ['Référence cadastrale', $p['cadastral_reference'] ?? 'Non renseignée'],
            ['Région', $a['region']], ['Département', $a['departement']], ['Commune', $a['commune']],
            ['Quartier / village', $a['quartier'] ?? $a['village']],
            ['Localisation', $p['locality_description']],
            ['Centre (WGS84)', sprintf('%.7f, %.7f', $p['centroid_lat'], $p['centroid_lng'])],
            ['Superficie', fmt_number($p['area_m2'], 2) . ' m²'],
            ['', fmt_number($p['area_m2'] / 10000, 4) . ' ha'],
            ['Périmètre', fmt_number($p['perimeter_m'], 2) . ' m'],
            ['Catégorie', label('category', $p['category'])],
            ['Créée le', fmt_date($p['created_at'], true)],
            ['Agent responsable', $p['created_by_name'] ?? '—'],
        ], self::M, 280, 110);
        $s->y = max($s->y, $top + 214);

        $s->section('C. Informations foncières');
        $s->kv([
            ['Situation déclarée', label('declared_status', $p['declared_status'])],
            ['Titre foncier', $p['title_reference']],
            ['Document justificatif', trim(($p['tenure_document_type'] ?? '') . ($p['tenure_document_date'] ? ' du ' . fmt_date($p['tenure_document_date']) : ''))],
            ['Autorité émettrice', $p['issuing_authority']],
            ['Restrictions', $p['restrictions']],
            ['Observations', $p['observations'] ?? $p['description']],
            ['Vérification', label('verification_status', $p['verification_status']) . ($p['verified_by_name'] ? ' - ' . $p['verified_by_name'] . ', ' . fmt_date($p['verified_at']) : '')],
        ]);

        $s->section('D. Propriétaires / ayants droit');
        $owners = OwnerService::currentOwners($id);
        if (!can('owner.view_personal')) {
            $s->kv([['Propriétaires', count($owners) . ' associé(s) - informations personnelles non autorisées pour votre profil']]);
        } elseif (!$owners) {
            $s->kv([['Propriétaires', 'Aucun propriétaire renseigné']]);
        } else {
            $s->table(['Nom / dénomination', 'Rôle', 'Quote-part', 'Depuis', 'Statut', 'Contact'],
                array_map(static fn ($o) => [owner_display_name($o), label('ownership_role', $o['ownership_role']),
                    $o['share_percentage'] !== null ? fmt_number($o['share_percentage'], 2) . ' %' : '-', fmt_date($o['valid_from']),
                    label('link_status', $o['verification_status']), (string) ($o['phone'] ?? '')], $owners), [150, 75, 55, 55, 105, 75]);
        }

        $s->section('Coordonnées des sommets (WGS84, degrés décimaux)');
        $ring = $geom['coordinates'][0] ?? [];
        $vrows = [];
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $vrows[] = [(string) ($i + 1), sprintf('%.7f', $ring[$i][1]), sprintf('%.7f', $ring[$i][0]), fmt_number(Geo::distance($ring[$i], $ring[$i + 1]), 2) . ' m'];
        }
        $s->table(['N°', 'Latitude', 'Longitude', 'Côté vers le suivant'], $vrows, [40, 140, 140, 195]);

        $s->section('E. Historique récent');
        $hist = array_slice(ParcelService::historyEntries($id), 0, 12);
        $s->table(['Date', 'Opération', 'Utilisateur'], array_map(static fn ($h) => [fmt_date($h['created_at'], true),
            label('history_action', $h['action_type']), $h['user_name'] ?? 'Système'], $hist), [110, 270, 135]);

        audit_log('export_fiche_pdf', 'parcel', $id);
        return $pdf->output();
    }

    public static function ownerSheet(int $id): string
    {
        $o = OwnerService::getOrFail($id);
        $s = new self('Fiche propriétaire #' . $id);
        $s->pdf->text(self::M, $s->y + 6, 'FICHE PROPRIÉTAIRE', 17, true, self::ORANGE);
        $s->pdf->text(self::M, $s->y + 26, owner_display_name($o), 13, true);
        $s->y += 44;
        if ((int) $o['is_demo'] === 1) {
            $s->pdf->text(self::M, $s->y, 'DONNÉE DE DÉMONSTRATION FICTIVE', 9, true, [140, 70, 0]);
            $s->y += 14;
        }
        $s->y = $s->pdf->paragraph(self::M, $s->y, 515, 'Informations déclarées ou documentées. L\'enregistrement d\'une personne dans l\'application ne constitue pas une preuve juridique de propriété.', 8, false, self::GRAY) + 6;
        $s->section('Identité');
        $s->kv([
            ['Type', label('owner_type', $o['owner_type'])], ['Nom', $o['last_name']], ['Prénom(s)', $o['first_name']],
            ['Dénomination', $o['organization_name']], ['Téléphone', $o['phone']], ['E-mail', $o['email']], ['Adresse déclarée', $o['address']],
            ['Pièce d\'identité', trim(($o['id_document_type'] ?? '') . ' ' . ($o['id_document_number'] ?? ''))],
            ['Référence du dossier', $o['file_reference']], ['Statut des informations', label('owner_info_status', $o['info_status'])],
            ['Dernière vérification', $o['last_verified_at'] ? fmt_date($o['last_verified_at'], true) : null], ['Observations', $o['notes']],
        ]);
        $s->section('Parcelles associées');
        $s->table(['Parcelle', 'Rôle', 'Quote-part', 'Du', 'Au', 'Statut du lien'], array_map(static fn ($pp) => [$pp['parcel_reference'],
            label('ownership_role', $pp['ownership_role']), $pp['share_percentage'] !== null ? fmt_number($pp['share_percentage'], 2) . ' %' : '-',
            fmt_date($pp['valid_from']), $pp['valid_to'] ? fmt_date($pp['valid_to']) : 'en cours', label('link_status', $pp['verification_status'])],
            OwnerService::parcelsOf($id)), [120, 80, 60, 65, 65, 125]);
        audit_log('export_fiche_proprietaire', 'owner', $id);
        return $s->pdf->output();
    }

    public static function report(array $report): string
    {
        $s = new self($report['title']);
        $s->pdf->text(self::M, $s->y + 6, mb_strtoupper($report['title']), 13, true, self::ORANGE);
        $s->y += 22;
        if ($report['description']) {
            $s->y = $s->pdf->paragraph(self::M, $s->y, 515, $report['description'], 8.5, false, self::GRAY) + 4;
        }
        $s->pdf->text(self::M, $s->y, count($report['rows']) . ' ligne(s)' . ($report['truncated'] ? ' (limitée à ' . ReportService::MAX_ROWS . ')' : ''), 8.5, false, self::GRAY);
        $s->y += 16;
        $n = max(1, count($report['columns']));
        $s->table($report['columns'], $report['rows'], array_fill(0, $n, 515 / $n), $n > 7 ? 6.5 : 7.5);
        return $s->pdf->output();
    }
}
