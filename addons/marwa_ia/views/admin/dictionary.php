<?php /** MARWA AI : dictionnaire de données (lu dans la base, en direct). */ $cur = 'dict'; require __DIR__ . '/_nav.php'; ?>
<div class="d-flex align-items-center mb-2 small"><span class="text-secondary"><?= count($schema) ?> tables · index vérifié <?= $checked ? e(date('d/m/Y H:i', $checked)) : 'jamais' ?></span>
  <form method="post" action="<?= e(url('/ia/admin/index')) ?>" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="back" value="dict"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Reconstruire la connaissance</button></form></div>
<p class="small text-secondary">MARWA AI ne lit ces tables qu'au travers d'outils en lecture seule (transaction READ ONLY, délai limité, résultats limités) et filtrés par les droits de l'utilisateur. Les modifications passent uniquement par les fonctions métier du logiciel, après confirmation.</p>
<div class="accordion" id="dict">
  <?php $i = 0; foreach ($schema as $t => $d): $i++; ?>
  <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed py-2 small" type="button" data-bs-toggle="collapse" data-bs-target="#d<?= $i ?>"><code class="me-2"><?= e($t) ?></code> <?= e((string) $d['description']) ?> <span class="ms-auto me-2 text-secondary"><?= count($d['columns']) ?> col.</span></button></h2>
    <div id="d<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#dict"><div class="accordion-body p-0"><table class="table table-sm small mb-0"><thead><tr><th>Colonne</th><th>Type</th><th>Signification</th></tr></thead><tbody>
      <?php foreach ($d['columns'] as $c): ?><tr><td><code><?= e($c['name']) ?></code><?= !empty($c['key']) ? ' <span class="badge text-bg-light border">' . e($c['key']) . '</span>' : '' ?></td><td><?= e($c['type']) ?></td><td><?= e($c['label']) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php if ($d['fks']): ?><div class="small text-secondary p-2">Liens : <?= e(implode(', ', $d['fks'])) ?></div><?php endif; ?></div></div></div>
  <?php endforeach; ?>
</div>
