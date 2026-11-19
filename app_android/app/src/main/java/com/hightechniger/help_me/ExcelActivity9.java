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

public class ExcelActivity9 extends AppCompatActivity {

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
        setContentView(R.layout.activity_excel9);

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
            Intent intent = new Intent(ExcelActivity9.this, DetailActivity.class);
            intent.putExtra("title", selected);
            intent.putExtra("content", content);
            startActivity(intent);
        }
    }

    private int getVideoResourceId(String code) {
        switch (code) {
            case "Naviguer sur Internet":
                // Lance l'activité MainActivity17
                Intent intent6 = new Intent(ExcelActivity9.this, MainActivity17.class);
                startActivity(intent6);
                return 0; // Retourne 0 pour indiquer qu'il n'y a pas de ressource vidéo


            case "Facture professionnelle":
            case "Liste des participants à un événement":
            case "Base de données simplifiée":
            case "Calendrier ou emploi du temps":
            case "Inventaire de stock":
            case "Suivi des ventes":
            case "Feuille de calcul pour la paie":
            case "Tableau de suivi des dépenses":
            case "Graphiques de performances":
            case "Gestion des commandes":
            case "Tableau de bord interactif":

                Intent intent = new Intent(ExcelActivity9.this, MainActivity10.class);
                startActivity(intent);
                return 0;
            case "MANUEL":

                Intent intent7 = new Intent(ExcelActivity9.this, MainActivity6.class);
                startActivity(intent7);
                return 0;

            case "Saisie des données":
                return MediaImages.image(this, "saisirdonnee");

            case "Mise en forme des données":
                return MediaImages.image(this, "copieexcel");
            case "Formules et fonctions":
                return MediaImages.image(this, "formuleexcel");

            case "Tableaux croisés dynamiques":
                return MediaImages.image(this, "croiseexcel");

            case "Tableaux Excel":
                return MediaImages.image(this, "tableauexcel");

            case "Ajouter un graphique":
                return MediaImages.image(this, "graphiqueexcel");

            case "Impression et protection":
                return MediaImages.image(this, "protectionetmotsexcel");



            default:
                return 0; // Par défaut, retourne 0 si aucune correspondance
        }
    }


    private void initListData() {
        listGroup.add("  EXCEL");
        listGroup.add("  ASTUCES");
        listGroup.add("  MODELES");
        listGroup.add("  ANNALYSE");
        listGroup.add("  REVISION");

        List<String> EXCEL = new ArrayList<>();
        EXCEL.add("Saisie des données");
        EXCEL.add("Mise en forme des données");
        EXCEL.add("Formules et fonctions");
        EXCEL.add("Tableaux Excel");
        EXCEL.add("Tableaux croisés dynamiques");
        EXCEL.add("Ajouter un graphique");
        EXCEL.add("Impression et protection");



        List<String> ASTUCES = new ArrayList<>();
        ASTUCES.add("Utiliser les raccourcis clavier");
        ASTUCES.add("Utiliser la fonction 'RECHERCHEV'");
        ASTUCES.add("Formater les cellules");
        ASTUCES.add("Utiliser les filtres automatiques");
        ASTUCES.add("Protéger les cellules sensibles");
        ASTUCES.add("Utiliser 'SOMME.SI' pour des calculs conditionnels");
        ASTUCES.add("Créer des graphiques dynamiques");
        ASTUCES.add("Utiliser la validation des données");


        List<String> MODELES = new ArrayList<>();
        MODELES.add("Facture professionnelle");
        MODELES.add("Liste des participants à un événement");
        MODELES.add("Base de données simplifiée");
        MODELES.add("Calendrier ou emploi du temps");
        MODELES.add("Inventaire de stock");
        MODELES.add("Suivi des ventes");
        MODELES.add("Feuille de calcul pour la paie");
        MODELES.add("Tableau de suivi des dépenses");
        MODELES.add("Graphiques de performances");
        MODELES.add("Gestion des commandes");
        MODELES.add("Tableau de bord interactif");


        List<String> ANNALYSE = new ArrayList<>();
        ANNALYSE.add("DONNEES COMPLEXES");

        List<String> REVISION = new ArrayList<>();
        REVISION.add("MANUEL");


        listItem.put(listGroup.get(0), EXCEL);
        listItem.put(listGroup.get(1), ASTUCES);
        listItem.put(listGroup.get(2), MODELES);
        listItem.put(listGroup.get(3), ANNALYSE);
        listItem.put(listGroup.get(4), REVISION);


// Astuce 1: Utiliser les raccourcis clavier sur Excel
        listContent.put("Utiliser les raccourcis clavier",
                "<br>Les raccourcis clavier sont essentiels pour gagner du temps et travailler plus efficacement sur Excel.<br>" +
                        "<b>Ctrl + C</b> : Copier les données sélectionnées<br>" +
                        "<b>Ctrl + V</b> : Coller les données copiées<br>" +
                        "<b>Ctrl + Z</b> : Annuler la dernière action<br>" +
                        "<b>Ctrl + S</b> : Sauvegarder le fichier<br>" +
                        "<b>Ctrl + P</b> : Imprimer le document<br>" +
                        "<b>Ctrl + A</b> : Sélectionner toutes les cellules<br>" +
                        "<b>Ctrl + F</b> : Ouvrir la fonction de recherche<br><br>" +
                        "L'utilisation de ces raccourcis rendra votre travail plus rapide et vous évitera d'avoir à naviguer dans les menus à chaque fois.");

// Astuce 2: Utiliser la fonction 'RECHERCHEV'
        listContent.put("Utiliser la fonction 'RECHERCHEV'",
                "<br>La fonction 'RECHERCHEV' (VLOOKUP en anglais) est utilisée pour rechercher une valeur dans une colonne d'une table et retourner une valeur dans une autre colonne.<br>" +
                        "Syntaxe : <i>RECHERCHEV(valeur_cherchée, table_array, no_index_col, [valeur_approximation])</i><br>" +
                        "Exemple : <b>RECHERCHEV(A2, B2:D10, 3, FAUX)</b> cherche la valeur dans la cellule A2 dans la plage B2:D10, et renvoie la valeur de la 3e colonne de la plage.<br><br>" +
                        "Assurez-vous que la première colonne de la plage de recherche contient les données triées si vous utilisez la recherche approximative.");

// Astuce 3: Formater les cellules
        listContent.put("Formater les cellules",
                "<br>Le formatage des cellules permet de rendre vos données plus lisibles et organisées. Voici quelques options courantes :<br>" +
                        "<b>Changer le format numérique</b> : Sélectionnez les cellules, faites un clic droit et choisissez 'Format de cellule'. Vous pouvez choisir entre des formats comme 'Monétaire', 'Date', 'Pourcentage', etc.<br>" +
                        "<b>Utiliser des couleurs de fond</b> : Utilisez des couleurs de fond pour distinguer différentes sections de votre tableau et améliorer la lisibilité.<br>" +
                        "<b>Appliquer des bordures</b> : Ajoutez des bordures aux cellules pour les délimiter clairement.<br><br>" +
                        "Ces options vous aident à organiser et à rendre vos données plus compréhensibles.");

// Astuce 4: Utiliser les filtres automatiques
        listContent.put("Utiliser les filtres automatiques",
                "<br>Les filtres automatiques vous permettent de trier et de filtrer rapidement les données dans vos tableaux Excel.<br>" +
                        "Pour activer les filtres :<br>" +
                        "<ol><li>Sélectionnez les colonnes où vous souhaitez appliquer les filtres.</li>" +
                        "<li>Allez dans l'onglet 'Données' et cliquez sur 'Filtrer'.</li>" +
                        "<li>Des flèches apparaîtront dans les en-têtes de colonne, vous permettant de filtrer les données en fonction de critères spécifiques.</li></ol><br>" +
                        "Les filtres permettent de réduire la complexité et de trouver rapidement des informations spécifiques.");

// Astuce 5: Protéger les cellules sensibles
        listContent.put("Protéger les cellules sensibles",
                "<br>Pour empêcher les utilisateurs non autorisés de modifier certaines parties de votre feuille de calcul, vous pouvez protéger des cellules spécifiques.<br>" +
                        "Voici comment faire :<br>" +
                        "<ol><li>Sélectionnez les cellules à protéger.</li>" +
                        "<li>Cliquez sur 'Format de cellule' puis allez dans l'onglet 'Protection' et cochez 'Verrouillée'.</li>" +
                        "<li>Ensuite, activez la protection de la feuille en allant dans l'onglet 'Révision' et en cliquant sur 'Protéger la feuille'.</li></ol><br>" +
                        "Cela permet de sécuriser certaines données et d'éviter les modifications accidentelles.");

// Astuce 6: Utiliser 'SOMME.SI' pour des calculs conditionnels
        listContent.put("Utiliser 'SOMME.SI' pour des calculs conditionnels",
                "<br>La fonction 'SOMME.SI' permet d'effectuer une somme en fonction d'une condition spécifiée.<br>" +
                        "Syntaxe : <i>SOMME.SI(plage, critère, [plage_somme])</i><br>" +
                        "Exemple : <b>SOMME.SI(A2:A10, \">10\", B2:B10)</b> additionne les valeurs de B2:B10 uniquement si les valeurs correspondantes dans A2:A10 sont supérieures à 10.<br><br>" +
                        "Cela permet de faire des calculs basés sur des critères spécifiques.");

// Astuce 7: Créer des graphiques dynamiques
        listContent.put("Créer des graphiques dynamiques",
                "<br>Les graphiques dynamiques permettent de visualiser facilement les tendances de vos données.<br>" +
                        "Pour créer un graphique dynamique :<br>" +
                        "<ol><li>Sélectionnez vos données.</li>" +
                        "<li>Allez dans l'onglet 'Insertion' et choisissez un type de graphique (par exemple, histogramme ou courbe).</li>" +
                        "<li>Le graphique sera créé et lié à vos données, et se mettra à jour automatiquement lorsque vous modifiez les valeurs.</li></ol><br>" +
                        "Les graphiques dynamiques permettent d'analyser rapidement des données en évolution.");

// Astuce 8: Utiliser la validation des données
        listContent.put("Utiliser la validation des données",
                "<br>La validation des données permet de contrôler les entrées dans les cellules, garantissant qu'elles respectent des critères spécifiques.<br>" +
                        "Pour utiliser la validation des données :<br>" +
                        "<ol><li>Sélectionnez la cellule ou la plage de cellules à valider.</li>" +
                        "<li>Allez dans 'Données' > 'Validation des données'.</li>" +
                        "<li>Définissez les critères (par exemple, limite de nombre ou liste déroulante).</li></ol><br>" +
                        "Cela garantit que seules des données valides sont saisies dans votre feuille de calcul.");

        listContent.put("DONNEES COMPLEXES",
                "<b>1. Power Query</b><br>" +
                        "• Permet d'importer, transformer et combiner des données provenant de différentes sources (fichiers, bases de données, web, etc.).<br>" +
                        "• Idéal pour automatiser le nettoyage et la préparation des données.<br>" +
                        "• Offre une interface simple pour les requêtes ETL (Extraction, Transformation, Chargement).<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Ouvrez Excel, cliquez sur l’onglet Données, puis sélectionnez Obtenir et transformer des données.<br>" +
                        "• Choisissez une source de données et importez-les.<br>" +
                        "• Utilisez l’éditeur Power Query pour nettoyer et transformer vos données.<br>" +
                        "• Appliquez les modifications et chargez les données dans Excel.<br>" +
                        "<b>Activation :</b> Power Query est activé par défaut dans les versions récentes d’Excel.<br><br>" +

                        "<b>2. Power Pivot</b><br>" +
                        "• Permet de gérer de grandes quantités de données dans un modèle de données intégré.<br>" +
                        "• Supporte les relations entre tables et l’analyse multidimensionnelle.<br>" +
                        "• Utilise des formules DAX pour créer des calculs complexes.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Activez Power Pivot via Fichier > Options > Compléments > Compléments COM > Power Pivot pour Excel.<br>" +
                        "• Ajoutez des données à votre modèle de données via Power Query ou en sélectionnant l’option Ajouter au modèle de données.<br>" +
                        "• Créez des relations entre tables et utilisez DAX pour définir des mesures ou colonnes calculées.<br>" +
                        "<b>Activation :</b> Disponible dans Excel Professional Plus ou Office 365 Business.<br><br>" +

                        "<b>3. Analyse des scénarios</b><br>" +
                        "• Gestionnaire de scénarios : Compare plusieurs scénarios en changeant des variables clés.<br>" +
                        "• Valeur cible (What-If Analysis) : Trouve les valeurs nécessaires pour atteindre un objectif spécifique.<br>" +
                        "• Tableau d’hypothèses : Analyse l’impact de plusieurs variables sur un résultat.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Accédez à Outils d’analyse dans l’onglet Données.<br>" +
                        "• Entrez plusieurs hypothèses et comparez-les avec le gestionnaire de scénarios.<br>" +
                        "• Utilisez la valeur cible pour ajuster les valeurs d’entrée jusqu’à atteindre un objectif.<br>" +
                        "• Analysez plusieurs variables avec un tableau d’hypothèses.<br>" +
                        "<b>Activation :</b> Déjà disponible dans Excel.<br><br>" +

                        "<b>4. Solver</b><br>" +
                        "• Résout des problèmes complexes d’optimisation (maximisation ou minimisation).<br>" +
                        "• Permet de gérer plusieurs contraintes (budget, temps, ressources, etc.).<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Activez Solver via Fichier > Options > Compléments > Compléments Excel > Solver.<br>" +
                        "• Définissez une cellule cible, les cellules variables et les contraintes.<br>" +
                        "• Lancez Solver pour trouver la meilleure solution.<br>" +
                        "<b>Activation :</b> Ajoutez Solver comme complément.<br><br>" +

                        "<b>5. Tableaux croisés dynamiques</b><br>" +
                        "• Permet d’organiser, résumer et analyser des données rapidement.<br>" +
                        "• Offre des fonctionnalités avancées comme les Segments et les Graphiques croisés dynamiques.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Sélectionnez vos données et accédez à Insertion > Tableau croisé dynamique.<br>" +
                        "• Organisez vos champs dans les zones Lignes, Colonnes, Valeurs, et Filtres.<br>" +
                        "• Ajoutez des segments ou graphiques croisés pour une meilleure interaction.<br>" +
                        "<b>Activation :</b> Déjà intégré dans Excel.<br><br>" +

                        "<b>6. Histogrammes et Analyse des données (Analysis ToolPak)</b><br>" +
                        "• Outils statistiques comme histogramme, corrélations, régressions, et moyennes mobiles.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Activez le complément via Fichier > Options > Compléments > Analysis ToolPak.<br>" +
                        "• Accédez à Données > Analyse des données pour générer des résultats statistiques.<br>" +
                        "<b>Activation :</b> Suivez les étapes pour activer Analysis ToolPak.<br><br>" +

                        "<b>7. Graphiques et visualisation de données avancés</b><br>" +
                        "• Graphiques combinés, cartographiques, en cascade, etc.<br>" +
                        "• Mise en forme conditionnelle pour identifier les tendances ou anomalies.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Insérez des graphiques via l’onglet Insertion > Graphiques.<br>" +
                        "• Appliquez une mise en forme conditionnelle.<br>" +
                        "<b>Activation :</b> Déjà disponible dans Excel.<br><br>" +

                        "<b>8. Fonctions Excel avancées</b><br>" +
                        "• Fonctions statistiques, logiques, de recherche, et d’analyse.<br>" +
                        "• Exemple : SOMMEPROD, INDEX, MOYENNE.SI.ENS, etc.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Combinez des fonctions pour résoudre des problèmes complexes.<br>" +
                        "<b>Activation :</b> Déjà intégré dans Excel.<br><br>" +

                        "<b>9. Compléments Excel</b><br>" +
                        "• Inquire pour analyser les relations entre classeurs.<br>" +
                        "• Power BI Publisher pour publier des données.<br>" +
                        "• XLSTAT pour des analyses statistiques avancées.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Activez et configurez les compléments nécessaires.<br>" +
                        "<b>Activation :</b> Via Fichier > Options > Compléments.<br><br>" +

                        "<b>10. Gestionnaire de noms</b><br>" +
                        "• Permet de nommer des plages de données ou des cellules pour simplifier les formules.<br>" +
                        "<b>Utilisation :</b><br>" +
                        "• Accédez à Formules > Gestionnaire de noms.<br>" +
                        "• Exemple : Nommez une plage 'Total_Ventes' et utilisez-la dans des formules.<br>" +
                        "<b>Activation :</b> Déjà disponible dans Excel.<br><br>"
        );






    }
}
