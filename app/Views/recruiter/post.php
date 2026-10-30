<?php $this->layout('layouts/app'); use App\Services\Jobs\ApplicationService; use App\Services\Jobs\InterviewService; use App\Services\Jobs\JobPostService; use App\Support\Phone; ?>
<div class="container py-4">
  <nav aria-label="Fil d'Ariane"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('/recruteur/offres')) ?>">Mes offres</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($post['reference']) ?></li>
  </ol></nav>
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1"><?= e($post['title']) ?></h1>
      <div class="small text-muted"><?= e(JobPostService::payLabel($post)) ?> · <?= e($post['city'] ?? '') ?> · <?= (int) $post['positions_filled'] ?>/<?= (int) $post['positions_total'] ?> poste(s) pourvu(s)
        · <?= badge(str_replace('_', ' ', ucfirst(strtolower($post['status']))), status_tone($post['status'])) ?></div>
      <?php if ($post['status'] === 'EN_MODERATION'): ?><div class="small text-warning-emphasis mt-1">En attente de validation par l'équipe JOB NIGER.</div><?php endif; ?>
      <?php if ($post['rejection_reason']): ?><div class="small text-danger mt-1">Motif de l'équipe : <?= e($post['rejection_reason']) ?></div><?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-start">
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/offres/' . $post['uuid'])) ?>">Voir l'annonce</a>
      <form method="post" action="<?= e(url('/recruteur/offres/' . $post['uuid'] . '/statut')) ?>">
        <?= csrf_field() ?>
        <?php if (in_array($post['status'], ['TERMINEE', 'EXPIREE'], true)): ?>
          <button class="btn btn-sm btn-outline-primary" name="action" value="reopen" type="submit">Rouvrir</button>
        <?php elseif (!in_array($post['status'], ['ANNULEE', 'REFUSEE'], true)): ?>
          <button class="btn btn-sm btn-outline-danger" name="action" value="close" type="submit" data-confirm="Clôturer l'offre ?">Clôturer</button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <h2 class="h5">Candidatures (<?= count($applications) ?>)</h2>
  <?php if (!$applications): ?><div class="jn-card p-4 text-muted text-center">Aucune candidature pour le moment.</div><?php endif; ?>
  <?php foreach ($applications as $a):
      $age = $a['birth_date'] ? (new DateTimeImmutable($a['birth_date']))->diff(new DateTimeImmutable('today'))->y : null; ?>
    <div class="jn-card p-3 mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
          <span class="fw-semibold"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></span>
          <?php if ((int) $a['verification_level'] >= 2): ?><span class="badge bg-primary-soft text-primary"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Vérifié</span><?php endif; ?>
          <div class="small text-muted"><?= e($a['city'] ?? '') ?><?= $age ? ' · ' . $age . ' ans' : '' ?><?= $a['trades'] ? ' · ' . e($a['trades']) : '' ?>
            <?= (int) $a['rating_count'] > 0 ? ' · ★ ' . e(number_format((float) $a['rating_avg'], 1, ',', '')) : '' ?> · postulé le <?= e(date_fr($a['created_at'])) ?></div>
          <?php if (in_array($a['status'], ['PRESELECTIONNEE', 'ACCEPTEE'], true)): ?><div class="small">Téléphone : <a href="tel:<?= e($a['phone']) ?>"><?= e(Phone::format($a['phone'])) ?></a></div><?php endif; ?>
        </div>
        <div><?= badge(ApplicationService::LABELS[$a['status']] ?? $a['status'], status_tone($a['status'])) ?></div>
      </div>
      <?php if ($a['headline'] || $a['bio']): ?><p class="small mb-1 mt-2"><?= e($a['headline'] ?? '') ?> <?= e(mb_strimwidth((string) $a['bio'], 0, 300, '…')) ?></p><?php endif; ?>
      <?php if ($a['message']): ?><div class="small border-start border-3 ps-2 my-2">« <?= e($a['message']) ?> »</div><?php endif; ?>

      <?php if ($a['interview_uuid'] && in_array($a['interview_status'], ['PROPOSE', 'CONFIRME', 'REPORT_DEMANDE'], true)): ?>
        <div class="small bg-primary-soft rounded p-2 my-2">
          <i class="bi bi-calendar-event" aria-hidden="true"></i> Entretien le <?= e(date_fr($a['interview_at'], true)) ?> — <?= badge(InterviewService::LABELS[$a['interview_status']] ?? '', status_tone($a['interview_status'])) ?>
          <form method="post" action="<?= e(url('/recruteur/entretiens/' . $a['interview_uuid'] . '/resultat')) ?>" class="d-flex flex-wrap gap-1 mt-2">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-success" name="result" value="CONCLUANT" type="submit">Entretien concluant</button>
            <button class="btn btn-sm btn-outline-secondary" name="result" value="NON_CONCLUANT" type="submit">Non concluant</button>
            <button class="btn btn-sm btn-link text-danger" name="result" value="ANNULE" type="submit" data-confirm="Annuler l'entretien ?">Annuler</button>
          </form>
        </div>
      <?php elseif ($a['interview_status']): ?>
        <div class="small text-muted my-1">Dernier entretien : <?= e(InterviewService::LABELS[$a['interview_status']] ?? '') ?> (<?= e(date_fr($a['interview_at'], true)) ?>)</div>
      <?php endif; ?>

      <div class="d-flex flex-wrap gap-2 mt-2">
        <?php if ($a['employment_uuid']): ?>
          <a class="btn btn-sm btn-success" href="<?= e(url('/emplois/' . $a['employment_uuid'])) ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Voir le contrat</a>
        <?php elseif (in_array($a['status'], ApplicationService::ACTIVE, true)): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/recruteur/candidatures/' . $a['uuid'] . '/entretien')) ?>"><i class="bi bi-calendar-plus" aria-hidden="true"></i> <?= $a['interview_uuid'] ? 'Nouvel entretien' : 'Proposer un entretien' ?></a>
          <a class="btn btn-sm btn-primary" href="<?= e(url('/recruteur/candidatures/' . $a['uuid'] . '/embaucher')) ?>"><i class="bi bi-person-check" aria-hidden="true"></i> Embaucher</a>
          <form method="post" action="<?= e(url('/recruteur/candidatures/' . $a['uuid'] . '/refuser')) ?>" class="d-flex gap-1" data-confirm="Refuser cette candidature ?">
            <?= csrf_field() ?>
            <input class="form-control form-control-sm" name="note" maxlength="500" placeholder="Message (facultatif)">
            <button class="btn btn-sm btn-outline-danger" type="submit">Refuser</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
