<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php use Addons\Hotel\HotelService as HS; ?>
<form method="post" action="<?= e(url('/hotel/reservations')) ?>" id="stayForm" data-avail-url="<?= e(url('/hotel/disponibilites')) ?>" data-guest-url="<?= e(url('/hotel/clients/recherche')) ?>" data-want-room="<?= (int) $roomId ?>" data-manage="<?= can('hotel.manage') ? '1' : '0' ?>">
<?= csrf_field() ?>
<input type="hidden" name="walk_in" value="<?= $walkIn ? '1' : '0' ?>">
<input type="hidden" name="guest_id" value="<?= (int) ($guest['id'] ?? 0) ?>" data-guest-id>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel')) ?>"><i class="bi bi-arrow-left"></i> Hôtel</a>
  <div class="btn-group">
    <a class="btn <?= $walkIn ? 'btn-outline-primary' : 'btn-primary' ?>" href="<?= e(url('/hotel/reservations/nouvelle', $roomId ? ['room' => $roomId] : [])) ?>"><i class="bi bi-calendar-plus"></i> Réservation</a>
    <a class="btn <?= $walkIn ? 'btn-success' : 'btn-outline-success' ?>" href="<?= e(url('/hotel/reservations/nouvelle', ['walkin' => 1] + ($roomId ? ['room' => $roomId] : []))) ?>"><i class="bi bi-person-plus"></i> Client sans réservation (arrivée maintenant)</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-calendar-range"></i> 1. Dates et chambre</div><div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label small mb-0">Arrivée</label><input type="date" name="arrival" class="form-control" value="<?= e($arrival) ?>" min="<?= e(date('Y-m-d')) ?>" <?= $walkIn ? 'readonly' : '' ?> data-arrival></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-0">Départ</label><input type="date" name="departure" class="form-control" value="<?= e($departure) ?>" data-departure></div>
        <div class="col-4 col-md-2"><label class="form-label small mb-0">Nuits</label><input type="number" min="1" max="365" class="form-control" value="1" data-nights></div>
        <div class="col-4 col-md-2"><label class="form-label small mb-0">Adultes</label><input type="number" name="adults" min="1" max="20" class="form-control" value="1"></div>
        <div class="col-4 col-md-2"><label class="form-label small mb-0">Enfants</label><input type="number" name="children" min="0" max="20" class="form-control" value="0"></div>
      </div>
      <div class="ht-types mt-3" data-types><div class="text-secondary small">Chargement des disponibilités…</div></div>
      <input type="hidden" name="type_id" value="" data-type-id>
      <div class="mt-2"><label class="form-label small mb-0">Chambre <?= $walkIn ? '*' : '(facultatif : peut être attribuée à l\'arrivée)' ?></label><div class="ht-rooms" data-rooms></div><input type="hidden" name="room_id" value="" data-room-id></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-person-vcard"></i> 2. Client</div><div class="card-body">
      <div class="position-relative mb-2">
        <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" placeholder="Client déjà venu ? Nom, téléphone ou n° de pièce" data-guest-search autocomplete="off"></div>
        <div class="list-group position-absolute w-100 shadow ht-guest-results" data-guest-results></div>
      </div>
      <div class="alert alert-info py-1 small d-none" data-guest-picked></div>
      <?= App\Core\View::partial('hotel::_guest_fields', ['g' => $guest, 'full' => true]) ?>
      <?php if (!$walkIn): ?><div class="form-text">Pour une réservation, le nom et le téléphone suffisent : la pièce d'identité est demandée à l'arrivée.</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3 ht-sticky"><div class="card-header"><i class="bi bi-receipt"></i> 3. Séjour et paiement</div><div class="card-body">
      <div class="row g-2">
        <div class="col-6"><label class="form-label small mb-0">Prix de la nuit</label><input name="rate" class="form-control text-end" inputmode="numeric" data-rate <?= can('hotel.manage') ? '' : 'readonly title="Tarif négocié : responsable"' ?>></div>
        <div class="col-6"><label class="form-label small mb-0">Origine</label><select name="source" class="form-select"><?php foreach (HS::SOURCES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($walkIn ? 'direct' : 'telephone') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small mb-0">Motif du séjour</label><select name="purpose" class="form-select"><option value=""></option><?php foreach (HS::PURPOSES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small mb-0">Société / payeur</label><input name="company" class="form-control" maxlength="150"></div>
        <div class="col-6"><label class="form-label small mb-0">Venant de</label><input name="coming_from" class="form-control" maxlength="100"></div>
        <div class="col-6"><label class="form-label small mb-0">Allant à</label><input name="going_to" class="form-control" maxlength="100"></div>
        <div class="col-12"><label class="form-label small mb-0">Remarque (heure d'arrivée, demande particulière)</label><input name="note" class="form-control" maxlength="255"></div>
      </div>
      <div class="ht-quote my-3" data-quote>—</div>
      <div class="small fw-semibold mb-1"><?= $walkIn ? 'Paiement (facultatif, possible au départ)' : 'Acompte (facultatif)' ?></div>
      <div class="row g-1"><?= App\Core\View::partial('hotel::_pay') ?></div>
      <button type="button" class="btn btn-sm btn-link px-0" data-fill-total>Encaisser tout le séjour</button>
      <?php if (!$walkIn): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="confirmed" value="1" id="cf"><label class="form-check-label small" for="cf">Réservation confirmée (garantie par le client)</label></div><?php endif; ?>
      <button class="btn btn-lg w-100 mt-3 <?= $walkIn ? 'btn-success' : 'btn-primary' ?>"><i class="bi <?= $walkIn ? 'bi-key' : 'bi-check2-circle' ?>"></i> <?= $walkIn ? 'Installer le client' : 'Enregistrer la réservation' ?></button>
    </div></div>
  </div>
</div>
</form>
