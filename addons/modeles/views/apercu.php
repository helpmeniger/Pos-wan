<?php /* Aperçu d'un modèle avec un document fictif (rien n'est enregistré). Dans l'éditeur (POST), sans barre d'impression. */ ?>
<?php if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'): ?><style>.print-toolbar{display:none}</style><?php endif; ?>
<div class="no-print" style="text-align:center;font:13px Arial,sans-serif;color:#555;padding:4px">Aperçu avec une vente fictive — les vrais documents utilisent vos ventes.</div>
<?= App\Core\View::partial('modeles::rendu', ['doc' => $doc, 'modele' => $modele]) ?>
