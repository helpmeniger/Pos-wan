<div class="page-header"><h1><i class="bi bi-people"></i> Doublons potentiels</h1>
    <a class="btn btn-outline-primary" href="<?= e(url('duplicates.compare')) ?>"><i class="bi bi-layout-split"></i> Comparer deux fiches</a></div>
<div class="alert alert-info small">
    Analyse fondée uniquement sur les informations administratives (identifiant, nom, prénom, date et lieu de naissance, orthographes approchantes).
    Aucune comparaison automatique de visages n'est réalisée. Chaque suggestion doit être examinée par un agent : comparaison visuelle
    des photographies, vérification des pièces, puis décision motivée.
</div>
<div class="card mb-4">
    <div class="card-header"><?= count($pairs) ?> paire(s) à examiner (fiches actives)</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Fiche A</th><th>Fiche B</th><th>Concordance</th><th>Motifs</th><th></th></tr></thead>
        <tbody>
        <?php if (!$pairs): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucun doublon potentiel en attente.</td></tr><?php endif; ?>
        <?php foreach ($pairs as $d): ?>
            <tr>
                <td><span class="text-uppercase"><?= e($d['a']['last_name']) ?></span> <?= e($d['a']['first_name']) ?><div class="small text-muted"><?= e($d['a']['file_number']) ?> · <?= e(fmt_date($d['a']['birth_date'])) ?></div></td>
                <td><span class="text-uppercase"><?= e($d['b']['last_name']) ?></span> <?= e($d['b']['first_name']) ?><div class="small text-muted"><?= e($d['b']['file_number']) ?> · <?= e(fmt_date($d['b']['birth_date'])) ?></div></td>
                <td><span class="badge dup-<?= $d['level'] === 'élevé' ? 'high' : ($d['level'] === 'moyen' ? 'mid' : 'low') ?>"><?= e(ucfirst($d['level'])) ?></span></td>
                <td class="small"><?= e(implode(', ', $d['reasons'])) ?></td>
                <td><a class="btn btn-sm btn-primary" href="<?= e(url('duplicates.compare', ['a' => $d['a']['id'], 'b' => $d['b']['id']])) ?>">Examiner</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<div class="card">
    <div class="card-header">Dernières décisions</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Date</th><th>Fiches</th><th>Décision</th><th>Commentaire</th><th>Agent</th></tr></thead>
        <tbody>
        <?php if (!$reviews): ?><tr><td colspan="5" class="text-muted">Aucune décision enregistrée.</td></tr><?php endif; ?>
        <?php foreach ($reviews as $r): ?>
            <tr><td class="small"><?= e(fmt_date($r['reviewed_at'], true)) ?></td>
                <td><a href="<?= e(url('duplicates.compare', ['a' => $r['person_a_id'], 'b' => $r['person_b_id']])) ?>"><?= e($r['a_number']) ?> / <?= e($r['b_number']) ?></a></td>
                <td><?= $r['decision'] === 'distinctes' ? '<span class="badge text-bg-success">Personnes distinctes</span>' : '<span class="badge text-bg-danger">Doublon confirmé</span>' ?></td>
                <td class="small"><?= e($r['comment']) ?></td><td class="small"><?= e($r['full_name'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
