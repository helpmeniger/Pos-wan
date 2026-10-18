<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Données faciales (empreintes de 128 nombres calculées dans le navigateur par un réseau de
 * neurones de reconnaissance faciale). Règles :
 *  - désactivées par défaut (Paramètres de l'établissement) ;
 *  - jamais enregistrées sans consentement noté sur la fiche (parent / tuteur pour un mineur) ;
 *  - effacées dès que le consentement est retiré, ou sur demande ;
 *  - aucune photo du visage n'est stockée ici, seulement l'empreinte (non réversible en photo).
 */
final class Biometrie
{
    public const MAX_PAR_PERSONNE = 12;

    public static function active(): bool
    {
        return setting('ecole_visage_actif', '0') === '1';
    }

    private static function table(string $type): string
    {
        return match ($type) { 'eleve' => 'ecole_eleves', 'personnel' => 'ecole_personnel', default => throw new BusinessException('Type de personne inconnu.') };
    }

    /** Empreinte reçue : 128 nombres à virgule (float32), finis, non nuls. */
    public static function decoder(string $b64): string
    {
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) !== 512) {
            throw new BusinessException('Empreinte faciale invalide.');
        }
        $f = unpack('g128', $bin);
        $n = 0.0;
        foreach ($f as $x) {
            if (!is_finite($x) || abs($x) > 10) {
                throw new BusinessException('Empreinte faciale invalide.');
            }
            $n += $x * $x;
        }
        if ($n < 0.01) {
            throw new BusinessException('Empreinte faciale vide.');
        }
        return $bin;
    }

    public static function enregistrer(string $type, int $id, array $empreintes, string $source = 'inscription'): int
    {
        if (!self::active()) {
            throw new BusinessException('La reconnaissance faciale est désactivée (Paramètres de l\'établissement).');
        }
        $p = DB::one('SELECT id, consentement_visage FROM ' . self::table($type) . ' WHERE id = ?', [$id]) ?? throw new BusinessException('Fiche introuvable.');
        if (!$p['consentement_visage']) {
            throw new BusinessException('Consentement aux données faciales non enregistré sur la fiche : impossible d\'enregistrer le visage.');
        }
        if (!in_array($source, ['inscription', 'pointage', 'correction'], true)) {
            throw new BusinessException('Origine inconnue.');
        }
        $bins = array_map([self::class, 'decoder'], array_slice(array_values($empreintes), 0, self::MAX_PAR_PERSONNE));
        if (!$bins) {
            throw new BusinessException('Aucun visage à enregistrer.');
        }
        return DB::transaction(function () use ($type, $id, $bins, $source) {
            foreach ($bins as $b) {
                DB::insert('ecole_visages', ['personne_type' => $type, 'personne_id' => $id, 'descriptor' => $b, 'source' => $source, 'user_id' => Auth::id()]);
            }
            // On garde les plus récentes au-delà du maximum
            $trop = DB::all('SELECT id FROM ecole_visages WHERE personne_type = ? AND personne_id = ? ORDER BY id DESC LIMIT 1000 OFFSET ' . self::MAX_PAR_PERSONNE, [$type, $id]);
            if ($trop) {
                DB::run('DELETE FROM ecole_visages WHERE id IN (' . implode(',', array_map('intval', array_column($trop, 'id'))) . ')');
            }
            self::versionSuivante();
            Audit::log('ecole.visage_enregistre', null, $id, ['type' => $type, 'nombre' => count($bins), 'source' => $source]);
            return (int) DB::value('SELECT COUNT(*) FROM ecole_visages WHERE personne_type = ? AND personne_id = ?', [$type, $id]);
        });
    }

    public static function supprimer(string $type, int $id, bool $journal = true): int
    {
        self::table($type);
        $n = DB::run('DELETE FROM ecole_visages WHERE personne_type = ? AND personne_id = ?', [$type, $id])->rowCount();
        if ($n) {
            self::versionSuivante();
        }
        if ($journal || $n) {
            Audit::log('ecole.visage_supprime', null, $id, ['type' => $type, 'nombre' => $n]);
        }
        return $n;
    }

    public static function nombre(string $type, int $id): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM ecole_visages WHERE personne_type = ? AND personne_id = ?', [$type, $id]);
    }

    public static function version(): int
    {
        return max(1, (int) setting('ecole_visages_version', '1'));
    }

    private static function versionSuivante(): void
    {
        setting_set('ecole_visages_version', (string) (self::version() + 1));
    }

    /** Galerie envoyée aux postes de pointage : personnes actives ayant donné leur consentement. */
    public static function galerie(): array
    {
        $rows = DB::all("SELECT v.personne_type, v.personne_id, v.descriptor FROM ecole_visages v
                         LEFT JOIN ecole_eleves e ON v.personne_type = 'eleve' AND e.id = v.personne_id
                         LEFT JOIN ecole_personnel p ON v.personne_type = 'personnel' AND p.id = v.personne_id
                         WHERE (e.id IS NOT NULL AND e.statut = 'actif' AND e.consentement_visage = 1) OR (p.id IS NOT NULL AND p.statut = 'actif' AND p.consentement_visage = 1)
                         ORDER BY v.personne_type, v.personne_id, v.id");
        return ['version' => self::version(), 'types' => array_column($rows, 'personne_type'), 'ids' => array_map('intval', array_column($rows, 'personne_id')),
            'empreintes' => base64_encode(implode('', array_column($rows, 'descriptor')))];
    }
}
