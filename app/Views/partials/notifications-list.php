<?php /** @var list<array> $notifications */ ?>
<?php if (!$notifications): ?>
  <p class="text-muted small mb-0">Aucune notification pour le moment.</p>
<?php else: ?>
  <ul class="list-group list-group-flush">
    <?php foreach ($notifications as $n): ?>
      <li class="list-group-item px-0 d-flex gap-2 align-items-start<?= $n['read_at'] ? '' : ' fw-semibold' ?>">
        <i class="bi <?= $n['read_at'] ? 'bi-bell' : 'bi-bell-fill text-primary' ?> mt-1" aria-hidden="true"></i>
        <div class="flex-grow-1">
          <div><?= e($n['title']) ?></div>
          <?php if ($n['body']): ?><div class="small text-muted fw-normal"><?= e($n['body']) ?></div><?php endif; ?>
          <div class="small text-muted fw-normal"><?= e(date_fr($n['created_at'], true)) ?></div>
        </div>
        <?php if (!$n['read_at']): ?>
          <form method="post" action="<?= e(url('/notifications/' . (int) $n['id'] . '/lue')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-link" type="submit" aria-label="Marquer comme lue"><i class="bi bi-check2" aria-hidden="true"></i></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
