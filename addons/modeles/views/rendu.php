<?php
/**
 * Modèles de documents — rendu d'un document avec le modèle affecté (ou $modele pour l'aperçu).
 * Sans modèle (supprimé entre-temps), le document garde le modèle des Paramètres.
 */
use Addons\Modeles\Modeles;
use App\Core\View;
use App\Services\Documents;

$m = $modele ?? Modeles::forDocument((string) ($doc['kind'] ?? 'sale'));
if (!$m) {
    $isTicket = isset($doc['items']);
    echo View::partial($isTicket ? Documents::ticketTemplate((string) setting('ticket_template', '1')) : Documents::a4Template((string) setting('invoice_template', '1')), ['doc' => $doc]);
    return;
}
$doc = Modeles::apply($doc, $m);
$uid = 'mdl-' . (int) $m['id'] . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
$css = Modeles::layoutCss() . Modeles::css($m, '#' . $uid);
if ($m['type'] === 'a4' && $m['base'] === 'a5') {
    $css .= '@page{size:A5;margin:6mm}';
}
$fil = $m['type'] === 'a4' ? $m['options']['filigrane'] : '';
?>
<style><?= $css ?></style>
<div id="<?= e($uid) ?>" class="mdl mdl-<?= e($m['type']) ?>" <?= $fil !== '' ? 'data-filigrane="' . e($fil) . '"' : '' ?>><?= View::partial(Modeles::view($m), ['doc' => $doc]) ?></div>
