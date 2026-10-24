<?php use Addons\Hotel\HotelService as HS; $v = fn($x) => e((string) $x) ?: '<span style="color:#999">………………………</span>'; ?>
<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'FICHE INDIVIDUELLE DE VOYAGEUR', 'docNumber' => $s['number'], 'docDate' => $s['checked_in_at'] ?: $s['created_at']]) ?>
  <table class="lines" style="margin-top:14px"><tbody>
    <tr><th style="width:35%">Nom</th><td><b><?= $v($g['last_name']) ?></b></td></tr>
    <tr><th>Prénom(s)</th><td><?= $v($g['first_name']) ?></td></tr>
    <tr><th>Sexe</th><td><?= $v($g['sex']) ?></td></tr>
    <tr><th>Date et lieu de naissance</th><td><?= $g['birth_date'] ? e(fdate($g['birth_date'], false)) : $v('') ?> à <?= $v($g['birth_place']) ?></td></tr>
    <tr><th>Nationalité</th><td><?= $v($g['nationality']) ?></td></tr>
    <tr><th>Profession</th><td><?= $v($g['profession']) ?></td></tr>
    <tr><th>Domicile habituel</th><td><?= $v($g['address']) ?></td></tr>
    <tr><th>Téléphone</th><td><?= $v($g['phone']) ?></td></tr>
    <tr><th>Pièce d'identité</th><td><?= $g['id_type'] ? e(HS::ID_TYPES[$g['id_type']] ?? '') : $v('') ?> n° <?= $v($g['id_number']) ?></td></tr>
    <tr><th>Délivrée le / à</th><td><?= $g['id_issued_on'] ? e(fdate($g['id_issued_on'], false)) : $v('') ?> à <?= $v($g['id_issued_at']) ?></td></tr>
    <tr><th>Venant de</th><td><?= $v($s['coming_from']) ?></td></tr>
    <tr><th>Allant à</th><td><?= $v($s['going_to']) ?></td></tr>
    <tr><th>Motif du séjour</th><td><?= $s['purpose'] ? e(HS::PURPOSES[$s['purpose']] ?? '') : $v('') ?></td></tr>
    <tr><th>Chambre</th><td><?= $v($s['room_number']) ?></td></tr>
    <tr><th>Arrivée le</th><td><?= e(fdate($s['checked_in_at'] ?: $s['arrival'])) ?></td></tr>
    <tr><th>Départ prévu le</th><td><?= e(fdate($s['departure'], false)) ?></td></tr>
    <tr><th>Personnes</th><td><?= (int) $s['adults'] ?> adulte(s)<?= (int) $s['children'] ? ', ' . (int) $s['children'] . ' enfant(s)' : '' ?></td></tr>
  </tbody></table>
  <p style="font-size:11px;color:#555;margin-top:10px">Renseignements recueillis pour la tenue du registre des voyageurs de l'établissement. Ils sont conservés par l'hôtel et ne sont communiqués qu'aux autorités habilitées (loi n° 2017-28 du 3 mai 2017 relative à la protection des données à caractère personnel).</p>
  <div class="sign"><div>Signature du voyageur</div><div>La réception</div></div>
</div>
