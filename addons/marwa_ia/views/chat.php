<?php
/** MARWA AI : conversations (colonne de gauche) et échange en continu. */
$ex = [];
foreach ($examples as $g => $list) {
    $ex[$g] = array_values(array_filter($list, fn($x) => !str_contains($x, '…')));
}
?>
<div class="mai" data-mai-app data-user="<?= e($who['name'] ?? '') ?>">
  <aside class="mai-side" data-mai-side>
    <div class="d-flex gap-2 mb-2"><button type="button" class="btn btn-primary flex-grow-1" data-mai-new><i class="bi bi-plus-lg"></i> Nouvelle conversation</button><button type="button" class="btn btn-outline-secondary d-lg-none" data-mai-toggle aria-label="Fermer la liste"><i class="bi bi-x-lg"></i></button></div>
    <div class="mai-convs" data-mai-convs>
      <?php foreach ($conversations as $c): ?>
        <div class="mai-conv" data-id="<?= (int) $c['id'] ?>"><button type="button" class="mai-conv-open" data-mai-open="<?= (int) $c['id'] ?>"><span><?= e($c['title']) ?></span><small><?= e(fdate($c['updated_at'] ?? '')) ?></small></button></div>
      <?php endforeach; ?>
    </div>
    <div class="mai-side-foot small">
      <form method="post" action="<?= e(url('/ia/conversations/supprimer-tout')) ?>" data-confirm="Effacer définitivement toutes vos conversations avec MARWA AI ?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i> Effacer toutes mes conversations</button></form>
      <div class="text-secondary mt-1">Conservées <?= (int) $retention ?> jours, visibles par vous seul.</div>
    </div>
  </aside>
  <section class="mai-main">
    <header class="mai-head">
      <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none" data-mai-toggle aria-label="Conversations"><i class="bi bi-list"></i></button>
      <div class="mai-title"><i class="bi bi-stars"></i> <b data-mai-title>MARWA AI</b><span class="d-none d-md-inline"> — votre administrateur intelligent</span></div>
      <span class="badge text-bg-light border ms-auto" title="Moteur utilisé pour rédiger"><?= $model ? '<i class="bi bi-cpu"></i> ' . e($model['name']) : '<i class="bi bi-shield-check"></i> Moteur intégré (local)' ?></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-mai-rename title="Renommer"><i class="bi bi-pencil"></i></button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-mai-del title="Supprimer cette conversation"><i class="bi bi-trash"></i></button>
    </header>
    <div class="mai-log" data-mai-log aria-live="polite">
      <div class="mai-welcome" data-mai-welcome>
        <h2><i class="bi bi-stars"></i> Bonjour<?= !empty($who['name']) ? ' ' . e(explode(' ', $who['name'])[0]) : '' ?>, que voulez-vous savoir ?</h2>
        <p class="text-secondary">Je lis vos données en direct, avec vos droits (<?= e($who['role'] ?? '') ?><?= !empty($who['scope']) ? ' · ' . e($who['scope']) : '' ?>), je vous explique le logiciel pas à pas et je prépare les opérations courantes — rien n'est exécuté sans votre confirmation.</p>
        <div class="mai-chips">
          <?php foreach (['Fais-moi un bilan de la journée', 'Quelle est la valeur de mon stock ?', 'Quels produits sont en rupture ?', 'Compare cette semaine avec la semaine dernière', 'Qui me doit de l\'argent ?', 'Comment faire un inventaire ?', 'Que sais-tu faire ?'] as $s): ?>
            <button type="button" data-mai-ask="<?= e($s) ?>"><?= e($s) ?></button>
          <?php endforeach; ?>
        </div>
        <details class="mt-3 small"><summary>Autres exemples</summary>
          <?php foreach ($ex as $g => $list): if (!$list) continue; ?><div class="fw-semibold mt-2"><?= e($g) ?></div><div class="mai-chips"><?php foreach ($list as $x): ?><button type="button" data-mai-ask="<?= e($x) ?>"><?= e($x) ?></button><?php endforeach; ?></div><?php endforeach; ?>
        </details>
      </div>
    </div>
    <form class="mai-form" data-mai-form data-no-lock>
      <textarea class="form-control" data-mai-input rows="1" maxlength="500" placeholder="Posez votre question… (Entrée pour envoyer, Maj+Entrée pour une nouvelle ligne)" aria-label="Question pour MARWA AI"></textarea>
      <button class="btn btn-primary" type="submit" data-mai-send aria-label="Envoyer"><i class="bi bi-send"></i></button>
      <button class="btn btn-outline-secondary d-none" type="button" data-mai-stop aria-label="Arrêter"><i class="bi bi-stop-fill"></i></button>
    </form>
    <div class="mai-foot small text-secondary">Les chiffres sont calculés par POS Marwa dans votre base, jamais inventés. Vérifiez les montants importants dans l'écran d'origine avant une décision.</div>
  </section>
</div>
<script src="<?= e(asset('addons/marwa_ia/marwa-ai.js')) ?>"></script>
