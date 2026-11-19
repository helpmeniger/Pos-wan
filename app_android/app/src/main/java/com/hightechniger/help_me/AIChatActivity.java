package com.hightechniger.help_me;

import android.content.Context;
import android.os.Bundle;
import android.text.TextUtils;
import android.util.Pair;
import android.view.View;
import android.widget.EditText;
import android.widget.HorizontalScrollView;
import android.widget.ImageButton;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.text.Normalizer;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Random;
import java.util.Set;

/**
 * AIChatActivity - version améliorée et fusionnant local JSONs
 *
 * - Charge dans l'ordre : knowledge_pro.json (priorité haute) -> data_clean.json -> data.json
 * - Merge intelligent des tokens via fields: patterns, question, tags, legacy question
 * - TF-IDF weights, cosine similarity, synonym boosting, simple spellfix, conversation context
 * - 100% offline
 *
 * Remplace ton ancien fichier par celui-ci ; conserve les drawables et layout existants.
 */
public class AIChatActivity extends AppCompatActivity {

    private EditText inputBox;
    private LinearLayout chatContainer;

    private List<KBItem> kb = new ArrayList<>();
    private final Map<String, List<Integer>> invertedIndex = new HashMap<>();
    private final Map<String, Set<String>> synonyms = new HashMap<>();

    // idf map and vocabulary
    private final Map<String, Double> idf = new HashMap<>();
    private final Set<String> vocabulary = new HashSet<>();

    private final Set<String> stopWords = new HashSet<>(Arrays.asList(
            "le","la","les","de","du","des","dans","un","une","pour","comment","faire",
            "mon","ma","mes","avec","au","aux","ce","cette","sur","est","que","quoi","ou","où",
            "qui","et","a","à","je","tu","il","elle","nous","vous","ils","elles","ça","sa","son",
            "en","par","plus","moins","tres","très","bien","aujourd","hui","d’","l’","d","l"
    ));

    private final Random random = new Random();

    // small spelling correction map (expandable)
    private final Map<String, String> spellFix = new HashMap<>();

    // conversation context
    private String lastIntent = null;

    // confidence threshold for returning an answer (tuneable)
    private static final double CONFIDENCE_THRESHOLD = 0.25;

    private final String[] welcomeLines = new String[]{
            "Bonjour 👋 Je suis HELP-ME, votre guide numérique ! Ensemble, découvrons la puissance de l’informatique bureautique 💻.",
            "Salut 👋 prêt(e) à apprendre ? Word, Excel, PowerPoint et Internet n’auront plus de secret pour vous ! 🚀",
            "Bienvenue 👋 Je suis HELP-ME 🤖. Apprenons pas à pas comment maîtriser les outils du bureau moderne 🧠.",
            "Coucou 👋 Ravi de vous revoir ! Avez-vous envie de progresser aujourd’hui dans Word ou Excel ? 😃",
            "Hello 👋 Je suis HELP-ME, votre assistant informatique. Ensemble, développons vos compétences numériques 💪.",
            "Bienvenue 👋 Comment puis-je vous aider aujourd’hui à mieux utiliser votre ordinateur ? 🖥️",
            "Bonjour 🌞 Je suis HELP-ME. Le numérique est un atout incontournable — commençons à l’explorer ensemble ! 💡",
            "Salut 👋 Chaque clic compte ! Découvrez aujourd’hui un nouvel outil bureautique avec HELP-ME 🧩.",
            "Bonjour 👋 et bienvenue dans votre espace d’apprentissage ! Ici, le numérique devient facile et amusant 😄.",
            "Je suis HELP-ME 🤖. Dites-moi ce que vous voulez apprendre : Word, Excel, PowerPoint ou Internet 🌐.",
            "Bienvenue 👋 Ensemble, faisons du numérique un véritable levier pour votre réussite professionnelle 💼.",
            "Salut 🙌 Envie de créer des documents, des tableaux ou des présentations ? Je suis là pour vous accompagner 🧭.",
            "Bonjour 👋 La maîtrise des outils bureautiques ouvre de nombreuses portes. Apprenons ensemble à les utiliser 🔑.",
            "Hey 👋 Je suis HELP-ME ! Utiliser le numérique, c’est investir dans votre avenir 🌍. Prêt(e) à commencer ?",
            "Bienvenue 💫 Votre aventure numérique commence ici. Choisissez un sujet et je vous guide pas à pas 📘.",
            "Salut 👋 Saviez-vous qu’Excel peut vous faire gagner du temps ? Et Word rendre vos rapports plus pros ? 😎",
            "Bonjour 🌟 Je suis HELP-ME. Découvrons ensemble comment Internet peut vous aider à mieux travailler 📡.",
            "Bienvenue 👋 L’informatique n’est pas compliquée — avec un peu de pratique, tout devient simple ! 💡",
            "Salut 👋 Les outils bureautiques sont vos alliés du quotidien. Prêt(e) à les maîtriser comme un pro ? 🧠",
            "Bonjour 👋 Avec HELP-ME, chaque jour est une opportunité d’apprendre quelque chose de nouveau 💻✨",
            "Bienvenue 🎉 Ensemble, rendons le numérique accessible à tous. Commencez par une question, et je m’occupe du reste 🤝",
            "Salut 👋 Avez-vous pensé à perfectionner vos compétences Word ou Excel aujourd’hui ? C’est le bon moment ⏰",
            "Bonjour 🌈 Votre futur numérique commence ici. Parlons bureautique, efficacité et créativité ! 💡"
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_aichat);

        inputBox = findViewById(R.id.inputBox);
        chatContainer = findViewById(R.id.chatContainer);
        ImageButton sendButton = findViewById(R.id.sendButton);

        initSpellFix();
        initSynonyms();
        loadKnowledgeMerged(); // <-- load & merge all JSONs

        // Message de bienvenue aléatoire
        addMessage(welcomeLines[random.nextInt(welcomeLines.length)], false, "assistanimg");

        // Suggestions au démarrage
        showStartupSuggestions();

        // Question transmise depuis une fiche de leçon (scan d'un code du manuel)
        String fromLesson = getIntent().getStringExtra("question");
        if (fromLesson != null && !fromLesson.trim().isEmpty()) {
            inputBox.setText(fromLesson);
            inputBox.setSelection(inputBox.getText().length());
        }

        sendButton.setOnClickListener(v -> {


            hideKeyboard(inputBox);

            String question = inputBox.getText().toString().trim();
            if (!TextUtils.isEmpty(question)) {
                addMessage("Vous : " + question, true, null);

                AnswerResult res = findBestAnswer(question);
                addMessage("HELP-ME 🤖 : " + res.answer, false, "assistantimg1");

                if (res.showSuggestions) addSuggestionChips(res.suggestions);

                inputBox.setText("");
            }
        });
    }
    private void hideKeyboard(View view) {
        try {
            android.view.inputmethod.InputMethodManager imm =
                    (android.view.inputmethod.InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);

            if (imm != null) {
                imm.hideSoftInputFromWindow(view.getWindowToken(), 0);
            }
        } catch (Exception ignored) {}
    }

    // ================= UI (identique à ta version améliorée) =================

    private void showStartupSuggestions() {
        List<String> suggestions = Arrays.asList(
                "📘 Découvrir les composants d’un ordinateur",
                "💾 Comprendre le système d’exploitation Windows",
                "🖥️ Explorer le bureau Windows et ses icônes",
                "📂 Gérer ses fichiers et dossiers avec l’Explorateur",
                "⚙️ Configurer les paramètres Windows",
                "🧹 Optimiser et entretenir son PC",
                "🛡️ Sécuriser son ordinateur (antivirus, firewall)",
                "🗂️ Apprendre Word : mise en page et styles",
                "📄 Créer un CV professionnel sur Word",
                "🧾 Insérer des tableaux et images dans Word",
                "🔢 Maîtriser Excel : formules et fonctions de base",
                "📊 Créer des graphiques et tableaux croisés dynamiques",
                "🧮 Analyser des données avec Excel",
                "🎞️ Concevoir des présentations PowerPoint",
                "🎨 Personnaliser un thème PowerPoint moderne",
                "🎬 Ajouter des animations et transitions",
                "🌐 Naviguer sur Internet efficacement",
                "🔍 Bien utiliser Google et les moteurs de recherche",
                "📧 Configurer sa messagerie Gmail ou Outlook",
                "💬 Utiliser les réseaux sociaux de manière professionnelle",
                "☁️ Découvrir le stockage cloud (OneDrive, Google Drive)",
                "📹 Organiser une visioconférence (Teams, Zoom, Meet)",
                "🛒 Comprendre le commerce électronique et les paiements sécurisés",
                "🏠 Créer un réseau Wi-Fi sécurisé à la maison",
                "🏢 Travailler à distance : outils de collaboration",
                "🤖 Introduction à l’intelligence artificielle",
                "🧠 Comprendre le machine learning et ses applications",
                "💡 Explorer le traitement du langage naturel (NLP)",
                "👁️ Découvrir la vision par ordinateur",
                "🧰 Les outils de développement IA : TensorFlow, PyTorch",
                "🌍 Sécurité sur Internet et bonnes pratiques",
                "📱 Utiliser les outils numériques pour la bureautique",
                "💼 Améliorer sa productivité avec les outils numériques",
                "🎯 Astuces Excel pour les professionnels",
                "🗣️ Comment rédiger un rapport Word bien présenté",
                "📈 Créer un tableau de bord interactif dans Excel",
                "🧭 Découvrir les raccourcis clavier utiles",
                "🧑‍💻 Bonnes pratiques pour le télétravail",
                "📚 Différence entre Word, Excel et PowerPoint",
                "🚀 Développer ses compétences numériques pas à pas"
        );

        LinearLayout container = new LinearLayout(this);
        container.setOrientation(LinearLayout.VERTICAL);
        container.setPadding(25, 25, 25, 25);
        container.setGravity(android.view.Gravity.CENTER_HORIZONTAL);

        TextView title = new TextView(this);
        title.setText("Suggestions rapides 💡");
        title.setTextSize(17f);
        title.setTextColor(getResources().getColor(android.R.color.black));
        title.setPadding(0, 10, 0, 20);
        title.setGravity(android.view.Gravity.CENTER);
        title.setTypeface(null, android.graphics.Typeface.BOLD);
        container.addView(title);

        HorizontalScrollView scrollHorizontal = new HorizontalScrollView(this);
        scrollHorizontal.setHorizontalScrollBarEnabled(false);
        scrollHorizontal.setOverScrollMode(View.OVER_SCROLL_NEVER);
        scrollHorizontal.setPadding(10, 5, 10, 5);

        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.HORIZONTAL);
        layout.setGravity(android.view.Gravity.CENTER_VERTICAL);

        for (String s : suggestions) {
            TextView chip = new TextView(this);
            chip.setText(s);
            chip.setPadding(40, 22, 40, 22);
            chip.setBackgroundResource(R.drawable.chip_bg);
            chip.setTextSize(15f);
            chip.setTextColor(getResources().getColor(android.R.color.black));

            LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.WRAP_CONTENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT);
            p.setMargins(15, 15, 15, 15);
            chip.setLayoutParams(p);

            chip.setOnClickListener(v -> inputBox.setText(s));
            layout.addView(chip);
        }

        scrollHorizontal.addView(layout);
        container.addView(scrollHorizontal);
        chatContainer.addView(container);

        ScrollView scroll = findViewById(R.id.scrollView);
        scroll.postDelayed(() -> scroll.fullScroll(View.FOCUS_DOWN), 300);
    }

    private void addSuggestionChips(List<String> suggestions) {
        LinearLayout container = new LinearLayout(this);
        container.setOrientation(LinearLayout.VERTICAL);
        container.setPadding(20, 10, 20, 20);
        container.setGravity(android.view.Gravity.CENTER_HORIZONTAL);

        TextView title = new TextView(this);
        title.setText("Autres idées 💭");
        title.setTextSize(16f);
        title.setTextColor(getResources().getColor(android.R.color.black));
        title.setPadding(0, 10, 0, 15);
        title.setGravity(android.view.Gravity.CENTER);
        title.setTypeface(null, android.graphics.Typeface.BOLD);
        container.addView(title);

        HorizontalScrollView scrollHorizontal = new HorizontalScrollView(this);
        scrollHorizontal.setHorizontalScrollBarEnabled(false);
        scrollHorizontal.setOverScrollMode(View.OVER_SCROLL_NEVER);
        scrollHorizontal.setPadding(10, 5, 10, 5);

        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.HORIZONTAL);
        layout.setGravity(android.view.Gravity.CENTER_VERTICAL);

        for (String s : suggestions) {
            TextView chip = new TextView(this);
            chip.setText(s);
            chip.setPadding(40, 22, 40, 22);
            chip.setBackgroundResource(R.drawable.chip_bg);
            chip.setTextSize(15f);
            chip.setTextColor(getResources().getColor(android.R.color.black));

            LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.WRAP_CONTENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT);
            p.setMargins(15, 15, 15, 15);
            chip.setLayoutParams(p);

            chip.setOnClickListener(v -> inputBox.setText(s));
            layout.addView(chip);
        }

        scrollHorizontal.addView(layout);
        container.addView(scrollHorizontal);
        chatContainer.addView(container);

        ScrollView scroll = findViewById(R.id.scrollView);
        scroll.postDelayed(() -> scroll.fullScroll(View.FOCUS_DOWN), 300);
    }

    private void addMessage(String text, boolean isUser, String imageName) {
        LinearLayout wrapper = new LinearLayout(this);
        wrapper.setOrientation(LinearLayout.HORIZONTAL);
        wrapper.setPadding(8,8,8,8);

        if (!isUser) {
            ImageView avatar = new ImageView(this);
            int id = getResources().getIdentifier(imageName, "drawable", getPackageName());
            avatar.setImageResource(id);
            LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(90,90);
            p.setMargins(4,4,12,4);
            avatar.setLayoutParams(p);
            wrapper.addView(avatar);
        }

        TextView msg = new TextView(this);
        msg.setText(text);
        msg.setTextSize(16f);
        msg.setPadding(22,16,22,16);
        msg.setBackgroundResource(isUser ? R.drawable.bubble_user : R.drawable.bubble_ai);

        LinearLayout.LayoutParams paramsMsg = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        paramsMsg.setMargins(8,8,8,8);
        msg.setLayoutParams(paramsMsg);

        wrapper.addView(msg);
        chatContainer.addView(wrapper);

        ScrollView scroll = findViewById(R.id.scrollView);
        scroll.post(() -> scroll.fullScroll(View.FOCUS_DOWN));
    }

    // ================= Knowledge / NLP Engine =================

    static class KBItem {
        String intent;
        List<String> patterns;
        String answer;
        String image;
        String legacyQuestion;
        List<String> tokens;
        Map<String, Double> tokenWeights = new HashMap<>(); // TF-IDF weights for this doc
        double docPriority = 1.0; // boost depending on source (knowledge_pro higher)
    }

    static class AnswerResult {
        String answer;
        String imageName;
        boolean showSuggestions;
        List<String> suggestions = new ArrayList<>();
    }

    /**
     * loadKnowledgeMerged:
     * - tries to load knowledge_pro.json (high priority), then data_clean.json, then data.json
     * - merges entries: uses fields 'patterns', 'question', 'tags', 'answer'
     * - assigns docPriority depending on source (3.0, 2.0, 1.0)
     */
    private void loadKnowledgeMerged() {
        kb.clear();
        vocabulary.clear();

        // source order and priority
        // knowledge_manuel.json : contenu du manuel HELP-ME (généré automatiquement), priorité la plus haute
        String[] files = {"knowledge_manuel.json", "knowledge_pro.json", "data_clean.json", "data.json"};
        double[] priorities = {3.5, 3.0, 2.0, 1.0};

        for (int idx = 0; idx < files.length; idx++) {
            JSONArray arr = tryLoadJson(files[idx]);
            if (arr == null) continue;

            for (int i = 0; i < arr.length(); i++) {
                JSONObject it = arr.optJSONObject(i);
                if (it == null) continue;

                KBItem item = new KBItem();
                item.docPriority = priorities[idx];

                // attempt to read intent/answer/image
                item.intent = it.optString("intent", null);
                item.answer = it.optString("answer", "");
                item.image = it.optString("image", null);

                // collect tokens from multiple possible fields
                Set<String> tokens = new LinkedHashSet<>();

                // - patterns array (if present)
                JSONArray patterns = it.optJSONArray("patterns");
                if (patterns != null) {
                    for (int k = 0; k < patterns.length(); k++) {
                        String v = normalize(patterns.optString(k, ""));
                        if (!v.isEmpty()) tokens.addAll(tokenize(v));
                    }
                }

                // - question array (if present in knowledge_pro)
                JSONArray questions = it.optJSONArray("question");
                if (questions != null) {
                    for (int k = 0; k < questions.length(); k++) {
                        String v = normalize(questions.optString(k, ""));
                        if (!v.isEmpty()) tokens.addAll(tokenize(v));
                    }
                }

                // - tags array (if present)
                JSONArray tags = it.optJSONArray("tags");
                if (tags != null) {
                    for (int k = 0; k < tags.length(); k++) {
                        String v = normalize(tags.optString(k, ""));
                        if (!v.isEmpty()) tokens.addAll(tokenize(v));
                    }
                }

                // - fallback to 'question' (legacy long text)
                String legacy = it.optString("question", "");
                if (tokens.isEmpty() && legacy != null && !legacy.isEmpty()) {
                    item.legacyQuestion = legacy;
                    tokens.addAll(extractKeywords(legacy));
                }

                // if tokens still empty, try best effort using answer text (extract keywords)
                if (tokens.isEmpty() && item.answer != null && !item.answer.isEmpty()) {
                    tokens.addAll(extractKeywords(item.answer));
                }

                item.tokens = new ArrayList<>(tokens);
                // add to vocabulary
                for (String t : item.tokens) vocabulary.add(t);
                kb.add(item);
            }
        }

        // after loading all docs, compute TF-IDF weights and inverted index
        buildIndexAndWeights();
    }

    private JSONArray tryLoadJson(String name) {
        try {
            InputStream is = getAssets().open(name);
            byte[] buffer = new byte[is.available()];
            int r = is.read(buffer);
            is.close();
            if (r <= 0) return null;
            return new JSONArray(new String(buffer, StandardCharsets.UTF_8));
        } catch (Exception e) {
            // file not present or parse error -> return null silently (we keep offline)
            return null;
        }
    }

    private List<String> tokenize(String t) {
        t = normalize(t);
        if (t.isEmpty()) return Collections.emptyList();
        List<String> out = new ArrayList<>();
        for (String w : t.split("\\s+")) {
            if (w.length() <= 2) continue;
            if (stopWords.contains(w)) continue;
            out.add(stem(w));
        }
        return dedup(out);
    }

    // normalization + spelling corrections
    private String normalize(String s) {
        if (s == null) return "";
        s = s.toLowerCase().trim();

        // Basic spelling corrections before normalization
        for (Map.Entry<String, String> e : spellFix.entrySet()) {
            s = s.replace(e.getKey(), e.getValue());
        }

        s = Normalizer.normalize(s, Normalizer.Form.NFD)
                .replaceAll("\\p{M}","")
                .replaceAll("[^a-z0-9 ]"," ")
                .replaceAll("\\s+"," ")
                .trim();
        return s;
    }

    // small stemmer
    private String stem(String w) {
        return w.replaceAll("(ements?|ations?|ment|tion|sion|euses?|euse|eaux|eau|e|es|s|ons|ez|er|ir|re)$","").trim();
    }

    private List<String> dedup(List<String> l) {
        Set<String> set = new LinkedHashSet<>(l);
        return new ArrayList<>(set);
    }

    private List<String> extractKeywords(String s) {
        List<String> base = tokenize(s);
        Set<String> exp = new HashSet<>(base);
        for (String w : base) {
            if (synonyms.containsKey(w)) exp.addAll(synonyms.get(w));
        }
        return new ArrayList<>(exp);
    }

    // Build inverted index AND compute idf and TF-IDF weights per document
    private void buildIndexAndWeights() {
        invertedIndex.clear();
        idf.clear();

        // compute document frequency for each token
        Map<String, Integer> docFreq = new HashMap<>();
        for (KBItem item : kb) {
            Set<String> seen = new HashSet<>(item.tokens);
            for (String t : seen) {
                docFreq.put(t, docFreq.getOrDefault(t, 0) + 1);
            }
        }

        int N = Math.max(1, kb.size());
        for (String t : docFreq.keySet()) {
            double df = docFreq.get(t);
            idf.put(t, Math.log((N + 1d) / (df + 1d)) + 1.0); // smoothed idf
        }

        // compute TF-IDF weights for each document
        for (int i = 0; i < kb.size(); i++) {
            KBItem item = kb.get(i);

            // term frequency
            Map<String, Integer> tf = new HashMap<>();
            for (String t : item.tokens) tf.put(t, tf.getOrDefault(t, 0) + 1);

            // compute weights and apply docPriority boost
            double norm = 0.0;
            item.tokenWeights.clear();
            double docBoost = 1.0 + (item.docPriority - 1.0) * 0.25; // small boost depending on source
            for (Map.Entry<String, Integer> e : tf.entrySet()) {
                String token = e.getKey();
                double termFreq = e.getValue();
                double weight = termFreq * idf.getOrDefault(token, 1.0) * docBoost;
                item.tokenWeights.put(token, weight);
                norm += weight * weight;
            }
            // normalize vector (to use cosine similarity)
            norm = Math.sqrt(Math.max(norm, 1e-9));
            for (Map.Entry<String, Double> e : new ArrayList<>(item.tokenWeights.entrySet())) {
                item.tokenWeights.put(e.getKey(), e.getValue() / norm);
            }

            // build inverted index
            for (String t : item.tokens) {
                invertedIndex.computeIfAbsent(t, k -> new ArrayList<>()).add(i);
            }
        }
    }

    private void addSyns(String... w) {
        List<String> list = new ArrayList<>();
        for (String s: w) list.add(stem(normalize(s)));
        for (String a: list) {
            synonyms.putIfAbsent(a,new HashSet<>());
            for (String b: list)
                if (!a.equals(b)) synonyms.get(a).add(b);
        }
    }

    private void initSynonyms() {
        addSyns("powerpoint","ppt","presentation","diaporama","slides");
        addSyns("ordinateur","pc","ordi");
        addSyns("excel","feuille","cellule","classeur");
        addSyns("word","document","texte");
        addSyns("internet","wifi","connexion","reseau");
        addSyns("apprendre","etudier","formation","cours","tutoriel","decouvrir");
        addSyns("creer","construire","faire","generer");
    }

    private void initSpellFix() {
        spellFix.put("exel", "excel");
        spellFix.put("woed", "word");
        spellFix.put("power point", "powerpoint");
        spellFix.put("power-point", "powerpoint");
        spellFix.put("ppt ", "powerpoint ");
        spellFix.put("reseu", "reseau");
        spellFix.put("intenet", "internet");
        spellFix.put("windiws", "windows");
        spellFix.put("microsof", "microsoft");
    }

    private List<String> defaultSuggestions() {
        return Arrays.asList(
                "Exemple : Excel — créer un graphique",
                "Word — insérer une image",
                "PowerPoint — animation"
        );
    }

    private List<String> suggestionsFromIntent(String i) {
        if (i == null) return defaultSuggestions();
        switch (i) {
            case "excel": return Arrays.asList("Excel — tableau", "Excel — graphique", "Excel — RECHERCHEV");
            case "word": return Arrays.asList("Word — sommaire", "Word — en-tête du document");
            case "powerpoint": return Arrays.asList("PPT — transitions", "PPT — SmartArt");
            case "windows": return Arrays.asList("Windows — Explorateur de fichiers", "Windows — paramètres");
            case "internet": return Arrays.asList("Internet — recherche avancée", "Sécurité en ligne");
            default: return defaultSuggestions();
        }
    }

    // Find best answer using TF-IDF vectorization + cosine similarity
    private AnswerResult findBestAnswer(String q) {
        AnswerResult r = new AnswerResult();

        List<String> toksRaw = tokenize(q);
        if (toksRaw.isEmpty()) {
            r.answer = "Pouvez-vous préciser ? 😊";
            r.showSuggestions = true;
            r.suggestions = defaultSuggestions();
            return r;
        }

        // create query tf-idf vector
        Map<String, Double> qWeights = new HashMap<>();
        Map<String, Integer> qTf = new HashMap<>();
        for (String t : toksRaw) qTf.put(t, qTf.getOrDefault(t, 0) + 1);
        double qNorm = 0.0;
        for (Map.Entry<String, Integer> e : qTf.entrySet()) {
            String token = e.getKey();
            double tf = e.getValue();
            double weight = tf * idf.getOrDefault(token, 1.0);
            qWeights.put(token, weight);
            qNorm += weight * weight;
        }
        qNorm = Math.sqrt(Math.max(qNorm, 1e-9));
        for (Map.Entry<String, Double> e : new ArrayList<>(qWeights.entrySet())) qWeights.put(e.getKey(), e.getValue() / qNorm);

        // expand with synonyms
        Set<String> expanded = new HashSet<>(qWeights.keySet());
        for (String tk : new ArrayList<>(qWeights.keySet())) {
            if (synonyms.containsKey(tk)) expanded.addAll(synonyms.get(tk));
        }

        // candidate docs
        Set<Integer> cand = new HashSet<>();
        for (String t : expanded) {
            List<Integer> ids = invertedIndex.get(t);
            if (ids != null) cand.addAll(ids);
        }
        if (cand.isEmpty()) for (int i=0;i<kb.size();i++) cand.add(i);

        List<Pair<Integer, Double>> scored = new ArrayList<>();
        for (int id : cand) {
            KBItem it = kb.get(id);
            double sim = 0.0;
            for (Map.Entry<String, Double> qE : qWeights.entrySet()) {
                String token = qE.getKey();
                double qv = qE.getValue();
                double dv = it.tokenWeights.getOrDefault(token, 0.0);
                sim += qv * dv;
            }
            // synonym partial credit
            for (String qtok : new HashSet<>(qWeights.keySet())) {
                if (synonyms.containsKey(qtok)) {
                    for (String syn : synonyms.get(qtok)) {
                        double dv = it.tokenWeights.getOrDefault(syn, 0.0);
                        if (dv > 0) sim += 0.35 * dv * qWeights.get(qtok);
                    }
                }
            }
            if (sim > 0) scored.add(new Pair<>(id, sim));
        }

        if (scored.isEmpty()) {
            r.answer = "Je n’ai pas trouvé de réponse précise. Pouvez-vous préciser ou essayer des mots-clés comme 'Excel', 'Word' ou 'PowerPoint' ? 😊";
            r.showSuggestions = true;
            r.suggestions = defaultSuggestions();
            return r;
        }

        scored.sort((a,b)->Double.compare(b.second,a.second));
        Pair<Integer, Double> bestPair = scored.get(0);
        double bestScore = bestPair.second;
        KBItem best = kb.get(bestPair.first);

        // update context
        if (best.intent != null && !best.intent.isEmpty()) lastIntent = best.intent;

        // confidence check
        if (bestScore < CONFIDENCE_THRESHOLD) {
            r.answer = "Je ne suis pas sûr d'avoir bien compris. Pouvez-vous préciser ? 🧐 Mais si vous rencontrez des difficultés, vous pouvez me contacter directement par WhatsApp ou par appel au +227 92 08 12 82 - 87 22 52 02.";
            r.showSuggestions = true;
            r.suggestions = defaultSuggestions();
            return r;
        }

        r.answer = best.answer != null && !best.answer.isEmpty() ? best.answer : "Je n'ai pas encore cette réponse 😅 , je m'entraîne chaque jour pour m'améliorer.";
        r.imageName = best.image;

        if (bestScore < (CONFIDENCE_THRESHOLD * 3)) {
            r.showSuggestions = true;
            r.suggestions = suggestionsFromIntent(best.intent);
        } else {
            r.showSuggestions = false;
        }

        return r;
    }
}
