<?php use Addons\Pressing\PressingService as PS; $open = count(array_filter($orders, fn($o) => in_array($o['status'], ['received', 'processing', 'ready', 'partial'], true))); ?>
<a class="btn btn-sm btn-outline-secondary mb-3" href="<?= e(url('/pressing/clients')) ?>"><i class="bi bi-arrow-left"></i> Clients pressing</a>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" action="<?= e(url('/pressing/clients/' . $c['id'])) ?>" class="card"><?= csrf_field() ?>
      <div class="card-header"><i class="bi bi-person-lines-fill"></i> Fiche client</div>
      <div class="card-body">
        <div class="mb-2"><label class="form-label required">Nom</label><input name="name" class="form-control" maxlength="150" required value="<?= e($c['name']) ?>"></div>
        <div class="mb-2"><label class="form-label required">Téléphone</label><input name="phone" class="form-control" maxlength="50" required inputmode="tel" value="<?= e($c['phone']) ?>"><div class="form-text">8 à 15 chiffres. Un même numéro ne peut appartenir qu'à un seul client.</div></div>
        <div class="mb-2"><label class="form-label">Adresse de livraison</label><input name="address" class="form-control" maxlength="255" value="<?= e($c['address'] ?? '') ?>" placeholder="Quartier, rue, repère…"></div>
        <div class="mb-2"><label class="form-label">Note</label><input name="note" class="form-control" maxlength="255" value="<?= e($c['note'] ?? '') ?>" placeholder="ex. préfère être appelé le soir"></div>
        <?php if ($open): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="open_too" value="1" id="openToo" checked><label class="form-check-label" for="openToo">Mettre aussi à jour le nom, le téléphone et l'adresse de livraison des <?= (int) $open ?> dépôt(s) non encore retiré(s)</label></div><?php endif; ?>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="merge" value="1" id="merge"><label class="form-check-label" for="merge">Regrouper les deux fiches si ce numéro appartient déjà à un autre client (même personne enregistrée deux fois)</label></div>
        <div class="small text-secondary mb-2">Les tickets déjà remis et les dépôts retirés gardent les informations d'origine. Chaque modification est inscrite au journal d'audit.</div>
        <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
      </div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><i class="bi bi-clock-history"></i> Dépôts du client (<?= count($orders) ?>)</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead><tr><th>N°</th><th>Date</th><th>Vêtements</th><th class="num">Total</th><th class="num">Reste</th><th>Statut</th></tr></thead><tbody>
      <?php foreach ($orders as $o): $rest = $o['status'] === 'cancelled' ? 0 : max(0, round((float) $o['total'] - (float) $o['paid'], 2)); ?>
        <tr><td><?php if (can('pressing.view')): ?><a href="<?= e(url('/pressing/' . $o['id'])) ?>"><?= e($o['number']) ?></a><?php else: ?><?= e($o['number']) ?><?php endif; ?></td><td><?= e(fdate($o['created_at'])) ?></td><td><?= e($o['clothes'] ?? '') ?></td><td class="num"><?= money($o['total']) ?></td><td class="num <?= $rest > 0 ? 'text-danger' : '' ?>"><?= money($rest) ?></td><td><?= e(PS::SHORT[$o['status']] ?? $o['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6" class="text-center text-secondary py-3">Aucun dépôt.</td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>
</div>
