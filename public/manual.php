<?php
declare(strict_types=1);
// Analyse manuelle de secours : quand un site bloque l'automatisation, l'analyste colle le texte et/ou importe l'image.
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Csrf;
use Verifia\Database;
use Verifia\Ingest\PublicationIngestor;
use Verifia\View;

$user = Auth::require('analyst');
$msg = null;
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyRequest();
    $images = [];
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        if ($_FILES['image']['size'] > PublicationIngestor::MAX_IMAGE_BYTES) {
            $err = 'Image trop volumineuse (3 Mo maximum).';
        } else {
            $images[] = ['data_base64' => base64_encode((string) file_get_contents($_FILES['image']['tmp_name'])), 'caption' => $_POST['caption'] ?? null];
        }
    }
    if (!$err) {
        $sessionId = !empty($_POST['session_id']) ? (int) $_POST['session_id'] : null;
        $r = PublicationIngestor::ingest([
            'text' => (string) ($_POST['text'] ?? ''),
            'permalink' => (string) ($_POST['permalink'] ?? ''),
            'author' => (string) ($_POST['author'] ?? ''),
            'published_label' => (string) ($_POST['published_label'] ?? ''),
            'images' => $images,
        ], $sessionId, null, 'manual');
        if ($r['status'] === 'rejected') {
            $err = 'Contenu refusé : ' . $r['reason'] . '. Une image valide (PNG, JPEG, WebP, GIF) ou un texte d\'au moins 15 caractères est nécessaire.';
        } else {
            \Verifia\EventLog::audit($user['id'], 'manual_submit', 'publication', $r['publication_id']);
            $msg = [$r['status'], (int) $r['publication_id']];
        }
    }
}
$sessions = Database::all("SELECT id, name FROM monitoring_sessions WHERE desired_state <> 'closed' ORDER BY name");
$recent = Database::all(
    "SELECT p.id, p.status, p.first_seen_at, LEFT(c.text_content, 140) AS txt, r.priority, a.id AS alert_id
       FROM detected_publications p JOIN publication_content c ON c.publication_id = p.id
  LEFT JOIN analysis_results r ON r.id = (SELECT MAX(id) FROM analysis_results WHERE publication_id = p.id)
  LEFT JOIN alerts a ON a.publication_id = p.id
      WHERE p.source = 'manual' ORDER BY p.id DESC LIMIT 20"
);
View::header('Analyse manuelle', 'manual');
?>
<h1 class="h4">Analyse manuelle</h1>
<p class="small text-body-secondary">Solution de secours lorsque l'automatisation est impossible ou interdite sur un site (CAPTCHA, blocage, conditions d'utilisation) :
copiez le texte d'une publication visible, son lien et, si besoin, enregistrez l'image (clic droit > Enregistrer l'image) puis importez-la. L'analyse est mise en file avec une priorité haute.
Une image importée conserve ses métadonnées EXIF, contrairement aux captures automatiques.</p>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert alert-success">Publication n° <?= $msg[1] ?> <?= $msg[0] === 'duplicate' ? 'déjà connue (occurrence ajoutée)' : 'enregistrée et placée en file d\'analyse' ?>.
  <a href="alert.php?pub=<?= $msg[1] ?>">Ouvrir la fiche</a> (actualisez dans quelques secondes si l'analyse est en cours).</div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="row g-2 mb-4"><?= Csrf::field() ?>
  <div class="col-12"><label class="form-label">Texte de la publication</label><textarea class="form-control" name="text" rows="6" maxlength="20000"></textarea></div>
  <div class="col-md-6"><label class="form-label">Lien de la publication</label><input class="form-control" name="permalink" type="url"></div>
  <div class="col-md-3"><label class="form-label">Auteur affiché</label><input class="form-control" name="author" maxlength="255"></div>
  <div class="col-md-3"><label class="form-label">Date affichée</label><input class="form-control" name="published_label" maxlength="120" placeholder="ex. 3 h, 12 mars"></div>
  <div class="col-md-5"><label class="form-label">Image (facultatif, 3 Mo max.)</label><input class="form-control" type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif"></div>
  <div class="col-md-4"><label class="form-label">Légende de l'image</label><input class="form-control" name="caption" maxlength="2000"></div>
  <div class="col-md-3"><label class="form-label">Rattacher à une session</label><select class="form-select" name="session_id"><option value="">Aucune</option><?php foreach ($sessions as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><button class="btn btn-primary">Analyser</button></div>
</form>
<h2 class="h6">Dernières analyses manuelles</h2>
<table class="table table-sm small"><thead><tr><th>#</th><th>Date</th><th>Extrait</th><th>Statut</th><th>Priorité</th><th></th></tr></thead><tbody>
<?php foreach ($recent as $p): ?><tr><td><?= (int) $p['id'] ?></td><td><?= e(View::date($p['first_seen_at'])) ?></td><td><?= e($p['txt']) ?></td><td><?= e($p['status']) ?></td><td><?= $p['priority'] ? View::priorityBadge($p['priority']) : '—' ?></td>
<td><a href="<?= $p['alert_id'] ? 'alert.php?id=' . (int) $p['alert_id'] : 'alert.php?pub=' . (int) $p['id'] ?>">Fiche</a></td></tr><?php endforeach; ?>
</tbody></table>
<?php View::footer();
