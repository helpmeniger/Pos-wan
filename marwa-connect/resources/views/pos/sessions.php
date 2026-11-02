<?php $title = 'Sessions de caisse'; ?>
<div class="mc-page-head"><div><h1>Sessions de <span class="mc-accent">caisse</span></h1><p>Ouvertures, clôtures et écarts.</p></div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/pos')) ?>"><i class="bi bi-arrow-left"></i> Caisse</a></div>
<div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
    <thead><tr><th>#</th><th>Caissier</th><th>Ouverture</th><th>Clôture</th><th class="text-end">Ventes</th><th class="text-end">Attendu</th><th class="text-end">Déclaré</th><th class="text-end">Écart</th><th>État</th></tr></thead><tbody>
    <?php foreach ($sessions as $s): ?>
        <tr><td><a href="<?= e(url('/pos/sessions/' . $s['id'])) ?>">#<?= (int) $s['id'] ?></a></td><td><?= e($s['full_name']) ?></td>
            <td class="small"><?= e(date_fr($s['opened_at'])) ?></td><td class="small"><?= e(date_fr($s['closed_at'])) ?></td>
            <td class="text-end"><?= (int) $s['sales_count'] ?> · <?= e(money($s['sales_total'])) ?></td>
            <td class="text-end"><?= $s['expected_amount'] !== null ? e(money($s['expected_amount'])) : '—' ?></td>
            <td class="text-end"><?= $s['declared_amount'] !== null ? e(money($s['declared_amount'])) : '—' ?></td>
            <td class="text-end fw-bold <?= $s['difference'] !== null && abs((float) $s['difference']) > 0 ? 'text-danger' : '' ?>"><?= $s['difference'] !== null ? e(money($s['difference'])) : '—' ?></td>
            <td><?= status_badge($s['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$sessions): ?><tr><td colspan="9"><div class="mc-empty"><i class="bi bi-cash"></i>Aucune session.</div></td></tr><?php endif; ?>
    </tbody></table></div></div>
