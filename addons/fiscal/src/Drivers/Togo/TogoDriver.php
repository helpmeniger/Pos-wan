<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Togo;

use Addons\Fiscal\DriverResult;
use Addons\Fiscal\Drivers\AbstractDriver;

/**
 * SQUELETTE d'extension — NON COMPATIBLE en l'état.
 * Aucune spécification officielle n'a été fournie ni vérifiée pour ce pays. Toutes les fonctions
 * répondent « non prise en charge » ; rien n'est envoyé, rien n'est certifié.
 * Pour le développer : obtenir la spécification officielle (protocole, formats, environnement de test)
 * auprès de l'administration fiscale ou du fournisseur agréé, puis suivre docs/fiscal/AJOUTER_UN_MCF.md.
 * En attendant, si le pays expose une API documentée, le pilote générique REST/SOAP peut être configuré.
 */
final class TogoDriver extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'tg_skeleton', 'label' => 'Togo (squelette)', 'kind' => 'mcf', 'transports' => [], 'verified' => false, 'skeleton' => true,
            'notes' => 'Squelette sans implémentation : spécification officielle requise. Ne pas utiliser en production.'];
    }

    public function testConnection(): DriverResult
    {
        return DriverResult::fail(DriverResult::UNSUPPORTED, 'Pilote « Togo (squelette) » non développé : la spécification officielle est requise.');
    }
}
