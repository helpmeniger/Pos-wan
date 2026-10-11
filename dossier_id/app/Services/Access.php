<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Contrôle d'accès au niveau de la fiche (côté serveur).
 * Toute ressource liée (photo, passage, procédure, document) hérite
 * des restrictions de la fiche à laquelle elle appartient.
 */
final class Access
{
    public static function canViewPerson(array $person): bool
    {
        if (!Auth::can('persons.view')) {
            return false;
        }
        if ($person['access_level'] === 'restreint' && !Auth::can('persons.view_restricted')) {
            return false;
        }
        if ($person['status'] === 'archive' && !Auth::can('persons.view_archived')) {
            return false;
        }
        return true;
    }

    /** Conditions SQL équivalentes à canViewPerson() pour les listes (alias de table « p »). */
    public static function personSqlFilter(string $alias = 'p'): string
    {
        $cond = [];
        if (!Auth::can('persons.view_restricted')) {
            $cond[] = "$alias.access_level = 'standard'";
        }
        if (!Auth::can('persons.view_archived')) {
            $cond[] = "$alias.status = 'actif'";
        }
        return $cond ? implode(' AND ', $cond) : '1=1';
    }

    /** Charge une fiche et vérifie l'accès ; 404 si inexistante, 403 si non autorisée. */
    public static function personOrFail(int $id, string $permission = 'persons.view'): array
    {
        Auth::require('persons.view');
        if ($permission !== 'persons.view') {
            Auth::require($permission);
        }
        $person = Database::one('SELECT * FROM persons WHERE id = :id', ['id' => $id]);
        if (!$person) {
            throw new HttpException(404, 'Fiche introuvable.');
        }
        if (!self::canViewPerson($person)) {
            Audit::log('access.denied', 'person', $id, ['raison' => 'fiche restreinte ou archivée']);
            throw new HttpException(403, "Cette fiche fait l'objet d'une restriction d'accès.");
        }
        return $person;
    }
}
