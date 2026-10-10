<?php /* Champs de période réutilisables : $from, $to */ ?>
<div class="col-auto"><label class="form-label small mb-0">Du</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
<div class="col-auto"><label class="form-label small mb-0">Au</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
<div class="col-auto d-flex align-items-end gap-1">
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'page' => null])) ?>">Aujourd'hui</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'page' => null])) ?>">Ce mois</a>
</div>
