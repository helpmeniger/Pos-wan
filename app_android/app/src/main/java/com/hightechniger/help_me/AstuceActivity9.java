package com.hightechniger.help_me;


import android.content.Intent;
import android.content.pm.ActivityInfo;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.ExpandableListView;
import android.widget.MediaController;
import android.widget.TextView;
import android.widget.VideoView;

import androidx.appcompat.app.AppCompatActivity;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;


public class AstuceActivity9 extends AppCompatActivity {


    ExpandableListView expandableListView;
    List<String> listGroup;
    HashMap<String, List<String>> listItem;
    ExpandableListAdapter adapter;
    private VideoView videoView;
    private View cardView;

    HashMap<String, String> listContent;
    private TextView contentTextView;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_astuce9);

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
        // Vidéos remplacées provisoirement par les illustrations du manuel
        ImageLessonActivity.open(this, resId);
    }


    private void displayContent(String selected) {
        String content = listContent.get(selected);
        if (content != null) {
            Intent intent = new Intent(AstuceActivity9.this, DetailActivity.class);
            intent.putExtra("title", selected);
            intent.putExtra("content", content);
            startActivity(intent);
        }
    }

    private int getVideoResourceId(String code) {
        switch (code) {
            case "Naviguer sur Internet":
                // Lance l'activité MainActivity17
                Intent intent6 = new Intent(AstuceActivity9.this, MainActivity17.class);
                startActivity(intent6);
                return 0; // Retourne 0 pour indiquer qu'il n'y a pas de ressource vidéo
            default:
                return 0; // Par défaut, retourne 0 si aucune correspondance
        }
    }

    /**
     * Initialise les données pour l'ExpandableListView.
     */
    private void initListData() {
        listGroup.add("  ASTUCES");
        listGroup.add("  DECOUVERTE");


        List<String> ASTUCES = new ArrayList<>();
        ASTUCES.add("Optimiser votre expérience avec les ordinateurs");


        List<String> DECOUVERTE = new ArrayList<>();
        DECOUVERTE.add("Liste d'astuces et d'outils pratiques peu connus");



        listItem.put(listGroup.get(0), ASTUCES);
        listItem.put(listGroup.get(1), DECOUVERTE);

        listContent.put("Optimiser votre expérience avec les ordinateurs",
                "1. <b>Activez le mode sombre pour réduire la fatigue visuelle</b><br>" +
                        "<b>Comment l'utiliser :</b> Sur Windows, accédez à Paramètres > Personnalisation > Couleurs, puis choisissez Mode sombre.<br>" +
                        "<b>Pratique :</b> Idéal pour le travail nocturne ou dans des environnements à faible luminosité.<br><br>" +

                        "2. <b>Ajouter plus de RAM pour améliorer les performances générales</b><br>" +
                        "<b>Comment l'utiliser :</b> Vérifiez la compatibilité de votre ordinateur avec les nouvelles barrettes de RAM. Installez-les ou faites appel à un technicien.<br>" +
                        "<b>Pratique :</b> Lancez plusieurs logiciels en même temps sans ralentir.<br><br>" +

                        "3. <b>Utilisez un SSD pour accélérer le démarrage et l'accès aux fichiers</b><br>" +
                        "<b>Comment l'utiliser :</b> Achetez un SSD et migrez votre système dessus avec des outils comme AOMEI Backupper.<br>" +
                        "<b>Pratique :</b> Profitez d'un démarrage en moins de 10 secondes !<br><br>" +

                        "4. <b>Défragmentez régulièrement votre disque dur</b><br>" +
                        "<b>Comment l'utiliser :</b> Sur Windows, recherchez \"Défragmenteur et optimisation des lecteurs\", sélectionnez le disque et cliquez sur \"Optimiser\".<br>" +
                        "<b>Pratique :</b> Augmentez la vitesse d'accès aux fichiers sur un disque HDD.<br><br>" +

                        "5. <b>Mettez à jour vos pilotes pour de meilleures performances</b><br>" +
                        "<b>Comment l'utiliser :</b> Utilisez Windows Update ou des outils comme Driver Booster pour mettre à jour vos pilotes.<br>" +
                        "<b>Pratique :</b> Évitez les problèmes matériels et améliorez les performances.<br><br>" +

                        "6. <b>Fenêtres</b><br>" +
                        "Utilisez le Gestionnaire des tâches pour identifier les processus gourmands<br>" +
                        "<b>Comment l'utiliser :</b> Appuyez sur Ctrl + Shift + Échap, allez dans l'onglet \"Processus\" pour voir les applications consommant le plus de ressources.<br>" +
                        "<b>Pratique :</b> Fermez les applications inutiles pour libérer la mémoire.<br><br>" +

                        "7. <b>Activez l'historique du presse-papiers</b><br>" +
                        "<b>Comment l'utiliser :</b> Appuyez sur Windows + V, activez l'option si nécessaire. Vous verrez tous vos éléments copiés.<br>" +
                        "<b>Pratique :</b> Copiez et collez des éléments sans perdre les anciens.<br><br>" +

                        "8. <b>Créer des points de restauration avant d'installer un logiciel risqué</b><br>" +
                        "<b>Comment l'utiliser :</b> Accédez à Panneau de configuration > Système > Protection du système, puis cliquez sur Créer.<br>" +
                        "<b>Pratique :</b> Restaurez votre système à un état précédent en cas de problème.<br><br>" +

                        "9. <b>Réinitialisez les paramètres réseau avec netsh winsock reset</b><br>" +
                        "<b>Comment l'utiliser :</b> Ouvrez Invite de commande en mode administrateur, tapez la commande, puis redémarrez votre PC.<br>" +
                        "<b>Pratique :</b> Résolvez les problèmes de connexion lente ou instable.<br><br>" +

                        "10. <b>Désactivez les applications inutiles au démarrage via le Gestionnaire des tâches</b><br>" +
                        "<b>Comment l'utiliser :</b> Dans le Gestionnaire des tâches, cliquez sur l'onglet Démarrage, sélectionnez une application, et cliquez sur \"Désactiver\".<br>" +
                        "<b>Pratique :</b> Accélérez le démarrage de votre PC.<br><br>" +

                        "11. <b>Microsoft Word</b><br>" +
                        "Utilisez les styles prédéfinis pour uniformiser vos documents<br>" +
                        "<b>Comment l'utiliser :</b> Dans l'onglet Accueil, appliquez les styles prédéfinis (Titre 1, Sous-titre, etc.) à vos paragraphes.<br>" +
                        "<b>Pratique :</b> Facilitez la création de tables des matières et l'uniformité du format.<br><br>" +

                        "12. <b>Maîtrisez les raccourcis clavier</b><br>" +
                        "<b>Comment l'utiliser :</b> Apprenez des raccourcis utiles comme Ctrl + S (enregistrer), Ctrl + Z (annuler).<br>" +
                        "<b>Pratique :</b> Travaillez plus rapidement sans utiliser la souris.<br><br>" +

                        "13. Activez la correction automatique pour corriger vos erreurs rapidement<br>" +
                        "<b>Comment l'utiliser :</b> Allez dans Fichier > Options > Vérification > Options de correction automatique. Activez les règles nécessaires.<br>" +
                        "<b>Pratique :</b> Corrigez automatiquement les fautes fréquentes.<br><br>" +

                        "14. <b>Créez des tableaux avec Alt + Maj + Flèche</b><br>" +
                        "<b>Comment l'utiliser :</b> Insérez un tableau, appuyez sur Alt + Maj + Flèche pour ajouter ou supprimer des colonnes ou des lignes.<br>" +
                        "<b>Pratique :</b> Modifiez rapidement un tableau sans utiliser la souris.<br><br>" +

                        "15. <b>Utilisez le mode navigation pour parcourir rapidement un long document</b><br>" +
                        "<b>Comment l'utiliser :</b> Appuyez sur Ctrl + F pour ouvrir le volet de navigation, puis recherchez un mot-clé ou une section.<br>" +
                        "<b>Pratique :</b> Gagnez du temps sur les documents longs.<br><br>" +

                        "16. <b>Microsoft Excel</b><br>" +
                        "Créer des tableaux croisés dynamiques<br>" +
                        "<b>Comment l'utiliser :</b> Sélectionnez vos données, cliquez sur Insertion > Tableau croisé dynamique et configurez vos champs.<br>" +
                        "<b>Pratique :</b> Analysez facilement des données complexes.<br><br>" +

                        "17. <b>Utilisez la formule VLOOKUP ou XLOOKUP</b><br>" +
                        "<b>Comment l'utiliser :</b> Tapez une formule comme =RECHERCHEV(A2,Tableau,2,Faux) pour trouver une valeur correspondante.<br>" +
                        "<b>Pratique :</b> Cherchez rapidement des informations dans un tableau.<br><br>" +

                        "18. <b>Mettez en surbrillance les données avec la mise en forme conditionnelle</b><br>" +
                        "<b>Comment l'utiliser :</b> Sélectionnez vos données, cliquez sur Accueil > Mise en forme conditionnelle et choisissez une règle (exemple : valeurs supérieures à 100).<br>" +
                        "<b>Pratique :</b> Faites ressortir les informations importantes.<br><br>" +

                        "19. <b>Bloquez les lignes ou colonnes avec l'option \"Figer les volets\"</b><br>" +
                        "<b>Comment l'utiliser :</b> Allez dans Affichage > Figer les volets pour fixer les titres visibles.<br>" +
                        "<b>Pratique :</b> Travaillez sur de grandes feuilles tout en gardant les en-têtes visibles.<br><br>" +

                        "20. <b>Insérez rapidement la date actuelle avec Ctrl + ;</b><br>" +
                        "<b>Comment l'utiliser :</b> Placez votre curseur dans une cellule et appuyez sur le raccourci Ctrl + ;.<br>" +
                        "<b>Pratique :</b> Gagnez du temps en ajoutant la date du jour."
        );

        listContent.put("Liste d'astuces et d'outils pratiques peu connus", "<b>Applications mobiles utiles</b><br><br>" +
                "1. <b>Radio Garden</b> : Écoutez des radios du monde entier en temps réel, simplement en naviguant sur une carte interactive.<br>" +
                "2. <b>Windy</b> : Suivez les prévisions détaillées avec des animations précises de vents, températures et poussées.<br>" +
                "3. <b>Speed Test (Ookla)</b> : Testez instantanément la vitesse de votre connexion Internet pour repérer les éventuelles lenteurs.<br>" +
                "4. <b>Forest</b> : Améliorez votre concentration en \"plantant des arbres\" numériques pendant que vous restez concentré et éloignez-vous de votre téléphone.<br>" +
                "5. <b>CamScanner</b> : Transformez votre smartphone en scanner pour numériser des documents en PDF ou en images de haute qualité.<br>" +
                "6. <b>IFTTT (If This Then That)</b> : Automatisez les tâches entre différentes applications (par exemple, sauvegardez automatiquement les photos sur Google Drive).<br>" +
                "7. <b>Google Lens</b> : Prenez une photo pour identifier des objets, traduire des textes ou rechercher des informations instantanément.<br>" +
                "8. <b>Shazam</b> : Découvrez rapidement des chansons ou musiques en cours de lecture.<br>" +
                "9. <b>Sleep Cycle</b> : Analysez vos cycles de sommeil pour vous réveiller au moment optimal.<br>" +
                "10. <b>Stellarium</b> : Explorez le ciel étoilé et identifiez les constellations en pointant votre téléphone vers le ciel.<br><br>" +

                "<b>Logiciels ou outils pratiques pour ordinateur</b><br>" +
                "1. <b>Tout</b> : Un moteur de recherche ultra-rapide pour trouver des fichiers ou dossiers spécifiques sur votre PC.<br>" +
                "2. <b>ShareX</b> : Un outil puissant pour capturer des captures d'écran, enregistrer des vidéos de l'écran ou annoter des images.<br>" +
                "3. <b>SpaceSniffer</b> : Visualisez l'utilisation de l'espace disque sur votre ordinateur sous forme de diagramme pour localiser rapidement les fichiers volumineux.<br>" +
                "4. <b>Ninite</b> : Installer ou mettre à jour plusieurs applications en une seule fois sans accepter d'options publicitaires.<br>" +
                "5. <b>Greenshot</b> : Prenez des captures d'écran personnalisées avec des outils d'édition simples et rapides.<br>" +
                "6. <b>Idem</b> : Un gestionnaire de presse-papiers pour enregistrer et réutiliser plusieurs copies dans l'historique.<br>" +
                "7. <b>Notion</b> : Gérez vos tâches, notes et projets de manière collaborative ou personnelle.<br>" +
                "8. <b>AutoHotkey</b> : Créez des scripts pour automatiser des tâches répétitives (comme des raccourcis clavier personnalisés).<br>" +
                "9. <b>TreeSize</b> : Analysez la taille des dossiers sur votre disque dur pour libérer de l'espace rapidement.<br>" +
                "10. <b>KeePass</b> : Gérez tous vos mots de passe de manière sécurisée dans un coffre-fort crypté.<br><br>" +

                "<b>Sites et outils en ligne méconnus</b><br><br>" +
                "1. <b>Canva</b> : Créez facilement des designs graphiques (affiches, présentations, vidéos) sans compétences avancées en design.<br>" +
                "2. <b>DownSub</b> : Téléchargez les sous-titres de vidéos YouTube ou autres plateformes en ligne.<br>" +
                "3. <b>Photopea</b> : Une alternative gratuite et en ligne à Photoshop, compatible avec les fichiers PSD.<br>" +
                "4. <b>Zamzar</b> : Convertissez vos fichiers (audio, vidéo, images, documents) en d'autres formats sans logiciel.<br>" +
                "5. <b>TinyWow</b> : Outil multifonction pour éditer, convertir ou compresser des fichiers directement depuis votre navigateur.<br>" +
                "6. <b>Remove.bg</b> : Supprimer automatiquement l'arrière-plan des photos en un clic.<br>" +
                "7. <b>Linguee</b> : Traduisez des textes tout en accédant à des exemples concrets d'utilisation des mots dans des contextes variés.<br>" +
                "8. <b>Simply Piano</b> : Apprenez à jouer du piano grâce à une application interactive et intuitive.<br>" +
                "9. <b>CloudConvert</b> : Un outil tout-en-un pour convertir presque tous les formats de fichiers.<br>" +
                "10. <b>PrivNote</b> : Envoyez des notes qui s'autodétruisent après lecture pour des communications confidentielles.<br><br>" +

                "<b>Fonctionnalités peu connues sur Windows</b><br><br>" +
                "1. <b>Windows God Mode</b> : Activez un dossier spécial regroupant toutes les options de paramétrage Windows en créant un dossier nommé GodMode.{ED7BA470-8E54-465E-825C-99712043E01C}.<br>" +
                "2. <b>Snipping Tool (Windows)</b> : Utilisez cet outil intégré pour capturer, annoter et partager rapidement des portions d'écran.<br>" +
                "3. <b>Clavier Emoji (Windows)</b> : Activez le clavier des émojis sur Windows avec Win + .pour les insérer facilement dans vos messages.<br>" +
                "4. <b>Bureaux virtuels</b> : Créez plusieurs bureaux virtuels sur Windows avec Win + Tabpour mieux organiser vos tâches.<br><br>" +

                "<b>Astuces générales</b><br><br>" +
                "1. <b>Recherche avancée sur Google</b> : Utilisez des opérateurs comme site:example.com, filetype:pdf ou des guillemets pour des recherches ciblées.<br>" +
                "2. <b>Télécharger des vidéos YouTube sans logiciel</b> : Ajoutez \"ss\" avant \"youtube\" dans l'URL pour accéder à un téléchargeur.<br>" +
                "3. <b>Nettoyage de votre PC</b> : Utilisez Win + R puis tapez temp, %temp%, ou prefetch pour supprimer des fichiers temporaires.<br>" +
                "4. <b>Créez un hotspot Wi-Fi avec votre PC</b> : Activez l'option via le panneau de configuration ou avec une commande PowerShell.<br>"
                );



    }
}
