<?php $cls = ['pending' => 'warning', 'sending' => 'info', 'certified' => 'success', 'rejected' => 'danger', 'failed' => 'danger'][$s] ?? 'secondary'; ?>
<span class="badge text-bg-<?= $cls ?>"><?= e(t(Addons\Fiscal\FiscalAdmin::STATUSES[$s] ?? $s)) ?></span><?php if (!empty($sim)): ?> <span class="badge text-bg-dark">SIMULATION – NON CERTIFIÉ</span><?php endif; ?>
