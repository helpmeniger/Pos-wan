<?php
use Addons\Vision\ParcoursService as P;

$t = $stats['totals'];
$roles = array_column($cameras, 'n', 'role');
$pc = fn($a, $b) => $b > 0 ? round(100 * $a / $b) . ' %' : '—';
$tabs = ['alerte' => 'À vérifier', 'en_cours' => 'En cours', 'resolus' => 'Clos automatiquement', 'verifies' => 'Vérifiés', 'tous' => 'Tous'];
$LV = ['priorite' => 'danger', 'verifier' => 'warning', 'faible' => 'secondary'];
?>
<?php if (empty($roles['etagere']) && empty($roles['caisse']) && empty($roles['sortie'])): ?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i> Aucune caméra n'a encore de rôle <b>Étagère</b>, <b>Caisse</b> ou <b>Sortie</b>. Dans <a href="<?= e(url('/vision/cameras')) ?>">Configuration → Caméras</a>, choisissez le rôle de chaque caméra, puis tracez les emplacements de l'étagère dans l'écran <a href="<?= e(url('/vision/surveillance')) ?>">Surveillance</a>.</div>
<?php endif; ?>

<div class="row g-2 mb-3">
  <?php foreach ([['Articles pris en rayon', $t['total'] ?? 0, ''], ['Encaissés', $t['paid'] ?? 0, 'text-success'], ['Remis en rayon', $t['back'] ?? 0, ''], ['En cours de suivi', $t['open'] ?? 0, 'text-primary'],
      ['Alertes émises', $t['alerts'] ?? 0, 'text-warning'], ['À vérifier', $t['to_check'] ?? 0, 'text-danger'], ['Pertes confirmées', $t['losses'] ?? 0, 'text-danger'],
      ['Fausses alertes (vérifiées)', $t['false_rate'] === null ? '—' : $t['false_rate'] . ' %', '']] as $k): ?>
    <div class="col-6 col-md-3 col-xl"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary"><?= e($k[0]) ?></div><div class="fs-5 fw-bold <?= $k[2] ?>"><?= e((string) $k[1]) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<form class="d-flex flex-wrap gap-2 align-items-end mb-2 small" method="get">
  <input type="hidden" name="show" value="<?= e($show) ?>">
  <div><label class="form-label mb-0">Du</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div><label class="form-label mb-0">au</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <button class="btn btn-sm btn-outline-primary">Afficher</button>
  <a class="btn btn-sm btn-outline-danger ms-auto" href="<?= e(url('/vision/surveillance')) ?>"><i class="bi bi-camera-video"></i> Surveillance en direct</a>
</form>
<ul class="nav nav-tabs small mb-0">
  <?php foreach ($tabs as $k => $l): ?><li class="nav-item"><a class="nav-link<?= $show === $k ? ' active' : '' ?>" href="<?= e(url('/vision/controles', ['show' => $k, 'from' => $from, 'to' => $to])) ?>"><?= e($l) ?><?= $k === 'alerte' && ($t['to_check'] ?? 0) ? ' <span class="badge bg-danger">' . (int) $t['to_check'] . '</span>' : '' ?></a></li><?php endforeach; ?>
</ul>
<div class="card border-top-0 mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0 ctl-table">
  <thead><tr><th>Article</th><th>Parcours observé</th><th>Résultat</th><th style="min-width:220px"></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $closed = !in_array($r['status'], ['alerte', 'en_cours', 'vu_caisse'], true) || $r['reviewed_at']; ?>
    <tr data-journey="<?= (int) $r['id'] ?>" class="<?= $r['status'] === 'alerte' && !$r['reviewed_at'] ? 'table-warning' : '' ?>">
      <td><div class="d-flex gap-2 align-items-center">
        <?php foreach (['taken_thumb', 'checkout_thumb', 'exit_thumb'] as $th): if ($r[$th]): ?><img class="ctl-thumb" src="<?= e($r[$th]) ?>" alt="" title="<?= e(['taken_thumb' => 'Au rayon', 'checkout_thumb' => 'En caisse', 'exit_thumb' => 'À la sortie'][$th]) ?>"><?php endif; endforeach; ?>
        <div><b><?= e($r['product'] ?? 'Article non identifié') ?></b>
          <div class="small text-secondary"><?= e(['emplacement' => 'nommé par l\'emplacement', 'reconnaissance' => 'reconnu par la caméra', 'aucun' => 'non identifié'][$r['identified_by']]) ?></div></div></div></td>
      <td class="small">
        <?php if ($r['origin'] === 'rayon'): ?><div><i class="bi bi-bookshelf text-warning"></i> <?= e(substr((string) $r['taken_at'], 11, 5)) ?> pris · <?= e(trim(($r['place'] ?: $r['camera']) . ($r['slot'] ? ' · ' . $r['slot'] : ''))) ?></div><?php endif; ?>
        <?php if ($r['checkout_at']): ?><div><i class="bi bi-cart-check text-success"></i> <?= e(substr((string) $r['checkout_at'], 11, 5)) ?> vu en caisse · <?= e((string) $r['checkout_camera']) ?></div><?php endif; ?>
        <?php if ($r['exit_at']): ?><div><i class="bi bi-door-open text-danger"></i> <?= e(substr((string) $r['exit_at'], 11, 5)) ?> vu à la sortie · <?= e((string) $r['exit_camera']) ?></div><?php endif; ?>
        <?php if ($r['sale_number']): ?><div><i class="bi bi-receipt"></i> vente <a href="<?= e(url('/sales/' . (int) $r['sale_id'])) ?>"><?= e($r['sale_number']) ?></a></div><?php endif; ?>
        <div class="text-secondary"><?= e(fdate($r['taken_at'], false)) ?></div>
      </td>
      <td class="small"><span data-status><?= e(P::STATUSES[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['level'] && $r['alerted_at']): ?><br><span class="badge bg-<?= $LV[$r['level']] ?>"><?= e(P::LEVELS[$r['level']]) ?></span> <span class="text-secondary"><?= e((string) $r['reason']) ?></span><?php elseif ($r['reason']): ?><br><span class="text-secondary"><?= e($r['reason']) ?></span><?php endif; ?>
        <?php if ($r['reviewed_at']): ?><br><span class="text-secondary">vérifié par <?= e((string) $r['reviewer']) ?> à <?= e(substr((string) $r['reviewed_at'], 11, 5)) ?><?= $r['note'] ? ' — « ' . e($r['note']) . ' »' : '' ?></span><?php endif; ?></td>
      <td class="text-end"><?php if (!$closed): ?><div class="ctl-actions justify-content-end">
        <button type="button" class="btn btn-sm btn-success" data-review="encaisse">Encaissé</button>
        <button type="button" class="btn btn-sm btn-secondary" data-review="remis">Remis</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-review="fausse">Fausse alerte</button>
        <button type="button" class="btn btn-sm btn-danger" data-review="perte">Perte</button></div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-secondary py-4"><?= $show === 'alerte' ? 'Aucune vérification demandée.' : 'Aucun suivi sur cette période.' ?></td></tr><?php endif; ?>
  </tbody></table></div></div>

<div class="card mb-3" id="rayons"><div class="card-header"><i class="bi bi-bookshelf"></i> État des rayons (articles vus à leur place)</div><div class="table-responsive"><table class="table table-sm small align-middle mb-0">
  <thead><tr><th>Emplacement</th><th>Article attendu</th><th style="min-width:160px">Vus à leur place</th><th>Seuil</th><th>Vu à</th><th></th></tr></thead><tbody>
  <?php foreach ($shelves as $sh): $u = max(1, (int) $sh['units']); $c = $sh['seen_count']; $low = $c !== null && $sh['min_count'] !== null && (int) $c <= (int) $sh['min_count'] && strtotime((string) $sh['seen_at']) >= time() - 86400; ?>
    <tr class="<?= $low ? 'table-warning' : '' ?>"><td><b><?= e($sh['label']) ?></b><br><span class="text-secondary"><?= e(trim($sh['camera'] . ($sh['place'] ? ' · ' . $sh['place'] : ''))) ?></span></td>
      <td><?= e((string) ($sh['product'] ?? '—')) ?></td>
      <td><?php if ($c === null): ?><span class="text-secondary">pas encore compté</span><?php else: ?>
        <div class="d-flex align-items-center gap-2"><div class="rep-bar flex-grow-1"><span style="width:<?= (int) round(100 * $c / $u) ?>%;background:<?= $low ? '#dc3545' : '#20c997' ?>"></span></div><b><?= (int) $c ?> / <?= $u ?></b></div><?php endif; ?></td>
      <td><?= $sh['min_count'] !== null ? 'alerte si ≤ ' . (int) $sh['min_count'] : '—' ?></td>
      <td class="text-secondary"><?= $sh['seen_at'] ? e(fdate($sh['seen_at'])) : '' ?></td>
      <td><?= $low ? '<span class="badge bg-warning text-dark">À réassortir</span>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$shelves): ?><tr><td colspan="6" class="text-center text-secondary py-3">Aucun emplacement tracé. Tracez les emplacements dans l'écran Surveillance et indiquez combien d'articles chacun contient quand il est plein.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-body small text-secondary border-top">Ce compte est celui des articles <b>visibles de face</b> à leur place, comparé à l'image « rayon plein » mémorisée. Les articles cachés derrière (profondeur du rayon) ne sont pas vus. Ce n'est pas le stock du logiciel : un rayon peut être vide alors que la réserve est pleine.</div></div>

<div class="card mb-3"><div class="card-header"><i class="bi bi-speedometer2"></i> Fiabilité par caméra (période affichée)</div><div class="table-responsive"><table class="table table-sm small mb-0">
  <thead><tr><th>Caméra</th><th class="text-end">Prises suivies</th><th class="text-end">Article nommé</th><th class="text-end">Encaissés</th><th class="text-end">Remis</th><th class="text-end">Alertes</th><th class="text-end">Fausses alertes</th><th class="text-end">Pertes</th><th class="text-end">Délai moyen jusqu'à la vente</th></tr></thead><tbody>
  <?php foreach ($stats['cameras'] as $c): ?>
    <tr><td><?= e($c['camera']) ?><?= $c['place'] ? ' <span class="text-secondary">· ' . e($c['place']) . '</span>' : '' ?></td>
      <td class="text-end"><?= (int) $c['total'] ?></td><td class="text-end"><?= $pc((int) $c['identified'], (int) $c['total']) ?><?= (int) $c['by_slot'] ? ' <span class="text-secondary">(' . (int) $c['by_slot'] . ' par emplacement)</span>' : '' ?></td>
      <td class="text-end"><?= (int) $c['paid'] ?></td><td class="text-end"><?= (int) $c['back'] ?></td><td class="text-end"><?= (int) $c['alerts'] ?><?= (int) $c['to_check'] ? ' <span class="badge bg-danger">' . (int) $c['to_check'] . '</span>' : '' ?></td>
      <td class="text-end"><?= (int) $c['reviewed'] ? $pc((int) $c['false_alerts'], (int) $c['reviewed']) : '—' ?></td><td class="text-end"><?= (int) $c['losses'] ?></td>
      <td class="text-end"><?= $c['avg_pay_s'] !== null ? (int) round($c['avg_pay_s'] / 60) . ' min' : '—' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$stats['cameras']): ?><tr><td colspan="9" class="text-center text-secondary py-3">Pas encore de données.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-body small text-secondary border-top">Une caméra avec beaucoup de fausses alertes est mal placée (reflets, passage de personnes devant le rayon) ou ses emplacements sont mal tracés. Un « délai moyen jusqu'à la vente » proche du délai réglé signifie que le délai est trop court : allongez-le dans Configuration → Caméras.</div></div>

<div class="alert alert-light border small mb-0">
  <i class="bi bi-shield-exclamation text-danger"></i> <b>Une alerte n'est pas une preuve de vol.</b> La détection et la reconnaissance sont automatiques et imparfaites : un article peut avoir été posé ailleurs, encaissé sous un autre article, mal reconnu, ou caché par une personne. Vérifiez avec discrétion (rayon, caisse, vignettes), sans accuser un client ni un employé, et enregistrez le résultat : il mesure la fiabilité de chaque caméra.
  Aucune vidéo n'est enregistrée ; seules des vignettes de l'article sont gardées, effacées après la durée de conservation. Les personnes filmées doivent être informées (affichage), conformément à la loi n° 2017-28 relative à la protection des données à caractère personnel.
</div>
