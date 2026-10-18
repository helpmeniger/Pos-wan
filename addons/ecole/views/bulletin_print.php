<?php use Addons\Ecole\Ecole; use Addons\Ecole\Notes;
$f = fn($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
$c = fn($v) => Addons\Ecole\Ecole::nb($v);
$s = $res['stat']; $logo = setting('company_logo', ''); $seuil = (float) setting('ecole_seuil_passage', '10');
$univ = (bool) array_filter($res['matieres'], fn($m) => $m['credits'] !== null);
$titre = $periode ? 'Bulletin de notes · ' . $periode['nom'] : 'Bulletin annuel'; ?>
<style>
.bul { font-size: 11.5px; page-break-after: always; break-after: page; }
.bul:last-child { page-break-after: auto; break-after: auto; }
.bul .entete { display: grid; grid-template-columns: 1fr auto 1fr; gap: 10px; align-items: center; border-bottom: 3px double #14213d; padding-bottom: 6px; }
.bul .entete img { max-height: 70px; max-width: 120px; }
.bul .ent-droite { text-align: right; font-size: 10.5px; }
.bul .titre { text-align: center; margin: 8px 0; font-size: 17px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; }
.bul .ident { display: grid; grid-template-columns: 1fr 1fr auto; gap: 4px 16px; border: 1px solid #999; border-radius: 6px; padding: 6px 10px; }
.bul .ident img { width: 70px; height: 85px; object-fit: cover; border: 1px solid #ccc; grid-row: span 3; }
.bul table.lines td, .bul table.lines th { padding: 4px 5px; }
.bul table.lines td { border: 1px solid #ccc; }
.bul .rec { display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 8px; margin-top: 8px; }
.bul .rec .box { min-height: 70px; }
.bul .big { font-size: 18px; font-weight: bold; }
.bul .rouge { color: #b00020; }
.bul .sign { margin-top: 18px; }
.bul .sign div { width: 45%; text-align: center; min-height: 70px; }
</style>
<?php foreach ($eleves as $eid => $r): $el = $r['eleve']; $b = $app[$el['inscription_id']] ?? []; $a = $abs[$eid] ?? ['absences' => 0, 'retards' => 0, 'excuses' => 0]; $dist = Notes::distinction($r['moyenne']); ?>
<div class="sheet bul">
  <div class="entete">
    <div><?php if ($logo): ?><img src="<?= e(upload_url($logo)) ?>" alt=""><br><?php endif; ?><b><?= e(Ecole::nom()) ?></b><br><?= e((string) setting('company_address', '')) ?><?= setting('company_phone') ? '<br>Tél. ' . e((string) setting('company_phone')) : '' ?></div>
    <div style="text-align:center"><b>RÉPUBLIQUE DU NIGER</b><br><span style="font-size:10px">Fraternité – Travail – Progrès</span><?= setting('ecole_devise') ? '<br><i>' . e((string) setting('ecole_devise')) . '</i>' : '' ?></div>
    <div class="ent-droite">Année scolaire<br><b style="font-size:14px"><?= e($annee['libelle']) ?></b></div>
  </div>
  <div class="titre"><?= e($titre) ?></div>
  <div class="ident">
    <div>Nom et prénom(s) : <b><?= e(mb_strtoupper($el['nom']) . ' ' . $el['prenom']) ?></b></div><div>Classe : <b><?= e($res['classe']['nom']) ?></b></div>
    <?php if ($el['photo']): ?><img src="<?= e(url('/ecole/photo/eleve/' . $el['id'])) ?>" alt=""><?php else: ?><span></span><?php endif; ?>
    <div>Matricule : <b><?= e($el['matricule']) ?></b> · Sexe : <?= e($el['sexe']) ?></div><div>Effectif : <b><?= (int) $s['effectif'] ?></b></div>
    <div><?= $el['date_naissance'] ? 'Né(e) le ' . e(fdate($el['date_naissance'])) . ($el['lieu_naissance'] ? ' à ' . e($el['lieu_naissance']) : '') : '' ?></div><div>Titulaire : <?= $titulaire ? e($titulaire['prenom'] . ' ' . $titulaire['nom']) : '—' ?></div>
  </div>
  <table class="lines"><thead><tr><th>Matière</th><th class="num">Coef.</th><th class="num">Moyenne /20</th><th class="num">Moy. × coef.</th><th class="num">Moy. classe</th><th class="num">Min – max</th><?php if ($univ): ?><th class="num">Crédits</th><?php endif; ?><th>Appréciation</th><th>Enseignant</th></tr></thead><tbody>
  <?php foreach ($res['matieres'] as $m): $v = $r['matieres'][(int) $m['matiere_id']]; $sm = $res['stat_matieres'][(int) $m['matiere_id']]; ?>
    <tr><td><b><?= e($m['nom']) ?></b></td><td class="num"><?= e($c($m['coefficient'])) ?></td><td class="num <?= $v !== null && $v < 10 ? 'rouge' : '' ?>"><b><?= $f($v) ?></b></td><td class="num"><?= $v === null ? '—' : $f($v * (float) $m['coefficient']) ?></td>
      <td class="num"><?= $f($sm['moy'] ?? null) ?></td><td class="num"><?= $sm ? $f($sm['min']) . ' – ' . $f($sm['max']) : '—' ?></td><?php if ($univ): ?><td class="num"><?= $m['credits'] === null ? '' : ($v !== null && $v >= 10 ? e($c($m['credits'])) : '0') . ' / ' . e($c($m['credits'])) ?></td><?php endif; ?>
      <td><?= $v === null ? 'Non évalué(e)' : e(Notes::appreciation($v)) ?></td><td><?= e((string) ($m['enseignant'] ?? '')) ?></td></tr>
  <?php endforeach; ?>
    <tr><td><b>TOTAL</b></td><td class="num"><b><?= e($c(number_format($r['total_coef'], 2, '.', ''))) ?></b></td><td></td><td class="num"><b><?= $f($r['points']) ?></b></td><td colspan="<?= $univ ? 5 : 4 ?>"><?= $univ ? 'Crédits acquis : <b>' . e($c(number_format($r['credits'], 2, '.', ''))) . '</b>' : '' ?></td></tr>
  </tbody></table>
  <div class="rec">
    <div class="box">Moyenne générale : <span class="big <?= $r['moyenne'] !== null && $r['moyenne'] < $seuil ? 'rouge' : '' ?>"><?= $f($r['moyenne']) ?></span> / 20<br>
      Rang : <b><?= $r['rang'] ? (int) $r['rang'] . ($r['rang'] === 1 ? 'er' : 'e') . ($r['ex_aequo'] ? ' ex æquo' : '') : '—' ?></b> sur <?= (int) $s['classes'] ?><br>
      Mention : <b><?= e(Notes::appreciation($r['moyenne'])) ?></b><?= $dist ? '<br>Distinction : <b>' . e($dist) . '</b>' : '' ?></div>
    <div class="box">Classe : moyenne <b><?= $f($s['moy']) ?></b><br>plus faible <?= $f($s['min']) ?> · plus forte <?= $f($s['max']) ?><br>Moyenne ≥ <?= e($c($seuil)) ?> : <?= (int) $s['admis'] ?> / <?= (int) $s['classes'] ?></div>
    <div class="box">Assiduité :<br>absences : <b><?= (int) $a['absences'] ?></b> jour(s)<?= $a['excuses'] ? ' · excusées : ' . (int) $a['excuses'] : '' ?><br>retards : <b><?= (int) $a['retards'] ?></b><?= !empty($b['conduite']) ? '<br>Conduite : <b>' . e($b['conduite']) . '</b>' : '' ?></div>
  </div>
  <div class="box" style="margin-top:8px">Appréciation du conseil de classe : <b><?= e((string) ($b['appreciation'] ?? '') ?: Notes::appreciation($r['moyenne'])) ?></b><?= !empty($b['decision']) ? '<br>Décision : <b>' . e($b['decision']) . '</b>' : '' ?></div>
  <div class="sign"><div>Le/la professeur(e) titulaire<br><?= $titulaire ? e($titulaire['prenom'] . ' ' . $titulaire['nom']) : '' ?></div><div><?= e((string) setting('ecole_signature', 'Le Directeur / La Directrice')) ?><br><span style="font-size:10px">Fait le <?= e(fdate(date('Y-m-d'))) ?></span></div></div>
  <div class="foot"><?= e(Ecole::nom()) ?></div>
</div>
<?php endforeach; ?>
