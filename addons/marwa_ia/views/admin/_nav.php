<?php /** Navigation de l'administration MARWA AI. */ $cur = $cur ?? ''; ?>
<ul class="nav nav-pills mb-3 small flex-wrap">
  <?php foreach (['admin' => ['/ia/admin', 'bi-speedometer2', 'Pilotage'], 'models' => ['/ia/admin/modeles', 'bi-cpu', 'Modèles'], 'knowledge' => ['/ia/admin/connaissances', 'bi-journal-text', 'Connaissances'],
      'intents' => ['/ia/admin/intentions', 'bi-diagram-3', 'Intentions'], 'dict' => ['/ia/admin/dictionnaire', 'bi-table', 'Dictionnaire de données'], 'settings' => ['/ia/reglages', 'bi-sliders', 'Réglages']] as $k => $n): ?>
    <li class="nav-item"><a class="nav-link<?= $cur === $k ? ' active' : '' ?>" href="<?= e(url($n[0])) ?>"><i class="bi <?= $n[1] ?>"></i> <?= e($n[2]) ?></a></li>
  <?php endforeach; ?>
</ul>
