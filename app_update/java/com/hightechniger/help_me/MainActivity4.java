package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.content.pm.ActivityInfo;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.view.WindowManager;
import android.widget.ExpandableListView;
import android.widget.MediaController;
import android.widget.TextView;
import android.widget.VideoView;

import androidx.appcompat.app.AppCompatActivity;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;

public class MainActivity4 extends AppCompatActivity {

    ExpandableListView expandableListView;
    List<String> listGroup;
    HashMap<String, List<String>> listItem;
    ExpandableListAdapter adapter;
    private VideoView videoView;
    private View cardView;

    HashMap<String, String> listContent;
    private TextView contentTextView;

    @SuppressLint("MissingInflatedId")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main4);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        // Initialisation des éléments de l'interface
        videoView = findViewById(R.id.videoView01);
        cardView = findViewById(R.id.cardView01);
        contentTextView = findViewById(R.id.contentTextView);
        expandableListView = findViewById(R.id.expandableListView);
        listGroup = new ArrayList<>();
        listItem = new HashMap<>();
        listContent = new HashMap<>();
        adapter = new ExpandableListAdapter(this, listGroup, listItem);

        expandableListView.setAdapter(adapter);
        initListData();

        // Gestion des clics sur les enfants de la liste
        expandableListView.setOnChildClickListener((parent, v, groupPosition, childPosition, id) -> {
            String selected = listItem.get(listGroup.get(groupPosition)).get(childPosition);

            // Vérifier si c'est une vidéo
            int resId = getVideoResourceId(selected);
            if (resId != 0) {
                playVideo(resId);
            } else {
                // Sinon, afficher le contenu textuel
                displayContent(selected);
            }
            return true;
        });
    }

    /**
     * Joue une vidéo dans le VideoView.
     */
    private void playVideo(int resId) {
        Uri videoUri = Uri.parse("android.resource://" + getPackageName() + "/" + resId);
        cardView.setVisibility(View.GONE);
        videoView.setVisibility(View.VISIBLE);
        MediaController mediaController = new MediaController(this);
        mediaController.setAnchorView(videoView);
        videoView.setMediaController(mediaController);
        videoView.setVideoURI(videoUri);

        // Passer en mode paysage
        setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE);

        videoView.start();

        // Listener pour revenir en portrait une fois terminé
        videoView.setOnCompletionListener(mp -> {
            videoView.setVisibility(View.GONE);
            cardView.setVisibility(View.VISIBLE);
            setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_PORTRAIT);
        });
    }


    private void displayContent(String selected) {
        String content = listContent.get(selected);
        if (content != null) {
            Intent intent = new Intent(MainActivity4.this, DetailActivity.class);
            intent.putExtra("title", selected);
            intent.putExtra("content", content);
            startActivity(intent);
        }
    }

    private int getVideoResourceId(String code) {
        switch (code) {
            case "Naviguer sur Internet":
                // Lance l'activité MainActivity17
                Intent intent6 = new Intent(MainActivity4.this, MainActivity17.class);
                startActivity(intent6);
                return 0; // Retourne 0 pour indiquer qu'il n'y a pas de ressource vidéo

            case "MANUEL":

                Intent intent7 = new Intent(MainActivity4.this, MainActivity6.class);
                startActivity(intent7);
                return 0;

            case "Démarrage":
                return R.raw.vid1;

            case "Utilisation de la souris":
                return R.raw.vid2;
            case "Manipulation d'une fenêtre":
                return R.raw.vid3;

            case "La barre des tâches":
                return R.raw.vid4;

            case "Créer et organiser":
                // Démonstration animée dessinée en code (remplace vid5.mp4, 17,4 Mo)
                startActivity(new Intent(MainActivity4.this, com.hightechniger.help_me.demo.DemoActivity.class)
                        .putExtra(com.hightechniger.help_me.demo.DemoActivity.EXTRA_DEMO, "creer_organiser"));
                return 0;

            case "Connexion à internet":
                return R.raw.vid6;

            case "Téléchargement, Site web, Youtube":
                return R.raw.telecharvid9;

            case "La messagerie":
                return R.raw.emailvid;

            case "Combinaisons clavier":
                return R.raw.combinaisonvid;
            case "Insérer des symboles":
                return R.raw.caracterevid;

            default:
                return 0; // Par défaut, retourne 0 si aucune correspondance
        }
    }

    private void initListData() {
        listGroup.add("  ORDINATEUR");
        listGroup.add("  INTERNET");
        listGroup.add("  ASTUCES");
        listGroup.add("  REVISION");

        List<String> ordinateur = new ArrayList<>();
        ordinateur.add("Démarrage");
        ordinateur.add("Utilisation de la souris");
        ordinateur.add("Manipulation d'une fenêtre");
        ordinateur.add("La barre des tâches");
        ordinateur.add("Créer et organiser");
        ordinateur.add("Connexion à internet");
        ordinateur.add("Téléchargement, Site web, Youtube");
        ordinateur.add("La messagerie");
        ordinateur.add("Combinaisons clavier");
        ordinateur.add("Insérer des symboles");

        List<String> internet = new ArrayList<>();
        internet.add("Naviguer sur Internet");

        List<String> astuces = new ArrayList<>();
        astuces.add("10 astuces pour Windows rapide");
        astuces.add("Résolvez les problèmes courants de Windows");
        astuces.add("Guide facile pour mettre à jour Windows");
        astuces.add("Boostez les performances de votre PC");
        astuces.add("Raccourcis clavier indispensables sur Windows");
        astuces.add("Protégez votre PC en 3 étapes");
        astuces.add("Organisez vos fichiers rapidement");
        astuces.add("Réparez un PC lent ou bloqué");
        astuces.add("Prolongez la vie de votre ordinateur");
        astuces.add("Rendez Windows plus fluide");
        astuces.add("Supprimez les fichiers inutiles");
        astuces.add("Tout sur Windows Update");
        astuces.add("Sauvegardez vos données facilement");
        astuces.add("Résolvez les erreurs Windows vite");
        astuces.add("Automatisez vos tâches sous Windows");
        astuces.add("Boostez la sécurité de votre réseau");
        astuces.add("Personnalisez votre bureau Windows");
        astuces.add("10 erreurs à éviter sur un ordinateur");


        List<String> revision = new ArrayList<>();
        revision.add("MANUEL");

        listItem.put(listGroup.get(0), ordinateur);
        listItem.put(listGroup.get(1), internet);
        listItem.put(listGroup.get(2), astuces);
        listItem.put(listGroup.get(3), revision);

        // Contenus textuels
        listContent.put("10 astuces pour Windows rapide",
                "<p><b>1. Désactivez les programmes au démarrage</b><br>"
                        + "Pourquoi ? De nombreux programmes inutiles se lancent automatiquement au démarrage de Windows, ce qui ralentit le PC.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Ctrl + Shift + Esc</b> pour ouvrir le Gestionnaire des tâches.</li><br>"
                        + "<li>Allez dans l'onglet <b>Démarrage</b>.</li><br>"
                        + "<li>Désactivez les programmes inutiles en cliquant avec le bouton droit sur l'application, puis sélectionnez <b>Désactiver</b>.</li>"
                        + "</ul><br>"
                        + "<p><b>2. Nettoyez le disque dur (Fichiers temporaires et inutiles)</b><br>"
                        + "Pourquoi ? Les fichiers temporaires, le cache et les fichiers inutiles encombrent votre disque dur.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Win + R</b>, tapez <b>cleanmgr</b> et appuyez sur <b>Entrée</b>.</li><br>"
                        + "<li>Sélectionnez le disque à nettoyer (généralement <b>C:</b>), puis cliquez sur <b>OK</b>.</li><br>"
                        + "<li>Cochez les fichiers que vous souhaitez supprimer et cliquez sur <b>Nettoyer</b>.</li>"
                        + "</ul><br>"
                        + "<p><b>3. Désactivez les effets visuels inutiles</b><br>"
                        + "Pourquoi ? Les animations et effets visuels consomment des ressources.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Win + R</b>, tapez <b>sysdm.cpl</b> et appuyez sur <b>Entrée</b>.</li><br>"
                        + "<li>Allez dans l'onglet <b>Avancé</b>, cliquez sur <b>Paramètres</b> dans la section <b>Performances</b>.</li><br>"
                        + "<li>Sélectionnez <b>Ajuster afin d’obtenir les meilleures performances</b>, ou désactivez uniquement les animations inutiles.</li>"
                        + "</ul><br>"
                        + "<p><b>4. Augmentez la mémoire virtuelle (Fichier d’échange)</b><br>"
                        + "Pourquoi ? Si votre RAM est insuffisante, une mémoire virtuelle plus grande peut aider.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Win + R</b>, tapez <b>sysdm.cpl</b> et appuyez sur <b>Entrée</b>.</li><br>"
                        + "<li>Allez dans l'onglet <b>Avancé</b> > <b>Paramètres</b> (Performances) > <b>Avancé</b> > <b>Modifier</b> (Mémoire virtuelle).</li><br>"
                        + "<li>Décochez <b>Gestion automatique</b>, sélectionnez votre disque, et choisissez une taille personnalisée (par ex., 1,5 fois la RAM installée).</li>"
                        + "</ul><br>"
                        + "<p><b>5. Mettez à jour Windows et les pilotes</b><br>"
                        + "Pourquoi ? Les mises à jour corrigent des bugs et optimisent les performances.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Allez dans <b>Paramètres</b> > <b>Mise à jour et sécurité</b> > <b>Windows Update</b>, puis cliquez sur <b>Rechercher des mises à jour</b>.</li><br>"
                        + "<li>Pour les pilotes, utilisez un outil comme <b>Driver Booster</b> ou allez sur le site du fabricant.</li>"
                        + "</ul><br>"
                        + "<p><b>6. Désinstallez les programmes inutilisés</b><br>"
                        + "Pourquoi ? Les logiciels inutilisés consomment de l’espace disque et parfois des ressources.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Win + R</b>, tapez <b>appwiz.cpl</b> et appuyez sur <b>Entrée</b>.</li><br>"
                        + "<li>Parcourez la liste des programmes, sélectionnez ceux dont vous n’avez plus besoin, puis cliquez sur <b>Désinstaller</b>.</li>"
                        + "</ul><br>"
                        + "<p><b>7. Désactivez les services inutiles</b><br>"
                        + "Pourquoi ? Certains services de Windows tournent en arrière-plan sans être nécessaires.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Win + R</b>, tapez <b>services.msc</b> et appuyez sur <b>Entrée</b>.</li><br>"
                        + "<li>Recherchez des services comme <b>Bluetooth Support</b> ou <b>Fax</b> (si vous ne les utilisez pas).</li><br>"
                        + "<li>Faites un clic droit, puis sélectionnez <b>Arrêter</b> ou changez le type de démarrage en <b>Désactivé</b>.</li>"
                        + "</ul><br>"
                        + "<p><b>8. Utilisez un disque SSD</b><br>"
                        + "Pourquoi ? Les SSD (Solid State Drives) sont beaucoup plus rapides que les disques durs traditionnels (HDD).<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Si vous utilisez un HDD, envisagez de migrer votre système d’exploitation sur un SSD.</li><br>"
                        + "<li>Clonez votre disque actuel à l’aide d’un outil comme <b>Macrium Reflect</b> ou <b>EaseUS Todo Backup</b>.</li>"
                        + "</ul><br>"
                        + "<p><b>9. Analysez et supprimez les malwares</b><br>"
                        + "Pourquoi ? Les virus et malwares ralentissent le système.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Téléchargez un outil comme <b>Malwarebytes</b> ou utilisez l’outil intégré de Windows :</li><br>"
                        + "<li>Allez dans <b>Paramètres</b> > <b>Mise à jour et sécurité</b> > <b>Sécurité Windows</b> > <b>Protection contre les virus et menaces</b>.</li><br>"
                        + "<li>Lancez un <b>Analyse complète</b>.</li>"
                        + "</ul><br>"
                        + "<p><b>10. Défragmentez (pour HDD) ou optimisez (pour SSD) vos disques</b><br>"
                        + "Pourquoi ? La défragmentation réorganise les fichiers pour qu’ils soient plus accessibles (uniquement pour HDD). Les SSD doivent être optimisés.<br>"
                        + "Comment faire ?</p><br>"
                        + "<ul>"
                        + "<li>Tapez <b>Défragmenter</b> dans la barre de recherche Windows.</li><br>"
                        + "<li>Sélectionnez votre disque, puis cliquez sur <b>Optimiser</b>.</li>"
                        + "</ul>");



        listContent.put("Résolvez les problèmes courants de Windows",
                "<h2>1. Windows est lent à démarrer :</h2>"
                        + "<p><b>Cause :</b> Trop de programmes se lancent au démarrage.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Ctrl + Shift + Esc</b> pour ouvrir le Gestionnaire des tâches.</li>"
                        + "<li>Allez dans l'onglet <b>Démarrage</b>.</li>"
                        + "<li>Désactivez les programmes inutiles en cliquant avec le bouton droit sur l'application, puis sélectionnez <b>Désactiver</b>.</li>"
                        + "</ul><br>"
                        + "<h2>2. Écran bleu de la mort (BSOD) :</h2>"
                        + "<p><b>Cause :</b> Pilotes corrompus, incompatibilités matérielles ou logiciels défectueux.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Notez le code d’erreur affiché sur l’écran bleu.</li>"
                        + "<li>Redémarrez le PC en Mode sans échec (appuyez sur F8 ou maintenez Shift pendant le démarrage).</li>"
                        + "<li>Mettez à jour vos pilotes via le Gestionnaire de périphériques.</li>"
                        + "<li>Utilisez Windows Update pour corriger les bugs système.</li>"
                        + "</ul><br>"
                        + "<h2>3. Windows ne détecte pas le réseau Wi-Fi :</h2>"
                        + "<p><b>Cause :</b> Problèmes de pilote ou configuration incorrecte.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Vérifiez que le Mode Avion est désactivé.</li>"
                        + "<li>Appuyez sur <b>Windows + R</b>, tapez <b>devmgmt.msc</b> et vérifiez si le pilote Wi-Fi a un triangle jaune.</li>"
                        + "<li>Si oui, cliquez droit sur le pilote > Mettre à jour le pilote ou désinstallez-le, puis redémarrez votre PC.</li>"
                        + "</ul><br>"
                        + "<h2>4. Logiciel qui ne répond pas :</h2>"
                        + "<p><b>Cause :</b> Trop de ressources utilisées ou bugs du logiciel.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Ctrl + Shift + Esc</b> pour ouvrir le Gestionnaire des tâches.</li>"
                        + "<li>Sélectionnez le programme concerné, puis cliquez sur <b>Fin de tâche</b>.</li>"
                        + "<li>Redémarrez le logiciel ou réinstallez-le si le problème persiste.</li>"
                        + "</ul><br>"
                        + "<h2>5. Pas de son sur le PC :</h2>"
                        + "<p><b>Cause :</b> Pilote audio corrompu, périphérique mal configuré ou haut-parleurs désactivés.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Clic droit sur l’icône de son dans la barre des tâches > <b>Résoudre les problèmes de son</b>.</li>"
                        + "<li>Vérifiez que le bon périphérique de sortie est sélectionné.</li>"
                        + "<li>Allez dans Gestionnaire de périphériques, développez <b>Entrées et sorties audio</b>, et mettez à jour ou réinstallez le pilote audio.</li>"
                        + "</ul><br>"
                        + "<h2>6. Windows ne démarre pas :</h2>"
                        + "<p><b>Cause :</b> Problème de disque ou de système d'exploitation.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Insérez une clé USB bootable de Windows et démarrez dessus.</li>"
                        + "<li>Cliquez sur <b>Réparer l'ordinateur</b> > <b>Options avancées</b> > <b>Réparation du démarrage</b>.</li>"
                        + "<li>Si cela échoue, ouvrez l’invite de commande et tapez <b>chkdsk /f /r</b> pour corriger les erreurs du disque.</li>"
                        + "</ul><br>"
                        + "<h2>7. Le PC surchauffe :</h2>"
                        + "<p><b>Cause :</b> Accumulation de poussière ou surutilisation des ressources.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Nettoyez les ventilateurs avec une bombe d’air comprimé.</li>"
                        + "<li>Vérifiez les processus gourmands avec <b>Ctrl + Shift + Esc</b>.</li>"
                        + "<li>Installez un logiciel comme <b>HWMonitor</b> pour surveiller la température.</li>"
                        + "<li>Assurez-vous que votre PC est bien ventilé.</li>"
                        + "</ul><br>"
                        + "<h2>8. Fichiers ou dossiers manquants :</h2>"
                        + "<p><b>Cause :</b> Suppression accidentelle ou corruption du système.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Vérifiez dans la <b>Corbeille</b>.</li>"
                        + "<li>Utilisez la fonction de <b>Restauration des versions précédentes</b>.</li>"
                        + "<li>Si nécessaire, utilisez un logiciel de récupération comme <b>Recuva</b>.</li>"
                        + "</ul><br>"
                        + "<h2>9. Application ou mise à jour bloquée :</h2>"
                        + "<p><b>Cause :</b> Conflits logiciels ou services Windows désactivés.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Ouvrez <b>Services</b> (appuyez sur <b>Windows + R</b>, tapez <b>services.msc</b>).</li>"
                        + "<li>Redémarrez le service <b>Windows Update</b>.</li>"
                        + "<li>Téléchargez l’outil de <b>résolution de problèmes de Windows Update</b> disponible sur le site de Microsoft.</li>"
                        + "</ul><br>"
                        + "<h2>10. Problème d’espace disque insuffisant :</h2>"
                        + "<p><b>Cause :</b> Accumulation de fichiers temporaires ou inutiles.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Appuyez sur <b>Windows + R</b>, tapez <b>cleanmgr</b>, et sélectionnez le disque à nettoyer.</li>"
                        + "<li>Supprimez les fichiers temporaires, la corbeille et les journaux système inutiles.</li>"
                        + "<li>Désinstallez les programmes inutilisés dans <b>Panneau de configuration > Programmes et fonctionnalités</b>.</li>"
                        + "</ul><br>"
                        + "<h2>11. Clavier ou souris qui ne fonctionne pas :</h2>"
                        + "<p><b>Cause :</b> Pilote manquant ou port défectueux.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Testez l'appareil sur un autre port USB ou un autre PC.</li>"
                        + "<li>Redémarrez le PC et, si cela persiste, mettez à jour le pilote via le Gestionnaire de périphériques.</li>"
                        + "</ul><br>"
                        + "<h2>12. Navigateur lent ou rempli de publicités :</h2>"
                        + "<p><b>Cause :</b> Trop d'extensions ou malware.</p>"
                        + "<p><b>Solution :</b></p>"
                        + "<ul>"
                        + "<li>Désinstallez les extensions inutiles dans les paramètres de votre navigateur.</li>"
                        + "<li>Effacez les données de navigation (cache et cookies).</li>"
                        + "<li>Exécutez un scan antivirus pour détecter les logiciels malveillants.</li>"
                        + "</ul>");

        listContent.put("Guide facile pour mettre à jour Windows",
                "1. <b>Vérifiez la version de Windows</b><br>" +
                        "• Avant de commencer, assurez-vous que vous utilisez une version de Windows compatible avec les mises à jour. Pour vérifier :<br>" +
                        "   1. Cliquez sur le bouton Démarrer.<br>" +
                        "   2. Tapez <i>winver</i> dans la barre de recherche et appuyez sur Entrée.<br>" +
                        "   3. Une fenêtre apparaîtra avec votre version de Windows.<br><br>" +

                        "2. <b>Accédez aux paramètres de mise à jour</b><br>" +
                        "   1. Cliquez sur le bouton Démarrer.<br>" +
                        "   2. Sélectionnez <i>Paramètres</i>.<br>" +
                        "   3. Dans le menu, cliquez sur <i>Mise à jour et sécurité</i>.<br>" +
                        "   4. Dans le menu de gauche, sélectionnez <i>Windows Update</i>.<br><br>" +

                        "3. <b>Vérifiez les mises à jour disponibles</b><br>" +
                        "• Sous <i>Windows Update</i>, cliquez sur le bouton <b>Rechercher des mises à jour</b>.<br>" +
                        "• Windows commencera à chercher les dernières mises à jour disponibles pour votre PC.<br>" +
                        "• Si des mises à jour sont trouvées, elles seront affichées.<br><br>" +

                        "4. <b>Téléchargez et installez les mises à jour</b><br>" +
                        "• Si des mises à jour sont disponibles, cliquez sur <b>Télécharger</b>.<br>" +
                        "• Une fois le téléchargement terminé, les mises à jour seront installées automatiquement.<br>" +
                        "• Vous devrez peut-être redémarrer votre PC pour appliquer certaines mises à jour.<br><br>" +

                        "5. <b>Vérifiez régulièrement les mises à jour</b><br>" +
                        "• Pour garantir que votre système reste sécurisé et à jour, vérifiez régulièrement les mises à jour :<br>" +
                        "   1. Retournez dans <i>Mise à jour et sécurité</i>.<br>" +
                        "   2. Cliquez sur <b>Vérifier les mises à jour</b> une fois par mois, ou activez les mises à jour automatiques pour ne rien manquer.<br><br>" +

                        "6. <b>Résolution des problèmes de mise à jour</b><br>" +
                        "• Si une mise à jour échoue ou si vous rencontrez un problème, essayez ces solutions :<br>" +
                        "   1. Redémarrez votre PC et réessayez.<br>" +
                        "   2. Utilisez l'outil de dépannage des mises à jour de Windows :<br>" +
                        "       - Allez dans <i>Paramètres > Mise à jour et sécurité > Dépannage</i>.<br>" +
                        "       - Sélectionnez <i>Windows Update</i> et suivez les instructions.<br><br>" +

                        "7. <b>Mise à jour manuelle (si nécessaire)</b><br>" +
                        "• Si certaines mises à jour ne se téléchargent pas automatiquement, vous pouvez les installer manuellement via le Catalogue Microsoft Update :<br>" +
                        "   1. Visitez <i>Catalogue Microsoft Update</i>.<br>" +
                        "   2. Recherchez la mise à jour par son numéro (par exemple, \"KB5006670\").<br>" +
                        "   3. Téléchargez et installez la mise à jour manuellement.<br>"
        );


        listContent.put("Boostez les performances de votre PC",
                "1. <b>Nettoyez votre disque dur :</b><br>" +
                        "Supprimez les fichiers temporaires et inutiles en utilisant l'outil intégré Nettoyage de disque :<br>" +
                        "Ouvrez le menu Démarrer et tapez \"Nettoyage de disque\".<br>" +
                        "Sélectionnez le lecteur à nettoyer (généralement C:) et cliquez sur OK.<br>" +
                        "Cochez les fichiers à supprimer (fichiers temporaires, corbeille, etc.) et validez.<br><br>" +

                        "Désinstallez les programmes inutilisés :<br>" +
                        "Allez dans Paramètres > Applications > Applications et fonctionnalités.<br>" +
                        "Désinstallez les programmes que vous n’utilisez plus.<br><br>" +

                        "2. <b>Désactivez les applications au démarrage :</b><br>" +
                        "Certaines applications se lancent automatiquement et ralentissent le démarrage :<br>" +
                        "Cliquez avec le bouton droit sur la barre des tâches et ouvrez Gestionnaire des tâches.<br>" +
                        "Allez dans l’onglet Démarrage.<br>" +
                        "Désactivez les applications non essentielles en cliquant sur Désactiver.<br><br>" +

                        "3. <b>Augmentez la mémoire virtuelle :</b><br>" +
                        "La mémoire virtuelle agit comme un support supplémentaire à la RAM :<br>" +
                        "Ouvrez Paramètres > Système > Informations système.<br>" +
                        "Cliquez sur Paramètres système avancés.<br>" +
                        "Dans l’onglet Avancé, sous Performance, cliquez sur Paramètres.<br>" +
                        "Allez dans l’onglet Avancé et cliquez sur Modifier sous Mémoire virtuelle.<br>" +
                        "Décochez Gérer automatiquement et définissez une taille personnalisée (par exemple, 1,5 fois la taille de votre RAM).<br><br>" +

                        "4. <b>Désactivez les effets visuels inutiles :</b><br>" +
                        "Pour privilégier la performance à l’esthétique :<br>" +
                        "Allez dans Paramètres système avancés comme expliqué ci-dessus.<br>" +
                        "Cliquez sur Paramètres dans la section Performance.<br>" +
                        "Sélectionnez Ajuster afin d'obtenir les meilleures performances ou personnalisez les options.<br><br>" +

                        "5. <b>Mettez à jour Windows et vos pilotes :</b><br>" +
                        "Un système à jour fonctionne mieux :<br>" +
                        "Allez dans Paramètres > Mise à jour et sécurité.<br>" +
                        "Cliquez sur Rechercher des mises à jour et installez-les.<br>" +
                        "Mettez également à jour vos pilotes via le Gestionnaire de périphériques.<br><br>" +

                        "6. <b>Supprimez les logiciels malveillants :</b><br>" +
                        "Scannez votre PC avec un antivirus :<br>" +
                        "Utilisez l'outil intégré Sécurité Windows ou un antivirus tiers.<br>" +
                        "Effectuez un scan complet pour éliminer les logiciels malveillants.<br><br>" +

                        "7. <b>Ajoutez de la RAM ou remplacez le disque dur par un SSD :</b><br>" +
                        "Si votre PC est encore lent malgré tout, envisagez d'ajouter de la RAM ou de remplacer votre disque dur classique par un SSD, beaucoup plus rapide.<br><br>" +

                        "8. <b>Gérez les ressources avec le Gestionnaire des tâches :</b><br>" +
                        "Surveillez les applications qui consomment trop de CPU, de RAM ou de disque :<br>" +
                        "Ouvrez le Gestionnaire des tâches (Ctrl + Maj + Échap).<br>" +
                        "Identifiez les processus gourmands et fermez-les si nécessaire.<br><br>" +

                        "9. <b>Désactivez les services inutiles :</b><br>" +
                        "Certains services fonctionnent en arrière-plan sans être indispensables :<br>" +
                        "Tapez services.msc dans le menu Démarrer.<br>" +
                        "Désactivez les services non essentiels (attention à ne pas désactiver les services critiques).<br><br>" +

                        "10. <b>Activez le mode \"Performances élevées\" :</b><br>" +
                        "Ce mode optimise les ressources pour de meilleures performances :<br>" +
                        "Allez dans Paramètres > Système > Alimentation et mise en veille.<br>" +
                        "Cliquez sur Paramètres d’alimentation supplémentaires.<br>" +
                        "Choisissez le mode Performances élevées.<br>"
        );

        listContent.put("Raccourcis clavier indispensables sur Windows",
                "<b>Raccourcis généraux</b><br>" +
                        "Ctrl + C : Copier<br>" +
                        "Ctrl + X : Couper<br>" +
                        "Ctrl + V : Coller<br>" +
                        "Ctrl + Z : Annuler<br>" +
                        "Ctrl + Y : Rétablir<br>" +
                        "Ctrl + A : Sélectionner tout<br>" +
                        "Ctrl + S : Enregistrer<br>" +
                        "Ctrl + P : Imprimer<br><br>" +

                        "<b>Navigation et gestion des fenêtres</b><br>" +
                        "Alt + Tab : Passer d'une application ouverte à une autre<br>" +
                        "Alt + F4 : Fermer la fenêtre active<br>" +
                        "Windows + D : Afficher ou masquer le bureau<br>" +
                        "Windows + E : Ouvrir l’Explorateur de fichiers<br>" +
                        "Windows + Tab : Afficher les bureaux virtuels<br>" +
                        "Windows + Flèche (Gauche/Droite) : Ancrer une fenêtre à gauche ou à droite<br>" +
                        "Windows + Flèche (Haut/Bas) : Agrandir ou réduire une fenêtre<br><br>" +

                        "<b>Gestion rapide</b><br>" +
                        "Ctrl + Shift + Esc : Ouvrir le Gestionnaire des tâches<br>" +
                        "Windows + R : Ouvrir la boîte de dialogue Exécuter<br>" +
                        "Windows + L : Verrouiller la session<br>" +
                        "Windows + Pause : Ouvrir les informations système<br>" +
                        "Alt + Espace : Accéder au menu système de la fenêtre active<br><br>" +

                        "<b>Navigation sur le texte</b><br>" +
                        "Ctrl + Flèche (Gauche/Droite) : Déplacer le curseur mot par mot<br>" +
                        "Ctrl + Shift + Flèche (Gauche/Droite) : Sélectionner des mots entiers<br>" +
                        "Shift + Flèche : Sélectionner du texte caractère par caractère<br>" +
                        "Ctrl + Backspace : Supprimer un mot entier à gauche<br>" +
                        "Ctrl + Delete : Supprimer un mot entier à droite<br><br>" +

                        "<b>Recherche et gestion</b><br>" +
                        "Ctrl + F : Rechercher dans un document ou une page web<br>" +
                        "Ctrl + N : Ouvrir une nouvelle fenêtre ou un nouvel onglet<br>" +
                        "Ctrl + T : Ouvrir un nouvel onglet dans un navigateur<br>" +
                        "Ctrl + W : Fermer un onglet ou une fenêtre<br><br>" +

                        "<b>Spécifiques à Windows 10/11</b><br>" +
                        "Windows + I : Ouvrir les paramètres<br>" +
                        "Windows + S : Ouvrir la recherche<br>" +
                        "Windows + G : Ouvrir la barre de jeu (Xbox Game Bar)<br>" +
                        "Windows + P : Projeter sur un autre écran<br>" +
                        "Windows + H : Activer la dictée vocale<br><br>" +

                        "<b>Combinaisons avancées</b><br>" +
                        "Ctrl + Shift + N : Créer un nouveau dossier<br>" +
                        "Ctrl + Shift + T : Rouvrir l’onglet récemment fermé dans un navigateur<br>" +
                        "Ctrl + Alt + Delete : Accéder au menu de sécurité (verrouillage, gestionnaire des tâches, etc.)<br>" +
                        "Alt + Enter : Ouvrir les propriétés de l’élément sélectionné<br>" +
                        "Ctrl + Shift + Esc : Accéder directement au Gestionnaire des tâches<br><br>" +

                        "<b>Personnalisation</b><br>" +
                        "Windows + (+/-) : Activer/désactiver le zoom avec la loupe<br>" +
                        "Windows + PrtScn : Capturer l'écran entier et enregistrer dans 'Images'<br>" +
                        "Alt + PrtScn : Capturer uniquement la fenêtre active<br>"
        );

        listContent.put("Protégez votre PC en 3 étapes",
                "<b>1. Installez un logiciel antivirus fiable</b><br>" +
                        "Un bon antivirus est essentiel pour protéger votre PC contre les malwares, virus et autres menaces. Choisissez un antivirus avec une protection en temps réel et des mises à jour automatiques pour garantir que votre ordinateur reste protégé contre les nouvelles menaces. Assurez-vous qu'il effectue régulièrement des analyses complètes de votre système.<br><br>" +
                        "<b>2. Activez un pare-feu (Firewall)</b><br>" +
                        "Le pare-feu est un outil de sécurité qui surveille et contrôle les connexions réseau entrant et sortant de votre ordinateur. Assurez-vous que le pare-feu Windows est activé ou installez un pare-feu tiers pour avoir un contrôle plus précis sur les connexions suspectes ou non autorisées.<br><br>" +
                        "<b>3. Mettez à jour régulièrement votre système et vos logiciels</b><br>" +
                        "Les mises à jour de sécurité corrigent les vulnérabilités de votre système et des applications. Activez les mises à jour automatiques pour Windows et les autres logiciels importants (navigateur, suite bureautique, etc.). Cela garantit que vous bénéficiez des derniers correctifs de sécurité, vous protégeant ainsi contre les exploits de vulnérabilités connues.<br><br>"
        );
        listContent.put("Organisez vos fichiers rapidement",
                "<b>Créez des dossiers structurés :</b> Commencez par organiser vos fichiers en créant des dossiers. Par exemple, créez un dossier principal pour un projet ou un type de fichier, puis des sous-dossiers pour chaque catégorie (ex : Photos, Documents, Projets, Factures, etc.).<br>" +
                        "<b>Utilisez des noms de fichiers explicites :</b> Donnez des noms de fichiers clairs et descriptifs pour faciliter la recherche. Évitez les noms génériques comme \"Document1\", et optez pour des noms incluant des mots-clés, des dates ou des numéros de version (ex : \"Facture_2025_01.pdf\").<br>" +
                        "<b>Trier automatiquement avec des règles :</b> Dans l'Explorateur de fichiers, vous pouvez trier vos fichiers par nom, date, taille, type, etc. Faites un clic droit dans un dossier, puis sélectionnez \"Trier par\" pour organiser les fichiers en un clin d'œil.<br>" +
                        "<b>Utilisez des raccourcis :</b> Si vous avez des fichiers ou dossiers fréquemment utilisés, créez des raccourcis sur votre bureau ou dans vos dossiers principaux pour un accès rapide. Faites un clic droit sur un fichier ou dossier, puis sélectionnez \"Créer un raccourci\".<br>" +
                        "<b>Recherche et filtres :</b> Utilisez la barre de recherche dans l'Explorateur de fichiers pour retrouver rapidement un fichier spécifique. Vous pouvez également filtrer les fichiers par date ou type de fichier (par exemple, tous les fichiers PDF ou Word).<br>" +
                        "<b>Archives compressées (ZIP) :</b> Pour économiser de l’espace et organiser des fichiers volumineux, vous pouvez regrouper plusieurs fichiers dans une archive ZIP. Cela permet de les compresser et de les garder dans un seul fichier tout en réduisant l'encombrement.<br>" +
                        "<b>Utilisez OneDrive ou un autre service cloud :</b> Si vous travaillez sur plusieurs ordinateurs ou avec des collaborateurs, l’utilisation d'un service de stockage cloud comme OneDrive peut vous aider à garder vos fichiers organisés et accessibles de n'importe où.<br>" +
                        "<b>Évitez le Bureau encombré :</b> Bien que le bureau soit un lieu pratique pour placer des fichiers temporairement, essayez de garder le bureau propre et bien rangé. Créez des dossiers spécifiques sur le bureau pour organiser vos fichiers au lieu de les laisser tous éparpillés.<br>" +
                        "<b>Utilisation des métadonnées :</b> Certains fichiers (comme les images ou les documents PDF) peuvent être facilement recherchés grâce aux métadonnées (date de création, auteur, etc.). Vous pouvez ajouter des informations aux propriétés des fichiers pour les rendre plus faciles à retrouver.<br>" +
                        "<b>Répétition de processus avec Power Automate :</b> Si vous avez des tâches répétitives, vous pouvez automatiser l’organisation de vos fichiers avec Power Automate (disponible sur Windows 10 et 11). Cela peut être utile pour trier des fichiers entrants dans un dossier spécifique ou les renommer en masse."
        );

        listContent.put("Réparez un PC lent ou bloqué",
                "<b>1. Vérifiez les programmes en arrière-plan :</b><br>" +
                        "Ouvrez le Gestionnaire des tâches (Ctrl + Shift + Esc) et observez les programmes qui utilisent beaucoup de ressources (processeur, mémoire, disque). Fermez ceux qui ne sont pas nécessaires.<br><br>" +
                        "<b>2. Désactivez les programmes au démarrage :</b><br>" +
                        "Certains programmes se lancent automatiquement au démarrage de Windows. Vous pouvez les désactiver via le Gestionnaire des tâches sous l’onglet \"Démarrage\", ou via la configuration de démarrage dans le Gestionnaire des tâches.<br><br>" +
                        "<b>3. Vérifiez l’espace disque :</b><br>" +
                        "Si le disque dur est presque plein, cela peut ralentir votre PC. Vérifiez l’espace disponible et supprimez les fichiers inutiles ou utilisez l'outil de nettoyage de disque de Windows pour libérer de l'espace.<br><br>" +
                        "<b>4. Exécutez une analyse antivirus :</b><br>" +
                        "Un virus ou un logiciel malveillant peut ralentir votre PC. Utilisez un antivirus à jour pour effectuer une analyse complète de votre système.<br><br>" +
                        "<b>5. Défragmenter le disque dur (HDD) :</b><br>" +
                        "Si vous avez un disque dur traditionnel (HDD), vous pouvez le défragmenter en allant dans Outils de gestion des disques sous le menu Propriétés de votre disque. Cela réorganise les fichiers pour une lecture plus rapide. Si vous avez un disque SSD, la défragmentation n'est pas nécessaire.<br><br>" +
                        "<b>6. Vérifiez la mémoire (RAM) :</b><br>" +
                        "Si votre ordinateur utilise trop de mémoire, il peut se bloquer. Vérifiez la quantité de RAM utilisée dans le Gestionnaire des tâches et fermez les applications inutiles. Vous pouvez aussi envisager de rajouter de la mémoire RAM si nécessaire.<br><br>" +
                        "<b>7. Mettez à jour les pilotes :</b><br>" +
                        "Des pilotes obsolètes peuvent ralentir votre PC. Allez dans le Gestionnaire de périphériques et mettez à jour les pilotes de vos composants matériels, comme la carte graphique, la carte réseau, et les autres périphériques.<br><br>" +
                        "<b>8. Désactivez les effets visuels :</b><br>" +
                        "Windows utilise des effets visuels qui peuvent ralentir les performances. Vous pouvez les désactiver dans Paramètres > Système > Paramètres avancés du système > Paramètres de performances.<br><br>" +
                        "<b>9. Réinitialiser Windows :</b><br>" +
                        "Si le PC reste très lent, une réinitialisation du système peut aider. Vous pouvez réinitialiser Windows sans perdre vos fichiers via Paramètres > Mise à jour et sécurité > Récupération. Cela remettra votre PC à l’état d’usine.<br><br>" +
                        "<b>10. Vérifiez les températures :</b><br>" +
                        "Si le PC chauffe excessivement, il peut ralentir ou se bloquer pour éviter des dommages. Utilisez un logiciel pour vérifier les températures de votre processeur et carte graphique. Si les températures sont élevées, nettoyez les ventilateurs et dissipateurs de chaleur ou envisagez d’améliorer le refroidissement."
        );

        listContent.put("Prolongez la vie de votre ordinateur",
                "<b>Entretenez régulièrement votre disque dur :</b><br>" +
                        "Défragmentez votre disque dur (si vous avez un HDD) pour réorganiser les données et améliorer les performances. Cela n'est pas nécessaire pour un SSD, mais vous pouvez vérifier son état avec des outils de gestion des SSD.<br><br>" +

                        "<b>Nettoyez régulièrement les fichiers inutiles :</b><br>" +
                        "Utilisez l'outil de nettoyage de disque pour supprimer les fichiers temporaires, les caches des navigateurs, et d'autres fichiers inutiles.<br><br>" +

                        "<b>Mettez à jour votre système d’exploitation et vos logiciels :</b><br>" +
                        "Les mises à jour de sécurité et de performance sont essentielles pour maintenir votre PC fonctionnel et sécurisé. Veillez à activer les mises à jour automatiques pour ne pas oublier.<br><br>" +

                        "<b>Surveillez la température de votre ordinateur :</b><br>" +
                        "La chaleur excessive peut endommager les composants internes de votre PC. Utilisez des logiciels pour surveiller la température du processeur et du disque dur. Assurez-vous que les ventilateurs fonctionnent correctement et nettoyez régulièrement les grilles d’aération.<br><br>" +

                        "<b>Utilisez un antivirus efficace :</b><br>" +
                        "Protégez votre PC contre les virus et les malwares qui peuvent endommager votre système. Un logiciel antivirus à jour aidera à maintenir votre ordinateur en bon état.<br><br>" +

                        "<b>Optimisez la gestion de l’énergie :</b><br>" +
                        "Si vous avez un ordinateur portable, configurez les paramètres d’alimentation pour éviter une surcharge ou une utilisation excessive de la batterie. Utilisez un mode d’économie d’énergie lorsque l’ordinateur n'est pas utilisé activement.<br><br>" +

                        "<b>Remplacez les pièces défectueuses au besoin :</b><br>" +
                        "Si vous constatez que des composants comme la batterie, le disque dur ou la RAM montrent des signes de faiblesse, envisagez de les remplacer. Cela peut améliorer considérablement la performance et prolonger la durée de vie de votre appareil.<br><br>" +

                        "<b>Nettoyez votre matériel régulièrement :</b><br>" +
                        "La poussière et les saletés peuvent s’accumuler à l’intérieur de votre ordinateur, réduisant ainsi l’efficacité du refroidissement et causant des surchauffes. Utilisez une bombe à air comprimé pour nettoyer les composants internes, notamment les ventilateurs et les dissipateurs thermiques.<br><br>" +

                        "<b>Sauvegardez vos données :</b><br>" +
                        "Bien que cela ne prolonge pas directement la vie de votre PC, cela vous permettra de protéger vos fichiers en cas de défaillance matérielle. Faites des sauvegardes régulières sur un disque dur externe ou un service de stockage en ligne.<br><br>" +

                        "<b>Utilisez un onduleur (UPS) :</b><br>" +
                        "Si vous êtes souvent confronté à des coupures de courant, un onduleur peut aider à protéger votre PC contre les fluctuations de tension, les courts-circuits et autres problèmes électriques pouvant endommager les composants.<br><br>" +

                        "<b>Évitez les programmes inutiles en arrière-plan :</b><br>" +
                        "Fermez les applications qui consomment trop de ressources système lorsque vous ne les utilisez pas. Utilisez le gestionnaire des tâches pour désactiver les programmes qui se lancent au démarrage et qui ne sont pas essentiels.");

        listContent.put("Rendez Windows plus fluide",
                        "<b>1. Optimisation des paramètres du registre Windows</b><br>" +
                        "Le registre Windows contient des paramètres de configuration pour de nombreux aspects du système. Parfois, des valeurs par défaut peuvent ralentir le système. Voici quelques clés de registre à modifier pour améliorer les performances :<br><br>" +
                        "<b>Désactiver les services inutiles :</b><br>" +
                        "Lancer l'outil Services.msc via la commande Exécuter (Win + R). Certains services de Windows sont inutiles pour des utilisateurs moyens (ex. Windows Search, Superfetch, Windows Defender, etc.). La désactivation de ces services peut alléger les ressources.<br><br>" +
                        "<b>Exemple :</b><br>" +
                        "Superfetch : Ce service permet d'optimiser les performances des applications, mais il peut causer des ralentissements en utilisant trop de ressources. Désactive-le pour les anciens ordinateurs en exécutant <b>sc stop SysMain</b> et <b>sc config SysMain start=disabled</b> dans l'Invite de commandes en mode administrateur.<br><br>" +
                        "<b>Optimisation de la mémoire virtuelle :</b><br>" +
                        "Windows gère automatiquement la mémoire virtuelle (fichier pagefile.sys), mais tu peux le faire manuellement pour améliorer les performances.<br><br>" +
                        "Ouvre Panneau de configuration > Système > Paramètres système avancés > Paramètres sous Performances > Avancé > Mémoire virtuelle.<br>" +
                        "Désactive l'option de gestion automatique et définis une taille fixe pour le fichier d'échange (par exemple 1,5 fois la taille de ta RAM).<br><br>" +
                        "<b>2. Désactiver la gestion dynamique de la mémoire</b><br>" +
                        "Windows ajuste automatiquement la quantité de mémoire allouée aux programmes en cours d'exécution, ce qui peut parfois entraîner un ralentissement lorsque plusieurs programmes utilisent beaucoup de mémoire. Il est possible de désactiver cette fonction pour optimiser les performances :<br><br>" +
                        "Ouvre Panneau de configuration > Système > Paramètres système avancés > Performances > Paramètres > Avancé > Mémoire virtuelle.<br>" +
                        "Désactive l'option Gestion automatique de la taille du fichier d'échange pour tous les lecteurs et fixe une taille personnalisée pour chaque disque dur.<br><br>" +
                        "<b>3. Utilisation d’un SSD avec optimisations avancées</b><br>" +
                        "Si tu utilises déjà un SSD, il existe des réglages supplémentaires pour maximiser ses performances :<br><br>" +
                        "<b>Activer TRIM :</b><br>" +
                        "Le TRIM est une commande qui permet de maintenir les performances du SSD au fil du temps. Windows 10 active normalement cette fonction par défaut, mais tu peux vérifier son statut avec la commande suivante dans PowerShell :<br>" +
                        "<b>fsutil behavior query DisableDeleteNotify</b><br>" +
                        "Si la valeur retournée est 0, le TRIM est activé. Sinon, tu peux l’activer via la commande :<br>" +
                        "<b>fsutil behavior set DisableDeleteNotify 0</b><br><br>" +
                        "<b>Désactiver l'indexation du SSD :</b><br>" +
                        "Comme le SSD est déjà rapide, l’indexation des fichiers peut entraîner une utilisation excessive du processeur. Désactive l'indexation pour le lecteur SSD via les propriétés du disque.<br><br>" +
                        "<b>4. Ajustement des priorités de processus dans le gestionnaire de tâches</b><br>" +
                        "En modifiant les priorités des processus dans le Gestionnaire de tâches, tu peux allouer plus de ressources aux applications essentielles :<br><br>" +
                        "Ouvre Gestionnaire de tâches (Ctrl + Shift + Esc).<br>" +
                        "Clic droit sur un processus que tu veux prioriser, puis Aller à détails.<br>" +
                        "Clic droit sur le détail du processus > Définir la priorité > Choisis une priorité élevée (par exemple, Haute).<br><br>" +
                        "<b>5. Utilisation de logiciels tiers pour l’optimisation</b><br>" +
                        "CCleaner ou Wise Disk Cleaner peuvent nettoyer les fichiers temporaires, les clés de registre inutilisées, et optimiser les processus en arrière-plan. Toutefois, utilise ces outils avec prudence, en t'assurant de faire des sauvegardes du registre avant toute manipulation.<br><br>" +
                        "Razer Cortex ou Game Fire sont des logiciels qui désactivent les processus et services inutiles lorsque tu utilises des applications nécessitant des ressources, comme les jeux ou les logiciels de montage vidéo.<br><br>" +
                        "<b>6. Optimisation des pilotes graphiques et de la carte réseau</b><br>" +
                        "<b>Pilotes graphiques :</b><br>" +
                        "Si tu utilises une carte graphique dédiée, assure-toi que les pilotes sont mis à jour avec les derniers pilotes disponibles directement sur le site du fabricant (NVIDIA, AMD). Les pilotes plus récents peuvent inclure des optimisations qui améliorent la fluidité du système.<br><br>" +
                        "<b>Optimisation de la carte réseau :</b><br>" +
                        "Les mauvaises performances réseau peuvent ralentir Windows, surtout lors des mises à jour et de l’utilisation des services en ligne. Accède aux paramètres avancés de la carte réseau (Gestionnaire de périphériques > Carte réseau) et désactive les paramètres non nécessaires comme le Wake on LAN.<br><br>" +
                        "<b>7. Utilisation des disques durs externes ou NAS pour le stockage lourd</b><br>" +
                        "Si tu as une grande quantité de données (fichiers vidéo, photos), les stocker sur un disque dur externe ou un NAS (Network Attached Storage) peut alléger les disques internes. Cela permet de libérer de l'espace sur ton SSD ou HDD principal, ce qui contribue à la fluidité du système.<br><br>" +
                        "<b>8. Utilisation de l'outil 'Performance Monitor' pour identifier les goulots d'étranglement</b><br>" +
                        "Utilise l'outil Performance Monitor pour surveiller les ressources système en temps réel :<br><br>" +
                        "Lance Performance Monitor (tape perfmon dans le menu Démarrer).<br>" +
                        "Dans Moniteur de performance, tu peux ajouter des compteurs pour surveiller l’utilisation du CPU, de la mémoire, du disque, et du réseau. Cela te permettra de repérer les processus ou services qui consomment trop de ressources."
        );
        listContent.put("Supprimez les fichiers inutiles",
                "<b>1. Utiliser l'outil de nettoyage de disque</b><br>" +
                        "Windows dispose d'un utilitaire intégré pour supprimer les fichiers inutiles.<br>" +
                        "<b>Étape 1</b>: Ouvrez le menu Démarrer et tapez \"Nettoyage de disque\" dans la barre de recherche, puis sélectionnez l'application.<br>" +
                        "<b>Étape 2</b>: Choisissez le lecteur à nettoyer (généralement C:) et cliquez sur OK.<br>" +
                        "<b>Étape 3</b>: Laissez Windows analyser votre disque. Une fois l'analyse terminée, cochez les types de fichiers que vous souhaitez supprimer (fichiers temporaires, fichiers système, etc.).<br>" +
                        "<b>Étape 4</b>: Cliquez sur OK, puis confirmez en cliquant sur Supprimer les fichiers.<br><br>" +

                        "<b>2. Supprimer les fichiers temporaires</b><br>" +
                        "Les fichiers temporaires peuvent s'accumuler avec le temps et occuper beaucoup d'espace.<br>" +
                        "<b>Étape 1</b>: Appuyez sur Windows + R pour ouvrir la boîte de dialogue Exécuter.<br>" +
                        "<b>Étape 2</b>: Tapez %temp% et appuyez sur Entrée.<br>" +
                        "<b>Étape 3</b>: Supprimez tous les fichiers qui apparaissent dans le dossier (certains fichiers peuvent être utilisés par d'autres applications, donc ignorez ceux qui ne peuvent pas être supprimés).<br><br>" +

                        "<b>3. Supprimer les fichiers de mise à jour Windows</b><br>" +
                        "Les mises à jour Windows peuvent laisser des fichiers résiduels après l'installation.<br>" +
                        "<b>Étape 1</b>: Ouvrez le menu Démarrer et tapez \"Paramètres\", puis sélectionnez Paramètres.<br>" +
                        "<b>Étape 2</b>: Allez dans Système > Stockage.<br>" +
                        "<b>Étape 3</b>: Sous \"Fichiers temporaires\", vous trouverez une option pour supprimer les anciens fichiers de mise à jour Windows. Cliquez sur Supprimer les fichiers.<br><br>" +

                        "<b>4. Vider la corbeille</b><br>" +
                        "Les fichiers que vous avez supprimés ne sont pas totalement effacés tant que la corbeille n'est pas vidée.<br>" +
                        "<b>Étape 1</b>: Faites un clic droit sur l'icône Corbeille sur votre bureau.<br>" +
                        "<b>Étape 2</b>: Sélectionnez Vider la corbeille.<br><br>" +

                        "<b>5. Désinstaller les programmes inutiles</b><br>" +
                        "Les programmes que vous n'utilisez plus occupent de l'espace et peuvent ralentir votre PC.<br>" +
                        "<b>Étape 1</b>: Ouvrez le menu Démarrer, puis allez dans Paramètres > Applications > Applications et fonctionnalités.<br>" +
                        "<b>Étape 2</b>: Parcourez la liste des applications et désinstallez celles que vous n'utilisez plus.<br><br>" +

                        "<b>6. Utiliser un logiciel tiers pour nettoyer en profondeur</b><br>" +
                        "Il existe des outils comme CCleaner qui peuvent vous aider à identifier et supprimer les fichiers inutiles, les caches d'applications et d'autres éléments inutiles."
        );
        listContent.put("Tout sur Windows Update",
                "<b>Windows Update</b> est une fonctionnalité essentielle de Windows qui permet de maintenir votre système d'exploitation à jour avec les derniers correctifs de sécurité, améliorations de performances, et nouvelles fonctionnalités. Voici un guide détaillé sur l'utilisation de Windows Update :<br><br>" +

                        "<b>Qu'est-ce que Windows Update ?</b><br>" +
                        "Windows Update est un service en ligne de Microsoft qui télécharge et installe automatiquement les mises à jour de sécurité, les mises à jour critiques et les mises à jour facultatives pour améliorer le fonctionnement de votre système d'exploitation. Ces mises à jour sont cruciales pour la stabilité, la sécurité et les performances de votre PC.<br><br>" +

                        "<b>Types de mises à jour :</b><br>" +
                        "<ul>" +
                        "<li><b>Mises à jour de sécurité :</b> Corrigent des vulnérabilités connues qui peuvent être exploitées par des attaquants pour accéder à votre ordinateur.</li>" +
                        "<li><b>Mises à jour de fonctionnalités :</b> Introduisent de nouvelles fonctionnalités, améliorations de l'interface utilisateur, et nouvelles applications dans le système d'exploitation.</li>" +
                        "<li><b>Mises à jour de qualité :</b> Fournissent des améliorations de performances et des corrections de bugs.</li>" +
                        "<li><b>Mises à jour optionnelles :</b> Ce sont des pilotes matériels ou des fonctionnalités supplémentaires que vous pouvez choisir d'installer ou non.</li>" +
                        "</ul><br>" +

                        "<b>Comment vérifier les mises à jour ?</b><br>" +
                        "Pour vérifier si des mises à jour sont disponibles, suivez ces étapes :<br>" +
                        "<ol>" +
                        "<li>Cliquez sur le bouton Démarrer et allez dans Paramètres (l'icône en forme de roue dentée).</li>" +
                        "<li>Sélectionnez Mise à jour et sécurité.</li>" +
                        "<li>Cliquez sur Rechercher des mises à jour. Windows va alors chercher les mises à jour disponibles et les télécharger automatiquement.</li>" +
                        "</ol><br>" +

                        "<b>Installation automatique des mises à jour :</b><br>" +
                        "Windows 10 et 11 sont conçus pour télécharger et installer les mises à jour automatiquement. Cependant, si vous souhaitez installer les mises à jour manuellement, vous pouvez suivre la procédure décrite ci-dessus.<br><br>" +

                        "<b>Problèmes courants de Windows Update :</b><br>" +
                        "<ul>" +
                        "<li><b>Échec du téléchargement :</b> Si Windows Update ne parvient pas à télécharger ou à installer une mise à jour, il est souvent utile de redémarrer votre PC et de réessayer.</li>" +
                        "<li><b>Code d'erreur :</b> Si une mise à jour échoue, un code d'erreur spécifique peut apparaître. Vous pouvez rechercher ce code sur le site de Microsoft pour trouver une solution.</li>" +
                        "<li><b>Pannes de mise à jour :</b> Si vous avez des problèmes persistants, l'outil de dépannage de Windows Update peut être utilisé pour identifier et résoudre des problèmes liés aux mises à jour.</li>" +
                        "</ul><br>" +

                        "<b>Gérer les heures d'activité (Windows 10 et 11) :</b><br>" +
                        "Pour éviter que les mises à jour ne perturbent votre travail, vous pouvez définir des heures d’activité, pendant lesquelles Windows ne redémarrera pas pour installer des mises à jour. Allez dans Paramètres > Mise à jour et sécurité > Modifier les heures d’activité pour personnaliser cette option.<br><br>" +

                        "<b>Forcer l'installation d'une mise à jour :</b><br>" +
                        "Si une mise à jour particulière ne s'installe pas automatiquement, vous pouvez forcer son installation via l'outil Windows Update Assistant ou télécharger manuellement la mise à jour depuis le Catalogue des mises à jour Microsoft.<br><br>" +

                        "<b>Prolongation du délai d'installation :</b><br>" +
                        "Windows permet de reporter les mises à jour de fonctionnalités pendant un certain temps, ce qui peut être utile si vous souhaitez éviter les changements importants sur votre système pendant un projet critique. Cela se fait via Paramètres > Mise à jour et sécurité > Options avancées.<br><br>" +

                        "<b>Nettoyage après mise à jour :</b><br>" +
                        "Après l'installation de mises à jour importantes, des fichiers temporaires peuvent s'accumuler. Utilisez l'outil Nettoyage de disque pour supprimer ces fichiers et libérer de l'espace sur votre disque dur."
        );
        listContent.put("Sauvegardez vos données facilement",
                "<b>1. Utiliser l'outil de sauvegarde intégré de Windows</b><br>" +
                        "Windows propose une fonctionnalité de sauvegarde appelée Historique des fichiers. Voici comment l'utiliser :<br>" +
                        "Accédez à Paramètres > Mise à jour et sécurité > Sauvegarde.<br>" +
                        "Activez l'option <b>Ajouter un lecteur</b> pour sélectionner un disque dur externe ou une clé USB.<br>" +
                        "Ensuite, activez l'Historique des fichiers pour sauvegarder vos fichiers automatiquement sur votre lecteur sélectionné.<br>" +
                        "Vous pouvez aussi personnaliser la fréquence des sauvegardes et les dossiers à inclure.<br><br>" +

                        "<b>2. Utiliser OneDrive pour la sauvegarde dans le cloud</b><br>" +
                        "OneDrive est un service de stockage en ligne intégré à Windows. Pour l'utiliser :<br>" +
                        "Accédez à OneDrive via l'Explorateur de fichiers ou dans les paramètres de Windows.<br>" +
                        "Déplacez vos fichiers importants dans le dossier OneDrive. Ils seront automatiquement synchronisés dans le cloud.<br>" +
                        "OneDrive propose un stockage gratuit, mais vous pouvez acheter plus d'espace si nécessaire.<br><br>" +

                        "<b>3. Utiliser un logiciel de sauvegarde tiers</b><br>" +
                        "Des logiciels comme Acronis True Image, EaseUS Todo Backup, ou Macrium Reflect vous permettent de sauvegarder l'intégralité de votre système, y compris vos fichiers et vos paramètres.<br>" +
                        "Ces outils vous permettent de faire des sauvegardes complètes (image disque) ou des sauvegardes différentielles pour ne sauvegarder que les fichiers modifiés.<br>" +
                        "Ils peuvent aussi être configurés pour effectuer des sauvegardes automatiques à intervalles réguliers.<br><br>" +

                        "<b>4. Utiliser un disque dur externe ou une clé USB</b><br>" +
                        "Sauvegarder vos données manuellement sur un disque dur externe est une méthode simple et fiable :<br>" +
                        "Branchez le disque dur externe ou la clé USB à votre PC.<br>" +
                        "Sélectionnez les fichiers et dossiers que vous souhaitez sauvegarder, puis copiez-les sur le périphérique de stockage.<br>" +
                        "Il est recommandé de le faire régulièrement pour garantir la sécurité de vos données.<br><br>" +

                        "<b>5. Planification de sauvegardes automatiques</b><br>" +
                        "Pour ne pas oublier de sauvegarder vos fichiers, configurez une sauvegarde automatique. Windows et les logiciels tiers offrent cette option, vous permettant de programmer des sauvegardes régulières à une heure de votre choix."
        );
        listContent.put("Résolvez les erreurs Windows vite",
                "<b>1. Redémarrer l'ordinateur</b><br>" +
                        "Parfois, un simple redémarrage peut résoudre des erreurs liées à des logiciels ou à des processus bloqués. Assurez-vous de redémarrer votre PC avant de tenter d'autres solutions.<br><br>" +

                        "<b>2. Utiliser l'outil de résolution des problèmes Windows</b><br>" +
                        "Windows dispose d'un outil intégré pour diagnostiquer et résoudre automatiquement certains problèmes. Voici comment y accéder :<br>" +
                        "Allez dans Paramètres > Mise à jour et sécurité > Résolution des problèmes.<br>" +
                        "Sélectionnez l'option qui correspond au problème que vous rencontrez, comme Internet et réseau ou Système.<br><br>" +

                        "<b>3. Mettre à jour Windows</b><br>" +
                        "De nombreuses erreurs peuvent être causées par des versions obsolètes de Windows. Assurez-vous que votre système est à jour :<br>" +
                        "Allez dans Paramètres > Mise à jour et sécurité > Windows Update.<br>" +
                        "Cliquez sur Rechercher des mises à jour et installez toutes les mises à jour disponibles.<br><br>" +

                        "<b>4. Vérifier les erreurs de disque avec CHKDSK</b><br>" +
                        "Si vous avez des problèmes de disque dur, vous pouvez utiliser l'outil CHKDSK pour rechercher et corriger les erreurs :<br>" +
                        "Ouvrez l'Invite de commandes en tant qu'administrateur (cliquez avec le bouton droit sur le menu Démarrer et sélectionnez Invite de commandes (Admin)).<br>" +
                        "Tapez chkdsk /f et appuyez sur Entrée. Cela scannera et réparera le disque dur.<br><br>" +

                        "<b>5. Vérifier les fichiers système avec SFC</b><br>" +
                        "L'outil SFC (System File Checker) permet de scanner et de réparer les fichiers système corrompus :<br>" +
                        "Ouvrez l'Invite de commandes en tant qu'administrateur.<br>" +
                        "Tapez sfc /scannow et appuyez sur Entrée. Cela lancera le scan des fichiers système et résoudra les problèmes.<br><br>" +

                        "<b>6. Vérifier les pilotes matériels</b><br>" +
                        "Des erreurs peuvent être causées par des pilotes obsolètes ou incompatibles. Pour vérifier et mettre à jour vos pilotes :<br>" +
                        "Faites un clic droit sur le menu Démarrer et sélectionnez Gestionnaire de périphériques.<br>" +
                        "Vérifiez si un périphérique présente un symbole d'avertissement jaune (indiquant un problème).<br>" +
                        "Faites un clic droit sur le périphérique concerné et sélectionnez Mettre à jour le pilote.<br><br>" +

                        "<b>7. Désinstaller les applications récemment installées</b><br>" +
                        "Si l'erreur est apparue après l'installation d'une nouvelle application, il peut être utile de la désinstaller :<br>" +
                        "Allez dans Paramètres > Applications > Applications et fonctionnalités.<br>" +
                        "Trouvez l'application en question et cliquez sur Désinstaller.<br><br>" +

                        "<b>8. Restaurer le système</b><br>" +
                        "Si les étapes précédentes n'ont pas fonctionné, une restauration du système à un point antérieur peut aider à corriger les erreurs récentes :<br>" +
                        "Allez dans Panneau de configuration > Récupération > Restaurer le système.<br>" +
                        "Choisissez un point de restauration avant l'apparition de l'erreur et suivez les instructions.<br><br>" +

                        "<b>9. Réinitialiser Windows</b><br>" +
                        "En dernier recours, si l'erreur persiste et que vous ne parvenez pas à la résoudre, vous pouvez réinitialiser Windows. Cela réinstalle le système tout en conservant vos fichiers personnels :<br>" +
                        "Allez dans Paramètres > Mise à jour et sécurité > Récupération.<br>" +
                        "Sous Réinitialiser ce PC, cliquez sur Commencer et choisissez de conserver ou non vos fichiers.<br><br>" +

                        "<b>10. Consulter les forums et communautés</b><br>" +
                        "Vous pouvez également consulter le forum de l'application Help-me pour obtenir des conseils sur la résolution des erreurs Windows. Le forum offre un espace où les utilisateurs peuvent poser des questions, partager leurs expériences et recevoir des réponses personnalisées de la communauté et des experts. Cela peut être particulièrement utile pour des problèmes techniques spécifiques que vous pourriez rencontrer."
        );
        listContent.put("Automatisez vos tâches sous Windows",
                "<b>1. Utiliser le Planificateur de tâches</b><br>" +
                        "Le Planificateur de tâches intégré à Windows vous permet de programmer des tâches régulières à des moments précis (par exemple, lancer une application ou exécuter un script). Voici comment l'utiliser :<br>" +
                        "Ouvrez Planificateur de tâches (tapez \"Planificateur de tâches\" dans la barre de recherche).<br>" +
                        "Dans le panneau de droite, cliquez sur Créer une tâche.<br>" +
                        "Donnez un nom à la tâche et définissez ses déclencheurs (quand vous voulez qu’elle s'exécute).<br>" +
                        "Dans l'onglet Actions, spécifiez l'action à exécuter (ex. démarrer un programme, envoyer un email, etc.).<br>" +
                        "Vous pouvez aussi définir des conditions et paramètres dans les autres onglets pour affiner l'automatisation.<br><br>" +

                        "<b>2. Utiliser les scripts Batch (Fichiers .bat)</b><br>" +
                        "Les fichiers batch (.bat) sont des scripts que vous pouvez créer pour exécuter des commandes automatiquement.<br>" +
                        "Créez un fichier texte avec l'extension .bat (exemple : automatisation.bat).<br>" +
                        "Ajoutez des commandes comme echo pour afficher des messages, ou start pour ouvrir des programmes.<br>" +
                        "Exemples de commandes :<br>" +
                        "<pre>" +
                        "echo \"Début de la tâche\"<br>" +
                        "start C:\\Program Files\\VotreApplication.exe<br>" +
                        "echo \"Fin de la tâche\"<br>" +
                        "</pre>" +
                        "Exécutez ce fichier manuellement ou planifiez-le avec le Planificateur de tâches.<br><br>" +

                        "<b>3. Utiliser PowerShell</b><br>" +
                        "PowerShell est un outil plus puissant que le fichier batch, qui permet d'automatiser des tâches complexes.<br>" +
                        "Par exemple, pour nettoyer les fichiers temporaires, utilisez la commande suivante dans un script PowerShell :<br>" +
                        "<pre>" +
                        "Get-ChildItem -Path \"C:\\Temp\" -Recurse | Remove-Item -Force<br>" +
                        "</pre>" +
                        "Les scripts PowerShell peuvent être sauvegardés avec l'extension .ps1 et exécutés de manière similaire aux fichiers batch.<br><br>" +

                        "<b>4. Utiliser les Applications tierces</b><br>" +
                        "Certaines applications permettent d'automatiser des tâches sous Windows sans nécessiter de script complexe. Des outils populaires incluent :<br>" +
                        "<ul>" +
                        "<li><b>AutoHotkey</b> : Idéal pour automatiser des clics de souris, des frappes clavier, ou des séquences d'actions.</li>" +
                        "<li><b>Task Scheduler</b> (via un script Python, par exemple) : Pour des automatisations plus complexes en utilisant des langages de programmation comme Python.</li>" +
                        "</ul><br>" +

                        "<b>5. Utiliser les \"Raccourcis clavier\" pour automatiser certaines actions</b><br>" +
                        "Si vous réalisez fréquemment des actions répétitives, vous pouvez utiliser des raccourcis clavier pour automatiser ces tâches sans avoir besoin d'un script :<br>" +
                        "Par exemple, appuyez sur Ctrl + Shift + Esc pour ouvrir le Gestionnaire des tâches directement.<br><br>" +

                        "<b>6. Automatiser avec des outils de synchronisation et de sauvegarde</b><br>" +
                        "Des outils comme OneDrive, Google Drive ou des logiciels de sauvegarde peuvent être configurés pour sauvegarder des fichiers de manière automatique, sans intervention manuelle.<br><br>" +

                        "<b>7. Utiliser les \"tâches de démarrage\"</b><br>" +
                        "Certaines applications peuvent être configurées pour s'exécuter dès que vous démarrez votre ordinateur. Vous pouvez ajouter des programmes à vos éléments de démarrage via le gestionnaire de tâches de Windows, ce qui vous permet de lancer des applications de manière automatique à l'ouverture de votre session.<br><br>" +

                        "L’automatisation sous Windows simplifie la gestion de nombreuses tâches répétitives, vous permettant de vous concentrer sur des activités plus importantes."
        );
        listContent.put("Boostez la sécurité de votre réseau",
                "<b>1. Utiliser un pare-feu Windows</b><br>" +
                        "Le pare-feu intégré à Windows est un excellent moyen de protéger votre réseau contre les accès non autorisés. Assurez-vous qu'il est activé :<br>" +
                        "<b>Étapes :</b><br>" +
                        "Allez dans Paramètres > Mise à jour et sécurité > Sécurité Windows.<br>" +
                        "Cliquez sur Pare-feu et protection réseau.<br>" +
                        "Assurez-vous que le pare-feu est activé pour chaque type de réseau (public, privé, et de domaine).<br><br>" +

                        "<b>2. Activer la protection contre les menaces réseau</b><br>" +
                        "Windows Defender offre une protection contre les menaces réseau comme les attaques DDoS ou les tentatives d'accès non autorisées. Il est crucial de l'activer pour une protection renforcée.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Ouvrez Sécurité Windows.<br>" +
                        "Allez dans Protection contre les menaces et activez Protection contre les menaces réseau.<br><br>" +

                        "<b>3. Mettre à jour régulièrement votre système d'exploitation</b><br>" +
                        "Les mises à jour de Windows corrigent souvent des vulnérabilités de sécurité, donc il est essentiel de maintenir votre système à jour.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Allez dans Paramètres > Mise à jour et sécurité.<br>" +
                        "Cliquez sur Vérifier les mises à jour pour installer les dernières versions et correctifs.<br><br>" +

                        "<b>4. Activer l'authentification à deux facteurs (2FA)</b><br>" +
                        "Si vous utilisez des comptes Microsoft ou d'autres services en ligne, activez l'authentification à deux facteurs pour ajouter une couche supplémentaire de sécurité.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Connectez-vous à votre compte Microsoft.<br>" +
                        "Accédez à la section Sécurité et suivez les instructions pour activer la 2FA.<br><br>" +

                        "<b>5. Sécuriser votre connexion Wi-Fi</b><br>" +
                        "Assurez-vous que votre réseau Wi-Fi est sécurisé avec un mot de passe fort et utilise un protocole de sécurité moderne comme WPA3.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Connectez-vous à l'interface de votre routeur (généralement via un navigateur avec l'adresse IP du routeur).<br>" +
                        "Accédez aux paramètres de sécurité et définissez le type de cryptage sur WPA3.<br>" +
                        "Utilisez un mot de passe long et complexe.<br><br>" +

                        "<b>6. Utiliser un VPN (Réseau Privé Virtuel)</b><br>" +
                        "Un VPN permet de masquer votre adresse IP et de chiffrer vos données, ce qui améliore considérablement la sécurité de vos connexions réseau, en particulier lors de l'utilisation de réseaux publics.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Choisissez un fournisseur VPN fiable.<br>" +
                        "Installez et activez le VPN sur votre appareil.<br><br>" +

                        "<b>7. Désactiver les services et les ports inutiles</b><br>" +
                        "Réduisez les risques d'attaque en désactivant les services et les ports réseau que vous n'utilisez pas.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Ouvrez Gestionnaire de périphériques.<br>" +
                        "Désactivez les périphériques ou services non utilisés, comme le partage de fichiers ou l'accès à distance.<br><br>" +

                        "<b>8. Surveiller l’activité réseau</b><br>" +
                        "Utilisez un logiciel de surveillance réseau pour détecter toute activité suspecte, comme des tentatives de connexion non autorisées.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Installez un outil comme Wireshark ou GlassWire.<br>" +
                        "Analysez régulièrement le trafic réseau pour détecter des anomalies.<br><br>" +

                        "<b>9. Configurer une sécurité avancée sur votre routeur</b><br>" +
                        "La plupart des routeurs offrent des fonctionnalités de sécurité avancées, telles que la gestion des utilisateurs, la détection d'intrusion, et les filtres IP.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Connectez-vous à l'interface de gestion de votre routeur.<br>" +
                        "Activez les fonctionnalités de sécurité telles que le filtrage par adresse MAC et la détection d'intrusion.<br><br>" +

                        "<b>10. Utiliser un antivirus fiable</b><br>" +
                        "Même si Windows Defender est efficace, il peut être judicieux d'utiliser un antivirus tiers qui offre une protection plus avancée, en particulier contre les menaces complexes.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Installez un antivirus comme Kaspersky, Bitdefender, ou Norton.<br>" +
                        "Assurez-vous que la fonction de protection en temps réel est activée.<br><br>" +

                        "<b>11. Réduire l'utilisation des privilèges d'administrateur</b><br>" +
                        "L'utilisation d'un compte avec des privilèges d'administrateur augmente le risque en cas de malware. Utilisez un compte standard lorsque cela est possible et ne donnez les droits d'administrateur que lorsque nécessaire.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Créez un compte standard via Paramètres > Comptes > Famille et autres utilisateurs.<br>" +
                        "Assurez-vous que votre compte principal est administrateur uniquement en cas de besoin.<br><br>" +

                        "<b>12. Désactiver le partage de fichiers public</b><br>" +
                        "Évitez que vos fichiers soient accessibles publiquement sur le réseau. Désactivez le partage de fichiers en mode public.<br>" +
                        "<b>Étapes :</b><br>" +
                        "Ouvrez Panneau de configuration.<br>" +
                        "Allez dans Réseau et partage > Modifier les paramètres de partage avancés.<br>" +
                        "Désactivez le partage de fichiers public et restreignez l'accès aux utilisateurs autorisés.<br>"
        );
        listContent.put("Personnalisez votre bureau Windows",
                "<b>1. Changer l'arrière-plan du bureau</b><br>" +
                        "Clic droit sur le bureau > Personnaliser.<br>" +
                        "Sélectionnez Arrière-plan et choisissez une image, une couleur unie, ou une présentation.<br>" +
                        "Vous pouvez aussi télécharger des images ou choisir parmi les fonds d'écran proposés par Windows.<br><br>" +

                        "<b>2. Modifier la disposition des icônes</b><br>" +
                        "Clic droit sur le bureau > Affichage.<br>" +
                        "Choisissez entre Icônes petites, moyennes ou grandes.<br>" +
                        "Si vous voulez organiser vos icônes, vous pouvez les faire glisser à l'endroit souhaité ou activer Disposition automatique des icônes pour un alignement plus ordonné.<br><br>" +

                        "<b>3. Personnaliser la barre des tâches</b><br>" +
                        "Clic droit sur la barre des tâches > Paramètres de la barre des tâches.<br>" +
                        "Vous pouvez choisir de déplacer la barre des tâches sur les côtés ou en bas de l'écran.<br>" +
                        "Ajoutez ou supprimez des icônes, personnalisez les icônes de notifications et activez ou désactivez l'option Masquer automatiquement.<br><br>" +

                        "<b>4. Modifier les couleurs du thème</b><br>" +
                        "Clic droit sur le bureau > Personnaliser > Couleurs.<br>" +
                        "Choisissez une couleur d’accentuation pour les menus, les fenêtres, et autres éléments visuels.<br>" +
                        "Vous pouvez également activer un thème sombre ou clair pour tout le système.<br><br>" +

                        "<b>5. Ajouter des widgets</b><br>" +
                        "Cliquez sur l'icône de widgets (ou appuyez sur Windows + W) pour ajouter des widgets comme l'actualité, la météo, l’agenda, etc.<br>" +
                        "Vous pouvez les organiser pour qu'ils soient facilement accessibles.<br><br>" +

                        "<b>6. Utiliser des bureaux virtuels</b><br>" +
                        "Si vous avez besoin de plusieurs espaces de travail, utilisez les bureaux virtuels pour organiser vos tâches.<br>" +
                        "Appuyez sur Windows + Tab pour accéder aux bureaux virtuels, puis cliquez sur Nouveau bureau pour en ajouter un.<br><br>" +

                        "<b>7. Changer le thème du système</b><br>" +
                        "Clic droit sur le bureau > Personnaliser > Thèmes.<br>" +
                        "Choisissez parmi les thèmes Windows par défaut ou téléchargez-en de nouveaux à partir du Microsoft Store.<br><br>" +

                        "<b>8. Ajouter des raccourcis d’applications</b><br>" +
                        "Pour un accès rapide aux applications, faites un clic droit sur une application dans le menu Démarrer, puis choisissez Plus > Épingler à la barre des tâches ou Épingler au démarrage.<br><br>" +

                        "<b>9. Organiser le menu Démarrer</b><br>" +
                        "Vous pouvez personnaliser le menu Démarrer en épinglant vos applications les plus utilisées.<br>" +
                        "Faites un clic droit sur l'application et choisissez Épingler à l'écran de démarrage.<br><br>" +

                        "<b>10. Utiliser des fonds d'écran animés ou des thèmes dynamiques</b><br>" +
                        "Pour un effet visuel plus dynamique, vous pouvez utiliser des applications tierces qui permettent des fonds d'écran animés.<br>" +
                        "Des options de fonds d'écran changeant en fonction de l’heure de la journée ou des saisons peuvent également être activées via les paramètres.<br><br>" +

                        "<b>11. Réorganiser les fenêtres</b><br>" +
                        "Pour optimiser l’utilisation de l’espace, utilisez Snap Assist (Windows + Flèches) pour organiser rapidement les fenêtres ouvertes sur votre écran.<br>"
        );

        listContent.put("10 erreurs à éviter sur un ordinateur",
                        "<b>1. Négliger les mises à jour du système et des logiciels</b><br>" +
                        "Ne pas mettre à jour Windows ou les logiciels peut exposer votre PC à des failles de sécurité, des bugs, ou des incompatibilités. " +
                        "Configurez les mises à jour automatiques ou effectuez-les régulièrement via Windows Update.<br><br>" +

                        "<b>2. Télécharger des programmes de sources non fiables</b><br>" +
                        "Installer des logiciels à partir de sites inconnus ou douteux peut introduire des virus ou des malwares. " +
                        "Utilisez toujours des sites officiels ou des plateformes de téléchargement reconnues comme Microsoft Store ou Softonic.<br><br>" +

                        "<b>3. Ignorer les alertes d’espace disque faible</b><br>" +
                        "Laisser votre disque dur presque plein peut ralentir le PC et provoquer des erreurs. " +
                        "Libérez de l’espace en supprimant les fichiers inutiles, en vidant la corbeille ou en déplaçant des données sur un disque externe ou un cloud.<br><br>" +

                        "<b>4. Ouvrir des fichiers inconnus ou non vérifiés</b><br>" +
                        "Cliquer sur des pièces jointes dans des emails suspects ou ouvrir des fichiers téléchargés sans les analyser peut infecter votre PC. " +
                        "Scannez toujours les fichiers avec un antivirus avant de les ouvrir.<br><br>" +

                        "<b>5. Surcharger le démarrage de Windows</b><br>" +
                        "Laisser trop de programmes se lancer au démarrage ralentit considérablement votre PC. " +
                        "Accédez au Gestionnaire des tâches (<b>Ctrl + Shift + Échap</b>), puis désactivez les programmes inutiles dans l’onglet \"Démarrage\".<br><br>" +

                        "<b>6. Négliger la maintenance régulière</b><br>" +
                        "Un PC non entretenu accumule poussière et logiciels inutiles, réduisant ses performances. " +
                        "Nettoyez régulièrement le matériel (avec précaution) et désinstallez les programmes inutilisés.<br><br>" +

                        "<b>7. Ne pas protéger ses données avec des sauvegardes</b><br>" +
                        "Ne pas sauvegarder vos fichiers importants peut vous faire perdre des données en cas de panne ou d’attaque. " +
                        "Utilisez des disques durs externes, des clés USB ou des services cloud (OneDrive, Google Drive).<br><br>" +

                        "<b>8. Installer plusieurs antivirus en même temps</b><br>" +
                        "Avoir plusieurs antivirus actifs peut créer des conflits et ralentir le PC. " +
                        "Choisissez-en un seul, fiable, et assurez-vous qu’il est à jour.<br><br>" +

                        "<b>9. Utiliser des mots de passe faibles</b><br>" +
                        "Des mots de passe simples comme \"123456\" ou \"motdepasse\" facilitent le piratage. " +
                        "Créez des mots de passe complexes avec des chiffres, lettres majuscules et minuscules, et symboles.<br><br>" +

                        "<b>10. Éteindre le PC directement sans l’arrêter correctement</b><br>" +
                        "Couper brutalement l’alimentation peut endommager vos données ou votre disque dur. " +
                        "Toujours utiliser l’option \"Arrêter\" dans Windows pour une extinction sécurisée."
        );

    }
}
