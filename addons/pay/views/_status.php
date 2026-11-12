<?php $cls = ['pending' => 'warning', 'processing' => 'info', 'succeeded' => 'success', 'declined' => 'danger', 'cancelled' => 'secondary', 'expired' => 'secondary',
    'refunded' => 'dark', 'partially_refunded' => 'dark', 'to_verify' => 'warning', 'failed' => 'danger'][$s] ?? 'secondary'; ?>
<span class="badge text-bg-<?= $cls ?>"><?= e(t(Addons\Pay\PayEngine::STATUSES[$s] ?? $s)) ?></span><?php if (!empty($test)): ?> <span class="badge text-bg-dark">TEST</span><?php endif; ?>
