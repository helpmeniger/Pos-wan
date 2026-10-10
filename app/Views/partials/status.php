<?php
$map = ['completed' => ['success', 'Validée'], 'cancelled' => ['danger', 'Annulée'], 'partially_returned' => ['warning text-dark', 'Retour partiel'], 'returned' => ['secondary', 'Retournée'],
    'draft' => ['secondary', 'Brouillon'], 'ordered' => ['primary', 'Commandée'], 'partial' => ['warning text-dark', 'Reçue partiellement'], 'received' => ['success', 'Reçue'],
    'validated' => ['success', 'Validé'], 'open' => ['success', 'Ouverte'], 'closed' => ['secondary', 'Clôturée'], 'valid' => ['success', 'Valide'], 'reversed' => ['danger', 'Contrepassée'],
    'active' => ['success', 'Actif'], 'archived' => ['secondary', 'Archivé'],
    'sent' => ['info text-dark', 'Envoyé'], 'accepted' => ['primary', 'Accepté'], 'refused' => ['danger', 'Refusé'], 'converted' => ['success', 'Facturé']];
[$cls, $lbl] = $map[$status] ?? ['secondary', $status];
?><span class="badge bg-<?= e($cls) ?>"><?= e($lbl) ?></span>
