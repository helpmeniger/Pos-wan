<?php $title = 'Optimus IA'; ?>
<div class="mc-page-head">
    <div><h1>Optimus <span class="mc-accent">IA</span></h1><p>Assistant d'exploitation : réponses construites uniquement à partir de vos données réelles. Les opérations nécessitent votre confirmation.</p></div>
    <div class="d-flex gap-2 align-items-center"><span class="mc-status <?= $external ? 'ok' : 'muted' ?>"><?= $external ? 'Moteur local + modèle externe' : 'Moteur local (hors ligne)' ?></span>
        <form method="post" action="<?= e(url('/ai/reset')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus-lg"></i> Nouvelle conversation</button></form></div>
</div>
<div class="mc-card" id="mc-ai" data-ask="<?= e(url('/ai/ask')) ?>" data-confirm-base="<?= e(url('/ai/actions')) ?>">
    <div class="mc-chat" id="mc-chat">
        <?php if (!$messages): ?>
            <div class="mc-msg assistant">Bonjour <?= e(auth()['full_name']) ?> ! Posez-moi une question sur votre réseau ou vos ventes. Tapez « aide » pour des exemples.</div>
        <?php endif; ?>
        <?php foreach ($messages as $m): ?>
            <div class="mc-msg <?= $m['role'] === 'user' ? 'user' : 'assistant' ?>" data-json="<?= e(json_encode(['table' => $m['data']['table'] ?? null])) ?>"><?= e($m['content']) ?></div>
        <?php endforeach; ?>
        <?php foreach ($pending as $p): ?>
            <div class="mc-msg assistant" data-pending="<?= (int) $p['id'] ?>"><strong>Action en attente :</strong> <?= e($p['summary']) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="px-3 pb-2 d-flex flex-wrap gap-1">
        <?php foreach (['Combien de clients sont connectés actuellement ?', 'Quel est le chiffre d\'affaires du mois ?', 'Affiche les tickets vendus aujourd\'hui', 'Quels routeurs sont déconnectés ?', 'Quels forfaits sont les plus vendus ?', 'Quel est le stock de tickets ?'] as $s): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary mc-suggest" data-suggest="<?= e($s) ?>"><?= e($s) ?></button>
        <?php endforeach; ?>
    </div>
    <form class="mc-card-head border-top border-bottom-0 gap-2" id="mc-ai-form" data-no-lock>
        <input class="form-control" id="mc-ai-input" placeholder="Ex. Génère 100 tickets de 500 FCFA sur le routeur principal" autocomplete="off" maxlength="1000" aria-label="Question">
        <button class="btn btn-primary"><i class="bi bi-send"></i></button>
    </form>
</div>
<?php if (can('settings.manage')): ?><p class="small text-muted mt-2">Un modèle externe (Claude) peut compléter le moteur local pour les questions libres : <a href="<?= e(url('/settings', ['tab' => 'ai'])) ?>">Paramètres → Optimus IA</a>.</p><?php endif; ?>
