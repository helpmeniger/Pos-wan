<?php $this->layout('layouts/app');
$statusLabels = ['SOUMISE' => 'En attente', 'EN_EXAMEN' => 'En cours d\'examen', 'INFOS_DEMANDEES' => 'Informations demandées', 'APPROUVEE' => 'Vérifié', 'REFUSEE' => 'Refusé', 'ANNULEE' => 'Annulé'];
$tone = ['APPROUVEE' => 'success', 'REFUSEE' => 'danger'];
?>
<div class="container py-4" style="max-width:820px">
  <h1 class="h3">Vérification de mon profil</h1>
  <p class="text-muted">Envoyez une pièce d'identité (et, pour une entreprise, le RCCM ou le NIF). Un membre de l'équipe JOB NIGER la contrôle
    puis affiche le badge <span class="badge bg-primary-soft text-primary"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Vérifié</span>.
    Vos documents restent privés : seuls les vérificateurs habilités peuvent les ouvrir, et chaque consultation est enregistrée.</p>
  <div class="jn-card p-4 mb-4">
    <h2 class="jn-section-title">Envoyer un document</h2>
    <form method="post" action="<?= e(url('/verification')) ?>" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="doc_type">Type de document</label>
          <select class="<?= e(field_class('doc_type', 'form-select')) ?>" id="doc_type" name="doc_type"><?php foreach ($docTypes as $k => $v): ?><option value="<?= e($k) ?>"<?= old('doc_type') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="number_last4">Numéro du document (facultatif)</label>
          <input class="form-control" id="number_last4" name="number_last4" maxlength="40" autocomplete="off"><div class="form-text">Seuls les 4 derniers caractères sont conservés.</div></div>
        <div class="col-12"><label class="form-label" for="document">Photo lisible ou PDF (5 Mo maximum)</label>
          <input class="<?= e(field_class('document')) ?>" type="file" id="document" name="document" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment" required>
          <?= $this->partial('partials/field-error', ['name' => 'document']) ?></div>
      </div>
      <button class="btn btn-primary mt-3" type="submit" data-loading="Envoi…"><i class="bi bi-upload" aria-hidden="true"></i> Envoyer</button>
    </form>
  </div>
  <h2 class="h5">Mes documents</h2>
  <div class="jn-card table-responsive"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Document</th><th>Envoyé le</th><th>Statut</th><th></th></tr></thead>
    <tbody>
      <?php if (!$requests): ?><tr><td colspan="4" class="text-muted small">Aucun document envoyé.</td></tr><?php endif; ?>
      <?php foreach ($requests as $r): ?><tr>
        <td><?= e($docTypes[$r['doc_type']] ?? $r['doc_type']) ?><?= $r['decision_note'] ? '<div class="small text-muted">' . e($r['decision_note']) . '</div>' : '' ?></td>
        <td class="small"><?= e(date_fr($r['submitted_at'])) ?></td>
        <td><?= badge($statusLabels[$r['status']] ?? $r['status'], $tone[$r['status']] ?? 'warning') ?></td>
        <td><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/fichiers/' . $r['file_uuid'])) ?>" target="_blank" rel="noopener">Voir</a></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
</div>
