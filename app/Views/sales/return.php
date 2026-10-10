<form method="post" action="<?= e(url('/sales/' . $s['id'] . '/return')) ?>" data-confirm="Enregistrer ce retour et rembourser le client ?">
  <?= csrf_field() ?>
  <div class="card mb-3"><div class="card-header">Articles de la vente <?= e($s['number']) ?> du <?= e(fdate($s['created_at'])) ?> — total <?= money($s['total']) ?></div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Article</th><th class="num">Vendu</th><th class="num">Déjà retourné</th><th class="num">Montant ligne</th><th style="width:150px">Qté à retourner</th></tr></thead><tbody>
  <?php foreach ($items as $i): $left = (float) $i['quantity'] - (float) $i['returned_qty']; ?>
    <tr><td><?= e($i['product_name']) ?></td><td class="num"><?= qty($i['quantity']) ?></td><td class="num"><?= qty($i['returned_qty']) ?></td><td class="num"><?= money($i['line_total']) ?></td>
    <td><input type="number" name="qty[<?= (int) $i['id'] ?>]" class="form-control form-control-sm" min="0" max="<?= e($left) ?>" step="any" value="0" <?= $left <= 0 ? 'disabled' : '' ?>></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
  <div class="card"><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label required">Mode de remboursement</label><select name="refund_method" class="form-select" required>
      <option value="cash">Espèces (sortie de caisse)</option>
      <?php if ($s['customer_id']): ?><option value="credit_note">Avoir sur le compte client</option><?php endif; ?>
      <option value="mobile_money">Mobile money</option><option value="card">Carte</option><option value="transfer">Virement</option></select></div>
    <div class="col-md-5"><label class="form-label required">Motif</label><input name="reason" class="form-control" required maxlength="255" placeholder="ex. produit défectueux, erreur de caisse"></div>
    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="restock" value="1" id="rs" checked><label class="form-check-label" for="rs">Remettre en stock (produit revendable)</label></div></div>
    <div class="col-12"><button class="btn btn-warning"><i class="bi bi-arrow-counterclockwise"></i> Enregistrer le retour</button> <a href="<?= e(url('/sales/' . $s['id'])) ?>" class="btn btn-light">Annuler</a></div>
  </div></div>
</form>
