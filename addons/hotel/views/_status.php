<?php /* Badge du statut d'un séjour. */ use Addons\Hotel\HotelService as HS; ?><span class="badge bg-<?= e(HS::COLORS[$st] ?? 'secondary') ?>"><?= e(t(HS::STATUSES[$st] ?? $st)) ?></span>
