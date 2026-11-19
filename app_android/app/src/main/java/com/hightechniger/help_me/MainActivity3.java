package com.hightechniger.help_me;

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

public class MainActivity3 extends AppCompatActivity {

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
        setContentView(R.layout.activity_main3);


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
        // Vidéos remplacées provisoirement par les illustrations du manuel
        ImageLessonActivity.open(this, resId);
    }


    private void displayContent(String selected) {
        String content = listContent.get(selected);
        if (content != null) {
            Intent intent = new Intent(MainActivity3.this, DetailActivity.class);
            intent.putExtra("title", selected);
            intent.putExtra("content", content);
            startActivity(intent);
        }
    }

    private int getVideoResourceId(String code) {
        switch (code) {

            case "Curriculum vitae (CV)":
                // Lance l'activité MainActivity10
                Intent intent1 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent1);
                return 0;

            case "Lettre de motivation":
                // Lance l'activité MainActivity10
                Intent intent2 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent2);
                return 0;

            case "Portfolio professionnel":
                // Lance l'activité MainActivity10
                Intent intent3 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent3);
                return 0;

            case "Facture professionnelle":
                // Lance l'activité MainActivity10
                Intent intent4 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent4);
                return 0;

            case "Devis":
                // Lance l'activité MainActivity10
                Intent intent5 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent5);
                return 0;

            case "Rapport financier":
                // Lance l'activité MainActivity10
                Intent intent6 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent6);
                return 0;

            case "Formulaire d'inscription":
                // Lance l'activité MainActivity10
                Intent intent7 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent7);
                return 0;

            case "Compte-rendu de réunion":
                // Lance l'activité MainActivity10
                Intent intent8 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent8);
                return 0;

            case "Politique d'entreprise":
                // Lance l'activité MainActivity10
                Intent intent9 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent9);
                return 0;

            case "Bulletin d'information":
                // Lance l'activité MainActivity10
                Intent intent10 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent10);
                return 0;

            case "Attestation de travail":
                // Lance l'activité MainActivity10
                Intent intent11 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent11);
                return 0;

            case "Certificat ou attestation":
                // Lance l'activité MainActivity10
                Intent intent12 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent12);
                return 0;

            case "Affiche publicitaire":
                // Lance l'activité MainActivity10
                Intent intent13 = new Intent(MainActivity3.this, MainActivity10.class);
                startActivity(intent13);
                return 0;

            case "MANUEL":

                Intent intent09 = new Intent(MainActivity3.this, MainActivity6.class);
                startActivity(intent09);
                return 0;
            case "Saisir du texte":
                return MediaImages.image(this, "vid7");

            case "Modifier les paragraphes":
                return MediaImages.image(this, "vid8");
            case "Puces et numéros":
                return MediaImages.image(this, "puce");

            case "Bordures et trames":
                return MediaImages.image(this, "bordure");

            case "Rechercher, remplacer et enregistrer":
                return MediaImages.image(this, "ramplacer");

            case "Insérer des pages et des tableaux":
                return MediaImages.image(this, "pagegarde");

            case "Insérer des images et des formes":
                return MediaImages.image(this, "formeimage");

            case "En-têtes, pieds de page, numéros et équations":
                return MediaImages.image(this, "entete");


            case "Filigrane et couleurs":
                return MediaImages.image(this, "filigranevid");


            case "Mise en page (Créer des sections)":
                return MediaImages.image(this, "sectionvid");


            case "Table des matières":
                return MediaImages.image(this, "tablematiere");


            case "Protection de document":
                return MediaImages.image(this, "motsdepassewordvid");

            case "Impression":
                return MediaImages.image(this, "impression");


            default:
                return 0; // Par défaut, retourne 0 si aucune correspondance
        }
    }

    /**
     * Initialise les données pour l'ExpandableListView.
     */
    private void initListData() {
        listGroup.add("  WORD");
        listGroup.add("  ASTUCES");
        listGroup.add("  MODELES");
        listGroup.add("  REVISION");

        List<String> WORD = new ArrayList<>();
        WORD.add("Saisir du texte");
        WORD.add("Modifier les paragraphes");
        WORD.add("Puces et numéros");
        WORD.add("Bordures et trames");
        WORD.add("Rechercher, remplacer et enregistrer");
        WORD.add("Insérer des pages et des tableaux");
        WORD.add("Insérer des images et des formes");
        WORD.add("En-têtes, pieds de page, numéros et équations");
        WORD.add("Filigrane et couleurs");
        WORD.add("Mise en page");
        WORD.add("Table des matières");
        WORD.add("Protection de document");
        WORD.add("Impression");




        List<String> astuces = new ArrayList<>();
        astuces.add("10 raccourcis clavier indispensables sur Word");
        astuces.add("Créer des styles personnalisés pour vos documents");
        astuces.add("Insérer une table des matières automatiquement");
        astuces.add("Aligner et positionner des images facilement");
        astuces.add("Utiliser le mode Révision pour collaborer efficacement");
        astuces.add("Ajouter des filigranes personnalisés à vos documents");
        astuces.add("Numéroter les pages de manière avancée");
        astuces.add("Créer et modifier rapidement des tableaux");
        astuces.add("Ajouter des hyperliens pour naviguer rapidement");
        astuces.add("Transformer vos fichiers Word en PDF sans effort");


        List<String> MODELES = new ArrayList<>();
        MODELES.add("Curriculum vitae (CV)");
        MODELES.add("Lettre de motivation");
        MODELES.add("Portfolio professionnel");
        MODELES.add("Facture professionnelle");
        MODELES.add("Devis");
        MODELES.add("Rapport financier");
        MODELES.add("Formulaire d'inscription");
        MODELES.add("Compte-rendu de réunion");
        MODELES.add("Politique d'entreprise");
        MODELES.add("Bulletin d'information");
        MODELES.add("Attestation de travail");
        MODELES.add("Certificat ou attestation");
        MODELES.add("Affiche publicitaire");


        List<String> revision = new ArrayList<>();
        revision.add("MANUEL");

        listItem.put(listGroup.get(0), WORD);
        listItem.put(listGroup.get(1), astuces);
        listItem.put(listGroup.get(2), MODELES);
        listItem.put(listGroup.get(3), revision);
        listContent.put("10 raccourcis clavier indispensables sur Word",
                        "<b>Raccourcis de base :</b><br>" +
                        "<b>Ctrl + N</b> : Créer un nouveau document.<br>" +
                        "<b>Ctrl + O</b> : Ouvrir un document existant.<br>" +
                        "<b>Ctrl + S</b> : Enregistrer le document.<br><br>" +
                        "<b>Raccourcis pour formater le texte :</b><br>" +
                        "<b>Ctrl + B</b> : Mettre le texte en gras (<i>Bold</i>).<br>" +
                        "<b>Ctrl + I</b> : Mettre le texte en italique (<i>Italic</i>).<br>" +
                        "<b>Ctrl + U</b> : Souligner le texte (<i>Underline</i>).<br><br>" +
                        "<b>Raccourcis pour manipuler le texte :</b><br>" +
                        "<b>Ctrl + C</b> : Copier une sélection.<br>" +
                        "<b>Ctrl + V</b> : Coller une sélection.<br>" +
                        "<b>Ctrl + X</b> : Couper une sélection.<br><br>" +
                        "<b>Navigation rapide :</b><br>" +
                        "<b>Ctrl + Z</b> : Annuler la dernière action.<br><br>" +
                        "Ces raccourcis vous permettront de travailler plus rapidement et d'améliorer votre productivité.");

        listContent.put("Créer des styles personnalisés pour vos documents",
                "Créer des styles personnalisés dans Word permet de donner un aspect professionnel à vos documents.<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Accédez à l'onglet <b>Accueil</b>.<br>" +
                        "2. Cliquez sur <b>Styles</b> et sélectionnez <b>Nouveau style</b>.<br>" +
                        "3. Donnez un nom à votre style et personnalisez les paramètres (police, taille, alignement, etc.).<br>" +
                        "4. Cliquez sur <b>OK</b> pour enregistrer votre style.<br><br>" +
                        "Une fois créé, appliquez le style à tout moment en le sélectionnant dans le menu des styles.");

        listContent.put("Insérer une table des matières automatiquement",
                "Ajouter une table des matières automatique rend votre document structuré et facile à naviguer.<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Assurez-vous que vos titres utilisent les styles prédéfinis (Titre 1, Titre 2, etc.).<br>" +
                        "2. Placez le curseur où vous souhaitez insérer la table des matières.<br>" +
                        "3. Accédez à l'onglet <b>Références</b> et cliquez sur <b>Table des matières</b>.<br>" +
                        "4. Choisissez un format prédéfini ou personnalisez votre table.<br><br>" +
                        "Mettez à jour la table en cliquant dessus et en sélectionnant <b>Mettre à jour les champs</b>.");

        listContent.put("Aligner et positionner des images facilement",
                "Pour aligner et positionner des images dans Word :<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Insérez votre image via l'onglet <b>Insertion</b> > <b>Images</b>.<br>" +
                        "2. Cliquez sur l'image et accédez à <b>Options de disposition</b>.<br>" +
                        "3. Sélectionnez l'option d'habillage du texte (Aligné, Carré, Derrière le texte, etc.).<br>" +
                        "4. Glissez-déposez l'image pour la positionner où vous le souhaitez.<br><br>" +
                        "Vous pouvez également utiliser les options de mise en forme pour ajuster la taille et les bordures.");

        listContent.put("Utiliser le mode Révision pour collaborer efficacement",
                "Le mode Révision de Word permet de suivre les modifications et de collaborer avec d'autres utilisateurs.<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Accédez à l'onglet <b>Révision</b>.<br>" +
                        "2. Activez <b>Suivi des modifications</b>.<br>" +
                        "3. Toute modification apparaîtra en couleur, indiquant qui l'a faite.<br>" +
                        "4. Ajoutez des commentaires en sélectionnant un texte et en cliquant sur <b>Nouveau commentaire</b>.<br><br>" +
                        "Ce mode est idéal pour les projets collaboratifs et les retours d'équipe.");

        listContent.put("Ajouter des filigranes personnalisés à vos documents",
                "Les filigranes personnalisés donnent un aspect unique à vos documents Word.<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Allez dans l'onglet <b>Conception</b>.<br>" +
                        "2. Cliquez sur <b>Filigrane</b> et sélectionnez <b>Filigrane personnalisé</b>.<br>" +
                        "3. Choisissez entre un texte ou une image comme filigrane.<br>" +
                        "4. Personnalisez la transparence, l'alignement, et la taille.<br>" +
                        "5. Cliquez sur <b>Appliquer</b> pour enregistrer vos modifications.");

        listContent.put("Numéroter les pages de manière avancée",
                "Pour une numérotation avancée dans Word :<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Accédez à l'onglet <b>Insertion</b> > <b>Numéro de page</b>.<br>" +
                        "2. Sélectionnez l'emplacement (en haut, en bas, ou dans les marges).<br>" +
                        "3. Pour une numérotation différente dans chaque section, insérez des sauts de section (onglet <b>Disposition</b>).<br>" +
                        "4. Décochez <b>Lier au précédent</b> dans l'onglet <b>En-tête et pied de page</b>.<br>" +
                        "5. Appliquez un style ou une numérotation personnalisée via <b>Format des numéros de page</b>.");

        listContent.put("Créer et modifier rapidement des tableaux",
                "Les tableaux permettent d'organiser vos données efficacement dans Word.<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Allez dans l'onglet <b>Insertion</b> et cliquez sur <b>Tableau</b>.<br>" +
                        "2. Dessinez ou insérez un tableau avec le nombre de lignes et colonnes désirées.<br>" +
                        "3. Personnalisez le style via l'onglet <b>Outils de tableau</b> (couleurs, bordures, etc.).<br>" +
                        "4. Ajoutez ou supprimez des lignes/colonnes avec un clic droit sur le tableau.<br><br>" +
                        "Les tableaux peuvent être copiés et collés dans d'autres logiciels comme Excel.");

        listContent.put("Ajouter des hyperliens pour naviguer rapidement",
                "Les hyperliens permettent de créer des liens directs vers des pages web, des documents ou des sections spécifiques.<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Sélectionnez le texte ou l'image à transformer en lien.<br>" +
                        "2. Cliquez avec le bouton droit et sélectionnez <b>Lien hypertexte</b>.<br>" +
                        "3. Entrez l'URL ou sélectionnez une destination dans le document.<br>" +
                        "4. Cliquez sur <b>OK</b> pour insérer le lien.<br><br>" +
                        "Testez vos liens en cliquant dessus tout en maintenant la touche Ctrl.");

        listContent.put("Transformer vos fichiers Word en PDF sans effort",
                "Convertir un fichier Word en PDF est simple et rapide :<br><br>" +
                        "<b>Étapes :</b><br>" +
                        "1. Ouvrez votre document Word.<br>" +
                        "2. Allez dans <b>Fichier</b> > <b>Enregistrer sous</b>.<br>" +
                        "3. Choisissez le format <b>PDF</b> dans le menu déroulant.<br>" +
                        "4. Sélectionnez l'emplacement et cliquez sur <b>Enregistrer</b>.<br><br>" +
                        "Votre fichier est maintenant prêt à être partagé ou imprimé.<br><br>" +
                        "<b>Bonus :</b> Voici comment transformer un fichier PDF en document Word :<br><br>" +
                        "1. Ouvrez votre fichier PDF avec <b>Adobe Acrobat Reader</b> ou un autre lecteur PDF compatible.<br>" +
                        "2. Cliquez sur <b>Fichier</b> > <b>Exporter vers</b> > <b>Microsoft Word</b>.<br>" +
                        "3. Sélectionnez <b>Document Word</b> comme format de sortie.<br>" +
                        "4. Enregistrez le fichier converti dans l'emplacement souhaité.<br><br>" +
                        "Vous pouvez également utiliser des outils en ligne gratuits comme <b>Smallpdf</b> ou <b>PDF to DOC</b> pour convertir vos fichiers PDF en documents Word.");














    }


}
