<?php $this->layout('layouts/app'); use App\Services\Jobs\ApplicationService; use App\Services\Jobs\InterviewService; ?>
<div class="container py-4" style="max-width:960px">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">Mes candidatures</h1>
    <a class="btn btn-primary" href="<?= e(url('/offres')) ?>"><i class="bi bi-search" aria-hidden="true"></i> Voir les offres</a>
  </div>
  <?php if (!$applications): ?>
    <div class="jn-card p-4 text-center text-muted">Vous n'avez pas encore postulé. <a href="<?= e(url('/offres')) ?>">Parcourez les offres</a> et postulez en un clic.</div>
  <?php endif; ?>
  <?php foreach ($applications as $a): ?>
    <div class="jn-card p-3 mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
          <a class="fw-semibold" href="<?= e(url('/offres/' . $a['post_uuid'])) ?>"><?= e($a['title']) ?></a>
          <div class="small text-muted"><?= e($a['recruiter']) ?> · <?= e($a['city'] ?? '') ?> · postulé le <?= e(date_fr($a['created_at'])) ?></div>
        </div>
        <div><?= badge(ApplicationService::LABELS[$a['status']] ?? $a['status'], status_tone($a['status'])) ?></div>
      </div>

      <?php if ($a['interview_uuid'] && !in_array($a['interview_status'], ['ANNULE'], true)): ?>
        <div class="border rounded p-3 mt-3 bg-primary-soft">
          <div class="fw-semibold mb-1"><i class="bi bi-calendar-event" aria-hidden="true"></i>
            Entretien <?= $a['interview_mode'] === 'EN_LIGNE' ? 'en ligne' : 'en présentiel' ?> — <?= e(date_fr($a['interview_at'], true)) ?> (<?= (int) $a['duration_minutes'] ?> min)
            <?= badge(InterviewService::LABELS[$a['interview_status']] ?? $a['interview_status'], status_tone($a['interview_status'])) ?></div>
          <?php if ($a['interview_mode'] === 'EN_LIGNE'): ?>
            <div class="small">Lien : <a href="<?= e($a['meeting_link']) ?>" target="_blank" rel="noopener noreferrer"><?= e($a['meeting_link']) ?></a></div>
          <?php else: ?>
            <div class="small">Adresse : <?= e($a['interview_location']) ?></div>
          <?php endif; ?>
          <?php if ($a['interview_notes']): ?><div class="small text-muted">Note du recruteur : <?= e($a['interview_notes']) ?></div><?php endif; ?>
          <?php if (in_array($a['interview_status'], ['PROPOSE', 'REPORT_DEMANDE'], true)): ?>
            <form method="post" action="<?= e(url('/entretiens/' . $a['interview_uuid'] . '/reponse')) ?>" class="mt-2 d-flex flex-wrap gap-2 align-items-start">
              <?= csrf_field() ?>
              <button class="btn btn-sm btn-primary" name="action" value="confirm" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Je confirme ma présence</button>
              <input class="form-control form-control-sm" style="max-width:280px" name="note" maxlength="500" placeholder="Autre créneau souhaité (ex : mardi 15 h)">
              <button class="btn btn-sm btn-outline-secondary" name="action" value="reschedule" type="submit">Demander un autre créneau</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($a['employment_uuid']): ?>
        <div class="mt-3"><a class="btn btn-sm btn-success" href="<?= e(url('/emplois/' . $a['employment_uuid'])) ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>
          <?= $a['employment_status'] === 'EN_ATTENTE_ACCEPTATION' ? 'Contrat proposé : le lire et l\'accepter' : 'Voir mon contrat' ?></a></div>
      <?php endif; ?>
      <?php if ($a['status'] === 'REFUSEE' && $a['decision_note']): ?><div class="small text-muted mt-2">Message du recruteur : <?= e($a['decision_note']) ?></div><?php endif; ?>
      <?php if (in_array($a['status'], ApplicationService::ACTIVE, true)): ?>
        <form method="post" action="<?= e(url('/candidatures/' . $a['uuid'] . '/retirer')) ?>" class="mt-2" data-confirm="Retirer cette candidature ?">
          <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0" type="submit">Retirer ma candidature</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
