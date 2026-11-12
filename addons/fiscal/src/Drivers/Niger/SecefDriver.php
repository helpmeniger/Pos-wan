<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Niger;

use Addons\Fiscal\DriverResult;
use Addons\Fiscal\Drivers\AbstractDriver;

/**
 * SQUELETTE d'extension — NON COMPATIBLE en l'état.
 * Aucune spécification officielle n'a été fournie ni vérifiée pour ce pays. Toutes les fonctions
 * répondent « non prise en charge » ; rien n'est envoyé, rien n'est certifié.
 * Pour le développer : obtenir la spécification officielle (protocole, formats, environnement de test)
 * auprès de l'administration fiscale ou du fournisseur agréé, puis suivre addons/fiscal/docs/AJOUTER_UN_MCF.md.
 * En attendant, si le pays expose une API documentée, le pilote générique REST/SOAP peut être configuré.
 */
final class SecefDriver extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'ne_secef', 'label' => 'Niger — SECeF (squelette)', 'kind' => 'mcf', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette sans implémentation : spécification officielle requise. Ne pas utiliser en production.'];
    }

    public function testConnection(): DriverResult
    {
        return DriverResult::fail(DriverResult::UNSUPPORTED, 'Pilote « Niger — SECeF (squelette) » non développé : la spécification officielle est requise.');
    }
}
