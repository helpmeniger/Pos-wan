<?php
declare(strict_types=1);
// Fiche détaillée : alerte (?id=) ou publication analysée sans alerte (?pub=).
require __DIR__ . '/_init.php';

use Verifia\Alerts\AlertService;
use Verifia\Analysis\TextAnalyzer;
use Verifia\Auth;
use Verifia\Database;
use Verifia\View;

$user = Auth::require('viewer');
$alert = null;
if (isset($_GET['id'])) {
    $alert = AlertService::detail((int) $_GET['id']);
    $pub = $alert['publication'] ?? null;
    if ($alert && $alert['status'] === 'new' && Auth::can('analyst')) {
        AlertService::setStatus((int) $alert['id'], 'read', $user['id']);
        $alert['status'] = 'read';
    }
} else {
    $pub = AlertService::publication((int) ($_GET['pub'] ?? 0));
}
if (!$pub) {
    http_response_code(404);
    View::header('Introuvable', 'alerts');
    echo '<div class="alert alert-warning">Élément introuvable.</div>';
    View::footer();
    exit;
}
$r = $pub['result'];
$ind = json_decode((string) ($r['indicators_json'] ?? '[]'), true) ?: [];
$pl = json_decode((string) ($r['places_json'] ?? '{}'), true) ?: [];
$sources = json_decode((string) ($r['sources_json'] ?? '[]'), true) ?: [];
$similar = json_decode((string) ($r['similar_json'] ?? '[]'), true) ?: [];
$canEdit = Auth::can('analyst');
$users = Database::all("SELECT u.id, u.display_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ('analyst','admin') ORDER BY u.display_name");
$searchLinks = fn(string $q) => [
    'Google' => 'https://www.google.com/search?q=' . rawurlencode($q),
    'Bing' => 'https://www.bing.com/search?q=' . rawurlencode($q),
    'DuckDuckGo' => 'https://duckduckgo.com/?q=' . rawurlencode($q),
    'Google Fact Check' => 'https://toolbox.google.com/factcheck/explorer/search/' . rawurlencode($q),
];
View::header($alert ? 'Alerte #' . $alert['id'] : 'Publication #' . $pub['id'], 'alerts', ['bare' => !empty($_GET['window'])]);
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <h1 class="h4 m-0"><?= $alert ? 'Alerte n° ' . (int) $alert['id'] : 'Publication n° ' . (int) $pub['id'] ?></h1>
  <?php if ($r): ?><?= View::priorityBadge($alert['priority'] ?? $r['priority']) ?>
  <span class="badge text-bg-dark border"><?= e(TextAnalyzer::CATEGORY_LABELS[$alert['category'] ?? $r['category']] ?? $r['category']) ?></span>
  <span class="badge text-bg-dark border">Confiance de l'analyse : <?= round((float) ($alert['confidence'] ?? $r['confidence']) * 100) ?> %</span><?php endif; ?>
  <?php if ($alert): ?><span class="badge text-bg-secondary" id="status-badge"><?= e(View::STATUS_LABELS[$alert['status']]) ?></span><?php endif; ?>
  <div class="ms-auto d-flex flex-wrap gap-1">
  <?php if ($alert): ?>
    <a class="btn btn-sm btn-outline-light" href="report.php?id=<?= (int) $alert['id'] ?>">Exporter en PDF</a>
    <?php if ($canEdit): ?>
      <button class="btn btn-sm btn-outline-info" data-status="in_review">En vérification</button>
      <button class="btn btn-sm btn-outline-success" data-status="verified">Marquer vérifiée</button>
      <button class="btn btn-sm btn-outline-secondary" data-status="ignored">Ignorer</button>
      <select class="form-select form-select-sm w-auto" id="assign"><option value="">Affecter à…</option>
        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $alert['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['display_name']) ?></option><?php endforeach; ?></select>
      <?php if (in_array($alert['session_state'], ['running', 'paused'], true)): ?><button class="btn btn-sm btn-outline-warning" id="btn-focus" data-session="<?= (int) $alert['session_id'] ?>">Afficher la session source</button><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
<div class="alert alert-warning small py-2">Cette fiche présente des <b>indices</b> calculés automatiquement. Une priorité élevée ou critique signifie qu'une vérification humaine est nécessaire ; elle ne prouve pas que la publication est fausse.</div>

<div class="row g-3">
<div class="col-lg-7">
  <div class="card mb-3"><div class="card-header">Publication</div><div class="card-body">
    <dl class="row small mb-2">
      <dt class="col-sm-4">Site / session d'origine</dt><dd class="col-sm-8"><?= e($pub['site_name'] ?? '—') ?> / <?= e($alert['session_name'] ?? ($pub['occurrences'][0]['session_name'] ?? ($pub['source'] === 'manual' ? 'Analyse manuelle' : '—'))) ?></dd>
      <dt class="col-sm-4">Lien</dt><dd class="col-sm-8 text-break"><?= $pub['permalink'] ? '<a href="' . e($pub['permalink']) . '" target="_blank" rel="noopener noreferrer">' . e($pub['permalink']) . '</a>' : 'non disponible' ?></dd>
      <dt class="col-sm-4">Auteur affiché</dt><dd class="col-sm-8"><?= e($pub['author_label'] ?? '—') ?></dd>
      <dt class="col-sm-4">Date affichée par le site</dt><dd class="col-sm-8"><?= e($pub['published_label'] ?? 'non disponible') ?></dd>
      <dt class="col-sm-4">Détectée le</dt><dd class="col-sm-8"><?= e(View::date($pub['first_seen_at'])) ?> — <?= (int) $pub['occurrences'] ?> occurrence(s)</dd>
    </dl>
    <?php if ($pub['thumbnail_path']): ?><img class="img-fluid rounded border mb-2 thumb-lg" src="media.php?f=<?= e(rawurlencode($pub['thumbnail_path'])) ?>" alt="Capture de la publication"><?php endif; ?>
    <div class="pub-text"><?= nl2br(e($pub['text_content'])) ?></div>
    <?php if ($pub['occurrences']): ?><details class="small mt-2"><summary>Occurrences (<?= count($pub['occurrences']) ?>)</summary><ul><?php foreach ($pub['occurrences'] as $o): ?><li><?= e(View::date($o['seen_at'])) ?> — <?= e($o['session_name'] ?? 'session supprimée') ?> <?= $o['page_url'] ? '— ' . e($o['page_url']) : '' ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  </div></div>

  <div class="card mb-3"><div class="card-header">Affirmations vérifiables (<?= count($pub['claims']) ?>)</div><div class="card-body">
  <?php if (!$pub['claims']): ?><p class="small text-body-secondary m-0">Aucune affirmation factuelle identifiée automatiquement.</p><?php endif; ?>
  <?php foreach ($pub['claims'] as $c): ?>
    <div class="claim border rounded p-2 mb-3" data-claim="<?= (int) $c['id'] ?>">
      <div class="fw-semibold">« <?= e($c['claim_text']) ?> »</div>
      <div class="small mt-1">Type : <?= e($c['claim_type']) ?> · Verdict : <b><?= e($c['verdict']) ?></b>
        <?= $c['external_search_done'] ? '' : ' · <span class="text-warning">aucune recherche externe enregistrée</span>' ?></div>
      <?php if ($c['verification_method']): ?><div class="small">Méthode : <?= e($c['verification_method']) ?></div><?php endif; ?>
      <?php if ($c['limitations']): ?><div class="small text-body-secondary">Limites : <?= e($c['limitations']) ?></div><?php endif; ?>
      <div class="small mt-1">Rechercher manuellement :
        <?php foreach ($searchLinks($c['claim_text']) as $name => $url): ?><a class="me-2" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($name) ?></a><?php endforeach; ?></div>
      <?php if ($c['evidence']): ?><table class="table table-sm small mt-2 mb-1"><thead><tr><th>Source</th><th>Position</th><th>Date</th><th>Indépendance</th><th>Méthode</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($c['evidence'] as $ev): ?><tr><td class="text-break"><a href="<?= e($ev['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($ev['title'] ?: $ev['url']) ?></a><?= $ev['publisher'] ? '<br><span class="text-body-secondary">' . e($ev['publisher']) . '</span>' : '' ?></td>
          <td><?= e(['corroborates' => 'corrobore', 'contradicts' => 'contredit', 'context' => 'contexte', 'neutral' => 'neutre'][$ev['stance']]) ?></td><td><?= e($ev['source_published_at'] ?? '—') ?></td><td><?= e($ev['independence']) ?></td><td><?= e($ev['method'] ?? '') ?></td>
          <?php if ($canEdit): ?><td><button class="btn btn-link btn-sm text-danger p-0" data-del-evidence="<?= (int) $ev['id'] ?>">✕</button></td><?php endif; ?></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
      <?php if ($canEdit): ?>
      <details class="small mt-1"><summary>Ajouter une source consultée / donner un verdict</summary>
        <form class="row g-1 mt-1 evidence-form">
          <input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>">
          <div class="col-md-6"><input class="form-control form-control-sm" name="url" type="url" placeholder="URL de la source consultée" required></div>
          <div class="col-md-6"><input class="form-control form-control-sm" name="title" placeholder="Titre"></div>
          <div class="col-md-4"><input class="form-control form-control-sm" name="publisher" placeholder="Éditeur / média"></div>
          <div class="col-md-2"><select class="form-select form-select-sm" name="stance"><option value="corroborates">Corrobore</option><option value="contradicts">Contredit</option><option value="context">Contexte</option><option value="neutral">Neutre</option></select></div>
          <div class="col-md-3"><input class="form-control form-control-sm" name="source_published_at" type="date" title="Date de publication de la source"></div>
          <div class="col-md-3"><select class="form-select form-select-sm" name="independence"><option value="unknown">Indépendance ?</option><option value="independent">Indépendante</option><option value="related">Liée</option><option value="same_origin">Même origine</option></select></div>
          <div class="col-md-9"><input class="form-control form-control-sm" name="method" placeholder="Méthode (ex. recherche Google, appel à la source, archive…)"></div>
          <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Ajouter la source</button></div>
        </form>
        <form class="row g-1 mt-2 verdict-form">
          <input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>">
          <div class="col-md-3"><select class="form-select form-select-sm" name="verdict">
            <?php foreach (['unverified' => 'Non vérifiée', 'corroborated' => 'Corroborée', 'contradicted' => 'Contredite', 'mixed' => 'Mitigée', 'unprovable' => 'Invérifiable'] as $k => $v): ?><option value="<?= $k ?>" <?= $c['verdict'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><input class="form-control form-control-sm" name="method" placeholder="Méthode de vérification" value="<?= e($c['verification_method']) ?>"></div>
          <div class="col-md-3"><input class="form-control form-control-sm" name="limitations" placeholder="Limites de la recherche" value="<?= e($c['limitations']) ?>"></div>
          <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Enregistrer</button></div>
        </form>
      </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div></div>

  <?php foreach ($pub['images'] as $img): ?>
  <div class="card mb-3"><div class="card-header">Image n° <?= (int) $img['id'] ?> <?= $img['matches'] ? '<span class="badge text-bg-warning">déjà rencontrée</span>' : '' ?></div><div class="card-body">
    <div class="row g-2">
      <div class="col-md-4"><img class="img-fluid rounded border" src="media.php?f=<?= e(rawurlencode($img['file_path'])) ?>" alt=""></div>
      <div class="col-md-8 small">
        <div>SHA-256 : <code class="text-break"><?= e($img['sha256']) ?></code></div>
        <div>dHash : <code><?= e(sprintf('%016x', (int) $img['dhash'])) ?></code> · aHash : <code><?= e(sprintf('%016x', (int) $img['ahash'])) ?></code> · <?= (int) $img['width'] ?>×<?= (int) $img['height'] ?></div>
        <div>OCR : <?= e(['unavailable' => 'indisponible (Tesseract non configuré)', 'done' => 'effectué', 'no_text' => 'aucun texte détecté', 'error' => 'erreur', 'not_run' => 'non exécuté'][$img['ocr_status']] ?? $img['ocr_status']) ?></div>
        <?php if ($img['ocr_text']): ?><div class="border rounded p-1 my-1"><?= nl2br(e($img['ocr_text'])) ?></div><?php endif; ?>
        <div>Métadonnées EXIF : <?= $img['exif_json'] ? e($img['exif_json']) : 'aucune (les captures d\'écran n\'en contiennent pas)' ?></div>
        <div class="mt-1">Recherche d'occurrences antérieures (manuelle, en téléversant l'image) :
          <a href="https://lens.google.com/" target="_blank" rel="noopener noreferrer">Google Lens</a> ·
          <a href="https://tineye.com/" target="_blank" rel="noopener noreferrer">TinEye</a> ·
          <a href="https://www.bing.com/visualsearch" target="_blank" rel="noopener noreferrer">Bing Visual Search</a> ·
          <a href="https://yandex.com/images/" target="_blank" rel="noopener noreferrer">Yandex Images</a> ·
          <a href="media.php?f=<?= e(rawurlencode($img['file_path'])) ?>" download>télécharger l'image</a></div>
      </div>
    </div>
    <h6 class="mt-3">Chronologie des occurrences retrouvées</h6>
    <ul class="timeline small">
      <?php foreach (AlertService::imageTimeline($img, $pub) as $t): ?>
        <li class="tl-<?= e($t['kind']) ?>"><b><?= e(View::date($t['date'])) ?></b> — <?= $t['url'] ? '<a href="' . e($t['url']) . '" target="_blank" rel="noopener noreferrer">' . e($t['label']) . '</a>' : e($t['label']) ?>
          <?= $t['shown_date'] ? ' <span class="text-body-secondary">(date affichée : ' . e($t['shown_date']) . ')</span>' : '' ?>
          <span class="badge text-bg-dark border"><?= e(['detection_verifia' => 'détection VERIFIA', 'source_analyste' => 'source ajoutée', 'publication_actuelle' => 'publication analysée'][$t['kind']]) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="small text-body-secondary">Les dates de détection ne correspondent pas nécessairement à la date de création de la photographie. Vérifiez le contexte d'origine.</p>
    <?php if ($img['matches']): ?><div class="d-flex gap-2 flex-wrap"><?php foreach ($img['matches'] as $m): ?>
      <a href="alert.php?pub=<?= (int) $m['publication_id'] ?>" class="text-decoration-none small text-center"><img class="thumb-sm rounded border d-block" src="media.php?f=<?= e(rawurlencode($m['file_path'])) ?>" alt="">pub. #<?= (int) $m['publication_id'] ?><br><?= e($m['match_type']) ?>, d=<?= (int) $m['distance'] ?></a>
    <?php endforeach; ?></div><?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="small mt-2"><summary>Ajouter une occurrence trouvée manuellement</summary>
      <form class="row g-1 mt-1 evidence-form">
        <input type="hidden" name="image_asset_id" value="<?= (int) $img['id'] ?>">
        <div class="col-md-5"><input class="form-control form-control-sm" name="url" type="url" placeholder="URL de l'occurrence" required></div>
        <div class="col-md-3"><input class="form-control form-control-sm" name="title" placeholder="Titre / légende d'origine"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" name="source_published_at" type="date"></div>
        <input type="hidden" name="stance" value="context">
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Ajouter</button></div>
        <div class="col-12"><input class="form-control form-control-sm" name="method" placeholder="Méthode (ex. TinEye, plus ancienne occurrence)"></div>
      </form></details>
    <?php endif; ?>
  </div></div>
  <?php endforeach; ?>
</div>

<div class="col-lg-5">
  <?php if ($alert): ?><div class="card mb-3"><div class="card-header">Motif du signalement</div><div class="card-body small"><?= e($alert['reason']) ?></div></div><?php endif; ?>
  <div class="card mb-3"><div class="card-header">Indices relevés <?= $r ? '(score ' . (int) $r['score'] . ')' : '' ?></div><div class="card-body p-0">
    <?php if (!$r): ?><p class="p-2 small">Analyse en attente.</p><?php elseif (!$ind): ?><p class="p-2 small">Aucun indice particulier.</p><?php else: ?>
    <table class="table table-sm small mb-0"><?php foreach ($ind as $i): ?><tr><td><span class="badge text-bg-<?= $i['weight'] >= 4 ? 'danger' : ($i['weight'] >= 2 ? 'warning' : 'secondary') ?>"><?= (int) $i['weight'] ?></span></td><td><b><?= e($i['label']) ?></b><br><span class="text-body-secondary"><?= e($i['excerpt'] ?? '') ?></span>
      <?php if (!empty($i['ref_publication_id'])): ?><br><a href="alert.php?pub=<?= (int) $i['ref_publication_id'] ?>">voir la publication #<?= (int) $i['ref_publication_id'] ?></a><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div></div>
  <?php if ($r): ?>
  <div class="card mb-3"><div class="card-header">Éléments extraits</div><div class="card-body small">
    <div><b>Sujet :</b> <?= e($r['topic'] ?? '—') ?></div>
    <div><b>Dates :</b> <?= e(implode(', ', array_column($pl['dates'] ?? [], 'text')) ?: 'aucune') ?></div>
    <div><b>Lieux probables :</b> <?= e(implode(', ', $pl['places'] ?? []) ?: 'aucun') ?></div>
    <div><b>Sources citées :</b> <?php if (!$sources): ?>aucune<?php else: ?><ul class="mb-0"><?php foreach ($sources as $s): ?><li class="text-break"><?= e($s['type'] === 'link' ? $s['value'] : '« ' . $s['value'] . ' »') ?></li><?php endforeach; ?></ul><?php endif; ?></div>
    <?php if ($similar): ?><div class="mt-1"><b>Contenus antérieurs similaires :</b><ul class="mb-0"><?php foreach ($similar as $s): ?><li><a href="alert.php?pub=<?= (int) $s['publication_id'] ?>">#<?= (int) $s['publication_id'] ?></a> — <?= round($s['similarity'] * 100) ?> %</li><?php endforeach; ?></ul></div><?php endif; ?>
  </div></div>
  <div class="card mb-3"><div class="card-header">Limites de l'analyse</div><div class="card-body small"><?= nl2br(e($r['limitations'])) ?><div class="text-body-secondary mt-1">Version du moteur : <?= e($r['analyzer_version']) ?> · analysée le <?= e(View::date($r['created_at'])) ?></div></div></div>
  <?php endif; ?>
  <?php if ($alert): ?><div class="card"><div class="card-header">Historique</div><div class="card-body p-0"><table class="table table-sm small mb-0">
    <?php foreach ($alert['history'] as $h): ?><tr><td class="text-nowrap"><?= e(View::date($h['created_at'])) ?></td><td><?= e($h['action']) ?> <?= e(trim(($h['old_value'] ?? '') . ' → ' . ($h['new_value'] ?? ''), ' →')) ?> <?= $h['display_name'] ? '<span class="text-body-secondary">(' . e($h['display_name']) . ')</span>' : '' ?></td></tr><?php endforeach; ?>
  </table></div></div><?php endif; ?>
</div>
</div>
<script type="application/json" id="page-data"><?= json_encode(['alertId' => $alert ? (int) $alert['id'] : null], JSON_HEX_TAG) ?></script>
<?php View::footer(['alert.js']);
