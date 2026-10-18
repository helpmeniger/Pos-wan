<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\Sequence;

/** Élèves / étudiants et personnel : fiches, photos, consentement aux données faciales. */
final class Personnes
{
    private static function txt(array $in, string $k, int $max): ?string
    {
        $v = trim(mb_substr((string) ($in[$k] ?? ''), 0, $max));
        return $v === '' ? null : $v;
    }

    private static function tel(array $in, string $k): ?string
    {
        $v = self::txt($in, $k, 40);
        if ($v !== null && !preg_match('/^[0-9 +().-]{6,40}$/', $v)) {
            throw new BusinessException('Numéro de téléphone invalide : ' . $v);
        }
        return $v;
    }

    private static function dateOuNull(array $in, string $k): ?string
    {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v === '') {
            return null;
        }
        if (!Ecole::date($v) || $v > date('Y-m-d')) {
            throw new BusinessException('Date invalide : ' . $v);
        }
        return $v;
    }

    /** Consentement aux données faciales : obligatoire avant tout enregistrement de visage. */
    private static function consentement(array $in, ?array $actuel, bool $eleve): array
    {
        $oui = !empty($in['consentement_visage']);
        if (!$oui) {
            return ['consentement_visage' => 0] + ($eleve ? ['consentement_par' => null] : []) + ['consentement_date' => null];
        }
        $d = ['consentement_visage' => 1, 'consentement_date' => ($actuel['consentement_visage'] ?? 0) ? $actuel['consentement_date'] : date('Y-m-d H:i:s')];
        if ($eleve) {
            $par = self::txt($in, 'consentement_par', 150);
            if ($par === null) {
                throw new BusinessException('Consentement aux données faciales : indiquez qui l\'a donné (l\'élève majeur, ou son parent / tuteur).');
            }
            $d['consentement_par'] = $par;
        }
        return $d;
    }

    // ------------------------------------------------------------------ élèves / étudiants
    public static function sauverEleve(array $in, ?int $id = null, ?string $photoData = null, ?array $photoFile = null): int
    {
        $actuel = $id ? (DB::one('SELECT * FROM ecole_eleves WHERE id = ?', [$id]) ?? throw new BusinessException('Fiche introuvable.')) : null;
        $nom = self::txt($in, 'nom', 80);
        $prenom = self::txt($in, 'prenom', 100);
        if ($nom === null || $prenom === null) {
            throw new BusinessException('Nom et prénom obligatoires.');
        }
        $sexe = ($in['sexe'] ?? '') === 'F' ? 'F' : (($in['sexe'] ?? '') === 'M' ? 'M' : throw new BusinessException('Sexe obligatoire (M ou F).'));
        $d = ['nom' => mb_strtoupper($nom), 'prenom' => $prenom, 'sexe' => $sexe,
            'date_naissance' => self::dateOuNull($in, 'date_naissance'), 'lieu_naissance' => self::txt($in, 'lieu_naissance', 100),
            'nationalite' => self::txt($in, 'nationalite', 60), 'adresse' => self::txt($in, 'adresse', 255),
            'telephone' => self::tel($in, 'telephone'), 'email' => self::txt($in, 'email', 150),
            'tuteur_nom' => self::txt($in, 'tuteur_nom', 150), 'tuteur_lien' => self::txt($in, 'tuteur_lien', 40),
            'tuteur_tel' => self::tel($in, 'tuteur_tel'), 'tuteur_tel2' => self::tel($in, 'tuteur_tel2'),
            'infos_medicales' => self::txt($in, 'infos_medicales', 2000)] + self::consentement($in, $actuel, true);
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('Adresse e-mail invalide.');
        }
        if (isset($in['statut']) && in_array($in['statut'], ['actif', 'suspendu', 'sorti', 'diplome'], true)) {
            $d['statut'] = $in['statut'];
        }
        $photo = Ecole::enregistrerPhoto($photoData, $photoFile);
        if ($photo) {
            $d['photo'] = $photo;
        }
        return DB::transaction(function () use ($d, $id, $actuel, $photo, $in) {
            if ($id) {
                DB::update('ecole_eleves', $d, 'id = ?', [$id]);
                if ($photo && $actuel['photo']) {
                    Ecole::supprimerPhoto($actuel['photo']);
                }
            } else {
                $mat = self::txt($in, 'matricule', 30);
                if ($mat !== null && DB::value('SELECT 1 FROM ecole_eleves WHERE matricule = ?', [$mat])) {
                    throw new BusinessException('Matricule déjà utilisé : ' . $mat);
                }
                $id = DB::insert('ecole_eleves', $d + ['matricule' => $mat ?? Sequence::next('ecole_eleve')]);
            }
            // Retrait du consentement : les données faciales sont effacées immédiatement
            if (!$d['consentement_visage']) {
                Biometrie::supprimer('eleve', (int) $id, false);
            }
            Audit::log($actuel ? 'ecole.eleve_update' : 'ecole.eleve_create', null, $id);
            return (int) $id;
        });
    }

    public static function eleve(int $id): array
    {
        return DB::one('SELECT * FROM ecole_eleves WHERE id = ?', [$id]) ?? throw new BusinessException('Fiche introuvable.');
    }

    /** Recherche : nom, prénom, matricule, téléphone du tuteur ou de l'élève. */
    public static function chercherEleves(string $q, int $limite = 30): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $an = (int) (Ecole::anneeCourante()['id'] ?? 0);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $digits = preg_replace('/\D/', '', $q);
        return DB::all("SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.photo, e.statut, e.tuteur_tel, c.nom AS classe, i.id AS inscription_id
                        FROM ecole_eleves e LEFT JOIN ecole_inscriptions i ON i.eleve_id = e.id AND i.annee_id = ? LEFT JOIN ecole_classes c ON c.id = i.classe_id
                        WHERE e.matricule = ? OR CONCAT(e.nom, ' ', e.prenom) LIKE ? OR CONCAT(e.prenom, ' ', e.nom) LIKE ?" . (strlen($digits) >= 6 ? ' OR REPLACE(REPLACE(e.tuteur_tel, \' \', \'\'), \'+\', \'\') LIKE ? OR REPLACE(REPLACE(e.telephone, \' \', \'\'), \'+\', \'\') LIKE ?' : '') . '
                        ORDER BY e.nom, e.prenom LIMIT ' . $limite, array_merge([$an, $q, $like, $like], strlen($digits) >= 6 ? ['%' . $digits . '%', '%' . $digits . '%'] : []));
    }

    // ------------------------------------------------------------------ personnel
    public static function sauverPersonnel(array $in, ?int $id = null, ?string $photoData = null, ?array $photoFile = null): int
    {
        $actuel = $id ? (DB::one('SELECT * FROM ecole_personnel WHERE id = ?', [$id]) ?? throw new BusinessException('Fiche introuvable.')) : null;
        $nom = self::txt($in, 'nom', 80);
        $prenom = self::txt($in, 'prenom', 100);
        if ($nom === null || $prenom === null) {
            throw new BusinessException('Nom et prénom obligatoires.');
        }
        $fonction = (string) ($in['fonction'] ?? 'enseignant');
        if (!isset(Ecole::FONCTIONS[$fonction])) {
            throw new BusinessException('Fonction inconnue.');
        }
        $sal = trim((string) ($in['salaire'] ?? ''));
        $d = ['nom' => mb_strtoupper($nom), 'prenom' => $prenom, 'sexe' => in_array($in['sexe'] ?? '', ['M', 'F'], true) ? $in['sexe'] : null,
            'fonction' => $fonction, 'poste' => self::txt($in, 'poste', 100), 'telephone' => self::tel($in, 'telephone'),
            'email' => self::txt($in, 'email', 150), 'adresse' => self::txt($in, 'adresse', 255),
            'date_naissance' => self::dateOuNull($in, 'date_naissance'), 'date_embauche' => self::dateOuNull($in, 'date_embauche'),
            'salaire' => $sal === '' ? null : max(0, round((float) str_replace([' ', ','], ['', '.'], $sal), 2)),
            'statut' => ($in['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif'] + self::consentement($in, $actuel, false);
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('Adresse e-mail invalide.');
        }
        $photo = Ecole::enregistrerPhoto($photoData, $photoFile);
        if ($photo) {
            $d['photo'] = $photo;
        }
        return DB::transaction(function () use ($d, $id, $actuel, $photo, $in) {
            if ($id) {
                DB::update('ecole_personnel', $d, 'id = ?', [$id]);
                if ($photo && $actuel['photo']) {
                    Ecole::supprimerPhoto($actuel['photo']);
                }
            } else {
                $mat = self::txt($in, 'matricule', 30);
                if ($mat !== null && DB::value('SELECT 1 FROM ecole_personnel WHERE matricule = ?', [$mat])) {
                    throw new BusinessException('Matricule déjà utilisé : ' . $mat);
                }
                $id = DB::insert('ecole_personnel', $d + ['matricule' => $mat ?? Sequence::next('ecole_personnel')]);
            }
            if (!$d['consentement_visage']) {
                Biometrie::supprimer('personnel', (int) $id, false);
            }
            Audit::log($actuel ? 'ecole.personnel_update' : 'ecole.personnel_create', null, $id);
            return (int) $id;
        });
    }

    public static function personnel(int $id): array
    {
        return DB::one('SELECT * FROM ecole_personnel WHERE id = ?', [$id]) ?? throw new BusinessException('Fiche introuvable.');
    }

    /** Fiche courte affichée au pointage (« qui est-ce ? »). */
    public static function carte(string $type, int $id): ?array
    {
        if ($type === 'eleve') {
            $an = (int) (Ecole::anneeCourante()['id'] ?? 0);
            $p = DB::one('SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.photo, e.statut, e.tuteur_nom, e.tuteur_tel, c.nom AS classe FROM ecole_eleves e
                          LEFT JOIN ecole_inscriptions i ON i.eleve_id = e.id AND i.annee_id = ? AND i.statut = \'active\' LEFT JOIN ecole_classes c ON c.id = i.classe_id WHERE e.id = ?', [$an, $id]);
            return $p ? ['type' => 'eleve', 'id' => (int) $p['id'], 'matricule' => $p['matricule'], 'nom' => $p['prenom'] . ' ' . $p['nom'], 'detail' => $p['classe'] ? Ecole::apprenant() . ' · ' . $p['classe'] : Ecole::apprenant() . ' · non inscrit cette année',
                'statut' => $p['statut'], 'contact' => $p['tuteur_nom'] ? $p['tuteur_nom'] . ' · ' . $p['tuteur_tel'] : null, 'photo' => $p['photo'] ? url('/ecole/photo/eleve/' . $p['id']) : null] : null;
        }
        $p = DB::one('SELECT id, matricule, nom, prenom, fonction, poste, telephone, photo, statut FROM ecole_personnel WHERE id = ?', [$id]);
        return $p ? ['type' => 'personnel', 'id' => (int) $p['id'], 'matricule' => $p['matricule'], 'nom' => $p['prenom'] . ' ' . $p['nom'], 'detail' => $p['poste'] ?: Ecole::FONCTIONS[$p['fonction']],
            'statut' => $p['statut'], 'contact' => $p['telephone'], 'photo' => $p['photo'] ? url('/ecole/photo/personnel/' . $p['id']) : null] : null;
    }
}
