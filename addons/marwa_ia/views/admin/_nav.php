<?php /** Navigation du MARWA AI TRAINING CENTER. */ $cur = $cur ?? ''; ?>
<div class="d-flex align-items-center mb-2"><h5 class="mb-0"><i class="bi bi-mortarboard"></i> MARWA AI Training Center</h5><a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(url('/ia')) ?>"><i class="bi bi-stars"></i> Ouvrir l'assistant</a></div>
<ul class="nav nav-pills mb-3 small flex-wrap">
  <?php foreach (['admin' => ['/ia/admin', 'bi-speedometer2', 'Tableau de bord'], 'knowledge' => ['/ia/admin/connaissances', 'bi-journal-text', 'Base de connaissances'], 'scenarios' => ['/ia/admin/scenarios', 'bi-collection', 'Scénarios'],
      'intents' => ['/ia/admin/intentions', 'bi-diagram-3', 'Intentions'], 'synonyms' => ['/ia/admin/synonymes', 'bi-translate', 'Synonymes'], 'unresolved' => ['/ia/admin/incomprises', 'bi-question-circle', 'Questions incomprises'],
      'corrections' => ['/ia/admin/corrections', 'bi-hand-thumbs-down', 'Corrections'], 'tests' => ['/ia/admin/tests', 'bi-check2-square', 'Tests automatiques'], 'performance' => ['/ia/admin/performance', 'bi-graph-up', 'Performance'],
      'models' => ['/ia/admin/modeles', 'bi-cpu', 'Modèles'], 'history' => ['/ia/admin/historique', 'bi-clock-history', 'Historique'], 'dict' => ['/ia/admin/dictionnaire', 'bi-table', 'Dictionnaire'], 'settings' => ['/ia/reglages', 'bi-sliders', 'Réglages']] as $k => $n): ?>
    <li class="nav-item"><a class="nav-link py-1 px-2<?= $cur === $k ? ' active' : '' ?>" href="<?= e(url($n[0])) ?>"><i class="bi <?= $n[1] ?>"></i> <?= e($n[2]) ?></a></li>
  <?php endforeach; ?>
</ul>
