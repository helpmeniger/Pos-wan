<?php use Addons\Clinique\ClinicService as CS; $colors = ['planned' => 'secondary', 'arrived' => 'info text-dark', 'done' => 'success', 'no_show' => 'warning text-dark', 'cancelled' => 'danger']; ?>
<span class="d-inline-flex gap-1 align-items-center">
<span class="badge bg-<?= e($colors[$a['status']]) ?>"><?= e(CS::APPT_STATUSES[$a['status']]) ?></span>
<?php if ($a['status'] === 'planned' && can('clinique.reception')): ?>
  <form method="post" action="<?= e(url('/clinique/rdv/' . $a['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="arrived"><button class="btn btn-sm btn-success" title="Patient arrivé : file d'attente"><i class="bi bi-box-arrow-in-right"></i> Arrivé</button></form>
  <form method="post" action="<?= e(url('/clinique/rdv/' . $a['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="no_show"><button class="btn btn-sm btn-outline-warning" title="Absent"><i class="bi bi-person-x"></i></button></form>
  <form method="post" action="<?= e(url('/clinique/rdv/' . $a['id'] . '/status')) ?>" data-confirm="Annuler ce rendez-vous ?"><?= csrf_field() ?><input type="hidden" name="status" value="cancelled"><button class="btn btn-sm btn-outline-danger" title="Annuler"><i class="bi bi-x"></i></button></form>
<?php elseif ($a['status'] === 'arrived' && $a['visit_id'] && can('clinique.medical')): ?>
  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/clinique/consultations/' . $a['visit_id'])) ?>"><i class="bi bi-clipboard2-pulse"></i></a>
<?php endif; ?>
</span>
