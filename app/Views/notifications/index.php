<?php $this->layout('layouts/app'); ?>
<div class="container py-4" style="max-width:820px">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0">Mes notifications</h1>
    <form method="post" action="<?= e(url('/notifications/tout-lire')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check2-all" aria-hidden="true"></i> Tout marquer comme lu</button>
    </form>
  </div>
  <div class="jn-card p-3"><?= $this->partial('partials/notifications-list', ['notifications' => $notifications]) ?></div>
</div>
