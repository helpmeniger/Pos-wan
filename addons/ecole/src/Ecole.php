<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;

/** Établissement : années scolaires, périodes, classes, matières, libellés et photos. */
final class Ecole
{
    public const TYPES = ['ecole' => 'École (maternelle / primaire)', 'secondaire' => 'Collège / lycée', 'universite' => 'Université / institut supérieur', 'mixte' => 'Plusieurs cycles'];
    public const CYCLES = ['maternelle' => 'Maternelle', 'primaire' => 'Primaire', 'college' => 'Collège', 'lycee' => 'Lycée', 'superieur' => 'Supérieur', 'formation' => 'Formation professionnelle'];
    public const FONCTIONS = ['enseignant' => 'Enseignant(e)', 'direction' => 'Direction', 'administration' => 'Administration', 'surveillance' => 'Surveillance', 'service' => 'Service / entretien', 'autre' => 'Autre'];

    /** « Élève » ou « Étudiant » selon le type d'établissement. */
    public static function apprenant(bool $pluriel = false): string
    {
        $u = setting('ecole_type', 'mixte') === 'universite';
        return $u ? ($pluriel ? 'Étudiants' : 'Étudiant') : ($pluriel ? 'Élèves' : 'Élève');
    }

    public static function nom(): string
    {
        return (string) (setting('company_name', '') ?: 'Établissement');
    }

    // ------------------------------------------------------------------ années et périodes
    public static function anneeCourante(): ?array
    {
        return DB::one('SELECT * FROM ecole_annees WHERE courante = 1 ORDER BY id DESC LIMIT 1');
    }

    public static function anneeCouranteOuErreur(): array
    {
        return self::anneeCourante() ?? throw new BusinessException('Aucune année scolaire en cours : créez-la dans Paramètres de l\'établissement.');
    }

    /** Crée une année scolaire avec ses périodes (trimestres ou semestres). */
    public static function creerAnnee(string $libelle, string $debut, string $fin, string $decoupage, bool $courante): int
    {
        $libelle = trim(mb_substr($libelle, 0, 20));
        if ($libelle === '' || !self::date($debut) || !self::date($fin) || $fin <= $debut) {
            throw new BusinessException('Année scolaire invalide : libellé, date de début et date de fin (après le début) obligatoires.');
        }
        if (DB::value('SELECT 1 FROM ecole_annees WHERE libelle = ?', [$libelle])) {
            throw new BusinessException('L\'année « ' . $libelle . ' » existe déjà.');
        }
        return DB::transaction(function () use ($libelle, $debut, $fin, $decoupage, $courante) {
            if ($courante) {
                DB::run('UPDATE ecole_annees SET courante = 0');
            }
            $id = DB::insert('ecole_annees', ['libelle' => $libelle, 'debut' => $debut, 'fin' => $fin, 'courante' => $courante ? 1 : 0]);
            $n = $decoupage === 'semestre' ? 2 : 3;
            $nom = $decoupage === 'semestre' ? 'Semestre' : 'Trimestre';
            $t0 = strtotime($debut); $t1 = strtotime($fin);
            for ($i = 1; $i <= $n; $i++) {
                $a = (int) round($t0 + ($t1 - $t0) * ($i - 1) / $n);
                $b = (int) round($t0 + ($t1 - $t0) * $i / $n) - ($i < $n ? 86400 : 0);
                DB::insert('ecole_periodes', ['annee_id' => $id, 'nom' => "$nom $i", 'ordre' => $i, 'debut' => date('Y-m-d', $a), 'fin' => date('Y-m-d', $b)]);
            }
            Audit::log('ecole.annee', null, $id, ['libelle' => $libelle]);
            return $id;
        });
    }

    public static function rendreCourante(int $id): void
    {
        DB::value('SELECT 1 FROM ecole_annees WHERE id = ?', [$id]) ?: throw new BusinessException('Année introuvable.');
        DB::transaction(function () use ($id) {
            DB::run('UPDATE ecole_annees SET courante = 0');
            DB::run('UPDATE ecole_annees SET courante = 1 WHERE id = ?', [$id]);
        });
        Audit::log('ecole.annee_courante', null, $id);
    }

    public static function periodes(int $anneeId): array
    {
        return DB::all('SELECT * FROM ecole_periodes WHERE annee_id = ? ORDER BY ordre', [$anneeId]);
    }

    // ------------------------------------------------------------------ classes et matières
    public static function sauverClasse(array $in, ?int $id = null): int
    {
        $an = self::anneeCouranteOuErreur();
        $nom = trim(mb_substr((string) ($in['nom'] ?? ''), 0, 80));
        if ($nom === '') {
            throw new BusinessException('Nom de la classe obligatoire (ex. : CM2 A, 6e B, Licence 1 Informatique).');
        }
        $num = fn($k) => max(0, round((float) str_replace([' ', ','], ['', '.'], (string) ($in[$k] ?? 0)), 2));
        $d = ['nom' => $nom, 'niveau' => trim(mb_substr((string) ($in['niveau'] ?? ''), 0, 60)) ?: null,
            'cycle' => isset(self::CYCLES[$in['cycle'] ?? '']) ? $in['cycle'] : 'primaire',
            'salle' => trim(mb_substr((string) ($in['salle'] ?? ''), 0, 50)) ?: null,
            'capacite' => (int) ($in['capacite'] ?? 0) ?: null,
            'titulaire_id' => (int) ($in['titulaire_id'] ?? 0) ?: null,
            'frais_inscription' => $num('frais_inscription'), 'frais_scolarite' => $num('frais_scolarite'),
            'nb_tranches' => max(1, min(12, (int) ($in['nb_tranches'] ?? 3))), 'active' => isset($in['active']) ? (int) (bool) $in['active'] : 1];
        $annee = $id ? (int) DB::value('SELECT annee_id FROM ecole_classes WHERE id = ?', [$id]) : (int) $an['id'];
        if (DB::value('SELECT 1 FROM ecole_classes WHERE annee_id = ? AND nom = ? AND id <> ?', [$annee, $nom, (int) $id])) {
            throw new BusinessException('La classe « ' . $nom . ' » existe déjà pour cette année.');
        }
        if ($id) {
            DB::update('ecole_classes', $d, 'id = ?', [$id]);
        } else {
            $id = DB::insert('ecole_classes', $d + ['annee_id' => $an['id']]);
        }
        Audit::log('ecole.classe', null, $id, ['nom' => $nom]);
        return (int) $id;
    }

    public static function sauverMatiere(string $nom, ?string $code): int
    {
        $nom = trim(mb_substr($nom, 0, 100));
        if ($nom === '') {
            throw new BusinessException('Nom de la matière obligatoire.');
        }
        $id = DB::value('SELECT id FROM ecole_matieres WHERE nom = ?', [$nom]);
        if ($id) {
            DB::update('ecole_matieres', ['code' => $code ?: null, 'active' => 1], 'id = ?', [$id]);
            return (int) $id;
        }
        return DB::insert('ecole_matieres', ['nom' => $nom, 'code' => $code ? mb_substr($code, 0, 20) : null]);
    }

    /** Programme d'une classe : matières, coefficients, crédits, enseignants. */
    public static function programme(int $classeId, array $lignes): void
    {
        DB::value('SELECT 1 FROM ecole_classes WHERE id = ?', [$classeId]) ?: throw new BusinessException('Classe introuvable.');
        DB::transaction(function () use ($classeId, $lignes) {
            $garder = [];
            foreach ($lignes as $l) {
                $mid = (int) ($l['matiere_id'] ?? 0);
                if (!$mid || !DB::value('SELECT 1 FROM ecole_matieres WHERE id = ?', [$mid])) {
                    continue;
                }
                $coef = round((float) str_replace(',', '.', (string) ($l['coefficient'] ?? 1)), 2);
                if ($coef <= 0 || $coef > 20) {
                    throw new BusinessException('Coefficient invalide (entre 0,5 et 20).');
                }
                $cred = isset($l['credits']) && $l['credits'] !== '' ? max(0, round((float) str_replace(',', '.', (string) $l['credits']), 2)) : null;
                $ens = (int) ($l['enseignant_id'] ?? 0) ?: null;
                DB::run('INSERT INTO ecole_classe_matieres (classe_id, matiere_id, coefficient, credits, enseignant_id) VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE coefficient = VALUES(coefficient), credits = VALUES(credits), enseignant_id = VALUES(enseignant_id)', [$classeId, $mid, $coef, $cred, $ens]);
                $garder[] = $mid;
            }
            // Une matière qui a déjà des évaluations n'est jamais retirée (notes conservées)
            foreach (DB::all('SELECT matiere_id FROM ecole_classe_matieres WHERE classe_id = ?', [$classeId]) as $r) {
                $mid = (int) $r['matiere_id'];
                if (!in_array($mid, $garder, true) && !DB::value('SELECT 1 FROM ecole_evaluations WHERE classe_id = ? AND matiere_id = ? LIMIT 1', [$classeId, $mid])) {
                    DB::run('DELETE FROM ecole_classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$classeId, $mid]);
                }
            }
        });
        Audit::log('ecole.programme', null, $classeId);
    }

    public static function classes(?int $anneeId = null, bool $actives = true): array
    {
        $anneeId ??= (int) (self::anneeCourante()['id'] ?? 0);
        return DB::all('SELECT c.*, (SELECT COUNT(*) FROM ecole_inscriptions i WHERE i.classe_id = c.id AND i.statut = \'active\') AS effectif,
                        CONCAT(p.prenom, \' \', p.nom) AS titulaire FROM ecole_classes c LEFT JOIN ecole_personnel p ON p.id = c.titulaire_id
                        WHERE c.annee_id = ?' . ($actives ? ' AND c.active = 1' : '') . ' ORDER BY c.cycle, c.niveau, c.nom', [$anneeId]);
    }

    /** Nombre sans zéros inutiles : 10 → « 10 », 2.50 → « 2.5 » (barèmes, coefficients, crédits). */
    public static function nb(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    public static function date(?string $d): bool
    {
        return (bool) ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d));
    }

    // ------------------------------------------------------------------ photos (hors du dossier public)
    private static function dossierPhotos(): string
    {
        $d = STORAGE_PATH . '/ecole/photos';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
            @file_put_contents(STORAGE_PATH . '/ecole/.htaccess', "Require all denied\n");
        }
        return $d;
    }

    /**
     * Enregistre une photo (prise par la caméra : data URL JPEG/PNG, ou fichier envoyé).
     * Image réencodée en JPEG 480 px maximum ; nom aléatoire ; jamais dans le dossier public.
     */
    public static function enregistrerPhoto(?string $dataUrl, ?array $file): ?string
    {
        $bin = null;
        if ($dataUrl && preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            $bin = base64_decode($m[2], true) ?: null;
        } elseif ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            if ($file['size'] > 8 * 1024 * 1024) {
                throw new BusinessException('Photo trop volumineuse (8 Mo maximum).');
            }
            $bin = (string) file_get_contents($file['tmp_name']);
        }
        if ($bin === null) {
            return null;
        }
        if (strlen($bin) > 8 * 1024 * 1024 || !function_exists('imagecreatefromstring') || !($img = @imagecreatefromstring($bin))) {
            throw new BusinessException('Photo illisible (formats acceptés : JPEG, PNG, WEBP).');
        }
        $w = imagesx($img); $h = imagesy($img); $k = min(1, 480 / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        $name = bin2hex(random_bytes(16)) . '.jpg';
        imagejpeg($out, self::dossierPhotos() . '/' . $name, 85);
        return $name;
    }

    public static function supprimerPhoto(?string $name): void
    {
        if ($name && preg_match('/^[a-f0-9]{32}\.jpg$/', $name)) {
            @unlink(self::dossierPhotos() . '/' . $name);
        }
    }

    public static function cheminPhoto(?string $name): ?string
    {
        return $name && preg_match('/^[a-f0-9]{32}\.jpg$/', $name) && is_file(self::dossierPhotos() . '/' . $name) ? self::dossierPhotos() . '/' . $name : null;
    }
}
