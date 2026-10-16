<?php $st = ['approved' => ['success', 'Autorisé'], 'pending' => ['warning text-dark', 'En attente'], 'revoked' => ['danger', 'Bloqué']]; ?>
<div class="card mb-3"><div class="card-body">
  <form method="post" action="<?= e(url('/devices/policy')) ?>" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?>
    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="device_approval" value="1" id="devApproval" <?= setting('device_approval', '0') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label" for="devApproval"><b>Approbation obligatoire des nouveaux appareils du réseau</b> (smartphones, tablettes, autres ordinateurs). Le poste serveur et l'administrateur général restent toujours autorisés.</label></div>
    <button class="btn btn-sm btn-primary ms-auto">Enregistrer</button>
  </form>
</div></div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Appareil</th><th>Utilisateur</th><th>Adresse</th><th>Première connexion</th><th>Dernière activité</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $d): ?>
  <tr class="<?= $d['status'] === 'pending' ? 'table-warning' : '' ?>">
    <td><i class="bi <?= str_contains($d['label'], 'mobile') ? 'bi-phone' : 'bi-pc-display' ?>"></i> <?= e($d['label']) ?><?= (int) $d['id'] === $current ? ' <span class="badge bg-info text-dark">cet appareil</span>' : '' ?></td>
    <td><?= e($d['full_name']) ?> <small class="text-secondary"><?= e($d['username']) ?></small></td>
    <td class="small"><?= e($d['ip'] ?? '') ?></td>
    <td class="small"><?= e(fdate($d['created_at'])) ?></td>
    <td class="small"><?= e(fdate($d['last_seen_at'])) ?></td>
    <td><span class="badge bg-<?= e($st[$d['status']][0]) ?>"><?= e($st[$d['status']][1]) ?></span><?= $d['approver'] ? '<br><small class="text-secondary">par ' . e($d['approver']) . '</small>' : '' ?></td>
    <td class="text-end text-nowrap">
      <?php if ($d['status'] !== 'approved'): ?><form class="d-inline" method="post" action="<?= e(url('/devices/' . $d['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="approved"><button class="btn btn-sm btn-success">Autoriser</button></form><?php endif; ?>
      <?php if ($d['status'] !== 'revoked' && (int) $d['id'] !== $current): ?><form class="d-inline" method="post" action="<?= e(url('/devices/' . $d['id'])) ?>" data-confirm="Bloquer cet appareil ? Sa session est coupée immédiatement."><?= csrf_field() ?><input type="hidden" name="status" value="revoked"><button class="btn btn-sm btn-outline-danger">Bloquer</button></form><?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucun appareil enregistré (les appareils apparaissent à leur première connexion).</td></tr><?php endif; ?>
</tbody></table></div></div>
