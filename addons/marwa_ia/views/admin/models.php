<?php /** MARWA AI : modèles de langage (local ou externe), rôles, limites, coûts. */ $cur = 'models'; require __DIR__ . '/_nav.php';
$use = []; foreach ($usage as $x) { $use[(int) $x['model_setting_id']] = $x; } ?>
<div class="alert alert-light border small">Les chiffres sont toujours calculés par POS Marwa ; un modèle ne sert qu'à <b>rédiger</b> (aide, reformulation) et à <b>classer</b> une question difficile. Il ne reçoit jamais la base de données : seulement la question, quelques extraits de documentation et, si la reformulation est activée, les faits déjà calculés.
  Un fournisseur externe (OpenAI, Claude, Gemini…) reçoit ces textes sur Internet : il reste inactif tant que vous n'avez pas coché l'accord ci-dessous (loi n° 2017-28 sur les données personnelles). Les clés d'API sont chiffrées dans la base et jamais réaffichées.</div>
<div class="row g-3">
  <div class="col-xl-7"><div class="card"><div class="card-header">Modèles configurés</div>
    <?php if (!$models): ?><div class="card-body small text-secondary">Aucun modèle : MARWA AI fonctionne avec son moteur intégré.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-sm small mb-0 align-middle"><thead><tr><th>Modèle</th><th>Rôle</th><th>État</th><th>Ce mois</th><th style="min-width:230px"></th></tr></thead><tbody>
      <?php foreach ($models as $m): $u = $use[(int) $m['id']] ?? null; ?><tr>
        <td><b><?= e($m['name']) ?></b><div class="text-secondary"><?= e($providers[$m['provider']][0] ?? $m['provider']) ?> · <code><?= e($m['model']) ?></code></div><div class="text-secondary"><?= e($m['base_url']) ?><?= $m['api_key_hint'] ? ' · clé ' . e($m['api_key_hint']) : '' ?></div></td>
        <td><?= (int) $m['is_default'] ? '<span class="badge text-bg-primary">par défaut</span>' : ((int) $m['is_fallback'] ? '<span class="badge text-bg-info">secours</span>' : '<span class="badge text-bg-light border">inactif</span>') ?><?= !(int) $m['external_ack'] ? '<div class="text-danger">accord externe manquant</div>' : '' ?></td>
        <td><?= e($m['status']) ?><?= $m['last_message'] ? '<div class="text-secondary">' . e(mb_substr($m['last_message'], 0, 60)) . '</div>' : '' ?></td>
        <td><?= $u ? (int) $u['n'] . ' appels<div class="text-secondary">' . number_format((int) $u['tin'] + (int) $u['tout'], 0, ',', ' ') . ' jetons · ' . e(number_format((float) $u['cost'], 4, ',', ' ')) . '</div>' : '—' ?></td>
        <td class="d-flex flex-wrap gap-1">
          <form method="post" action="<?= e(url('/ia/admin/modeles/' . (int) $m['id'] . '/role')) ?>" class="d-flex gap-1"><?= csrf_field() ?><select name="role" class="form-select form-select-sm"><option value="default">Par défaut</option><option value="fallback">Secours</option><option value="off">Désactiver</option></select><button class="btn btn-sm btn-outline-primary">OK</button></form>
          <form method="post" action="<?= e(url('/ia/admin/modeles/' . (int) $m['id'] . '/tester')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Tester</button></form>
          <a class="btn btn-sm btn-link" href="<?= e(url('/ia/admin/modeles', ['id' => $m['id']])) ?>">Modifier</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></div></div>
  <div class="col-xl-5"><form method="post" action="<?= e(url('/ia/admin/modeles')) ?>" class="card" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="card-header"><?= $edit ? 'Modifier « ' . e($edit['name']) . ' »' : 'Ajouter un modèle' ?></div><div class="card-body">
      <label class="form-label">Fournisseur</label><select name="provider" class="form-select mb-2"><?php foreach ($providers as $k => $p): ?><option value="<?= e($k) ?>" <?= ($edit['provider'] ?? 'ollama') === $k ? 'selected' : '' ?>><?= e($p[0]) ?></option><?php endforeach; ?></select>
      <label class="form-label">Nom affiché</label><input name="name" class="form-control mb-2" required maxlength="120" value="<?= e($edit['name'] ?? '') ?>" placeholder="ex. Claude (siège)">
      <label class="form-label">Identifiant du modèle</label><input name="model" class="form-control mb-2" required maxlength="120" value="<?= e($edit['model'] ?? '') ?>" placeholder="ex. llama3.2, claude-opus-5-5">
      <label class="form-label">Adresse de l'API</label><input name="base_url" class="form-control mb-2" maxlength="255" value="<?= e($edit['base_url'] ?? '') ?>" placeholder="vide = adresse officielle du fournisseur">
      <label class="form-label">Clé d'API <?= !empty($edit['api_key_hint']) ? '(actuelle : ' . e($edit['api_key_hint']) . ' — laisser vide pour la garder)' : '' ?></label><input type="password" name="api_key" class="form-control mb-2" autocomplete="new-password">
      <?php if (!empty($edit['api_key_hint'])): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="clear_key" value="1" id="mK"><label class="form-check-label" for="mK">Effacer la clé enregistrée</label></div><?php endif; ?>
      <div class="row g-2"><div class="col-6"><label class="form-label">Jetons max. par réponse</label><input type="number" name="max_output_tokens" class="form-control" value="<?= (int) ($edit['max_output_tokens'] ?? 1024) ?>"></div>
        <div class="col-6"><label class="form-label">Délai max. (s)</label><input type="number" name="timeout_s" class="form-control" value="<?= (int) ($edit['timeout_s'] ?? 40) ?>"></div>
        <div class="col-6"><label class="form-label">Jetons par jour (limite)</label><input name="daily_token_limit" class="form-control" value="<?= e((string) ($edit['daily_token_limit'] ?? '')) ?>" placeholder="illimité"></div>
        <div class="col-6"><label class="form-label">Requêtes / utilisateur / jour</label><input name="user_daily_requests" class="form-control" value="<?= e((string) ($edit['user_daily_requests'] ?? '')) ?>" placeholder="illimité"></div>
        <div class="col-6"><label class="form-label">Coût entrée / M jetons</label><input name="input_cost_per_mtok" class="form-control" value="<?= e((string) ($edit['input_cost_per_mtok'] ?? '')) ?>"></div>
        <div class="col-6"><label class="form-label">Coût sortie / M jetons</label><input name="output_cost_per_mtok" class="form-control" value="<?= e((string) ($edit['output_cost_per_mtok'] ?? '')) ?>"></div></div>
      <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="external_ack" value="1" id="mA" <?= (int) ($edit['external_ack'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label small" for="mA">Fournisseur externe : j'accepte que les questions des utilisateurs, des extraits de documentation et les faits calculés soient envoyés à ce fournisseur. J'informe les utilisateurs conformément à la loi n° 2017-28.</label></div>
    </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Enregistrer</button></div></form></div>
</div>
