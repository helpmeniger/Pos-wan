<?php /** @var array $terms @var array $e */ ?>
<dl class="row small mb-0">
  <dt class="col-sm-4">Référence</dt><dd class="col-sm-8"><?= e($terms['reference'] ?? $e['reference']) ?></dd>
  <dt class="col-sm-4">Employeur</dt><dd class="col-sm-8"><?= e($terms['employeur'] ?? '') ?></dd>
  <dt class="col-sm-4">Employé(e)</dt><dd class="col-sm-8"><?= e($terms['employe'] ?? '') ?></dd>
  <dt class="col-sm-4">Emploi / poste</dt><dd class="col-sm-8 fw-semibold"><?= e($terms['poste'] ?? '') ?></dd>
  <?php if (!empty($terms['lieu'])): ?><dt class="col-sm-4">Lieu de travail</dt><dd class="col-sm-8"><?= e($terms['lieu']) ?></dd><?php endif; ?>
  <dt class="col-sm-4">Temps de travail</dt><dd class="col-sm-8"><?= e($terms['jours'] ?? '') ?><?= !empty($terms['horaires']) ? ', ' . e($terms['horaires']) : '' ?> — <?= e(rtrim(rtrim((string) ($terms['heures_par_semaine'] ?? ''), '0'), '.')) ?> h par semaine</dd>
  <dt class="col-sm-4">Rémunération</dt><dd class="col-sm-8 fw-semibold"><?= e(money((int) ($terms['salaire'] ?? 0))) ?> <?= ($terms['periodicite'] ?? '') === 'MENSUEL' ? 'par mois (brut)' : 'pour la prestation (brut)' ?></dd>
  <dt class="col-sm-4">Commission JOB NIGER</dt><dd class="col-sm-8"><?= e(rtrim(rtrim((string) ($terms['commission_jobniger_pourcentage'] ?? '0'), '0'), '.') ?: '0') ?> % du brut, déduite sur la fiche de paie</dd>
  <dt class="col-sm-4">Paiement</dt><dd class="col-sm-8"><?= ($terms['mode_paiement'] ?? '') === 'ESPECES' ? 'En main propre (espèces)' : 'Mobile Money' ?>, versé directement par l'employeur</dd>
  <dt class="col-sm-4">Période</dt><dd class="col-sm-8">À partir du <?= e(date_fr($terms['date_debut'] ?? null)) ?><?= !empty($terms['date_fin']) ? ' jusqu\'au ' . e(date_fr($terms['date_fin'])) : ' (durée indéterminée)' ?></dd>
  <dt class="col-sm-4">Fiches de paie</dt><dd class="col-sm-8"><?= e($terms['fiche_de_paie'] ?? '') ?></dd>
</dl>
<?php if (!empty($terms['taches_et_clauses'])): ?>
  <div class="mt-2"><div class="small fw-semibold">Tâches et clauses particulières</div><div class="jn-terms"><?= e($terms['taches_et_clauses']) ?></div></div>
<?php endif; ?>
