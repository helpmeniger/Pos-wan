<?php
/**
 * Importation de données géographiques : GeoJSON, KML/KMZ, CSV, Shapefile (ZIP).
 * Étapes : contrôle du fichier -> lecture -> système de coordonnées ->
 * validation des géométries -> doublons / chevauchements -> aperçu ->
 * confirmation -> enregistrement transactionnel -> rapport.
 */
declare(strict_types=1);

final class ImportService
{
    public const FORMATS = ['geojson' => 'GeoJSON', 'json' => 'GeoJSON', 'kml' => 'KML', 'kmz' => 'KMZ', 'csv' => 'CSV', 'zip' => 'Shapefile (ZIP)'];
    public const MAX_FEATURES = 5000;

    /** Correspondance des noms de colonnes vers les champs de parcelle. */
    private const FIELD_ALIASES = [
        'cadastral_reference' => ['cadastral_reference', 'ref_cadastrale', 'reference_cadastrale', 'ref_cad', 'cadastre', 'num_cadastre', 'lot'],
        'category' => ['category', 'categorie', 'catégorie', 'usage', 'type'],
        'locality_description' => ['locality_description', 'localite', 'localité', 'quartier', 'village', 'lieu', 'name', 'nom'],
        'address' => ['address', 'adresse'],
        'title_reference' => ['title_reference', 'titre_foncier', 'tf', 'num_tf'],
        'description' => ['description', 'observations', 'obs', 'remarque', 'commentaire'],
    ];

    /** Validation du fichier téléversé. Retourne [chemin temporaire conservé, format]. */
    public static function acceptUpload(array $file, bool $checkUploaded = true): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new UserFacingException('Aucun fichier reçu ou envoi incomplet (vérifiez la taille maximale).');
        }
        $tmp = (string) $file['tmp_name'];
        if ($checkUploaded && !is_uploaded_file($tmp)) {
            throw new UserFacingException('Fichier non valide.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > (int) config('import_max_mb', 20) * 1024 * 1024) {
            throw new UserFacingException('Taille de fichier invalide (maximum ' . (int) config('import_max_mb', 20) . ' Mo).');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::FORMATS[$ext])) {
            throw new UserFacingException('Format non pris en charge. Formats acceptés : GeoJSON, KML, KMZ, CSV, Shapefile compressé en ZIP.');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $okMime = [
            'geojson' => ['application/json', 'text/plain', 'application/geo+json'], 'json' => ['application/json', 'text/plain'],
            'kml' => ['text/xml', 'application/xml', 'text/plain', 'application/vnd.google-earth.kml+xml'],
            'kmz' => ['application/zip', 'application/vnd.google-earth.kmz', 'application/octet-stream'],
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
            'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        ];
        if (!in_array($mime, $okMime[$ext], true)) {
            throw new UserFacingException("Le contenu du fichier ($mime) ne correspond pas à l'extension .$ext.");
        }
        $dir = storage_path('imports');
        $dest = $dir . '/' . bin2hex(random_bytes(12)) . '.upload';
        if (!($checkUploaded ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest))) {
            throw new RuntimeException('Impossible de conserver le fichier importé.');
        }
        return [$dest, $ext === 'json' ? 'geojson' : $ext];
    }

    /**
     * Lit le fichier. Retourne ['features'=>[['properties','geometry']], 'crs'=>?string, 'warnings'=>[]].
     */
    public static function parse(string $path, string $format, array $options = []): array
    {
        switch ($format) {
            case 'geojson':
                $j = json_decode((string) file_get_contents($path), true);
                if (!is_array($j)) {
                    throw new UserFacingException('GeoJSON illisible : ' . json_last_error_msg());
                }
                $crs = Crs::detect(is_array($j['crs'] ?? null) ? json_encode($j['crs']) : null);
                $feats = [];
                $type = $j['type'] ?? '';
                if ($type === 'FeatureCollection') {
                    foreach ((array) ($j['features'] ?? []) as $f) {
                        $feats[] = ['properties' => (array) ($f['properties'] ?? []), 'geometry' => $f['geometry'] ?? null];
                    }
                } elseif ($type === 'Feature') {
                    $feats[] = ['properties' => (array) ($j['properties'] ?? []), 'geometry' => $j['geometry'] ?? null];
                } elseif (in_array($type, ['Polygon', 'MultiPolygon'], true)) {
                    $feats[] = ['properties' => [], 'geometry' => $j];
                } else {
                    throw new UserFacingException('Structure GeoJSON non reconnue (FeatureCollection, Feature ou Polygon attendu).');
                }
                return ['features' => $feats, 'crs' => $crs, 'warnings' => []];

            case 'kml':
                return self::parseKml((string) file_get_contents($path));

            case 'kmz':
                if (!class_exists('ZipArchive')) {
                    throw new UserFacingException('L\'extension PHP zip est nécessaire pour lire un KMZ.');
                }
                $zip = new ZipArchive();
                if ($zip->open($path) !== true) {
                    throw new UserFacingException('Archive KMZ illisible.');
                }
                $kml = null;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (strtolower(pathinfo((string) $zip->getNameIndex($i), PATHINFO_EXTENSION)) === 'kml') {
                        $kml = (string) $zip->getFromIndex($i);
                        break;
                    }
                }
                $zip->close();
                if ($kml === null) {
                    throw new UserFacingException('Aucun fichier KML dans l\'archive KMZ.');
                }
                return self::parseKml($kml);

            case 'csv':
                return self::parseCsv($path);

            case 'zip':
                try {
                    $r = ShapefileReader::readZip($path);
                } catch (InvalidArgumentException $e) {
                    throw new UserFacingException($e->getMessage());
                }
                return ['features' => $r['features'], 'crs' => Crs::detect($r['prj']), 'warnings' => $r['warnings'],
                        'prj' => $r['prj'] ? mb_substr($r['prj'], 0, 300) : null];
        }
        throw new UserFacingException('Format inconnu.');
    }

    private static function parseKml(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        // Refus des entités externes (XXE) : aucune option LIBXML_NOENT / DTDLOAD
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new UserFacingException('Fichier KML refusé : déclarations DOCTYPE/ENTITY non autorisées.');
        }
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new UserFacingException('KML illisible (XML invalide).');
        }
        $doc->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
        $placemarks = $doc->xpath('//k:Placemark') ?: $doc->xpath('//Placemark') ?: [];
        $feats = [];
        $parseCoords = static function (string $txt): array {
            $pts = [];
            foreach (preg_split('/\s+/', trim($txt)) as $tuple) {
                $p = explode(',', $tuple);
                if (count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                    $pts[] = [(float) $p[0], (float) $p[1]];
                }
            }
            return $pts;
        };
        foreach ($placemarks as $pm) {
            $props = ['name' => (string) ($pm->name ?? ''), 'description' => trim(strip_tags((string) ($pm->description ?? '')))];
            foreach ($pm->xpath('.//*[local-name()="Data"]') ?: [] as $d) {
                $props[(string) $d['name']] = (string) ($d->value ?? '');
            }
            foreach ($pm->xpath('.//*[local-name()="SimpleData"]') ?: [] as $d) {
                $props[(string) $d['name']] = (string) $d;
            }
            $polys = [];
            foreach ($pm->xpath('.//*[local-name()="Polygon"]') ?: [] as $poly) {
                $outer = $poly->xpath('./*[local-name()="outerBoundaryIs"]//*[local-name()="coordinates"]');
                if (!$outer) {
                    continue;
                }
                $rings = [$parseCoords((string) $outer[0])];
                foreach ($poly->xpath('./*[local-name()="innerBoundaryIs"]//*[local-name()="coordinates"]') ?: [] as $in) {
                    $rings[] = $parseCoords((string) $in);
                }
                $polys[] = $rings;
            }
            $geom = !$polys ? null : (count($polys) === 1 ? ['type' => 'Polygon', 'coordinates' => $polys[0]] : ['type' => 'MultiPolygon', 'coordinates' => $polys]);
            $feats[] = ['properties' => $props, 'geometry' => $geom];
        }
        return ['features' => $feats, 'crs' => 'EPSG:4326', 'warnings' => []];
    }

    /**
     * CSV : soit une colonne WKT (POLYGON((x y, ...))), soit une ligne par
     * sommet avec colonnes identifiant + x/lng/longitude + y/lat/latitude [+ ordre].
     */
    private static function parseCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $delim = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        if (substr_count($firstLine, "\t") > substr_count($firstLine, $delim)) {
            $delim = "\t";
        }
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $raw);
        rewind($fh);
        $headers = fgetcsv($fh, 0, $delim, '"', '');
        if (!$headers) {
            throw new UserFacingException('CSV vide.');
        }
        $headers = array_map(static fn ($h) => mb_strtolower(trim((string) $h)), $headers);
        $find = static function (array $names) use ($headers): ?int {
            foreach ($names as $n) {
                $i = array_search($n, $headers, true);
                if ($i !== false) {
                    return (int) $i;
                }
            }
            return null;
        };
        $wkt = $find(['wkt', 'geometry', 'geometrie', 'géométrie', 'the_geom']);
        $num = static fn ($v) => is_numeric(str_replace(',', '.', trim((string) $v))) ? (float) str_replace(',', '.', trim((string) $v)) : null;
        $feats = [];
        if ($wkt !== null) {
            while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue;
                }
                $props = [];
                foreach ($headers as $i => $h) {
                    if ($i !== $wkt) {
                        $props[$h] = $row[$i] ?? '';
                    }
                }
                $feats[] = ['properties' => $props, 'geometry' => self::parseWkt((string) ($row[$wkt] ?? ''))];
            }
            return ['features' => $feats, 'crs' => null, 'warnings' => []];
        }
        $idCol = $find(['parcel_id', 'id_parcelle', 'identifiant', 'id', 'parcelle', 'groupe', 'code']);
        $xCol = $find(['lng', 'lon', 'long', 'longitude', 'x', 'easting', 'est']);
        $yCol = $find(['lat', 'latitude', 'y', 'northing', 'nord']);
        $ordCol = $find(['ordre', 'order', 'sommet', 'vertex', 'num_sommet', 'point']);
        if ($idCol === null || $xCol === null || $yCol === null) {
            throw new UserFacingException('Colonnes introuvables. Le CSV doit contenir soit une colonne « wkt », soit les colonnes « identifiant », « longitude » (ou x) et « latitude » (ou y), une ligne par sommet.');
        }
        $groups = [];
        $line = 1;
        $warnings = [];
        while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $line++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $gid = trim((string) ($row[$idCol] ?? ''));
            $x = $num($row[$xCol] ?? '');
            $y = $num($row[$yCol] ?? '');
            if ($gid === '' || $x === null || $y === null) {
                $warnings[] = "Ligne $line ignorée : identifiant ou coordonnée manquant(e).";
                continue;
            }
            if (!isset($groups[$gid])) {
                $props = [];
                foreach ($headers as $i => $h) {
                    if (!in_array($i, [$xCol, $yCol, $ordCol], true)) {
                        $props[$h] = $row[$i] ?? '';
                    }
                }
                $groups[$gid] = ['props' => $props, 'pts' => []];
            }
            $groups[$gid]['pts'][] = ['o' => $ordCol !== null ? (float) $num($row[$ordCol]) : count($groups[$gid]['pts']), 'p' => [$x, $y]];
        }
        foreach ($groups as $g) {
            usort($g['pts'], static fn ($a, $b) => $a['o'] <=> $b['o']);
            $feats[] = ['properties' => $g['props'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [array_column($g['pts'], 'p')]]];
        }
        return ['features' => $feats, 'crs' => null, 'warnings' => array_slice($warnings, 0, 50)];
    }

    /** WKT POLYGON / MULTIPOLYGON -> géométrie GeoJSON. */
    public static function parseWkt(string $wkt): ?array
    {
        $wkt = trim($wkt);
        if (!preg_match('/^(MULTI)?POLYGON\s*(Z|M|ZM)?\s*\((.*)\)$/is', $wkt, $m)) {
            return null;
        }
        $ringsOf = static function (string $body): array {
            preg_match_all('/\(([^()]+)\)/', $body, $mm);
            $rings = [];
            foreach ($mm[1] as $r) {
                $pts = [];
                foreach (explode(',', $r) as $pt) {
                    $c = preg_split('/\s+/', trim($pt));
                    if (count($c) >= 2 && is_numeric($c[0]) && is_numeric($c[1])) {
                        $pts[] = [(float) $c[0], (float) $c[1]];
                    }
                }
                $rings[] = $pts;
            }
            return $rings;
        };
        if (!empty($m[1])) {
            preg_match_all('/\(\s*(\([^()]+\)(?:\s*,\s*\([^()]+\))*)\s*\)/', $m[3], $polys);
            $coords = array_map($ringsOf, $polys[1]);
            return count($coords) === 1 ? ['type' => 'Polygon', 'coordinates' => $coords[0]] : ['type' => 'MultiPolygon', 'coordinates' => $coords];
        }
        return ['type' => 'Polygon', 'coordinates' => $ringsOf('(' . $m[3] . ')')];
    }

    /** Extrait les attributs de parcelle reconnus dans les propriétés. */
    public static function mapAttributes(array $props): array
    {
        $lower = [];
        foreach ($props as $k => $v) {
            if (is_scalar($v)) {
                $lower[mb_strtolower(trim((string) $k))] = trim((string) $v);
            }
        }
        $out = [];
        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($aliases as $a) {
                if (isset($lower[$a]) && $lower[$a] !== '') {
                    $out[$field] = $lower[$a];
                    break;
                }
            }
        }
        if (isset($out['category'])) {
            $c = self::normalizeCategory($out['category']);
            if ($c === null) {
                unset($out['category']);
            } else {
                $out['category'] = $c;
            }
        }
        return $out;
    }

    private static function normalizeCategory(string $v): ?string
    {
        $v = mb_strtolower(trim($v));
        $map = ['résidentiel' => 'residentiel', 'residentiel' => 'residentiel', 'habitation' => 'residentiel', 'commercial' => 'commercial',
                'commerce' => 'commercial', 'agricole' => 'agricole', 'champ' => 'agricole', 'industriel' => 'industriel', 'administratif' => 'administratif',
                'terrain nu' => 'terrain_nu', 'terrain_nu' => 'terrain_nu', 'nu' => 'terrain_nu', 'en construction' => 'en_construction',
                'en_construction' => 'en_construction', 'autre' => 'autre'];
        return $map[$v] ?? (array_key_exists($v, labels('category')) ? $v : null);
    }

    /**
     * Analyse complète : transformation, validation, doublons, chevauchements.
     * Le résultat est conservé dans storage/imports pour l'étape de confirmation.
     */
    public static function analyze(string $path, string $format, string $originalName, string $crsChoice, int $userId): array
    {
        $parsed = self::parse($path, $format);
        $features = $parsed['features'];
        if (!$features) {
            throw new UserFacingException('Le fichier ne contient aucune entité.');
        }
        if (count($features) > self::MAX_FEATURES) {
            throw new UserFacingException('Trop d\'entités (' . count($features) . ') : maximum ' . self::MAX_FEATURES . ' par importation. Scindez le fichier.');
        }
        $warnings = $parsed['warnings'];

        // Système de coordonnées : choix explicite > détection > déduction
        $crs = $crsChoice !== 'auto' ? $crsChoice : $parsed['crs'];
        $crsSource = $crsChoice !== 'auto' ? 'choisi par l\'utilisateur' : ($parsed['crs'] ? 'détecté dans le fichier' : null);
        if ($crs === null) {
            $sample = null;
            foreach ($features as $f) {
                $c = $f['geometry']['coordinates'] ?? null;
                while (is_array($c) && isset($c[0]) && is_array($c[0])) {
                    $c = $c[0];
                }
                if (is_array($c) && isset($c[0], $c[1]) && is_numeric($c[0])) {
                    $sample = $c;
                    break;
                }
            }
            if ($sample && Crs::guessFromCoordinate((float) $sample[0], (float) $sample[1]) === 'EPSG:4326') {
                $crs = 'EPSG:4326';
                $crsSource = 'déduit des valeurs (degrés)';
                $warnings[] = 'Système de coordonnées non déclaré : WGS84 (degrés) a été déduit des valeurs. Vérifiez l\'aperçu.';
            } else {
                throw new UserFacingException('Le système de coordonnées n\'a pas pu être identifié (coordonnées en mètres ?). Choisissez le système source (ex. UTM 31N/32N/33N) et relancez l\'analyse.');
            }
        }
        if (!isset(Crs::SUPPORTED[$crs])) {
            throw new UserFacingException("Système de coordonnées non pris en charge : $crs.");
        }

        $items = [];
        $valid = [];
        $bounds = geo_bounds();
        foreach ($features as $i => $f) {
            $item = ['index' => $i + 1, 'errors' => [], 'warnings' => [], 'attributes' => self::mapAttributes((array) $f['properties']),
                     'label' => self::featureLabel((array) $f['properties'], $i + 1)];
            $g = $f['geometry'];
            if (!is_array($g)) {
                $item['errors'][] = 'Géométrie absente.';
                $items[] = $item;
                continue;
            }
            try {
                $g = Crs::transformGeometry($g, $crs);
                $rings = Geo::normalizePolygon($g);
                $errs = Geo::validate($rings, $bounds, (float) setting('min_parcel_area_m2', 1), (float) setting('max_parcel_area_m2', 5e7));
                if ($errs) {
                    $item['errors'] = $errs;
                } else {
                    $item['geometry'] = Geo::toGeoJson($rings);
                    $item['measure'] = Geo::measure($rings);
                    foreach (ParcelService::findOverlaps($rings, null) as $o) {
                        $item['warnings'][] = ($o['type'] === 'doublon_probable' ? 'Doublon probable avec ' : 'Chevauchement avec ') . $o['parcel_reference'] . ' (base).';
                    }
                    if (!empty($item['attributes']['cadastral_reference'])) {
                        $dup = db_value('SELECT parcel_reference FROM parcels WHERE cadastral_reference = ? LIMIT 1', [$item['attributes']['cadastral_reference']]);
                        if ($dup) {
                            $item['warnings'][] = 'Référence cadastrale déjà utilisée par ' . $dup . '.';
                        }
                    }
                    $valid[] = count($items);
                }
            } catch (InvalidArgumentException $e) {
                $item['errors'][] = $e->getMessage();
            }
            $items[] = $item;
        }
        // Doublons / chevauchements internes au fichier
        $n = count($valid);
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $A = &$items[$valid[$a]];
                $B = &$items[$valid[$b]];
                $rel = Geo::compare($A['geometry']['coordinates'], $B['geometry']['coordinates'], (float) setting('overlap_tolerance_m', 0.10));
                if ($rel !== 'none') {
                    $msg = ($rel === 'duplicate' ? 'Doublon probable' : 'Chevauchement') . ' avec l\'entité n° ';
                    $A['warnings'][] = $msg . $B['index'] . ' du fichier.';
                    $B['warnings'][] = $msg . $A['index'] . ' du fichier.';
                }
                unset($A, $B);
            }
        }

        $summary = [
            'total' => count($items),
            'valid' => count(array_filter($items, static fn ($x) => !$x['errors'])),
            'errors' => count(array_filter($items, static fn ($x) => (bool) $x['errors'])),
            'warnings' => count(array_filter($items, static fn ($x) => !$x['errors'] && $x['warnings'])),
        ];
        $token = bin2hex(random_bytes(16));
        $state = ['token' => $token, 'user_id' => $userId, 'created' => time(), 'filename' => mb_substr($originalName, 0, 255),
                  'format' => $format, 'crs' => $crs, 'crs_source' => $crsSource, 'prj' => $parsed['prj'] ?? null,
                  'warnings' => $warnings, 'summary' => $summary, 'items' => $items];
        file_put_contents(storage_path('imports/' . $token . '.json'), json_encode($state, JSON_UNESCAPED_UNICODE));
        @unlink($path);
        return $state;
    }

    private static function featureLabel(array $props, int $i): string
    {
        foreach (['name', 'nom', 'id', 'identifiant', 'parcel_id', 'ref', 'reference', 'lot'] as $k) {
            foreach ($props as $pk => $v) {
                if (mb_strtolower((string) $pk) === $k && is_scalar($v) && trim((string) $v) !== '') {
                    return mb_substr(trim((string) $v), 0, 80);
                }
            }
        }
        return 'Entité ' . $i;
    }

    public static function loadState(string $token, int $userId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new UserFacingException('Jeton d\'importation invalide.');
        }
        $file = storage_path('imports/' . $token . '.json');
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!$state || (int) $state['user_id'] !== $userId) {
            throw new UserFacingException('Analyse introuvable ou expirée. Relancez l\'importation.', 404);
        }
        if (time() - (int) $state['created'] > 86400) {
            @unlink($file);
            throw new UserFacingException('Analyse expirée (plus de 24 h). Relancez l\'importation.');
        }
        return $state;
    }

    /**
     * Enregistre les entités valides dans une transaction unique.
     * Options : area_id, survey_level, category, include_warnings (bool).
     */
    public static function commit(string $token, array $opts, int $userId): array
    {
        $state = self::loadState($token, $userId);
        $includeWarnings = !empty($opts['include_warnings']);
        $base = ParcelService::sanitizeAttributes(array_filter([
            'administrative_area_id' => $opts['area_id'] ?? null,
            'survey_level' => $opts['survey_level'] ?? 'indicative',
            'category' => $opts['category'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''));

        $report = ['imported' => [], 'skipped' => [], 'errors' => []];
        try {
            db_transaction(static function () use ($state, $base, $includeWarnings, $userId, &$report) {
                foreach ($state['items'] as $item) {
                    if ($item['errors']) {
                        $report['errors'][] = ['index' => $item['index'], 'label' => $item['label'], 'messages' => $item['errors']];
                        continue;
                    }
                    if ($item['warnings'] && !$includeWarnings) {
                        $report['skipped'][] = ['index' => $item['index'], 'label' => $item['label'], 'messages' => $item['warnings']];
                        continue;
                    }
                    $attrs = array_merge($base, ParcelService::sanitizeAttributes($item['attributes']));
                    if (empty($base['category']) && empty($attrs['category'])) {
                        $attrs['category'] = 'autre';
                    }
                    $attrs['description'] = trim(($attrs['description'] ?? '') . ' [Import : ' . $state['filename'] . ', entité ' . $item['index'] . ']');
                    $r = ParcelService::create($attrs, $item['geometry'], $userId, true, 'import', $state['crs']);
                    $report['imported'][] = ['index' => $item['index'], 'label' => $item['label'], 'id' => $r['id'], 'reference' => $r['parcel_reference'],
                                             'overlaps' => array_column($r['overlaps'], 'parcel_reference')];
                }
            });
            $status = 'termine';
        } catch (Throwable $e) {
            log_error($e);
            $status = 'echec';
            $report['fatal'] = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur technique : aucune parcelle n\'a été enregistrée (annulation complète).';
            $report['imported'] = [];
        }
        db_query('INSERT INTO import_batches (filename, format, source_crs, feature_count, imported_count, error_count, warning_count, report, status, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$state['filename'], $state['format'], $state['crs'], $state['summary']['total'],
            count($report['imported']), count($report['errors']), count($report['skipped']), json_encode($report, JSON_UNESCAPED_UNICODE), $status, $userId]);
        $batchId = (int) db()->lastInsertId();
        audit_log('import', 'import_batch', $batchId, ['fichier' => $state['filename'], 'importees' => count($report['imported']), 'statut' => $status]);
        @unlink(storage_path('imports/' . $token . '.json'));
        return ['batch_id' => $batchId, 'status' => $status] + $report;
    }
}
