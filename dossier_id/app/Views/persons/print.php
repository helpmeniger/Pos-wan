<?php use App\Support\Labels; ?>
<div class="print-body">
    <div class="d-flex gap-4 mb-3">
        <?php if ($photo): ?><img class="print-photo" src="<?= e(url('photos.show', ['id' => $photo['id']])) ?>" alt="Photographie"><?php endif; ?>
        <div>
            <h1 class="h4 mb-1"><span class="text-uppercase"><?= e($p['last_name']) ?></span> <?= e($p['first_name']) ?></h1>
            <div>Dossier n° <strong><?= e($p['file_number']) ?></strong></div>
            <div>Autres noms : <?= e($p['other_names'] ?: '—') ?></div>
            <div>Né(e) le <?= e(fmt_date($p['birth_date'])) ?> à <?= e($p['birth_place'] ?: '—') ?> · <?= e(person_age($p)) ?></div>
            <div>Nationalité : <?= e($p['nationality'] ?: '—') ?> · Profession : <?= e($p['profession'] ?: '—') ?></div>
            <div>Identifiant : <?= $p['admin_id_number'] ? e($p['admin_id_type'] . ' n° ' . $p['admin_id_number']) . ($p['admin_id_verified'] ? ' (vérifié)' : ' (non vérifié)') : '—' ?></div>
            <div>Enregistrée le <?= e(fmt_date($p['registration_date'])) ?> à <?= e($p['registration_place']) ?> par <?= e($p['registering_service']) ?></div>
            <div>Dernière mise à jour : <?= e(fmt_date($p['updated_at'], true)) ?><?= $p['status'] === 'archive' ? ' — FICHE ARCHIVÉE' : '' ?></div>
        </div>
    </div>
    <?php if ($visits): ?>
    <h2 class="h6 border-bottom pb-1">Historique des passages</h2>
    <table class="table table-sm"><thead><tr><th>Date</th><th>Service</th><th>Motif</th><th>Réf.</th><th>Agent</th><th>Statut</th></tr></thead><tbody>
    <?php foreach ($visits as $v): ?><tr><td><?= e(fmt_date($v['visit_date'], true)) ?></td><td><?= e($v['service']) ?></td><td><?= e($v['reason']) ?></td>
        <td><?= e($v['reference']) ?></td><td><?= e($v['responsible_agent']) ?></td><td><?= e(Labels::VISIT_CLOSURE[$v['closure_status']]) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php if ($procedures): ?>
    <h2 class="h6 border-bottom pb-1">Procédures</h2>
    <table class="table table-sm"><thead><tr><th>N°</th><th>Faits allégués</th><th>Service</th><th>Statut</th><th>Décision documentée</th></tr></thead><tbody>
    <?php foreach ($procedures as $pr): ?><tr><td><?= e($pr['procedure_number']) ?><?= $pr['pv_reference'] ? '<br><small>PV ' . e($pr['pv_reference']) . '</small>' : '' ?></td>
        <td><?= e($pr['facts_nature']) ?><br><small><?= e(fmt_date($pr['facts_date'])) ?></small></td><td><?= e($pr['investigating_service']) ?></td>
        <td><?= e(Labels::procedureStatus($pr['status'])) ?></td>
        <td><?= $pr['decision_text'] ? e($pr['decision_text'] . ' (' . fmt_date($pr['decision_date']) . ') — ' . Labels::DECISION_FINAL[$pr['decision_final']]) : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>
