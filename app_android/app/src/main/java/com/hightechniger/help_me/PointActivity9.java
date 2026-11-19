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

public class PointActivity9 extends AppCompatActivity {

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
        setContentView(R.layout.activity_point9);


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
            Intent intent = new Intent(PointActivity9.this, DetailActivity.class);
            intent.putExtra("title", selected);
            intent.putExtra("content", content);
            startActivity(intent);
        }
    }

    private int getVideoResourceId(String code) {
        switch (code) {
            case "Naviguer sur Internet":
                // Lance l'activité MainActivity17
                Intent intent6 = new Intent(PointActivity9.this, MainActivity17.class);
                startActivity(intent6);
                return 0; // Retourne 0 pour indiquer qu'il n'y a pas de ressource vidéo

            case "Présentation professionnelle":
            case "Présentation créative":
            case "Rapport d'entreprise":
            case "Pitch de startup":
            case "Planification de projet":
            case "Présentation académique":
            case "Portfolio créatif":
            case "Réunion d'équipe":

                Intent intent = new Intent(PointActivity9.this, MainActivity10.class);
                startActivity(intent);
                return 0;

            case "MANUEL":

                Intent intent7 = new Intent(PointActivity9.this, MainActivity6.class);
                startActivity(intent7);
                return 0;

            case "Présentation de l'interface de PowerPoint":
                return MediaImages.image(this, "interfacepoint");

            case "Création d’une première présentation":
                return MediaImages.image(this, "pointpresentation");
            case "Manipulation des diapositives":
                return MediaImages.image(this, "pointdiapositiv");

            case "Finalisation et présentation":
                return MediaImages.image(this, "pointfinal");




            default:
                return 0;
        }
    }

    /**
     * Initialise les données pour l'ExpandableListView.
     */
    private void initListData() {
        listGroup.add("  POWERPOINT");
        listGroup.add("  ASTUCES");
        listGroup.add("  MODELES");

        List<String> Powerpoint = new ArrayList<>();
        Powerpoint.add("Présentation de l'interface de PowerPoint");
        Powerpoint.add("Création d’une première présentation");
        Powerpoint.add("Manipulation des diapositives");
        Powerpoint.add("Finalisation et présentation");

        List<String> ASTUCES = new ArrayList<>();
        ASTUCES.add("Astuces pour créer des diapositives percutantes");
        ASTUCES.add("Utiliser des transitions pour dynamiser votre présentation");
        ASTUCES.add("Personnaliser vos animations de texte et d'objets");
        ASTUCES.add("Ajouter des graphiques et des tableaux efficacement");
        ASTUCES.add("Optimiser la mise en page de vos diapositives");
        ASTUCES.add("Utiliser des modèles pour gagner du temps");
        ASTUCES.add("Gérer les diapositives maître pour un design cohérent");
        ASTUCES.add("Insérer des vidéos et des audios dans votre présentation");

        List<String> MODELES = new ArrayList<>();
        MODELES.add("Présentation professionnelle");
        MODELES.add("Présentation créative");
        MODELES.add("Rapport d'entreprise");
        MODELES.add("Pitch de startup");
        MODELES.add("Planification de projet");
        MODELES.add("Présentation académique");
        MODELES.add("Portfolio créatif");
        MODELES.add("Réunion d'équipe");




        listItem.put(listGroup.get(0), Powerpoint);
        listItem.put(listGroup.get(1), ASTUCES);
        listItem.put(listGroup.get(2), MODELES);
        listContent.put("Astuces pour créer des diapositives percutantes",
                "<li>Utilisez la règle des 6x6 : Limitez chaque diapositive à 6 points et chaque point à 6 mots maximum.</li>" +
                        "<li>Choisissez une police adaptée : Privilégiez des polices lisibles comme Arial ou Calibri avec une taille minimale de 28.</li>" +
                        "<li>Ajoutez des images ou icônes pour remplacer le texte lorsque possible.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Créez une diapositive avec un titre comme <b>'Chiffres clés de 2024'</b>. Ajoutez une infographie pour représenter les chiffres au lieu d'une liste de données.</li>"
        );

        listContent.put("Utiliser des transitions pour dynamiser votre présentation",
                "<li>Sélectionnez une diapositive et allez dans l'onglet <b>Transitions</b>.</li>" +
                        "<li>Choisissez un effet comme <b>Fondu</b> ou <b>Balayage</b>.</li>" +
                        "<li>Ajustez la durée de la transition pour qu'elle ne dépasse pas 2 secondes.</li>" +
                        "<li>Appliquez la transition à toutes les diapositives ou individuellement selon vos besoins.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Utilisez <b>Fondu</b> pour les diapositives d’introduction et <b>Balayage</b> pour les diapositives principales.</li>"
        );

        listContent.put("Personnaliser vos animations de texte et d'objets",
                "<li>Sélectionnez un objet ou du texte, puis allez dans l'onglet <b>Animations</b>.</li>" +
                        "<li>Choisissez un effet comme <b>Apparition</b>, <b>Zoom</b>, ou <b>Vol</b>.</li>" +
                        "<li>Utilisez le <b>Volet Animation</b> pour ajuster l’ordre et le timing.</li>" +
                        "<li>Combinez plusieurs animations pour rendre vos présentations dynamiques.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Animer un graphique pour qu'il apparaisse section par section afin d'expliquer chaque partie clairement.</li>"
        );

        listContent.put("Ajouter des graphiques et des tableaux efficacement",
                "<li>Allez dans <b>Insérer</b>, puis choisissez <b>Graphique</b> ou <b>Tableau</b>.</li>" +
                        "<li>Sélectionnez un type adapté comme un diagramme en barres ou en secteurs.</li>" +
                        "<li>Remplissez les données dans le tableau Excel intégré.</li>" +
                        "<li>Personnalisez les couleurs et les étiquettes pour améliorer la lisibilité.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Créez un graphique en secteurs pour représenter les parts de marché de différentes entreprises.</li>"
        );

        listContent.put("Optimiser la mise en page de vos diapositives",
                "<li>Utilisez des <b>modèles préconçus</b> pour un design uniforme.</li>" +
                        "<li>Activez les <b>guides et grilles</b> dans l'onglet <b>Affichage</b> pour aligner les éléments.</li>" +
                        "<li>Préférez des espaces blancs pour aérer vos diapositives et éviter les surcharges.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Créez une diapositive d’introduction avec un titre centré, une image en arrière-plan, et un sous-titre en bas.</li>"
        );

        listContent.put("Utiliser des modèles pour gagner du temps",
                "<li>Allez dans <b>Fichier</b> > <b>Nouveau</b>, puis choisissez un modèle disponible.</li>" +
                        "<li>Téléchargez des modèles externes depuis des plateformes comme Canva ou SlidesCarnival.</li>" +
                        "<li>Personnalisez les couleurs, le logo et le contenu pour refléter votre identité visuelle.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Téléchargez un modèle minimaliste pour une réunion d’entreprise et modifiez les couleurs selon votre charte graphique.</li>"
        );

        listContent.put("Gérer les diapositives maître pour un design cohérent",
                "<li>Allez dans l'onglet <b>Affichage</b>, puis cliquez sur <b>Masque des diapositives</b>.</li>" +
                        "<li>Modifiez la diapositive maître en ajustant les polices, couleurs, et mises en page.</li>" +
                        "<li>Enregistrez les modifications pour qu’elles s’appliquent à toutes les diapositives.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Ajoutez le logo de votre entreprise dans le masque pour qu’il apparaisse sur toutes les diapositives automatiquement.</li>"
        );

        listContent.put("Insérer des vidéos et des audios dans votre présentation",
                "<li>Cliquez sur <b>Insérer</b>, puis choisissez <b>Vidéo</b> ou <b>Audio</b>.</li>" +
                        "<li>Insérez un fichier de votre ordinateur ou un lien YouTube.</li>" +
                        "<li>Ajustez la taille et la position des médias sur la diapositive.</li>" +
                        "<li>Activez l’option <b>Lecture automatique</b> ou <b>Lecture au clic</b>.</li>" +
                        "<lu>Cas pratique :</lu>" +
                        "<li>Ajoutez une vidéo tutorielle dans une présentation de formation pour engager votre audience.</li>"
        );



    }

    @Override
    protected void onDestroy() {
        super.onDestroy();

        // Retirer le drapeau pour permettre à l'écran de s'éteindre si nécessaire
        getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
    }
}
