<?php
/** Rendu Markdown minimal et sûr (titres, listes, code, gras) : tout le texte est échappé. */
$md = function (string $src): string {
    $out = ''; $inCode = false; $inList = false;
    foreach (preg_split('/\R/', $src) as $line) {
        if (str_starts_with($line, '```')) { $out .= $inCode ? '</pre>' : ($inList ? '</ul>' : '') . '<pre class="bg-light p-2 small">'; $inCode = !$inCode; $inList = false; continue; }
        if ($inCode) { $out .= e($line) . "\n"; continue; }
        $h = e($line);
        $h = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $h); $h = preg_replace('/`([^`]+)`/', '<code>$1</code>', $h);
        if (preg_match('/^(#{1,4}) (.*)$/', $h, $m)) { $out .= ($inList ? '</ul>' : '') . '<h' . (strlen($m[1]) + 2) . ' class="mt-3">' . $m[2] . '</h' . (strlen($m[1]) + 2) . '>'; $inList = false; continue; }
        if (preg_match('/^\s*[-*] (.*)$/', $h, $m) || preg_match('/^\s*\d+\. (.*)$/', $h, $m)) { $out .= ($inList ? '' : '<ul>') . '<li>' . $m[1] . '</li>'; $inList = true; continue; }
        if (trim($h) === '') { $out .= $inList ? '</ul>' : ''; $inList = false; continue; }
        $out .= ($inList ? '</ul>' : '') . '<p class="mb-2">' . $h . '</p>'; $inList = false;
    }
    return $out . ($inList ? '</ul>' : '') . ($inCode ? '</pre>' : '');
};
?>
<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<div class="row g-3"><div class="col-lg-3"><div class="list-group small">
<a class="list-group-item list-group-item-action <?= $content === null ? 'active' : '' ?>" href="<?= e(url('/fiscal/documentation')) ?>"><?= e(t('Pilotes disponibles')) ?></a>
<?php foreach ($files as $k => $l): ?><a class="list-group-item list-group-item-action <?= $doc === $k ? 'active' : '' ?>" href="<?= e(url('/fiscal/documentation', ['doc' => $k])) ?>"><?= e(t($l)) ?></a><?php endforeach; ?></div></div>
<div class="col-lg-9"><div class="card"><div class="card-body small">
<?php if ($content !== null): ?><?= $md($content) ?>
<?php else: ?><table class="table table-sm align-middle"><thead><tr><th><?= e(t('Pilote')) ?></th><th><?= e(t('Type')) ?></th><th><?= e(t('Liaisons')) ?></th><th><?= e(t('Vérifié')) ?></th><th><?= e(t('Fonctions')) ?></th></tr></thead><tbody>
<?php foreach ($drivers as $code => $d): ?><tr><td><b><?= e($d['label']) ?></b> <code><?= e($code) ?></code><div class="text-secondary"><?= e(t($d['notes'])) ?></div></td><td><?= e($d['kind']) ?></td><td><?= e(implode(', ', $d['transports'])) ?></td><td><?= $d['verified'] ? '<span class="text-success">' . e(t('oui')) . '</span>' : '<span class="text-danger">' . e(t('non')) . '</span>' ?></td><td><?= e(implode(', ', $d['capabilities']) ?: '—') ?></td></tr><?php endforeach; ?></tbody></table>
<p class="text-secondary mb-0"><?= e(t('« Vérifié » signifie que le protocole a été testé d\'après la documentation officielle du dispositif ou de l\'API. Aucun pilote livré ne l\'est : les pilotes génériques doivent être configurés et testés sur l\'environnement de test officiel avant toute mise en production.')) ?></p><?php endif; ?>
</div></div></div></div>
