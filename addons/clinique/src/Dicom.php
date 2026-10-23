<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Core\BusinessException;

/**
 * Lecture des fichiers DICOM (norme de l'imagerie médicale) sans bibliothèque externe :
 * - en-tête (patient, examen, série, image, paramètres de pixels), lu en flux sans charger l'image ;
 * - emplacement des pixels : non compressés (Implicit / Explicit VR Little Endian) ou encapsulés
 *   (fragments : RLE, JPEG…) ;
 * - décompression RLE (DICOM PS3.5 annexe G) ; aperçu PNG (fenêtrage, MONOCHROME1/2, RGB, JPEG baseline).
 * Les syntaxes JPEG Lossless, JPEG-LS et JPEG 2000 ne sont pas décodées : le fichier est conservé
 * intact et signalé « non affichable » (le récepteur réseau demande de préférence le format non compressé).
 */
final class Dicom
{
    public const TS_IMPLICIT = '1.2.840.10008.1.2';
    public const TS_EXPLICIT = '1.2.840.10008.1.2.1';
    public const TS_DEFLATE = '1.2.840.10008.1.2.1.99';
    public const TS_BIG = '1.2.840.10008.1.2.2';
    public const TS_RLE = '1.2.840.10008.1.2.5';
    public const TS_JPEG_BASELINE = '1.2.840.10008.1.2.4.50';
    /** Syntaxes affichables, par ordre de préférence lors de la négociation réseau. */
    public const RENDERABLE_TS = [self::TS_EXPLICIT, self::TS_IMPLICIT, self::TS_RLE, self::TS_JPEG_BASELINE];

    /** VR dont la longueur est codée sur 4 octets en Explicit VR. */
    private const LONG_VR = ['OB', 'OD', 'OF', 'OL', 'OW', 'OV', 'SQ', 'SV', 'UC', 'UN', 'UR', 'UT', 'UV'];

    /** Éléments retenus (tag hexadécimal → nom). */
    public const TAGS = [
        '00020010' => 'TransferSyntaxUID', '00020002' => 'MediaStorageSOPClassUID', '00020003' => 'MediaStorageSOPInstanceUID', '00020016' => 'SourceAETitle',
        '00080005' => 'SpecificCharacterSet', '00080016' => 'SOPClassUID', '00080018' => 'SOPInstanceUID', '00080020' => 'StudyDate', '00080030' => 'StudyTime',
        '00080050' => 'AccessionNumber', '00080060' => 'Modality', '00080070' => 'Manufacturer', '00081010' => 'StationName', '00081030' => 'StudyDescription', '0008103E' => 'SeriesDescription',
        '00100010' => 'PatientName', '00100020' => 'PatientID', '00100030' => 'PatientBirthDate', '00100040' => 'PatientSex',
        '00180015' => 'BodyPartExamined', '00181164' => 'ImagerPixelSpacing',
        '0020000D' => 'StudyInstanceUID', '0020000E' => 'SeriesInstanceUID', '00200011' => 'SeriesNumber', '00200013' => 'InstanceNumber',
        '00280002' => 'SamplesPerPixel', '00280004' => 'PhotometricInterpretation', '00280006' => 'PlanarConfiguration', '00280008' => 'NumberOfFrames',
        '00280010' => 'Rows', '00280011' => 'Columns', '00280030' => 'PixelSpacing', '00280100' => 'BitsAllocated', '00280101' => 'BitsStored',
        '00280102' => 'HighBit', '00280103' => 'PixelRepresentation', '00281050' => 'WindowCenter', '00281051' => 'WindowWidth',
        '00281052' => 'RescaleIntercept', '00281053' => 'RescaleSlope',
    ];
    private const NUMERIC = ['US' => 'v', 'SS' => 's', 'UL' => 'V', 'SL' => 'l', 'FL' => 'g', 'FD' => 'e'];
    /** VR des éléments numériques utiles en Implicit VR (pas de VR dans le fichier). */
    private const IMPLICIT_VR = ['00280002' => 'US', '00280006' => 'US', '00280010' => 'US', '00280011' => 'US', '00280100' => 'US', '00280101' => 'US', '00280102' => 'US', '00280103' => 'US'];

    /** @var resource */
    private $fh;
    private int $size;
    private bool $explicit = true;
    private bool $little = true;
    private array $values = [];
    private ?array $pixel = null;

    /**
     * Analyse un fichier : en-tête + emplacement des pixels.
     * @return array{tags: array<string,mixed>, ts: string, pixel: ?array, part10: bool}
     */
    public static function parse(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new BusinessException('Fichier illisible.');
        }
        try {
            $d = new self();
            $d->fh = $fh;
            $d->size = (int) filesize($path);
            return $d->run($path);
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
    }

    /** Un fichier ressemble-t-il à du DICOM (préambule « DICM » ou premier élément du groupe 0008) ? */
    public static function looks(string $path): bool
    {
        $h = @file_get_contents($path, false, null, 0, 132);
        if ($h === false || strlen($h) < 8) {
            return false;
        }
        if (strlen($h) >= 132 && substr($h, 128, 4) === 'DICM') {
            return true;
        }
        $g = unpack('v', substr($h, 0, 2))[1];
        return in_array($g, [0x0002, 0x0008], true);
    }

    private function run(string $path): array
    {
        $part10 = false;
        $ts = self::TS_IMPLICIT;
        $head = $this->read(132);
        if (strlen($head) === 132 && substr($head, 128, 4) === 'DICM') {
            $part10 = true;
            // Méta-informations (groupe 0002) toujours en Explicit VR Little Endian
            $this->explicit = true;
            $this->little = true;
            while (!feof($this->fh) && ftell($this->fh) < $this->size) {
                $pos = ftell($this->fh);
                $g = $this->peekGroup();
                if ($g !== 0x0002) {
                    fseek($this->fh, $pos);
                    break;
                }
                $this->element(0);
            }
            $ts = (string) ($this->values['TransferSyntaxUID'] ?? self::TS_IMPLICIT);
        } else {
            fseek($this->fh, 0);
            // Jeu de données sans préambule : Explicit si les octets 4-5 sont deux lettres majuscules (VR)
            $vr = substr($head, 4, 2);
            $this->explicit = (bool) preg_match('/^[A-Z]{2}$/', $vr);
            $ts = $this->explicit ? self::TS_EXPLICIT : self::TS_IMPLICIT;
        }
        if ($ts === self::TS_DEFLATE) {
            // Jeu de données compressé (deflate) : décompression en mémoire dans un fichier temporaire
            $rest = stream_get_contents($this->fh);
            $raw = @gzinflate((string) $rest);
            if ($raw === false) {
                throw new BusinessException('Fichier DICOM « deflate » illisible.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'dcm');
            file_put_contents($tmp, $raw);
            try {
                $inner = self::parseDataset($tmp, self::TS_EXPLICIT);
            } finally {
                @unlink($tmp);
            }
            $inner['tags'] = $this->values + $inner['tags'];
            $inner['ts'] = $ts;
            $inner['part10'] = true;
            $inner['pixel'] = null; // décompressé en mémoire seulement : non affichable directement
            return $inner;
        }
        $this->explicit = $ts !== self::TS_IMPLICIT;
        $this->little = $ts !== self::TS_BIG;
        $this->dataset(PHP_INT_MAX, 0);
        return ['tags' => $this->values, 'ts' => $ts, 'pixel' => $this->pixel, 'part10' => $part10];
    }

    /** Analyse d'un jeu de données brut (sans préambule) dans une syntaxe donnée. */
    public static function parseDataset(string $path, string $ts): array
    {
        $d = new self();
        $d->fh = fopen($path, 'rb');
        $d->size = (int) filesize($path);
        $d->explicit = $ts !== self::TS_IMPLICIT;
        $d->little = $ts !== self::TS_BIG;
        try {
            $d->dataset(PHP_INT_MAX, 0);
        } finally {
            fclose($d->fh);
        }
        return ['tags' => $d->values, 'ts' => $ts, 'pixel' => $d->pixel, 'part10' => false];
    }

    private function read(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $s = '';
        while (strlen($s) < $n && !feof($this->fh)) {
            $c = fread($this->fh, $n - strlen($s));
            if ($c === false || $c === '') {
                break;
            }
            $s .= $c;
        }
        return $s;
    }

    private function u16(string $b): int
    {
        return unpack($this->little ? 'v' : 'n', $b)[1];
    }

    private function u32(string $b): int
    {
        return unpack($this->little ? 'V' : 'N', $b)[1];
    }

    private function peekGroup(): int
    {
        $pos = ftell($this->fh);
        $b = $this->read(2);
        fseek($this->fh, $pos);
        return strlen($b) === 2 ? unpack('v', $b)[1] : -1;
    }

    /** Lit les éléments jusqu'à $end (octets) ou jusqu'à un délimiteur. */
    private function dataset(int $end, int $depth): void
    {
        while (ftell($this->fh) < min($end, $this->size)) {
            if (!$this->element($depth)) {
                return;
            }
        }
    }

    /** Lit un élément ; retourne false sur un délimiteur d'élément / fin de séquence. */
    private function element(int $depth): bool
    {
        $h = $this->read(4);
        if (strlen($h) < 4) {
            return false;
        }
        $group = $this->u16(substr($h, 0, 2));
        $elem = $this->u16(substr($h, 2, 2));
        if ($group === 0xFFFE) {
            $len = $this->u32($this->read(4));
            if ($elem === 0xE00D || $elem === 0xE0DD) {
                return false; // fin d'item / de séquence
            }
            // Item à l'intérieur d'une séquence
            if ($len === 0xFFFFFFFF) {
                $this->dataset(PHP_INT_MAX, $depth + 1);
            } else {
                fseek($this->fh, $len, SEEK_CUR);
            }
            return true;
        }
        $tag = sprintf('%04X%04X', $group, $elem);
        $vr = null;
        if ($this->explicit && $group !== 0xFFFE) {
            $vr = $this->read(2);
            if (in_array($vr, self::LONG_VR, true)) {
                $this->read(2);
                $len = $this->u32($this->read(4));
            } elseif (preg_match('/^[A-Z]{2}$/', $vr)) {
                $len = $this->u16($this->read(2));
            } else {
                throw new BusinessException('Fichier DICOM mal formé (VR « ' . bin2hex($vr) . ' » en ' . $tag . ').');
            }
        } else {
            $len = $this->u32($this->read(4));
            $vr = self::IMPLICIT_VR[$tag] ?? null;
        }
        if ($tag === '7FE00010' && $depth === 0) {
            $this->pixelData($len);
            return true;
        }
        if ($len === 0xFFFFFFFF) {
            // Séquence (ou valeur encapsulée) de longueur indéfinie : items jusqu'au délimiteur
            $this->sequence();
            return true;
        }
        if ($vr === 'SQ') {
            fseek($this->fh, $len, SEEK_CUR);
            return true;
        }
        if ($depth === 0 && isset(self::TAGS[$tag]) && $len < 4096) {
            $raw = $this->read($len);
            $this->values[self::TAGS[$tag]] = $this->decode($raw, $vr);
            return true;
        }
        if ($len > 0) {
            fseek($this->fh, $len, SEEK_CUR);
        }
        return true;
    }

    private function sequence(): void
    {
        while (!feof($this->fh)) {
            $h = $this->read(8);
            if (strlen($h) < 8) {
                return;
            }
            $group = $this->u16(substr($h, 0, 2));
            $elem = $this->u16(substr($h, 2, 2));
            $len = $this->u32(substr($h, 4, 4));
            if ($group !== 0xFFFE || $elem === 0xE0DD) {
                return; // fin de séquence
            }
            if ($len === 0xFFFFFFFF) {
                $this->dataset(PHP_INT_MAX, 1);
            } else {
                fseek($this->fh, $len, SEEK_CUR);
            }
        }
    }

    private function pixelData(int $len): void
    {
        $start = ftell($this->fh);
        if ($len !== 0xFFFFFFFF) {
            $this->pixel = ['offset' => $start, 'length' => min($len, $this->size - $start), 'encapsulated' => false, 'fragments' => [], 'offsets' => []];
            fseek($this->fh, $len, SEEK_CUR);
            return;
        }
        // Encapsulé : table d'offsets puis fragments
        $frags = [];
        $offsets = [];
        $first = true;
        while (!feof($this->fh)) {
            $h = $this->read(8);
            if (strlen($h) < 8) {
                break;
            }
            $g = unpack('v', substr($h, 0, 2))[1];
            $e = unpack('v', substr($h, 2, 2))[1];
            $l = unpack('V', substr($h, 4, 4))[1];
            if ($g !== 0xFFFE || $e === 0xE0DD) {
                break;
            }
            $pos = ftell($this->fh);
            if ($first) {
                if ($l > 0) {
                    $offsets = array_values(unpack('V*', $this->read($l)));
                }
                $first = false;
            } else {
                $frags[] = [$pos, $l];
                fseek($this->fh, $l, SEEK_CUR);
            }
        }
        $this->pixel = ['offset' => $start, 'length' => 0, 'encapsulated' => true, 'fragments' => $frags, 'offsets' => $offsets];
    }

    private function decode(string $raw, ?string $vr): mixed
    {
        if ($vr !== null && isset(self::NUMERIC[$vr])) {
            $fmt = self::NUMERIC[$vr];
            if (!$this->little) {
                $fmt = ['v' => 'n', 'V' => 'N', 'g' => 'G', 'e' => 'E', 's' => 's', 'l' => 'l'][$fmt];
            }
            $size = ['v' => 2, 'n' => 2, 's' => 2, 'V' => 4, 'N' => 4, 'l' => 4, 'g' => 4, 'G' => 4, 'e' => 8, 'E' => 8][$fmt];
            if (strlen($raw) < $size) {
                return null;
            }
            return unpack($fmt, $raw)[1];
        }
        // En Implicit VR, les entiers US connus ont été typés ; les autres valeurs sont du texte
        return rtrim($raw, " \0");
    }

    // ------------------------------------------------------------------ informations normalisées

    /** Champs utiles (texte nettoyé, UTF-8, nombres) à partir du résultat de parse(). */
    public static function info(array $p): array
    {
        $t = $p['tags'];
        $str = function (string $k, int $max = 150) use ($t): ?string {
            $v = $t[$k] ?? null;
            if ($v === null || is_int($v) || is_float($v)) {
                return $v === null ? null : (string) $v;
            }
            $v = trim(str_replace("\0", '', (string) $v));
            if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
                $v = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1'); // ISO_IR 100 (Latin-1), le plus courant
            }
            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $num = fn(string $k): ?float => isset($t[$k]) && is_numeric(is_string($t[$k]) ? explode('\\', trim($t[$k]))[0] : $t[$k]) ? (float) (is_string($t[$k]) ? explode('\\', trim($t[$k]))[0] : $t[$k]) : null;
        $int = fn(string $k): ?int => isset($t[$k]) && is_numeric(is_string($t[$k]) ? trim($t[$k]) : $t[$k]) ? (int) (is_string($t[$k]) ? trim($t[$k]) : $t[$k]) : null;
        $date = function (?string $d, ?string $tm = null): ?string {
            if (!$d || !preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }
            $time = '00:00:00';
            if ($tm && preg_match('/^(\d{2})(\d{2})?(\d{2})?/', $tm, $h) && (int) $h[1] < 24) {
                $time = sprintf('%02d:%02d:%02d', (int) $h[1], (int) ($h[2] ?? 0), min(59, (int) ($h[3] ?? 0)));
            }
            return "$m[1]-$m[2]-$m[3] $time";
        };
        $spacing = $str('PixelSpacing', 40) ?: $str('ImagerPixelSpacing', 40);
        if ($spacing && !preg_match('/^\s*[\d.]+\s*\\\\\s*[\d.]+\s*$/', $spacing)) {
            $spacing = null;
        }
        $name = $str('PatientName');
        $px = $p['pixel'];
        $ts = (string) $p['ts'];
        $rows = $int('Rows');
        $cols = $int('Columns');
        $bits = $int('BitsAllocated') ?? 0;
        $samples = $int('SamplesPerPixel') ?? 1;
        $photo = strtoupper((string) $str('PhotometricInterpretation', 20));
        [$ok, $why] = self::renderability($ts, $px, $rows, $cols, $bits, $samples, $photo);
        return [
            'sop_class' => $str('SOPClassUID', 64) ?: $str('MediaStorageSOPClassUID', 64),
            'sop_uid' => $str('SOPInstanceUID', 64) ?: $str('MediaStorageSOPInstanceUID', 64),
            'study_uid' => $str('StudyInstanceUID', 64), 'series_uid' => $str('SeriesInstanceUID', 64),
            'series_number' => $int('SeriesNumber'), 'instance_number' => $int('InstanceNumber'),
            'series_description' => $str('SeriesDescription', 100), 'study_description' => $str('StudyDescription', 150),
            'modality' => strtoupper((string) $str('Modality', 8)) ?: null, 'accession' => $str('AccessionNumber', 30),
            'study_date' => $date($str('StudyDate', 8), $str('StudyTime', 16)),
            'patient_name' => $name ? trim(preg_replace('/\s+/', ' ', str_replace(['^', '='], ' ', $name))) : null,
            'patient_id' => $str('PatientID', 64), 'birth_date' => ($bd = $date($str('PatientBirthDate', 8))) ? substr($bd, 0, 10) : null,
            'sex' => in_array($s = strtoupper((string) $str('PatientSex', 2)), ['M', 'F', 'O'], true) ? $s : null,
            'body_part' => $str('BodyPartExamined', 40), 'manufacturer' => $str('Manufacturer', 60), 'station' => $str('StationName', 60),
            'rows' => $rows, 'cols' => $cols, 'frames' => max(1, $int('NumberOfFrames') ?? 1), 'samples' => $samples,
            'photometric' => $photo ?: 'MONOCHROME2', 'planar' => $int('PlanarConfiguration') ?? 0,
            'bits_allocated' => $bits, 'bits_stored' => $int('BitsStored') ?? $bits, 'signed' => ($int('PixelRepresentation') ?? 0) === 1,
            'slope' => $num('RescaleSlope') ?: 1.0, 'intercept' => $num('RescaleIntercept') ?? 0.0,
            'window_center' => $num('WindowCenter'), 'window_width' => $num('WindowWidth'),
            'pixel_spacing' => $spacing ? preg_replace('/\s+/', '', $spacing) : null,
            'transfer_syntax' => $ts, 'renderable' => $ok, 'render_note' => $why,
        ];
    }

    /** L'image peut-elle être affichée (format de pixels pris en charge) ? */
    private static function renderability(string $ts, ?array $px, ?int $rows, ?int $cols, int $bits, int $samples, string $photo): array
    {
        if (!$px || !$rows || !$cols) {
            return [false, 'Pas d\'image dans ce fichier (rapport structuré, document ou objet sans pixels).'];
        }
        if (!in_array($ts, self::RENDERABLE_TS, true)) {
            return [false, 'Compression non prise en charge par la visionneuse (' . $ts . ') : fichier conservé intact, à ouvrir avec un logiciel DICOM. Réglez l\'appareil sur « non compressé » si possible.'];
        }
        if ($ts === self::TS_JPEG_BASELINE) {
            return [true, null];
        }
        if (!in_array($bits, [8, 16], true)) {
            return [false, 'Profondeur de ' . $bits . ' bits non prise en charge.'];
        }
        if ($samples === 3 && ($bits !== 8 || !in_array($photo, ['RGB', 'YBR_FULL'], true))) {
            return [false, 'Couleurs « ' . $photo . ' » non prises en charge.'];
        }
        if ($samples === 1 && !in_array($photo, ['MONOCHROME1', 'MONOCHROME2'], true)) {
            return [false, 'Palette de couleurs « ' . $photo . ' » non prise en charge.'];
        }
        return [true, null];
    }

    // ------------------------------------------------------------------ pixels

    /** Octets bruts (non compressés) d'une image : natif, ou RLE décompressé. */
    public static function frameBytes(string $path, array $p, array $info, int $frame = 0): string
    {
        $px = $p['pixel'];
        $frameSize = $info['rows'] * $info['cols'] * $info['samples'] * intdiv($info['bits_allocated'], 8);
        $frame = max(0, min($info['frames'] - 1, $frame));
        if (!$px['encapsulated']) {
            $fh = fopen($path, 'rb');
            fseek($fh, $px['offset'] + $frame * $frameSize);
            $data = (string) fread($fh, $frameSize);
            while (strlen($data) < $frameSize && !feof($fh)) {
                $data .= (string) fread($fh, $frameSize - strlen($data));
            }
            fclose($fh);
            return $data;
        }
        $enc = self::frameEncoded($path, $px, $info['frames'], $frame);
        if ($p['ts'] === self::TS_RLE) {
            return self::rleDecode($enc, $info);
        }
        throw new BusinessException('Compression non décodable ici.');
    }

    /** Octets compressés d'une image (fragments de son cadre). */
    public static function frameEncoded(string $path, array $px, int $frames, int $frame): string
    {
        $frags = $px['fragments'];
        if (!$frags) {
            return '';
        }
        $groups = [];
        if ($frames <= 1) {
            $groups = [$frags];
        } elseif (count($px['offsets']) === $frames) {
            // Table d'offsets : position du premier fragment de chaque image
            $base = $frags[0][0] - 8;
            $starts = array_map(fn($o) => $base + $o, $px['offsets']);
            foreach ($frags as $f) {
                $idx = 0;
                foreach ($starts as $i => $s) {
                    if ($f[0] - 8 >= $s) {
                        $idx = $i;
                    }
                }
                $groups[$idx][] = $f;
            }
        } else {
            $groups = array_map(fn($f) => [$f], $frags); // un fragment par image (cas le plus courant)
        }
        $fh = fopen($path, 'rb');
        $out = '';
        foreach ($groups[$frame] ?? [] as [$pos, $len]) {
            fseek($fh, $pos);
            $out .= (string) fread($fh, $len);
        }
        fclose($fh);
        return $out;
    }

    /** RLE DICOM : en-tête de 64 octets (nombre de segments + offsets), segments PackBits. */
    public static function rleDecode(string $enc, array $info): string
    {
        if (strlen($enc) < 64) {
            throw new BusinessException('Image RLE vide.');
        }
        $hdr = array_values(unpack('V16', substr($enc, 0, 64)));
        $nseg = $hdr[0];
        $pixels = $info['rows'] * $info['cols'];
        $bytesPer = intdiv($info['bits_allocated'], 8);
        if ($nseg < 1 || $nseg > 15 || $nseg !== $bytesPer * $info['samples']) {
            throw new BusinessException('Image RLE incohérente.');
        }
        $segs = [];
        for ($s = 0; $s < $nseg; $s++) {
            $start = $hdr[$s + 1];
            $end = $s + 1 < $nseg ? $hdr[$s + 2] : strlen($enc);
            $segs[] = self::packbits(substr($enc, $start, $end - $start), $pixels);
        }
        // Réassemblage : segments = octets de poids fort d'abord, par composante
        $out = '';
        if ($info['samples'] === 1 && $bytesPer === 1) {
            return $segs[0];
        }
        if ($info['samples'] === 1 && $bytesPer === 2) {
            $hi = $segs[0];
            $lo = $segs[1];
            $buf = [];
            for ($i = 0; $i < $pixels; $i++) {
                $buf[] = $lo[$i] . $hi[$i];
            }
            return implode('', $buf);
        }
        // Couleur 8 bits : R, G, B entrelacés
        $buf = [];
        for ($i = 0; $i < $pixels; $i++) {
            $buf[] = $segs[0][$i] . $segs[1][$i] . $segs[2][$i];
        }
        return implode('', $buf);
    }

    private static function packbits(string $in, int $expected): string
    {
        $out = '';
        $n = strlen($in);
        $i = 0;
        while ($i < $n && strlen($out) < $expected) {
            $c = ord($in[$i++]);
            if ($c < 128) {
                $out .= substr($in, $i, $c + 1);
                $i += $c + 1;
            } elseif ($c > 128) {
                $out .= str_repeat($in[$i] ?? "\0", 257 - $c);
                $i++;
            }
        }
        return str_pad(substr($out, 0, $expected), $expected, "\0");
    }

    /**
     * Aperçu PNG (miniature ou impression) : réduction par échantillonnage, fenêtrage automatique
     * (1er–99e centile) ou imposé, inversion pour MONOCHROME1.
     */
    public static function png(string $path, int $max = 256, int $frame = 0): string
    {
        $p = self::parse($path);
        $info = self::info($p);
        if (!$info['renderable']) {
            throw new BusinessException($info['render_note'] ?? 'Image non affichable.');
        }
        $w = $info['cols'];
        $h = $info['rows'];
        $step = max(1, (int) ceil(max($w, $h) / $max));
        $tw = intdiv($w + $step - 1, $step);
        $th = intdiv($h + $step - 1, $step);
        if ($p['ts'] === self::TS_JPEG_BASELINE) {
            $src = @imagecreatefromstring(self::frameEncoded($path, $p['pixel'], $info['frames'], $frame));
            if (!$src) {
                throw new BusinessException('JPEG illisible (12 bits ?).');
            }
            $img = imagecreatetruecolor($tw, $th);
            imagecopyresampled($img, $src, 0, 0, 0, 0, $tw, $th, imagesx($src), imagesy($src));
            return self::toPng($img);
        }
        $bytes = self::frameBytes($path, $p, $info, $frame);
        $img = imagecreatetruecolor($tw, $th);
        if ($info['samples'] === 3) {
            $planar = $info['planar'] === 1;
            $plane = $w * $h;
            for ($y = 0, $ty = 0; $y < $h; $y += $step, $ty++) {
                for ($x = 0, $tx = 0; $x < $w; $x += $step, $tx++) {
                    $i = $y * $w + $x;
                    if ($planar) {
                        $r = ord($bytes[$i]); $g = ord($bytes[$plane + $i]); $b = ord($bytes[2 * $plane + $i]);
                    } else {
                        $r = ord($bytes[3 * $i]); $g = ord($bytes[3 * $i + 1]); $b = ord($bytes[3 * $i + 2]);
                    }
                    if ($info['photometric'] === 'YBR_FULL') {
                        [$r, $g, $b] = [$r + 1.402 * ($b - 128), $r - 0.344136 * ($g - 128) - 0.714136 * ($b - 128), $r + 1.772 * ($g - 128)];
                    }
                    imagesetpixel($img, $tx, $ty, ((int) max(0, min(255, $r)) << 16) | ((int) max(0, min(255, $g)) << 8) | (int) max(0, min(255, $b)));
                }
            }
            return self::toPng($img);
        }
        // Niveaux de gris : valeurs échantillonnées puis fenêtrage
        $two = $info['bits_allocated'] === 16;
        $fmt = $two ? ($info['signed'] ? 's' : 'v') : ($info['signed'] ? 'c' : 'C');
        $mask = $info['bits_stored'] < ($two ? 16 : 8) && !$info['signed'] ? (1 << $info['bits_stored']) - 1 : null;
        $vals = [];
        for ($y = 0; $y < $h; $y += $step) {
            $row = substr($bytes, $y * $w * ($two ? 2 : 1), $w * ($two ? 2 : 1));
            $u = unpack($fmt . '*', $row);
            for ($x = 1; $x <= $w; $x += $step) {
                $v = $u[$x] ?? 0;
                if ($mask !== null) {
                    $v &= $mask;
                }
                $vals[] = $v * $info['slope'] + $info['intercept'];
            }
        }
        if ($info['window_width'] && $info['window_width'] > 1) {
            $lo = $info['window_center'] - $info['window_width'] / 2;
            $hi = $info['window_center'] + $info['window_width'] / 2;
        } else {
            $sorted = $vals;
            sort($sorted);
            $n = count($sorted);
            $lo = $sorted[(int) floor($n * 0.01)] ?? 0;
            $hi = $sorted[(int) floor($n * 0.99)] ?? 1;
        }
        $range = max(1e-6, $hi - $lo);
        $inv = $info['photometric'] === 'MONOCHROME1';
        $i = 0;
        for ($ty = 0; $ty < $th; $ty++) {
            for ($tx = 0; $tx < $tw; $tx++) {
                $g = (int) round(max(0, min(1, ($vals[$i++] - $lo) / $range)) * 255);
                if ($inv) {
                    $g = 255 - $g;
                }
                imagesetpixel($img, $tx, $ty, ($g << 16) | ($g << 8) | $g);
            }
        }
        return self::toPng($img);
    }

    private static function toPng(\GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** Nouvel identifiant DICOM unique (racine 2.25 + UUID en décimal). */
    public static function uid(): string
    {
        $hex = bin2hex(random_bytes(16));
        $dec = '0';
        foreach (str_split($hex) as $c) {
            // multiplication décimale par 16 + chiffre (chaîne, sans bcmath)
            $carry = hexdec($c);
            $out = '';
            for ($i = strlen($dec) - 1; $i >= 0; $i--) {
                $v = (int) $dec[$i] * 16 + $carry;
                $out = ($v % 10) . $out;
                $carry = intdiv($v, 10);
            }
            while ($carry > 0) {
                $out = ($carry % 10) . $out;
                $carry = intdiv($carry, 10);
            }
            $dec = ltrim($out, '0') ?: '0';
        }
        return '2.25.' . $dec;
    }
}
