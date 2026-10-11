<?php use App\Support\Labels; ?>
<div class="page-header"><h1><i class="bi bi-question-circle"></i> Documentation et assistance</h1></div>
<div class="row g-4">
<div class="col-lg-8">
    <div class="card mb-3"><div class="card-header">Principes d'utilisation</div><div class="card-body small">
        <ul class="mb-0">
            <li>DOSSIER ID est un <strong>outil d'aide</strong> à la gestion administrative : toute décision reste humaine et doit s'appuyer sur des vérifications complémentaires (pièces d'identité, registres, hiérarchie).</li>
            <li>Une mention dans un dossier <strong>ne constitue jamais une preuve de culpabilité</strong>. Seule une décision judiciaire documentée et définitive établit une condamnation.</li>
            <li>Ne consultez que les dossiers nécessaires à votre mission : <strong>chaque consultation est journalisée</strong> et contrôlée.</li>
            <li>Ne saisissez que les informations nécessaires et autorisées. Les observations doivent rester factuelles.</li>
            <li>Verrouillez votre poste en cas d'absence ; la session expire automatiquement après inactivité.</li>
        </ul>
    </div></div>

    <div class="card mb-3"><div class="card-header">Retrouver une fiche et éviter les doublons</div><div class="card-body small">
        <ol class="mb-0">
            <li>Recherchez d'abord par numéro de dossier ou identifiant administratif, puis par nom et prénom.</li>
            <li>Cochez « orthographes approchantes » pour retrouver des graphies voisines (Moussa / Musa, Oumarou / Umaru…).</li>
            <li>À la création, le système signale les fiches dont les informations administratives sont proches. Vérifiez-les avant de créer une nouvelle fiche ; une justification est exigée.</li>
            <li>La page « Comparaison visuelle » affiche côte à côte les photographies et informations de deux fiches. <strong>La comparaison est faite par l'agent</strong> : le système n'analyse pas les visages.</li>
            <li>Un doublon confirmé est traité en archivant la fiche redondante avec le motif « doublon de … ».</li>
        </ol>
    </div></div>

    <div class="card mb-3"><div class="card-header">Statuts procéduraux</div><div class="card-body small">
        <table class="table table-sm mb-0"><tbody>
        <?php foreach (Labels::PROCEDURE_STATUS as $k => [$l, , $hint]): ?>
            <tr><td class="text-nowrap"><?= status_badge($k) ?></td><td><?= e($hint) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p class="mt-2 mb-0">Le statut « condamnée » exige la juridiction, la date et le dispositif de la décision, et la référence d'un document justificatif. Tout changement de statut est justifié et historisé.</p>
    </div></div>

    <div class="card mb-3"><div class="card-header">Rectification, archivage et restriction</div><div class="card-body small">
        <ul class="mb-0">
            <li><strong>Rectification</strong> : toute modification exige un motif, conservé dans le journal avec la liste des champs modifiés.</li>
            <li><strong>Archivage</strong> : une fiche archivée n'apparaît plus dans les consultations courantes et ne peut plus être modifiée.</li>
            <li><strong>Accès restreint</strong> : la fiche n'est visible que des comptes disposant de la permission correspondante.</li>
            <li>Les photographies retirées ne sont plus affichées mais restent conservées pour la traçabilité.</li>
        </ul>
    </div></div>
</div>
<div class="col-lg-4">
    <div class="card mb-3"><div class="card-header">Votre compte</div><div class="card-body small">
        <p>Rôle : <strong><?= e(\App\Core\Auth::user()['role_label'] ?? '') ?></strong></p>
        <p class="mb-1">Permissions :</p>
        <ul class="mb-0"><?php foreach (\App\Core\Auth::permissions() as $perm): ?><li><code><?= e($perm) ?></code></li><?php endforeach; ?></ul>
    </div></div>
    <div class="card"><div class="card-header">Assistance</div><div class="card-body small">
        <p>En cas de problème (compte verrouillé, erreur, suspicion d'accès non autorisé), contactez l'administrateur ou le responsable de contrôle de votre service.</p>
        <p class="mb-0">Documentation technique : dossier <code>docs/</code> de l'application (installation, sauvegarde, sécurité).</p>
    </div></div>
</div>
</div>
