<div class="container" style="padding:24px 16px;max-width:820px"><div class="card">
  <h1><?= e($pageContent['title']) ?></h1>
  <div><?= nl2br(e($pageContent['body'])) ?></div>
  <p class="small muted" style="margin-top:18px">Mis à jour le <?= e(date('d/m/Y', strtotime($pageContent['updated_at']))) ?></p>
</div></div>
