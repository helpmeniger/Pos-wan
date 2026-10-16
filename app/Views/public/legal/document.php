<?php $this->layout('layouts/app'); ?>
<div class="container py-4 jn-legal" style="max-width:900px">
  <h1 class="h3"><?= e($title) ?></h1>
  <p class="text-muted small">Version <?= e($version) ?><?= $document ? ' — publiée le ' . e(date_fr($document['published_at'])) : '' ?></p>
  <?php if ($document): ?>
    <div style="white-space: pre-line"><?= e($document['content']) ?></div>
  <?php else: ?>
    <div class="alert bg-accent-soft jn-draft-banner">
      <strong>Document provisoire.</strong> Ce texte est un projet qui doit être validé par un juriste au regard du droit nigérien
      et des règles applicables de l'UEMOA avant la mise en production de la plateforme.
    </div>
    <?= $this->partial($fallbackView) ?>
  <?php endif; ?>
</div>
