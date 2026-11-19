package com.hightechniger.help_me.brain;

import java.text.Normalizer;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Outils de langue française pour l'assistant hors ligne :
 * normalisation (accents, apostrophes), expressions figées (« mot de passe » -> motdepasse),
 * synonymes et fautes courantes, mots vides, racinisation légère et distance d'édition.
 * Java pur (aucune dépendance Android) : testable sur ordinateur.
 */
public final class TextTools {

    private TextTools() {}

    private static final Pattern MARKS = Pattern.compile("\\p{M}+");
    private static final Pattern NON_WORD = Pattern.compile("[^a-z0-9#+@€]+");

    /** Minuscules, sans accents, apostrophes typographiques unifiées, ligatures dépliées. */
    public static String fold(String s) {
        if (s == null) return "";
        s = s.toLowerCase().replace('’', '\'').replace('ʼ', '\'').replace("œ", "oe").replace("æ", "ae");
        s = MARKS.matcher(Normalizer.normalize(s, Normalizer.Form.NFD)).replaceAll("");
        return s;
    }

    // ---------------------------------------------------------------- expressions figées
    private static final String[][] PHRASES = {
            {"mots? de passe|\\bmdp\\b|password", " motdepasse "},
            {"cles? usb|\\bflash disk\\b|\\bflash\\b", " cleusb "},
            {"tableaux? croises?( dynamiques?)?|\\btcd\\b|pivot ?table", " tcd "},
            {"recherche ?v\\b|recherchex|recherche ?x\\b|vlookup|xlookup", " recherchev "},
            {"tables? des matieres|sommaire automatique", " sommaire "},
            {"pieds? de page", " piedpage "},
            {"en ?-?tetes?", " entete "},
            {"captures? d ?'? ?ecran|captur d ecran|capture ecran|screen ?shots?|impr ?ecran|imprime ?ecran|print ?screen|prtsc", " captureecran "},
            {"barre des taches", " barretaches "},
            {"menu (de )?demarr(er|age)|bouton demarrer", " menudemarrer "},
            {"panneau de configuration", " panneauconfig "},
            {"gestionnaire des taches", " gestionnairetaches "},
            {"explorateur (de |des )?fichiers?|explorateur windows", " explorateur "},
            {"intelligences? artificielles?|\\bi\\.a\\.?|\\bia\\b|\\bias\\b", " ia "},
            {"t?chat ?-?g[pb]t|chat ?gtp", " chatgpt "},
            {"wi ?-?fi|wify|wifii", " wifi "},
            {"e ?-?mails?|courriels?|\\bmails?\\b|messagerie|adresse electronique", " email "},
            {"copier ?-?coller", " copier coller "},
            {"mise en forme conditionnelle", " mfc "},
            {"(mise|mettre|mets|met|mettez) en page", " miseenpage "},
            {"(mise|mettre|mets|met|mettez) en forme", " miseenforme "},
            {"zones? de texte", " zonetexte "},
            {"sauts? de (page|section)", " sautpage "},
            {"page de garde", " pagegarde "},
            {"ecran bleu|blue ?screen|bsod", " ecranbleu "},
            {"ecran noir", " ecrannoir "},
            {"moteurs? de recherche", " moteurrecherche "},
            {"navigateurs?( web| internet)?|browser", " navigateur "},
            {"power ?-?point|\\bpptx?\\b|diaporamas?|\\bslides?\\b", " powerpoint "},
            {"\\bms ?word\\b|winword|\\bdocx\\b", " word "},
            {"\\bexell?\\b|\\bexcell\\b|\\bxlsx?\\b|tableur", " excel "},
            {"machine learning|apprentissage automatique", " machinelearning "},
            {"deep learning|apprentissage profond", " deeplearning "},
            {"reseaux? sociaux?", " reseauxsociaux "},
            {"raccourcis? (clavier|clavie)", " raccourci "},
            {"ctrl ?\\+ ?([a-z0-9])\\b|control ?\\+ ?([a-z0-9])\\b", " ctrl$1$2 "},
            {"alt ?\\+ ?tab", " alttab "},
            {"\\bwin(dows)? ?\\+ ?([a-z0-9])\\b", " win$2 "},
            {"pare ?-?feu|firewall", " parefeu "},
            {"mettre a jour|met a jour|mettez a jour|mise a jour|mises a jour|\\bmaj\\b|update", " miseajour "},
            {"disque dur|\\bhdd\\b|\\bssd\\b", " disquedur "},
            {"memoires? vives?|memoir vive|\\bram\\b", " ram "},
            {"carte mere", " cartemere "},
            {"unite centrale", " unitecentrale "},
            {"systeme d ?'? ?exploitation|\\bos\\b", " systemeexploitation "},
            {"traitement de texte", " word "},
            {"nuage|\\bcloud\\b|one ?drive|google ?drive", " cloud "},
            {"hamecon+age|phishing|fishing", " phishing "},
            {"logiciels? malveillants?|malwares?|cheval de troie|trojan|rancongiciel|ransomware", " virus "},
            {"you ?tube", " youtube "},
            {"what ?s ?app|watsap|whatsap", " whatsapp "},
            {"lettre de motivation", " lettremotivation "},
            {"curriculum vitae|\\bcv\\b", " cv "},
            {"\\bpdf\\b", " pdf "},
            {"@|arr?obase|arobaz", " arobase "},
            {"€", " euro "},
    };
    private static final Pattern[] PHRASE_RX = new Pattern[PHRASES.length];
    /** Pour chaque expression : débuts littéraux de ses variantes (filtre rapide), ou null s'il faut toujours tester. */
    private static final String[][] TRIGGERS = new String[PHRASES.length][];

    static {
        for (int i = 0; i < PHRASES.length; i++) {
            PHRASE_RX[i] = Pattern.compile(PHRASES[i][0]);
            TRIGGERS[i] = triggers(PHRASES[i][0]);
        }
    }

    private static String[] triggers(String rx) {
        List<String> out = new ArrayList<>();
        int depth = 0, start = 0;
        for (int i = 0; i <= rx.length(); i++) {
            char c = i < rx.length() ? rx.charAt(i) : '|';
            if (c == '(') depth++;
            else if (c == ')') depth--;
            else if (c == '|' && depth == 0) {
                String b = rx.substring(start, i);
                if (b.startsWith("\\b")) b = b.substring(2);
                int k = 0;
                while (k < b.length() && Character.isLetterOrDigit(b.charAt(k))) k++;
                // un quantificateur (« s? ») rend la dernière lettre facultative
                if (k < b.length() && "?*{".indexOf(b.charAt(k)) >= 0) k--;
                if (k < 2) return null;
                out.add(b.substring(0, k));
                start = i + 1;
            }
        }
        return out.toArray(new String[0]);
    }

    /** Remplace les expressions de plusieurs mots par un seul mot (même traitement des deux côtés). */
    public static String phrases(String folded) {
        String s = " " + folded.replace('\'', ' ') + " ";
        s = s.replaceAll("\\s+", " ");
        for (int i = 0; i < PHRASES.length; i++) {
            if (TRIGGERS[i] != null) {
                boolean any = false;
                for (String t : TRIGGERS[i]) if (s.contains(t)) { any = true; break; }
                if (!any) continue;
            }
            Matcher m = PHRASE_RX[i].matcher(s);
            if (m.find()) s = m.replaceAll(PHRASES[i][1]);   // un groupe absent ($2) n'ajoute rien
        }
        return s;
    }

    // ---------------------------------------------------------------- synonymes / fautes
    private static final Map<String, String> SYN = new HashMap<>();

    private static void syn(String canon, String... words) {
        for (String w : words) SYN.put(w, canon);
    }

    static {
        syn("ordinateur", "ordi", "pc", "computer", "laptop", "ordinateurs", "ordinater", "ordinatuer", "ordinnateur");
        syn("logiciel", "logiciels", "programme", "programmes", "application", "applications", "appli", "app", "apps", "software");
        syn("supprimer", "effacer", "enlever", "retirer", "delete", "suprimer", "supprime");
        syn("creer", "fabriquer", "realiser", "concevoir", "generer", "crer", "cree");
        syn("enregistrer", "sauvegarder", "sauver", "save", "enregistre", "sauvegarde");
        syn("image", "images", "photo", "photos", "illustration", "illustrations", "picture");
        syn("telephone", "smartphone", "telephones", "mobile", "tel", "android", "iphone");
        syn("lent", "lenteur", "ralenti", "ralentit", "rame", "lente", "lents");
        syn("bloque", "freeze", "plante", "plantage", "bloquer", "repond", "bloquee", "plantee");
        syn("telecharger", "download", "telechargement", "telecharge", "telechargements");
        syn("graphique", "graphiques", "diagramme", "diagrammes", "courbe", "chart", "histogramme");
        syn("police", "polices", "font", "fonts", "caractere", "caracteres");
        syn("rechercher", "chercher", "trouver", "recherche", "cherche", "search");
        syn("dossier", "dossiers", "repertoire", "folder");
        syn("imprimer", "impression", "imprimante", "imprime", "print");
        syn("internet", "net", "web", "toile", "internnet", "intenet", "internete");
        syn("windows", "windiws", "windos", "window", "windaws", "windows11", "windows10");
        syn("word", "woed", "wrd", "wword");
        syn("ecran", "moniteur", "screen");
        syn("clavier", "keyboard", "clavie");
        syn("souris", "mouse");
        syn("tableau", "tableaux", "table", "tablau");
        syn("colonne", "colonnes", "column");
        syn("ligne", "lignes", "row");
        syn("formule", "formules", "fonction", "fonctions", "calcul", "calculs", "calculer");
        syn("somme", "additionner", "addition", "sum", "sommes");
        syn("moyenne", "average");
        syn("virus", "antivirus", "infecte", "infection");
        syn("prompt", "prompts", "consigne", "prompter");
        syn("hallucination", "hallucinations", "hallucine", "trompe", "tromper", "trompent", "invente", "inventer");
        syn("fiable", "fiabilite", "confiance", "croire");
        syn("recuperer", "restaurer", "retrouver", "recupere", "restaure", "recuperation");
        syn("numero", "numeros", "numeroter", "numerotation", "pagination", "paginer");
        syn("figer", "figez", "fige");
        syn("connexion", "connecter", "connecte", "connection");
        syn("installer", "installation", "install", "installe");
        syn("ouvrir", "ouvre", "ouverture", "lancer");
        syn("fermer", "ferme", "quitter");
        syn("eteindre", "eteins", "eteint", "eteignez", "arreter", "arret", "shutdown", "eteindr");
        syn("allumer", "allume", "allumez", "demarrer", "demarrage", "boot");
        syn("renommer", "rename", "renomme");
        syn("copier", "copie", "dupliquer", "copy");
        syn("coller", "colle", "paste");
        syn("couper", "coupe", "cut");
        syn("annuler", "undo", "annule");
        syn("selectionner", "selection", "selectionne", "surligner", "select");
        syn("traduire", "traduction", "translate");
        syn("resumer", "resume", "synthese", "synthetiser");
        syn("exercice", "exercices", "exo", "exos", "devoir", "devoirs", "pratique");
        syn("difference", "differences", "differencier", "comparer", "versus", "vs");
        syn("definition", "definir", "signifie", "signification", "definitions");
    }

    // ---------------------------------------------------------------- mots vides
    private static final Set<String> STOP = new HashSet<>(Arrays.asList((
            "le la les un une des de du d l et ou en au aux a pour par sur dans avec sans ce cet cette ces se sa son ses "
          + "leur leurs que qui quoi est sont etre avoir ai as avons avez ont ne pas plus moins comme vous votre vos nous on "
          + "il elle ils elles y je j tu te t me m moi toi mon ma mes ton ta tes tout tous toute toutes fait peut peux "
          + "peuvent pouvez puis mais donc car aussi tres bien comment quel quelle quels quelles pourquoi quand "
          + "c qu s n est-ce ca cela ceci svp stp sil plait merci voudrais veux voulais aimerais souhaite besoin aide "
          + "aider aidez explique expliquer expliquez montre montrer montrez dis dire faire faut fais "
          + "estce quest cest quoi existe il-y-a ya chose truc trucs etc alors bon bonjour salut hello "
          + "mais encore possible savoir sais connaitre apprendre utiliser utilise utilisation "
          + "fonctionne marche faire avoir moyen methode facon maniere etape etapes sert servir sers "
          + "trop vraiment beaucoup tjrs toujours deja juste simplement rapidement facilement "
          + "coment commant komment cmt cmnt kel kelle koi koa ke ki fe fai fair fer pr pk pq sa ses "
          + "comen comman svp stp pls please mn moi ns vs ds bcp"
    ).split(" ")));

    /** Mots qui portent le sens de la demande mais ne doivent jamais décider seuls. */
    private static final Set<String> WEAK = new HashSet<>();


    public static boolean isStop(String w) { return STOP.contains(w); }

    /** Vrai si le mot (déjà replié) est connu des listes de synonymes. */
    public static boolean isKnown(String w) { return SYN.containsKey(w) || STOP.contains(w); }

    /** Mots bruts (repliés) d'un texte, pour la correction orthographique avant toute transformation. */
    public static String[] rawWords(String folded) { return NON_WORD.split(folded.replace('\'', ' ')); }

    public static boolean isWeak(String stem) { return WEAK.contains(stem); }

    // ---------------------------------------------------------------- racinisation légère
    private static final String[] SUFFIXES = {
            "issements", "issement", "ements", "ement", "ations", "ation", "atrices", "atrice", "ateurs", "ateur",
            "ables", "able", "istes", "iste", "ances", "ance", "ences", "ence", "ismes", "isme", "ites", "ite",
            "ives", "ive", "ifs", "if", "euses", "euse", "eurs", "eur", "iques", "ique", "ments", "ment",
            "erons", "eront", "erez", "erai", "eras", "era", "ions", "iez", "ais", "ait", "aient", "ant", "ants",
            "ees", "ee", "es", "er", "ez", "ir", "is", "it", "e", "s", "x"};

    public static String stem(String w) {
        if (w.length() <= 3 || !Character.isLetter(w.charAt(0)) || w.startsWith("0x") || w.startsWith("ctrl")) return w;
        if (w.endsWith("aux") && w.length() > 5) w = w.substring(0, w.length() - 3) + "al";
        for (String suf : SUFFIXES) {
            if (w.endsWith(suf) && w.length() - suf.length() >= 3) {
                return w.substring(0, w.length() - suf.length());
            }
        }
        return w;
    }

    // ---------------------------------------------------------------- découpage
    /** Mots significatifs, déjà ramenés à leur forme canonique puis racinisés. */
    public static List<String> terms(String text) {
        List<String> out = new ArrayList<>();
        String s = phrases(fold(text));
        for (String w : NON_WORD.split(s)) {
            if (w.isEmpty()) continue;
            String c = SYN.get(w);
            if (c != null) w = c;
            if (STOP.contains(w)) continue;
            if (w.length() < 2 && !Character.isDigit(w.charAt(0))) continue;
            out.add(stem(w));
        }
        return out;
    }

    // ---------------------------------------------------------------- distance d'édition
    /** Distance de Damerau-Levenshtein (transpositions adjacentes), interrompue au-delà de max. */
    public static int distance(String a, String b, int max) {
        int n = a.length(), m = b.length();
        if (Math.abs(n - m) > max) return max + 1;
        int[] p2 = new int[m + 1], p1 = new int[m + 1], cur = new int[m + 1];
        for (int j = 0; j <= m; j++) p1[j] = j;
        for (int i = 1; i <= n; i++) {
            cur[0] = i;
            int rowMin = cur[0];
            for (int j = 1; j <= m; j++) {
                int cost = a.charAt(i - 1) == b.charAt(j - 1) ? 0 : 1;
                int v = Math.min(Math.min(cur[j - 1] + 1, p1[j] + 1), p1[j - 1] + cost);
                if (i > 1 && j > 1 && a.charAt(i - 1) == b.charAt(j - 2) && a.charAt(i - 2) == b.charAt(j - 1))
                    v = Math.min(v, p2[j - 2] + 1);
                cur[j] = v;
                if (v < rowMin) rowMin = v;
            }
            if (rowMin > max) return max + 1;
            int[] t = p2; p2 = p1; p1 = cur; cur = t;
        }
        return p1[m];
    }

    static {   // après SUFFIXES : stem() en a besoin
        for (String w : new String[]{"creer", "ouvrir", "fermer", "supprimer", "enregistrer", "logiciel", "ordinateur",
                "rechercher", "image", "tableau", "probleme", "erreur", "nouveau", "nouvelle", "document", "fichier",
                "page", "texte", "definition", "difference", "mettre", "ajouter", "inserer", "changer", "modifier",
                "utiliser", "premier", "premiere", "dernier", "faire", "nouveau", "petit", "grand", "bon", "mon"}) WEAK.add(stem(w));
    }

    /** Équivalent de String.join (absent d'Android 7.1, API 25). */
    public static String join(String sep, Iterable<String> items) {
        StringBuilder b = new StringBuilder();
        for (String s : items) {
            if (b.length() > 0) b.append(sep);
            b.append(s);
        }
        return b.toString();
    }

    /** Première lettre en majuscule. */
    public static String cap(String s) {
        if (s == null || s.isEmpty()) return s;
        return Character.toUpperCase(s.charAt(0)) + s.substring(1);
    }
}
