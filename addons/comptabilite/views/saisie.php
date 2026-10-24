<?php
use Addons\Comptabilite\Compta;
use App\Core\Session;
require __DIR__ . '/_nav.php';
$editing = $e && $e['id'];
$old = Session::get('_old') ?: [];
$lines = $old['lignes'] ?? null;
if ($lines === null) {
    $lines = [];
    foreach ($e['lignes'] ?? [] as $l) {
        $lines[] = ['compte' => $l['numero'] . ' — ' . $l['compte_libelle'], 'tiers' => $l['tiers_code'] ? $l['tiers_code'] . ' — ' . $l['tiers_nom'] : '', 'libelle' => $l['libelle'],
            'debit' => (float) $l['debit'] ? Compta::num((float) $l['debit']) : '', 'credit' => (float) $l['credit'] ? Compta::num((float) $l['credit']) : '', 'echeance' => (string) $l['echeance']];
    }
}
while (count($lines) < 4) {
    $lines[] = ['compte' => '', 'tiers' => '', 'libelle' => '', 'debit' => '', 'credit' => '', 'echeance' => ''];
}
$h = fn(string $k, $default = '') => $old[$k] ?? ($e[$k] ?? $default);
$last = (int) App\Core\Request::query('derniere');
?>
<?php if ($last): ?><div class="alert alert-success py-2 small d-flex align-items-center gap-2"><i class="bi bi-check2-circle"></i> Écriture enregistrée.
  <a href="<?= e(url('/compta/ecritures/' . $last)) ?>">Voir l'écriture</a> · <a href="<?= e(url('/compta/saisie', ['copie' => $last])) ?>">Saisir une écriture semblable</a></div><?php endif; ?>
<?php if (!$ex || $ex['statut'] === 'cloture'): ?><div class="alert alert-warning">Aucun exercice ouvert ne couvre la date du jour : vérifiez la date de l'écriture ou créez l'exercice dans « Exercices ».</div><?php endif; ?>
<form method="post" action="<?= e(url($editing ? '/compta/ecritures/' . $e['id'] : '/compta/ecritures')) ?>" data-cpt-grid autocomplete="off">
  <?= csrf_field() ?>
  <div class="card mb-2"><div class="card-body py-2"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small mb-0">Journal</label>
      <select name="journal_id" class="form-select form-select-sm" required data-journal>
        <?php foreach ($journaux as $j): ?><option value="<?= (int) $j['id'] ?>" data-contrepartie="<?= e((string) $j['compte_numero']) ?>" <?= (int) $h('journal_id', $journalId) === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['code'] . ' — ' . $j['libelle']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label small mb-0">Date</label><input type="date" name="date_ecriture" class="form-control form-control-sm" required value="<?= e($h('date_ecriture', date('Y-m-d'))) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0">N° de pièce</label><input name="piece" class="form-control form-control-sm" maxlength="40" value="<?= e((string) $h('piece')) ?>" placeholder="Facture, reçu…"></div>
    <div class="col-md-5"><label class="form-label small mb-0">Libellé de l'écriture</label><input name="libelle" class="form-control form-control-sm" maxlength="190" required value="<?= e((string) $h('libelle')) ?>" data-head-label placeholder="Ex. Facture SONIDEP n° 125"></div>
  </div></div></div>
  <div class="card"><div class="table-responsive"><table class="table table-sm mb-0 cpt-grid align-middle">
    <thead><tr><th class="c-compte">Compte</th><th class="c-tiers">Tiers</th><th>Libellé de la ligne</th><th class="c-mt text-end">Débit</th><th class="c-mt text-end">Crédit</th><th class="c-ech">Échéance</th><th></th></tr></thead>
    <tbody data-rows>
    <?php foreach ($lines as $i => $l): ?>
      <tr data-row>
        <td><input name="lignes[<?= $i ?>][compte]" class="form-control" list="dl-comptes" value="<?= e((string) ($l['compte'] ?? '')) ?>" data-f="compte" placeholder="N° ou nom"></td>
        <td><input name="lignes[<?= $i ?>][tiers]" class="form-control" list="dl-tiers" value="<?= e((string) ($l['tiers'] ?? '')) ?>" data-f="tiers"></td>
        <td><input name="lignes[<?= $i ?>][libelle]" class="form-control" maxlength="190" value="<?= e((string) ($l['libelle'] ?? '')) ?>" data-f="libelle"></td>
        <td class="num"><input name="lignes[<?= $i ?>][debit]" class="form-control" inputmode="decimal" value="<?= e((string) ($l['debit'] ?? '')) ?>" data-f="debit"></td>
        <td class="num"><input name="lignes[<?= $i ?>][credit]" class="form-control" inputmode="decimal" value="<?= e((string) ($l['credit'] ?? '')) ?>" data-f="credit"></td>
        <td><input type="date" name="lignes[<?= $i ?>][echeance]" class="form-control" value="<?= e((string) ($l['echeance'] ?? '')) ?>" data-f="echeance"></td>
        <td><button type="button" class="btn btn-sm btn-outline-secondary" data-del title="Supprimer la ligne" tabindex="-1"><i class="bi bi-x"></i></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="cpt-balance d-flex flex-wrap align-items-center gap-3 p-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-add><i class="bi bi-plus"></i> Ligne</button>
      <button type="button" class="btn btn-sm btn-outline-primary" data-equilibrer title="Porte l'écart sur la ligne en cours, ou sur le compte de contrepartie du journal"><i class="bi bi-distribute-vertical"></i> Équilibrer</button>
      <span class="small">Débit <b data-td>0</b></span><span class="small">Crédit <b data-tc>0</b></span><span class="small">Écart <b data-diff class="ok">0</b></span>
      <span class="ms-auto d-flex gap-2">
        <?php if ($editing): ?><a href="<?= e(url('/compta/ecritures/' . $e['id'])) ?>" class="btn btn-sm btn-outline-secondary">Annuler</a><?php endif; ?>
        <button class="btn btn-sm btn-primary" name="action" value="brouillard"><i class="bi bi-save"></i> Enregistrer au brouillard</button>
        <?php if (can('compta.valider')): ?><button class="btn btn-sm btn-success" name="action" value="valider" data-need-balance><i class="bi bi-check2-all"></i> Enregistrer et valider</button><?php endif; ?>
      </span>
    </div>
  </div>
  <p class="small text-secondary mt-2 mb-0">Tapez le numéro ou le nom du compte puis choisissez dans la liste ; « Entrée » passe au champ suivant. Une écriture au brouillard reste modifiable ; validée, elle reçoit son numéro définitif et ne se modifie plus (une erreur se corrige par une extourne). Un compte collectif (clients, fournisseurs, personnel) demande le tiers.</p>
</form>
<datalist id="dl-comptes"><?php foreach ($comptes as $c): ?><option value="<?= e($c['numero'] . ' — ' . $c['libelle']) ?>" data-t="<?= e($c['tiers_type']) ?>"></option><?php endforeach; ?></datalist>
<datalist id="dl-tiers"><?php foreach ($tiers as $t): ?><option value="<?= e($t['code'] . ' — ' . $t['nom']) ?>" data-compte="<?= e($t['compte']) ?>"></option><?php endforeach; ?></datalist>
<script src="<?= e(asset('addons/comptabilite/compta.js')) ?>"></script>
