<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel.css')) ?>">
<?php
use Addons\Hotel\HotelService as HS;
$today = date('Y-m-d');
$open = in_array($s['status'], HS::HOLDING, true);
$live = $s['status'] === 'en_cours';
$resa = in_array($s['status'], ['reservee', 'confirmee'], true);
$nights = HS::nights($s['arrival'], $s['departure']);
$negotiated = abs((float) $s['rate'] - (float) $s['list_rate']) >= 0.01;
// Montant dû si le client part aujourd'hui (une nuit au moins)
$out = $s;
$out['departure'] = max($today, date('Y-m-d', strtotime($s['arrival'] . ' +1 day')));
$leaveNow = $live ? HS::pendingNights($out) : ['nights' => 0, 'amount' => 0.0];
$dueNow = round($bal['posted'] + $leaveNow['amount'] - $bal['paid'], 2);
$credit = round($bal['paid'] - $bal['posted'] - ($open ? $bal['pending']['amount'] : 0), 2);
$guestName = trim($s['last_name'] . ' ' . $s['first_name']);
$wa = preg_replace('/\D/', '', (string) $s['phone']);
$wa = strlen($wa) === 8 ? '227' . $wa : $wa;
?>
<?php if (App\Core\Request::query('facture') === '1' && $s['status'] === 'terminee'): ?><span data-autoprint-url="<?= e(url('/hotel/sejours/' . $s['id'] . '/facture', ['auto' => 1])) ?>"></span><?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel')) ?>"><i class="bi bi-arrow-left"></i> Hôtel</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/hotel/sejours/' . $s['id'] . '/facture')) ?>"><i class="bi bi-receipt"></i> <?= $s['status'] === 'terminee' ? 'Facture' : 'Note provisoire' ?></a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/hotel/sejours/' . $s['id'] . '/fiche')) ?>"><i class="bi bi-shield-check"></i> Fiche de police</a>
  <?php if ($wa): ?><a class="btn btn-outline-success" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>"><i class="bi bi-whatsapp"></i> WhatsApp</a><?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= e(url('/hotel/clients/' . $s['guest_id'])) ?>"><i class="bi bi-clock-history"></i> Historique du client<?= $previous ? ' (' . $previous . ')' : '' ?></a>
  <span class="ms-auto fs-5"><?= App\Core\View::partial('hotel::_status', ['st' => $s['status']]) ?></span>
</div>
<?php if ((int) $s['blacklisted']): ?><div class="alert alert-danger py-2"><i class="bi bi-slash-circle"></i> Client marqué « refusé » : <?= e((string) $guest['note']) ?></div><?php endif; ?>
<?php if ($live && $s['departure'] < $today): ?><div class="alert alert-danger py-2"><i class="bi bi-alarm"></i> Départ prévu le <b><?= e(fdate($s['departure'], false)) ?></b> : faites le départ ou prolongez le séjour.</div><?php endif; ?>
<?php if ($resa && $s['arrival'] < $today): ?><div class="alert alert-warning py-2"><i class="bi bi-hourglass-split"></i> Arrivée prévue le <?= e(fdate($s['arrival'], false)) ?> : enregistrez l'arrivée, ou notez le client « non présenté ».</div><?php endif; ?>
<?php if (in_array($s['status'], ['annulee', 'no_show'], true)): ?><div class="alert alert-secondary py-2"><?= e(HS::STATUSES[$s['status']]) ?> le <?= e(fdate($s['cancelled_at'])) ?> : <?= e((string) $s['cancel_reason']) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3 ht-stay"><div class="card-body">
      <div class="d-flex flex-wrap gap-3 align-items-start">
        <div class="ht-roomno"><small>Chambre</small><b><?= e($s['room_number'] ?? '—') ?></b><small><?= e($s['type_name']) ?></small></div>
        <div class="flex-grow-1">
          <div class="fs-4 fw-bold"><?= (int) $s['vip'] ? '<i class="bi bi-star-fill text-warning"></i> ' : '' ?><?= e($guestName) ?></div>
          <div class="text-secondary small"><?= e($s['number']) ?><?= $s['invoice_number'] ? ' · facture ' . e($s['invoice_number']) : '' ?><?= $s['phone'] ? ' · <a href="tel:' . e(preg_replace('/[^0-9+]/', '', $s['phone'])) . '">' . e($s['phone']) . '</a>' : '' ?><?= $s['nationality'] ? ' · ' . e($s['nationality']) : '' ?></div>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <span class="ht-chip"><i class="bi bi-box-arrow-in-right"></i> <?= e(fdate($s['arrival'], false)) ?><?= $s['checked_in_at'] ? ' ' . e(substr($s['checked_in_at'], 11, 5)) : '' ?></span>
            <span class="ht-chip"><i class="bi bi-box-arrow-right"></i> <?= e(fdate($s['departure'], false)) ?><?= $s['checked_out_at'] ? ' ' . e(substr($s['checked_out_at'], 11, 5)) : '' ?></span>
            <span class="ht-chip"><i class="bi bi-moon"></i> <?= $nights ?> nuit(s)</span>
            <span class="ht-chip"><i class="bi bi-people"></i> <?= (int) $s['adults'] ?> adulte(s)<?= (int) $s['children'] ? ', ' . (int) $s['children'] . ' enfant(s)' : '' ?></span>
            <span class="ht-chip"><i class="bi bi-tag"></i> <?= money($s['rate']) ?> / nuit<?= $negotiated ? ' <span class="badge bg-warning text-dark">négocié</span>' : '' ?></span>
            <span class="ht-chip"><i class="bi bi-signpost"></i> <?= e(HS::SOURCES[$s['source']] ?? $s['source']) ?></span>
            <?php if ($s['company']): ?><span class="ht-chip"><i class="bi bi-briefcase"></i> <?= e($s['company']) ?></span><?php endif; ?>
            <?php if ($s['purpose']): ?><span class="ht-chip"><?= e(HS::PURPOSES[$s['purpose']] ?? '') ?></span><?php endif; ?>
          </div>
          <?php if ($s['note']): ?><div class="mt-2 small"><i class="bi bi-chat-left-text"></i> <?= e($s['note']) ?></div><?php endif; ?>
        </div>
        <div class="text-end ht-balance">
          <small><?= $open ? 'Reste à payer (estimé)' : 'Reste à payer' ?></small>
          <b class="<?= ($open ? $bal['estimated_due'] : $bal['due']) > 0.009 ? 'text-danger' : 'text-success' ?>"><?= money(max(0, $open ? $bal['estimated_due'] : $bal['due'])) ?></b>
          <small>payé <?= money($bal['paid']) ?></small>
        </div>
      </div>
    </div></div>

    <div class="card mb-3"><div class="card-header d-flex"><span><i class="bi bi-journal-text"></i> Folio</span><span class="ms-auto small text-secondary">nuitées portées jusqu'au <?= e(fdate($s['charged_until'] ?: $s['arrival'], false)) ?></span></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Date</th><th>Prestation</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Montant</th><th></th></tr></thead><tbody>
      <?php foreach ($charges as $c): ?>
        <tr class="<?= (int) $c['cancelled'] ? 'text-decoration-line-through text-secondary' : '' ?>"><td class="text-nowrap"><?= e(fdate($c['service_date'], false)) ?></td>
          <td><?= e($c['label']) ?> <span class="badge bg-light text-dark border"><?= e(HS::KINDS[$c['kind']] ?? $c['kind']) ?></span><?= (int) $c['cancelled'] ? '<br><small class="text-danger">annulée : ' . e((string) $c['cancel_reason']) . '</small>' : '' ?></td>
          <td class="num"><?= e(qty($c['quantity'])) ?></td><td class="num"><?= money($c['unit_price']) ?></td><td class="num fw-semibold"><?= money($c['amount']) ?></td>
          <td class="text-end"><?php if (!(int) $c['cancelled'] && can('hotel.manage') && in_array($s['status'], ['en_cours', 'terminee'], true) && !($c['kind'] === 'nuitee' && $live)): ?>
            <form method="post" action="<?= e(url('/hotel/prestations/' . $c['id'] . '/annuler')) ?>" data-confirm="Annuler « <?= e($c['label']) ?> » ?" data-reason="1"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0" title="Annuler la ligne"><i class="bi bi-x-circle"></i></button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($open && $bal['pending']['nights'] > 0): ?>
        <tr class="text-secondary fst-italic"><td></td><td><?= $bal['pending']['nights'] ?> nuitée(s) <?= $live ? 'restante(s) jusqu\'au départ prévu' : 'réservée(s)' ?> — portée(s) au folio <?= $live ? 'chaque nuit (audit) et au départ' : 'après l\'arrivée' ?></td><td></td><td></td><td class="num"><?= money($bal['pending']['amount']) ?></td><td></td></tr>
      <?php endif; ?>
      <?php if (!$charges && !($open && $bal['pending']['nights'])): ?><tr><td colspan="6" class="text-center text-secondary py-3">Aucune prestation.</td></tr><?php endif; ?>
      </tbody><tfoot>
        <tr><td colspan="4" class="text-end">Porté au folio</td><td class="num"><?= money($bal['posted']) ?></td><td></td></tr>
        <?php if ($open): ?><tr><td colspan="4" class="text-end">Total estimé du séjour</td><td class="num fw-bold"><?= money($bal['estimated']) ?></td><td></td></tr><?php endif; ?>
        <tr><td colspan="4" class="text-end">Payé</td><td class="num text-success"><?= money($bal['paid']) ?></td><td></td></tr>
        <tr class="fw-bold"><td colspan="4" class="text-end"><?= $open ? 'Reste (estimé)' : 'Reste à payer' ?></td><td class="num <?= ($open ? $bal['estimated_due'] : $bal['due']) > 0.009 ? 'text-danger' : 'text-success' ?>"><?= money($open ? $bal['estimated_due'] : $bal['due']) ?></td><td></td></tr>
      </tfoot></table></div></div>

    <div class="row g-3">
      <div class="col-md-6"><div class="card h-100"><div class="card-header">Paiements</div><ul class="list-group list-group-flush small">
        <?php foreach ($payments as $pm): ?><li class="list-group-item d-flex gap-2"><span><?= e(ucfirst($pm['kind'])) ?> · <?= e(method_label($pm['method'])) ?><?= $pm['provider'] ? ' · ' . e($pm['provider']) : '' ?><?= $pm['reference'] ? ' · ' . e($pm['reference']) : '' ?><br><span class="text-secondary"><?= e(fdate($pm['created_at'])) ?> · <?= e($pm['full_name']) ?></span></span><span class="ms-auto fw-semibold <?= (float) $pm['amount'] < 0 ? 'text-danger' : '' ?>"><?= money($pm['amount']) ?></span></li><?php endforeach; ?>
        <?php if (!$payments): ?><li class="list-group-item text-secondary">Aucun paiement.</li><?php endif; ?>
      </ul></div></div>
      <div class="col-md-6"><div class="card h-100"><div class="card-header">Historique</div><ul class="list-group list-group-flush small ht-log">
        <?php foreach ($log as $l): ?><li class="list-group-item"><?= e(fdate($l['created_at'])) ?> · <b><?= e(ucfirst(str_replace('_', ' ', $l['event']))) ?></b> · <?= e($l['full_name'] ?? '') ?><?= $l['note'] ? '<br><span class="text-secondary">' . e($l['note']) . '</span>' : '' ?></li><?php endforeach; ?>
      </ul></div></div>
    </div>

    <div class="card mt-3"><div class="card-header d-flex align-items-center"><i class="bi bi-person-vcard me-1"></i> Fiche du client
      <?php if (can('hotel.reception')): ?><button class="btn btn-sm btn-outline-primary ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#guestEdit"><i class="bi bi-pencil"></i> Modifier</button><?php endif; ?></div>
      <div class="card-body small">
        <div class="row">
          <div class="col-md-6"><dl class="row mb-0">
            <dt class="col-5">Pièce</dt><dd class="col-7"><?= $guest['id_type'] ? e(HS::ID_TYPES[$guest['id_type']] ?? '') . ' n° ' . e((string) $guest['id_number']) : '<span class="text-danger">non renseignée</span>' ?><?= $guest['id_issued_on'] ? '<br>du ' . e(fdate($guest['id_issued_on'], false)) . ' ' . e((string) $guest['id_issued_at']) : '' ?></dd>
            <dt class="col-5">Né(e) le</dt><dd class="col-7"><?= $guest['birth_date'] ? e(fdate($guest['birth_date'], false)) : '—' ?> <?= e((string) $guest['birth_place']) ?></dd>
            <dt class="col-5">Profession</dt><dd class="col-7"><?= e((string) $guest['profession']) ?: '—' ?></dd>
          </dl></div>
          <div class="col-md-6"><dl class="row mb-0">
            <dt class="col-5">Domicile</dt><dd class="col-7"><?= e((string) $guest['address']) ?: '—' ?></dd>
            <dt class="col-5">E-mail</dt><dd class="col-7"><?= e((string) $guest['email']) ?: '—' ?></dd>
            <dt class="col-5">Venant de / allant à</dt><dd class="col-7"><?= e((string) $s['coming_from']) ?: '—' ?> / <?= e((string) $s['going_to']) ?: '—' ?></dd>
          </dl></div>
        </div>
        <?php if ($guest['note']): ?><div class="mt-1"><i class="bi bi-info-circle"></i> <?= e($guest['note']) ?></div><?php endif; ?>
        <?php if (can('hotel.reception')): ?>
        <div class="collapse mt-3" id="guestEdit"><form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/client')) ?>"><?= csrf_field() ?>
          <?= App\Core\View::partial('hotel::_guest_fields', ['g' => $guest, 'full' => true]) ?>
          <button class="btn btn-primary mt-2"><i class="bi bi-save"></i> Enregistrer la fiche</button></form></div>
        <?php endif; ?>
      </div></div>
  </div>

  <div class="col-xl-4">
  <?php if (can('hotel.reception')): ?>
    <?php if ($resa): $rooms = $s['departure'] > $today ? HS::availableRooms(max($today, $s['arrival']), $s['departure'], null, (int) $s['id']) : []; ?>
    <div class="card mb-3 border-info" id="blk-arrivee"><div class="card-header bg-info-subtle"><i class="bi bi-box-arrow-in-right"></i> Arrivée du client</div><div class="card-body">
      <?php if ($s['arrival'] > $today): ?><div class="small text-secondary mb-2">Arrivée prévue le <?= e(fdate($s['arrival'], false)) ?>. Une arrivée anticipée démarre le séjour aujourd'hui (si une chambre est libre).</div><?php endif; ?>
      <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/arrivee')) ?>"><?= csrf_field() ?>
        <label class="form-label small mb-0">Chambre</label>
        <select name="room_id" class="form-select mb-2"><option value="">— choisir —</option>
          <?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $s['room_id'] ? 'selected' : '' ?>><?= e($r['number']) ?> · <?= e($r['type_name']) ?><?= in_array($r['housekeeping'], ['sale', 'nettoyage'], true) ? ' · ' . e(HS::HK[$r['housekeeping']]) : '' ?><?= (int) $r['type_id'] !== (int) $s['type_id'] ? ' (autre type, tarif conservé)' : '' ?></option><?php endforeach; ?>
        </select>
        <?php if (!$guest['id_type'] || !$guest['id_number']): ?><div class="small text-danger mb-2"><i class="bi bi-exclamation-triangle"></i> Pièce d'identité à saisir dans la fiche du client (Modifier) avant l'arrivée.</div><?php endif; ?>
        <?php if (can('hotel.manage')): ?><div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="force" value="1" id="force"><label class="form-check-label" for="force">Installer quand même si la chambre n'est pas encore propre</label></div><?php endif; ?>
        <button class="btn btn-info w-100"><i class="bi bi-key"></i> Enregistrer l'arrivée</button>
      </form>
      <?php if ($s['status'] === 'reservee'): ?><form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/confirmer')) ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-sm btn-outline-info w-100"><i class="bi bi-check2"></i> Marquer la réservation confirmée</button></form><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><a class="text-decoration-none" data-bs-toggle="collapse" href="#resaEdit"><i class="bi bi-pencil-square"></i> Modifier la réservation</a></div>
      <div class="collapse" id="resaEdit"><div class="card-body"><form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/modifier')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-6"><label class="form-label small mb-0">Arrivée</label><input type="date" name="arrival" class="form-control form-control-sm" value="<?= e($s['arrival']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0">Départ</label><input type="date" name="departure" class="form-control form-control-sm" value="<?= e($s['departure']) ?>"></div>
        <div class="col-12"><label class="form-label small mb-0">Type de chambre</label><select name="type_id" class="form-select form-select-sm"><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === (int) $s['type_id'] ? 'selected' : '' ?>><?= e($t['name']) ?> · <?= money($t['base_price']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">Chambre (n°, facultatif)</label><select name="room_id" class="form-select form-select-sm"><option value="0">à attribuer à l'arrivée</option>
          <?php foreach ($allRooms as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $s['room_id'] ? 'selected' : '' ?>><?= e($r['number']) ?> · <?= e($r['type_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-4"><label class="form-label small mb-0">Adultes</label><input type="number" min="1" name="adults" class="form-control form-control-sm" value="<?= (int) $s['adults'] ?>"></div>
        <div class="col-4"><label class="form-label small mb-0">Enfants</label><input type="number" min="0" name="children" class="form-control form-control-sm" value="<?= (int) $s['children'] ?>"></div>
        <div class="col-4"><label class="form-label small mb-0">Prix/nuit</label><input name="rate" class="form-control form-control-sm text-end" value="<?= $negotiated ? e((string) (float) $s['rate']) : '' ?>" placeholder="grille" <?= can('hotel.manage') ? '' : 'readonly' ?>></div>
        <div class="col-12"><input name="note" class="form-control form-control-sm" value="<?= e((string) $s['note']) ?>" placeholder="Remarque" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary w-100">Enregistrer les modifications</button></div>
      </form></div></div></div>
    <?php endif; ?>

    <?php if ($live): ?>
    <div class="card mb-3" id="blk-prestation"><div class="card-header"><i class="bi bi-cup-hot"></i> Ajouter une prestation</div><div class="card-body">
      <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/prestation')) ?>" class="row g-1"><?= csrf_field() ?>
        <div class="col-12"><select name="extra_id" class="form-select form-select-sm" data-extra-select><option value="">Autre (libellé libre)…</option>
          <?php foreach ($extras as $x): ?><option value="<?= (int) $x['id'] ?>" data-price="<?= e((string) (float) $x['price']) ?>"><?= e($x['name']) ?> · <?= money($x['price']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12" data-free-label><input name="label" class="form-control form-control-sm" placeholder="Libellé" maxlength="150"></div>
        <div class="col-12" data-free-kind><select name="kind" class="form-select form-select-sm"><?php foreach (HS::KINDS as $k => $l): if (in_array($k, ['nuitee', 'taxe', 'annulation', 'depart_tardif'], true) || ($k === 'remise' && !can('hotel.manage') && !can('pos.discount'))) continue; ?><option value="<?= e($k) ?>" <?= $k === 'autre' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4"><input name="quantity" class="form-control form-control-sm text-end" value="1" inputmode="decimal" title="Quantité"></div>
        <div class="col-8"><input name="unit_price" class="form-control form-control-sm text-end" placeholder="Prix unitaire" inputmode="numeric"></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus-circle"></i> Porter au folio</button></div>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if ($open || ($s['status'] === 'terminee' && $bal['due'] > 0.009)): $suggest = $live ? max(0, $dueNow) : max(0, $open ? $bal['estimated_due'] : $bal['due']); ?>
    <div class="card mb-3" id="blk-paiement"><div class="card-header"><i class="bi bi-cash-coin"></i> <?= $resa ? 'Acompte' : 'Encaisser' ?></div><div class="card-body">
      <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/paiement')) ?>" class="row g-1"><?= csrf_field() ?>
        <?= App\Core\View::partial('hotel::_pay') ?>
        <?php if ($suggest > 0): ?><div class="col-12 small"><button type="button" class="btn btn-sm btn-link p-0" data-fill-amount="<?= e((string) $suggest) ?>"><?= $live ? 'solde à ce jour' : 'reste' ?> : <?= money($suggest) ?></button></div><?php endif; ?>
        <div class="col-12 mt-1"><button class="btn btn-sm btn-outline-success w-100"><i class="bi bi-cash"></i> Encaisser</button></div>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if ($live): ?>
    <div class="card mb-3 border-warning" id="blk-depart"><div class="card-header bg-warning-subtle"><i class="bi bi-box-arrow-right"></i> Départ du client</div><div class="card-body">
      <div class="small mb-2">Si le client part aujourd'hui : <?= $leaveNow['nights'] ?> nuitée(s) encore à porter (<?= money($leaveNow['amount']) ?>) → reste à payer <b class="<?= $dueNow > 0 ? 'text-danger' : 'text-success' ?>"><?= money($dueNow) ?></b>.</div>
      <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/depart')) ?>" class="row g-1"><?= csrf_field() ?>
        <?php if ($dueNow > 0): ?><?= App\Core\View::partial('hotel::_pay', ['amount' => (string) $dueNow]) ?><?php endif; ?>
        <div class="col-12 form-check small ms-1"><input class="form-check-input" type="checkbox" name="late" value="1" id="late"><label class="form-check-label" for="late">Départ tardif (après <?= e(setting('hotel_heure_depart', '12:00')) ?>) : + <?= e(setting('hotel_depart_tardif_pourcent', '50')) ?> % de la nuitée</label></div>
        <?php if (can('hotel.manage')): ?><div class="col-12"><input name="unpaid_reason" class="form-control form-control-sm" placeholder="Partir avec un solde : motif (responsable)" maxlength="180"></div><?php endif; ?>
        <div class="col-12 mt-1"><button class="btn btn-warning w-100 fw-bold"><i class="bi bi-door-open"></i> Enregistrer le départ<?= $dueNow > 0 ? ' et encaisser' : '' ?></button></div>
      </form>
      <div class="small text-secondary mt-1">La facture s'ouvre pour impression ; la chambre passe « À nettoyer ».</div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><a class="text-decoration-none" data-bs-toggle="collapse" href="#stayChange"><i class="bi bi-arrow-left-right"></i> Changer de chambre / prolonger</a></div>
      <div class="collapse" id="stayChange"><div class="card-body">
        <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/chambre')) ?>" class="mb-3" data-change-room data-current="<?= (int) $s['room_id'] ?>" data-url="<?= e(url('/hotel/disponibilites', ['arrival' => $today, 'departure' => max($s['departure'], date('Y-m-d', strtotime('+1 day'))), 'except' => $s['id']])) ?>"><?= csrf_field() ?>
          <label class="form-label small mb-0">Nouvelle chambre (libre jusqu'au départ)</label><select name="room_id" class="form-select form-select-sm"><option value="">Chargement…</option></select>
          <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="keep_rate" value="1" id="kr" checked><label class="form-check-label" for="kr">Garder le prix actuel (<?= money($s['rate']) ?>)</label></div>
          <input name="reason" class="form-control form-control-sm mt-1" placeholder="Raison (panne, demande du client…)" maxlength="150">
          <button class="btn btn-sm btn-outline-primary w-100 mt-1">Changer de chambre</button>
        </form>
        <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/depart-prevu')) ?>" class="d-flex gap-1"><?= csrf_field() ?>
          <input type="date" name="departure" class="form-control form-control-sm" value="<?= e($s['departure']) ?>" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"><button class="btn btn-sm btn-outline-secondary text-nowrap">Nouvelle date de départ</button>
        </form>
      </div></div></div>
    <?php endif; ?>

    <?php if ($resa): ?>
    <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/annuler')) ?>" class="mb-2" data-confirm="Annuler la réservation <?= e($s['number']) ?> ?" data-reason="1"><?= csrf_field() ?>
      <?php if ($bal['paid'] > 0): ?><div class="form-check small"><input class="form-check-input" type="checkbox" name="keep_deposit" value="1" id="kd"><label class="form-check-label" for="kd">Conserver l'acompte (<?= money($bal['paid']) ?>) comme frais d'annulation — sinon remboursé en espèces</label></div><?php endif; ?>
      <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-octagon"></i> Annuler la réservation</button></form>
    <?php if ($s['arrival'] <= $today): ?>
    <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/annuler')) ?>" data-confirm="Noter <?= e($guestName) ?> « non présenté » ?<?= $bal['paid'] > 0 ? ' L\'acompte de ' . e(money($bal['paid'])) . ' est conservé.' : '' ?>" data-reason="1"><?= csrf_field() ?><input type="hidden" name="no_show" value="1"><input type="hidden" name="keep_deposit" value="1">
      <button class="btn btn-sm btn-outline-dark w-100"><i class="bi bi-person-x"></i> Client non présenté</button></form>
    <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (can('hotel.manage') && $credit > 0.009 && !$resa): ?>
    <div class="card mb-3 border-danger"><div class="card-header text-danger"><i class="bi bi-arrow-counterclockwise"></i> Trop-perçu : <?= money($credit) ?></div><div class="card-body">
      <form method="post" action="<?= e(url('/hotel/sejours/' . $s['id'] . '/remboursement')) ?>" class="row g-1"><?= csrf_field() ?>
        <div class="col-5"><input name="amount" class="form-control form-control-sm text-end" value="<?= e((string) $credit) ?>"></div>
        <div class="col-7"><input name="reason" class="form-control form-control-sm" placeholder="Motif" required maxlength="100"></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-danger w-100">Rembourser en espèces</button></div>
      </form></div></div>
  <?php endif; ?>
  </div>
</div>
