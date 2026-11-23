package com.hightechniger.help_me.brain;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.Calendar;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Random;
import java.util.Set;
import java.util.regex.Pattern;

/**
 * Moteur de l'assistant HELP-ME, 100 % hors ligne.
 *
 * Compréhension : normalisation française, expressions figées, synonymes, correction des fautes
 * (distance d'édition sur le vocabulaire), détection du thème (Word, Excel, Windows...).
 * Recherche : BM25F sur trois champs (questions et titre, mots-clés, réponse), bonus de couverture
 * de la question, de proximité des mots (bigrammes), de thème et de source (le manuel d'abord).
 * Conversation : salutations, remerciements, aide, contact, calculs ; suites de questions
 * (« et dans Excel ? »), « plus de détails », « non, autre chose », choix parmi des propositions
 * quand la question est ambiguë ; apprentissage local par les avis « Utile / Pas utile ».
 *
 * Java pur + org.json (inclus dans Android) : le même code est évalué sur ordinateur.
 */
public final class Brain {

    // ================================================================ types publics

    /** Stockage persistant des avis (SharedPreferences sur Android). */
    public interface Memory {
        String get(String key);

        void put(String key, String value);
    }

    public static final class Chip {
        public static final String ASK = "ask", DOC = "doc", LESSON = "lesson";
        public final String label, action, arg;

        Chip(String label, String action, String arg) {
            this.label = label;
            this.action = action;
            this.arg = arg;
        }

        @Override public String toString() { return label; }
    }

    public static final class Reply {
        public enum Kind { ANSWER, CLARIFY, SMALLTALK, FALLBACK }

        public Kind kind = Kind.ANSWER;
        public String text = "";
        /** Petite ligne sous la réponse : source, correction comprise... */
        public String note;
        public String image;
        public int docId = -1;
        public String lessonCode;
        /** Chapitre du manuel de la fiche (pour retrouver la leçon et ses images). */
        public String chapter;
        /** Thème de la fiche (word, excel, windows, internet...). */
        public String topic;
        public double confidence;
        public String chipsTitle;
        public final List<Chip> chips = new ArrayList<>();
        public boolean askFeedback;
    }

    // ================================================================ données

    static final class Doc {
        int id, prio;
        String topic, title, answer, image, code, chapter, part, flag;
        String[] questions;
        int[] rel;
        int lenQ, lenK, lenA;
        final Set<String> bigrams = new HashSet<>();
        final Set<String> exact = new HashSet<>();
        final List<List<String>> qTerms = new ArrayList<>();
        final Set<String> allTerms = new HashSet<>();
        /** Termes du début de la réponse quand celle-ci est une définition (« X est un... »). */
        final Set<String> defLead = new HashSet<>();
        /** Réponse en étapes (« 1. », « › ») : préférée pour les questions « comment ... ». */
        boolean steps;
    }

    private static final class Posting {
        final int doc;
        final float q, k, a;

        Posting(int doc, float q, float k, float a) {
            this.doc = doc;
            this.q = q;
            this.k = k;
            this.a = a;
        }
    }

    static final class Scored {
        final Doc doc;
        /** score : classement ; base : pertinence brute (BM25F x couverture) qui sert à la confiance. */
        final double score, base, coverage;

        Scored(Doc doc, double score, double base, double coverage) {
            this.doc = doc;
            this.score = score;
            this.base = base;
            this.coverage = coverage;
        }
    }

    private static final class Query {
        final List<String> terms;
        final Map<String, Double> weight = new LinkedHashMap<>();
        final List<String> notes = new ArrayList<>();

        Query(List<String> terms) { this.terms = terms; }
    }

    private final List<Doc> docs = new ArrayList<>();
    private final Map<String, List<Posting>> postings = new HashMap<>();
    private final Map<Integer, List<String>> vocabByLen = new HashMap<>();
    /** Vocabulaire brut (mots repliés -> fréquence) pour corriger les fautes avant l'analyse. */
    private final Map<String, Integer> rawVocab = new HashMap<>();
    private final Map<Integer, List<String>> rawByLen = new HashMap<>();
    private double avgQ = 1, avgK = 1, avgA = 1;
    private final Memory memory;
    private final Random random = new Random();

    // BM25F
    private static double K1 = 1.2, BQ = 0.3, BK = 0.5, BA = 0.75, WQ = 3.0, WK = 1.2, WA = 0.8;
    /** Seuils de confiance (réglés avec tools/eval sur le jeu de questions de test). */
    static double SURE = 0.42, MAYBE = 0.20;

    /** Réglage des paramètres (outil d'évaluation uniquement). */
    public static void tune(String k, double v) {
        switch (k) {
            case "K1": K1 = v; break; case "BQ": BQ = v; break; case "BK": BK = v; break; case "BA": BA = v; break;
            case "WQ": WQ = v; break; case "WK": WK = v; break; case "WA": WA = v; break;
            case "SURE": SURE = v; break; case "MAYBE": MAYBE = v; break;
            default: throw new IllegalArgumentException(k);
        }
    }

    // conversation
    private List<String> lastTerms = Collections.emptyList();
    private List<Scored> lastRanking = Collections.emptyList();
    private final Set<Integer> shown = new HashSet<>();
    private Doc lastDoc;
    private List<Doc> offered = Collections.emptyList();

    // ================================================================ chargement

    public static Brain fromJson(String json, Memory memory) throws Exception {
        return new Brain(new JSONObject(json).getJSONArray("docs"), memory);
    }

    private Brain(JSONArray arr, Memory memory) throws Exception {
        this.memory = memory;
        for (int i = 0; i < arr.length(); i++) {
            JSONObject o = arr.getJSONObject(i);
            Doc d = new Doc();
            d.id = o.getInt("id");
            d.topic = o.optString("t", "general");
            d.title = o.has("ti") ? o.getString("ti") : null;
            d.answer = o.getString("a");
            d.image = o.has("img") ? o.getString("img") : null;
            d.code = o.has("code") ? o.getString("code") : null;
            d.chapter = o.has("ch") ? o.getString("ch") : null;
            d.part = o.has("part") ? o.getString("part") : null;
            d.flag = o.has("f") ? o.getString("f") : null;
            d.prio = o.optInt("p", 1);
            JSONArray q = o.optJSONArray("q");
            d.questions = new String[q == null ? 0 : q.length()];
            for (int k = 0; k < d.questions.length; k++) d.questions[k] = q.getString(k);
            JSONArray r = o.optJSONArray("rel");
            d.rel = new int[r == null ? 0 : r.length()];
            for (int k = 0; k < d.rel.length; k++) d.rel[k] = r.getInt(k);
            List<String> tags = new ArrayList<>();
            JSONArray kk = o.optJSONArray("k");
            if (kk != null) for (int k = 0; k < kk.length(); k++) tags.add(kk.getString(k));
            if (d.chapter != null) tags.add(d.chapter);
            index(d, tags);
            docs.add(d);
        }
        double sq = 0, sk = 0, sa = 0;
        for (Doc d : docs) { sq += d.lenQ; sk += d.lenK; sa += d.lenA; }
        int n = Math.max(1, docs.size());
        avgQ = Math.max(1, sq / n); avgK = Math.max(1, sk / n); avgA = Math.max(1, sa / n);
        for (String t : postings.keySet())
            vocabByLen.computeIfAbsent(t.length(), x -> new ArrayList<>()).add(t);
        for (String w : rawVocab.keySet())
            if (w.length() >= 3) rawByLen.computeIfAbsent(w.length(), x -> new ArrayList<>()).add(w);
    }

    private void index(Doc d, List<String> tags) {
        Map<String, float[]> tf = new HashMap<>();
        List<String> qs = new ArrayList<>(Arrays.asList(d.questions));
        if (d.title != null) qs.add(0, d.title);
        for (String q : qs) {
            List<String> t = TextTools.terms(q);
            d.exact.add(TextTools.join(" ", t));
            if (!t.isEmpty() && d.qTerms.size() < 30) d.qTerms.add(t);
            for (int i = 0; i < t.size(); i++) {
                tf.computeIfAbsent(t.get(i), x -> new float[3])[0]++;
                if (i > 0) d.bigrams.add(t.get(i - 1) + " " + t.get(i));
            }
            d.lenQ += t.size();
        }
        for (String g : tags) for (String t : TextTools.terms(g)) {
            tf.computeIfAbsent(t, x -> new float[3])[1]++;
            d.lenK++;
        }
        StringBuilder raw = new StringBuilder(d.answer).append(' ');
        for (String q : qs) raw.append(q).append(' ');
        for (String w : TextTools.rawWords(TextTools.fold(raw.toString())))
            if (w.length() >= 3 && Character.isLetter(w.charAt(0))) rawVocab.merge(w, 1, Integer::sum);
        String lead = TextTools.fold(d.answer.length() > 220 ? d.answer.substring(0, 220) : d.answer);
        if (DEF_LIKE.matcher(" " + lead.replace('\'', ' ')).find()) d.defLead.addAll(TextTools.terms(lead));
        d.steps = d.answer.contains("›") || d.answer.contains(" > ") || d.answer.matches("(?s).*(^|\\n)\\s*[12][.)] .*");
        List<String> at = TextTools.terms(d.answer);
        for (String t : at) tf.computeIfAbsent(t, x -> new float[3])[2]++;
        for (int i = 1; i < at.size(); i++) d.bigrams.add(at.get(i - 1) + " " + at.get(i));
        d.lenA = at.size();
        for (Map.Entry<String, float[]> e : tf.entrySet()) {
            float[] v = e.getValue();
            postings.computeIfAbsent(e.getKey(), x -> new ArrayList<>()).add(new Posting(docs.size(), v[0], v[1], v[2]));
            d.allTerms.add(e.getKey());
        }
    }

    public int size() { return docs.size(); }

    /** Nouvelle conversation (le contexte est oublié, les avis enregistrés restent). */
    public void reset() {
        lastTerms = Collections.emptyList();
        lastRanking = Collections.emptyList();
        shown.clear();
        lastDoc = null;
        offered = Collections.emptyList();
    }

    Brain fresh() { reset(); return this; }

    // ================================================================ thèmes

    private static final Map<String, String> TOPIC_OF = new HashMap<>();

    private static void topic(String t, String... words) {
        for (String w : words) TOPIC_OF.put(TextTools.stem(w), t);
    }

    static {
        topic("word", "word", "sommaire", "entete", "piedpage", "pagegarde", "miseenpage", "publipostage", "paragraphe");
        topic("excel", "excel", "cellule", "classeur", "recherchev", "tcd", "mfc", "formule", "somme", "moyenne");
        topic("powerpoint", "powerpoint", "diapositive", "transition");
        topic("windows", "windows", "explorateur", "barretaches", "menudemarrer", "panneauconfig", "gestionnairetaches", "bureau");
        topic("internet", "internet", "navigateur", "email", "wifi", "google", "gmail", "moteurrecherche", "youtube", "cloud");
        topic("ia", "ia", "chatgpt", "prompt", "gemini", "copilot", "claude", "machinelearning", "deeplearning");
        topic("securite", "virus", "motdepasse", "phishing", "parefeu", "securite", "pirate", "arnaque");
        topic("ordinateur", "ram", "disquedur", "cartemere", "unitecentrale", "clavier", "souris", "processeur", "peripherique");
        topic("erreur", "ecranbleu", "ecrannoir");
    }

    private static Set<String> topics(List<String> terms) {
        Set<String> s = new LinkedHashSet<>();
        for (String t : terms) {
            String tp = TOPIC_OF.get(t);
            if (tp != null) s.add(tp);
            if (t.startsWith("0x")) s.add("erreur");
        }
        return s;
    }

    // ================================================================ compréhension

    private static final Pattern DEF_LIKE = Pattern.compile(" (est un|est une|est le|est la|sont des|designe|signifie|definition|c est|permet de|sert a) ");

    private Query understand(String text) {
        // 1. correction des mots inconnus sur le vocabulaire brut (« dosier » -> « dossier »)
        List<String> fixes = new ArrayList<>();
        StringBuilder fixed = new StringBuilder();
        for (String w : TextTools.rawWords(TextTools.fold(text))) {
            if (w.isEmpty()) continue;
            String c = w;
            if (w.length() >= 4 && !rawVocab.containsKey(w) && !TextTools.isKnown(w) && w.chars().allMatch(Character::isLetter)) {
                String f = correctRaw(w);
                if (f != null) { c = f; fixes.add(w + " → " + f); }
            }
            fixed.append(c).append(' ');
        }
        List<String> raw = TextTools.terms(fixed.toString());
        List<String> terms = new ArrayList<>();
        Query q = new Query(terms);
        q.notes.addAll(fixes);
        for (String t : raw) {
            String c = t;
            if (!postings.containsKey(t) && t.length() >= 4 && !Character.isDigit(t.charAt(0))) {
                String fix = correct(t);
                if (fix != null) {
                    c = fix;
                    q.notes.add(t + " → " + fix);
                }
            }
            if (!terms.contains(c)) terms.add(c);
            q.weight.put(c, (c.equals(t) ? 1.0 : 0.8) * (TextTools.isWeak(c) ? 0.6 : 1.0));
        }
        return q;
    }

    private String correctRaw(String w) {
        int max = w.length() >= 8 ? 2 : 1;
        String best = null;
        int bestD = max + 1, bestF = -1;
        for (int len = w.length() - max; len <= w.length() + max; len++) {
            List<String> l = rawByLen.get(len);
            if (l == null) continue;
            for (String v : l) {
                if (max == 1 && v.charAt(0) != w.charAt(0)) continue;
                int d = TextTools.distance(w, v, max);
                if (d > max) continue;
                int f = rawVocab.get(v);
                if (d < bestD || (d == bestD && f > bestF)) { best = v; bestD = d; bestF = f; }
            }
        }
        return best;
    }

    /** Mot du vocabulaire le plus proche (1 faute jusqu'à 6 lettres, 2 au-delà), le plus fréquent à égalité. */
    private String correct(String w) {
        int max = w.length() <= 6 ? 1 : 2;
        String best = null;
        int bestD = max + 1, bestDf = -1;
        for (int len = w.length() - max; len <= w.length() + max; len++) {
            List<String> l = vocabByLen.get(len);
            if (l == null) continue;
            for (String v : l) {
                if (v.charAt(0) != w.charAt(0) && max == 1) continue;
                int d = TextTools.distance(w, v, max);
                if (d > max) continue;
                int df = postings.get(v).size();
                if (d < bestD || (d == bestD && df > bestDf)) {
                    best = v; bestD = d; bestDf = df;
                }
            }
        }
        return best;
    }

    private double idf(String t) {
        List<Posting> p = postings.get(t);
        int df = p == null ? 0 : p.size();
        return Math.log(1 + (docs.size() - df + 0.5) / (df + 0.5));
    }

    /** Classement des fiches pour une question. */
    List<Scored> rank(Query q, boolean definitionCue) { return rank(q, definitionCue, false); }

    List<Scored> rank(Query q, boolean definitionCue, boolean howTo) {
        if (q.terms.isEmpty()) return Collections.emptyList();
        Map<Integer, double[]> acc = new HashMap<>();   // [score, idf couvert]
        double totalIdf = 0;
        for (String t : q.terms) {
            double w = q.weight.get(t), idf = idf(t);
            totalIdf += w * idf;
            List<Posting> pl = postings.get(t);
            if (pl == null) continue;
            for (Posting p : pl) {
                Doc d = docs.get(p.doc);
                double wtf = WQ * p.q / (1 - BQ + BQ * d.lenQ / avgQ)
                        + WK * p.k / (1 - BK + BK * d.lenK / avgK)
                        + WA * p.a / (1 - BA + BA * d.lenA / avgA);
                double[] a = acc.computeIfAbsent(p.doc, x -> new double[2]);
                a[0] += w * idf * wtf * (K1 + 1) / (wtf + K1);
                a[1] += w * idf;
            }
        }
        Set<String> qTopics = topics(q.terms);
        String exact = TextTools.join(" ", q.terms);
        String key = memoryKey(q.terms);
        String liked = mem("q:" + key);
        String disliked = mem("n:" + key);
        List<Scored> out = new ArrayList<>();
        for (Map.Entry<Integer, double[]> e : acc.entrySet()) {
            Doc d = docs.get(e.getKey());
            double cov = totalIdf > 0 ? e.getValue()[1] / totalIdf : 0;
            double base = e.getValue()[0] * (0.2 + 0.8 * cov * cov);
            double s = base * (1 + 0.07 * (d.prio - 1));
            if (d.exact.contains(exact)) s *= 1.8;
            // précision : la meilleure question de la fiche ne doit pas parler d'autre chose (« éteindre l'écran »)
            double prec = 0;
            for (List<String> qt : d.qTerms) {
                int hit = 0;
                for (String t : qt) if (q.weight.containsKey(t)) hit++;
                prec = Math.max(prec, (double) hit / qt.size());
            }
            if (!d.qTerms.isEmpty()) s *= 0.55 + 0.45 * prec;
            int bi = 0;
            for (int i = 1; i < q.terms.size(); i++)
                if (d.bigrams.contains(q.terms.get(i - 1) + " " + q.terms.get(i))) bi++;
            s *= 1 + Math.min(0.6, 0.2 * bi);
            if (!qTopics.isEmpty()) {
                if (qTopics.contains(d.topic)) s *= 1.35;
                else {
                    boolean mentions = false;
                    for (String t : q.terms) if (TOPIC_OF.containsKey(t) && d.allTerms.contains(t)) mentions = true;
                    s *= mentions ? 1.0 : 0.45;
                }
            }
            if ("smalltalk".equals(d.topic)) s *= q.terms.size() > 3 ? 0.2 : 0.8;
            if (definitionCue) {
                if ("def".equals(d.flag) || d.prio >= 3) s *= 1.15;
                if (!d.defLead.isEmpty() && d.defLead.containsAll(q.terms)) s *= 2.2;
            } else if ("def".equals(d.flag) && (q.terms.size() >= 2 || howTo)) s *= howTo ? 0.4 : 0.7;
            if (howTo && d.steps) s *= 1.15;
            String id = Integer.toString(d.id);
            if (liked != null && Arrays.asList(liked.split(",")).contains(id)) s *= 2.5;
            if (disliked != null && Arrays.asList(disliked.split(",")).contains(id)) s *= 0.15;
            String votes = mem("d:" + id);
            if (votes != null) {
                String[] v = votes.split("/");
                s *= 1 + 0.04 * Math.min(8, Integer.parseInt(v[0])) - 0.07 * Math.min(6, Integer.parseInt(v[1]));
            }
            out.add(new Scored(d, s, base, cov));
        }
        Collections.sort(out, (a, b) -> Double.compare(b.score, a.score));
        return out;
    }

    /** Confiance 0..1 : score obtenu rapporté au score d'une fiche idéale pour cette question. */
    private double confidence(Query q, Scored s) {
        double ideal = 0;
        for (String t : q.terms) ideal += q.weight.get(t) * idf(t) * 0.55 * (K1 + 1);
        if (ideal <= 0) return 0;
        return Math.min(1, s.base / ideal) * (0.4 + 0.6 * s.coverage);
    }

    // ================================================================ conversation

    private static final Pattern GREET = Pattern.compile("^ (bonjour|bonsoir|salut|slt|bjr|bsr|hello|hi|hey|coucou|salam|salamalekoum|assalam\\w*|yo|bonne (journee|soiree|nuit)) ");
    private static final Pattern HOW = Pattern.compile(" (ca va|cv|comment (tu )?vas|comment allez|la forme|tu vas bien) ");
    private static final Pattern THANKS = Pattern.compile(" (merci|mrc|thanks|thank you|thx|je te remercie|super merci) ");
    private static final Pattern BYE = Pattern.compile(" (au revoir|aurevoir|bye|a plus|a bientot|a demain|ciao|bonne nuit) ");
    private static final Pattern WHO = Pattern.compile(" (qui es tu|qui est tu|t es qui|tu es qui|ton nom|tu t appelles|tu es un robot|qui t a (cree|fait|concu)|c est quoi help ?me|tu es quoi) ");
    private static final Pattern HELP = Pattern.compile("^ (aide|help|menu|aide moi|que sais tu faire|tu sais faire quoi|tu peux faire quoi|que peux tu faire|comment (ca marche|t utiliser)|quoi faire|par ou commencer|je veux apprendre|je suis debutant|debutant) ");
    private static final Pattern CONTACT = Pattern.compile(" (contact|contacter|joindre|whatsapp|numero|appeler|formateur|inscription|inscrire|centre de formation|adresse de help) ");
    private static final Pattern YES = Pattern.compile("^ (oui|ouais|ok|okay|d accord|daccord|exact|exactement|c est ca|voila|yes|celle la|la premiere) $");
    private static final Pattern NO = Pattern.compile("^ (non|pas ca|pas celle la|autre chose|une autre|ce n est pas ca|c est pas ca|faux|mauvaise reponse|autre) $");
    private static final Pattern MORE = Pattern.compile("^ (et )?(plus de details?|plus d explications?|explique (mieux|encore|plus)|developpe|detaille|precise|je (n ai|ne|n) (pas )?(compris|comprends)( pas)?|j ai pas compris|pas compris|je comprends pas|un exemple|exemple|donne (moi )?un exemple|et apres|la suite|encore|continue) $");
    private static final Pattern FOLLOW = Pattern.compile("^ (et|et pour|et dans|et sur|et avec|et en|et si|et pour le|et pour la|et avec le|aussi|pareil|meme chose|et le|et la|et les|et un|et une|et sous|et sur un|dans|sur|avec) ");
    private static final Pattern DEFINE = Pattern.compile(" (c est quoi|qu est ce que|qu est ce qu|definition|definir|que signifie|que veut dire|veut dire|signification) ");
    private static final Pattern HOWTO = Pattern.compile("^ (comment|cmt|coment|comman|komment|how)\\b| comment (faire|on|je|mettre|creer|inserer|ajouter) ");
    private static final Pattern INSULT = Pattern.compile(" (idiot|stupide|nul|imbecile|debile|con|connard|bete) ");
    private static final Pattern CALC = Pattern.compile("^[0-9 .,+\\-*/x×÷()%^]+$");

    /** Répond à un message de l'utilisateur. */
    public Reply ask(String input) {
        String text = input == null ? "" : input.trim();
        String g = " " + TextTools.fold(text).replaceAll("[^a-z0-9+]+", " ").trim() + " ";
        int words = g.trim().isEmpty() ? 0 : g.trim().split(" ").length;
        if (words == 0) return clarifyEmpty();

        // calculs simples : « 12*3 », « combien font 15% de 2000 »
        String calc = text.toLowerCase(Locale.ROOT).replaceFirst("^(combien (font|fait|vaut)|calcule[rz]?|calcul)\\s*", "").replace("=", "").replace("?", "").replaceAll("%\\s*de\\s*", "%*").trim();
        if (CALC.matcher(calc).matches() && calc.matches(".*\\d.*[+\\-*/x×÷%^].*\\d.*")) {
            Double v = Calc.eval(calc);
            if (v != null) return small("Résultat : " + calc.replaceAll("\\s+", " ") + " = " + Calc.format(v)
                    + "\n\nDans Excel, tapez la même opération précédée du signe = dans une cellule.");
        }
        // reprise de la conversation
        if (YES.matcher(g).find() && !offered.isEmpty()) return show(offered.get(0), "D’accord, voici la fiche :", null);
        if (NO.matcher(g).find() && (lastDoc != null || !offered.isEmpty())) return another("Je vous propose plutôt ceci :");
        if (MORE.matcher(g).find() && lastDoc != null) return more();

        if (WHO.matcher(g).find()) return who();
        if (HELP.matcher(g).find()) return help();
        if (CONTACT.matcher(g).find() && words <= 10) return contact();
        boolean greet = GREET.matcher(g).find(), how = HOW.matcher(g).find();
        if (THANKS.matcher(g).find() && words <= 6) return thanks();
        if (BYE.matcher(g).find() && words <= 5) return small(pick("Au revoir et bonne continuation dans votre apprentissage.",
                "À bientôt ! Pensez à pratiquer quelques minutes chaque jour.",
                "Au revoir. Je reste disponible, même sans connexion Internet."));
        if (INSULT.matcher(g).find() && words <= 5)
            return small("Je fais de mon mieux pour vous aider. Dites-moi ce qui ne va pas et je vous propose une autre explication.");

        Query q = understand(text);
        if (q.terms.isEmpty() || ((greet || how) && q.terms.size() <= 1)) {
            if (greet || how) return greeting(how);
            return clarifyEmpty();
        }
        boolean def = DEFINE.matcher(g).find() && q.terms.size() <= 3;
        boolean howTo = HOWTO.matcher(g).find() && !def;

        // suites de question : « et dans Excel ? », « et pour une image »
        boolean follow = FOLLOW.matcher(g).find() && !lastTerms.isEmpty();
        List<Scored> solo = rank(q, def, howTo);
        double soloConf = solo.isEmpty() ? 0 : confidence(q, solo.get(0));
        Query use = q;
        List<Scored> ranking = solo;
        if (!lastTerms.isEmpty() && (follow || (q.terms.size() <= 2 && soloConf < SURE))) {
            Query merged = mergeWithContext(q);
            List<Scored> mr = rank(merged, def, howTo);
            double mc = mr.isEmpty() ? 0 : confidence(merged, mr.get(0));
            if (follow ? mc >= soloConf * 0.7 : mc > soloConf * 1.15) {
                use = merged;
                ranking = mr;
            }
        }
        if (lastDoc != null && !ranking.isEmpty() && ranking.get(0).doc.id == lastDoc.id && ranking.size() > 1
                && use != q && ranking.get(1).score > 0.6 * ranking.get(0).score) {
            ranking = new ArrayList<>(ranking);
            ranking.add(0, ranking.remove(1));
        }
        return decide(use, ranking);
    }

    private Query mergeWithContext(Query q) {
        Set<String> newTopics = topics(q.terms);
        List<String> terms = new ArrayList<>(q.terms);
        Query m = new Query(terms);
        m.weight.putAll(q.weight);
        m.notes.addAll(q.notes);
        for (String t : lastTerms) {
            String tp = TOPIC_OF.get(t);
            if (tp != null && !newTopics.isEmpty() && !newTopics.contains(tp)) continue;   // Word remplacé par Excel
            if (!terms.contains(t)) {
                terms.add(t);
                m.weight.put(t, 0.7 * (TextTools.isWeak(t) ? 0.6 : 1.0));
            }
        }
        return m;
    }

    private Reply decide(Query q, List<Scored> ranking) {
        lastTerms = q.terms;
        lastRanking = ranking;
        shown.clear();
        double conf = ranking.isEmpty() ? 0 : confidence(q, ranking.get(0));
        String note = q.notes.isEmpty() ? null : "Compris : " + TextTools.join(", ", q.notes);
        if (conf >= SURE) {
            Reply r = show(ranking.get(0).doc, null, note);
            r.confidence = conf;
            // question ambiguë : deux fiches proches de thèmes différents
            if (ranking.size() > 1 && ranking.get(1).score > 0.85 * ranking.get(0).score
                    && !ranking.get(1).doc.topic.equals(ranking.get(0).doc.topic) && ranking.get(1).doc.title != null) {
                r.chips.clear();
                r.chipsTitle = "Vous parliez peut-être de :";
                addAlternatives(r, ranking, 3);
            }
            return r;
        }
        if (conf >= MAYBE) {
            Reply r = show(ranking.get(0).doc, pick("Voici ce qui se rapproche le plus de votre question :",
                    "Je pense que cette fiche répond à votre question :"), note);
            r.confidence = conf;
            r.chips.clear();
            r.chipsTitle = "Ce n’est pas ça ? Autres pistes :";
            addAlternatives(r, ranking, 3);
            return r;
        }
        // pas assez sûr : on propose des pistes plutôt qu'une mauvaise réponse
        Reply r = new Reply();
        r.kind = Reply.Kind.FALLBACK;
        r.confidence = conf;
        r.note = note;
        List<Doc> cands = new ArrayList<>();
        Set<String> titles = new HashSet<>();
        for (Scored s : ranking) {
            if (cands.size() == 3) break;
            if (s.doc.title == null || "smalltalk".equals(s.doc.topic) || s.coverage < 0.5
                    || confidence(q, s) < MAYBE * 0.6) continue;
            if (titles.add(s.doc.title.toLowerCase(Locale.ROOT))) cands.add(s.doc);
        }
        offered = cands;
        lastDoc = null;
        if (!cands.isEmpty()) {
            r.kind = Reply.Kind.CLARIFY;
            r.text = "Je ne suis pas certain d’avoir bien compris. Voulez-vous parler de l’un de ces sujets ?";
            r.chipsTitle = "Choisissez :";
            for (Doc d : cands) r.chips.add(new Chip(shortTitle(d), Chip.DOC, Integer.toString(d.id)));
        } else {
            r.text = "Je n’ai pas trouvé de réponse à cette question dans mes fiches.\n\n"
                    + "Essayez de préciser le logiciel et l’action, par exemple : « insérer une image dans Word », "
                    + "« faire une somme dans Excel » ou « créer un dossier ».\n\n"
                    + "Vous pouvez aussi joindre un formateur HELP-ME : +227 92 08 12 82 / 87 22 52 02 / 96 42 00 80.";
            topicChips(r);
        }
        return r;
    }

    private void addAlternatives(Reply r, List<Scored> ranking, int n) {
        Set<String> titles = new HashSet<>();
        if (lastDoc != null && lastDoc.title != null) titles.add(lastDoc.title.toLowerCase(Locale.ROOT));
        List<Doc> alt = new ArrayList<>();
        for (int i = 1; i < ranking.size() && alt.size() < n; i++) {
            Doc d = ranking.get(i).doc;
            if (d.title == null || "smalltalk".equals(d.topic)) continue;
            if (ranking.get(i).score < 0.25 * ranking.get(0).score) break;
            if (titles.add(d.title.toLowerCase(Locale.ROOT))) alt.add(d);
        }
        for (Doc d : alt) r.chips.add(new Chip(shortTitle(d), Chip.DOC, Integer.toString(d.id)));
        offered = alt;
        if (r.chips.isEmpty()) relatedChips(r, lastDoc);
    }

    /** Fiche choisie par l'utilisateur (bouton de suggestion). */
    public Reply open(int docId) {
        if (docId < 0 || docId >= docs.size()) return clarifyEmpty();
        Doc d = docs.get(docId);
        lastTerms = TextTools.terms(d.title != null ? d.title : d.questions.length > 0 ? d.questions[0] : "");
        return show(d, null, null);
    }

    private Reply show(Doc d, String lead, String note) {
        Reply r = new Reply();
        lastDoc = d;
        shown.add(d.id);
        offered = Collections.emptyList();
        r.kind = "smalltalk".equals(d.topic) ? Reply.Kind.SMALLTALK : Reply.Kind.ANSWER;
        r.docId = d.id;
        r.image = d.image;
        r.chapter = d.chapter;
        r.topic = d.topic;
        r.lessonCode = d.code;
        String body = d.answer;
        if ("def".equals(d.flag)) {
            String term = d.title != null ? d.title : d.questions.length > 0 ? d.questions[0] : "";
            if (!term.isEmpty() && !TextTools.fold(body).startsWith(TextTools.fold(term))) body = TextTools.cap(term) + " : " + body;
        }
        r.text = lead == null ? body : lead + "\n\n" + body;
        List<String> notes = new ArrayList<>();
        if (note != null) notes.add(note);
        if (d.prio >= 5) notes.add("Source : manuel HELP-ME" + (d.chapter != null ? " · " + d.chapter : ""));
        r.note = notes.isEmpty() ? null : TextTools.join("\n", notes);
        r.askFeedback = r.kind == Reply.Kind.ANSWER;
        if (r.kind == Reply.Kind.ANSWER) {
            if (d.code != null) {
                r.lessonCode = d.code;
                r.chips.add(new Chip("Ouvrir la leçon du manuel", Chip.LESSON, d.code));
            }
            // une autre explication, plus détaillée (même chapitre ou fiche voisine)
            r.chips.add(new Chip("Expliquer autrement", Chip.ASK, "explique mieux"));
            relatedChips(r, d);
        }
        return r;
    }

    private void relatedChips(Reply r, Doc d) {
        if (d == null) return;
        int before = r.chips.size();
        for (int id : d.rel) {
            if (id < 0 || id >= docs.size() || shown.contains(id)) continue;
            Doc o = docs.get(id);
            if (o.title != null) r.chips.add(new Chip(shortTitle(o), Chip.DOC, Integer.toString(o.id)));
        }
        if (r.chips.size() > before && r.chipsTitle == null) r.chipsTitle = "Voir aussi :";
    }

    /** « Non, autre chose » : fiche suivante du dernier classement. */
    private Reply another(String lead) {
        if (!offered.isEmpty()) {
            Doc d = offered.get(Math.min(1, offered.size() - 1));
            if (lastDoc == null || d.id != lastDoc.id) return show(d, lead, null);
        }
        for (Scored s : lastRanking) {
            if (shown.contains(s.doc.id) || "smalltalk".equals(s.doc.topic)) continue;
            if (lastDoc != null && s.doc.title != null && s.doc.title.equals(lastDoc.title)) continue;
            if (!lastRanking.isEmpty() && s.score < 0.45 * lastRanking.get(0).score) break;
            return show(s.doc, lead, null);
        }
        Reply r = new Reply();
        r.kind = Reply.Kind.FALLBACK;
        r.text = "Je n’ai pas d’autre fiche sur ce sujet. Reformulez votre question avec d’autres mots, "
                + "en précisant le logiciel (Word, Excel, Windows, Internet...).";
        topicChips(r);
        return r;
    }

    /** « Plus de détails », « je n'ai pas compris ». */
    private Reply more() {
        Doc base = lastDoc;
        Doc next = null;
        // fiche du manuel : la section suivante du même chapitre est la suite naturelle
        if (base.chapter != null) {
            for (Doc d : docs) {
                if (d.id != base.id && base.chapter.equals(d.chapter) && !shown.contains(d.id)) { next = d; break; }
            }
            if (next != null) {
                Reply r = show(next, "Voici la suite de cette leçon :", null);
                return r;
            }
        }
        double top = lastRanking.isEmpty() ? 0 : lastRanking.get(0).score;
        for (Scored s : lastRanking) {
            Doc d = s.doc;
            if (s.score < 0.5 * top) break;
            if (shown.contains(d.id) || "smalltalk".equals(d.topic) || !d.topic.equals(base.topic)) continue;
            if (base.title != null && base.title.equalsIgnoreCase(d.title)) continue;
            if (d.answer.length() > 80 && (d.prio != base.prio || d.answer.length() > base.answer.length())) { next = d; break; }
        }
        if (next == null) for (int id : base.rel) if (!shown.contains(id)) { next = docs.get(id); break; }
        if (next == null) {
            Reply r = new Reply();
            r.kind = Reply.Kind.FALLBACK;
            r.text = "Je n’ai pas d’explication plus détaillée sur ce point."
                    + (base.code != null ? " La leçon du manuel montre chaque étape avec des captures d’écran." : "")
                    + " Vous pouvez aussi joindre un formateur HELP-ME : +227 92 08 12 82.";
            if (base.code != null) r.chips.add(new Chip("Ouvrir la leçon du manuel", Chip.LESSON, base.code));
            return r;
        }
        Reply r = show(next, pick("Voici une autre explication :", "Je reprends autrement :"), null);
        if (base.code != null && r.lessonCode == null) r.chips.add(0, new Chip("Ouvrir la leçon du manuel", Chip.LESSON, base.code));
        return r;
    }

    // ================================================================ avis (apprentissage local)

    /** Enregistre l'avis sur une réponse. Si elle n'a pas aidé, renvoie une autre proposition (ou null). */
    public Reply feedback(int docId, boolean useful) {
        String key = memoryKey(lastTerms);
        String id = Integer.toString(docId);
        String votes = mem("d:" + id);
        int up = 0, down = 0;
        if (votes != null) { String[] v = votes.split("/"); up = Integer.parseInt(v[0]); down = Integer.parseInt(v[1]); }
        if (useful) up++; else down++;
        put("d:" + id, up + "/" + down);
        if (!key.isEmpty()) put((useful ? "q:" : "n:") + key, addTo(mem((useful ? "q:" : "n:") + key), id));
        if (useful) return null;
        return another("Désolé. Voici une autre proposition :");
    }

    private static String memoryKey(List<String> terms) {
        List<String> t = new ArrayList<>(new HashSet<>(terms));
        Collections.sort(t);
        return TextTools.join(" ", t);
    }

    private static String addTo(String list, String id) {
        if (list == null || list.isEmpty()) return id;
        List<String> l = new ArrayList<>(Arrays.asList(list.split(",")));
        if (!l.contains(id)) l.add(id);
        while (l.size() > 5) l.remove(0);
        return TextTools.join(",", l);
    }

    private String mem(String k) { return memory == null ? null : memory.get(k); }

    private void put(String k, String v) { if (memory != null) memory.put(k, v); }

    // ================================================================ messages fixes

    /** Sujets de départ : chaque bouton envoie une vraie question. */
    public static final String[][] STARTERS = {
            {"Les composants de l’ordinateur", "Quels sont les composants d’un ordinateur ?"},
            {"Organiser ses fichiers", "Comment créer un dossier et organiser ses fichiers ?"},
            {"Raccourcis clavier", "Quels sont les raccourcis clavier les plus utiles ?"},
            {"Capture d’écran", "Comment faire une capture d’écran ?"},
            {"Rechercher sur Internet", "Comment bien rechercher sur Internet ?"},
            {"Mot de passe sûr", "Comment créer un mot de passe sûr ?"},
            {"Tableau dans Word", "Comment insérer un tableau dans Word ?"},
            {"Somme dans Excel", "Comment faire une somme dans Excel ?"},
            {"Bien écrire un prompt", "Comment bien écrire un prompt pour une IA ?"},
            {"Présentation PowerPoint", "Comment créer une présentation PowerPoint ?"},
    };

    private void topicChips(Reply r) {
        r.chipsTitle = "Exemples de questions :";
        int start = random.nextInt(STARTERS.length);
        for (int i = 0; i < 5; i++) {
            String[] s = STARTERS[(start + i) % STARTERS.length];
            r.chips.add(new Chip(s[0], Chip.ASK, s[1]));
        }
    }

    /** Message d'accueil (selon l'heure). */
    public Reply welcome() {
        int h = Calendar.getInstance().get(Calendar.HOUR_OF_DAY);
        Reply r = small((h >= 18 || h < 4 ? "Bonsoir" : "Bonjour") + ", je suis l’assistant HELP-ME.\n\n"
                + "Posez-moi vos questions sur l’ordinateur, Windows, Internet, Word, Excel, PowerPoint ou l’intelligence "
                + "artificielle, avec vos propres mots. Je fonctionne sans connexion Internet.");
        r.chips.clear();
        topicChips(r);
        r.chipsTitle = "Pour commencer :";
        return r;
    }

    private Reply greeting(boolean how) {
        int h = Calendar.getInstance().get(Calendar.HOUR_OF_DAY);
        String hello = h >= 18 || h < 4 ? "Bonsoir" : "Bonjour";
        Reply r = small(how ? hello + " ! Je vais très bien, merci. Et vous ? Sur quoi voulez-vous progresser aujourd’hui ?"
                : pick(hello + " ! Que voulez-vous apprendre aujourd’hui ?",
                hello + " ! Je suis prêt. Posez votre question sur Word, Excel, Windows, Internet ou l’IA.",
                hello + " et bienvenue ! Dites-moi ce que vous cherchez à faire sur l’ordinateur."));
        topicChips(r);
        return r;
    }

    private Reply who() {
        return small("Je suis l’assistant de HELP-ME, l’application du manuel « Apprendre l’informatique de bureau ».\n\n"
                + "Je fonctionne entièrement sur votre téléphone, sans Internet. Je connais " + docs.size()
                + " fiches : l’ordinateur, Windows, Internet et la sécurité, Word, Excel, PowerPoint et l’intelligence "
                + "artificielle. Écrivez votre question normalement, même avec des fautes : je les corrige.");
    }

    private Reply help() {
        Reply r = small("Voici comment m’utiliser :\n"
                + "1. Écrivez une question simple, par exemple « comment mettre un texte en gras dans Word ? ».\n"
                + "2. Précisez le logiciel si possible (Word, Excel, Windows, Internet...).\n"
                + "3. Pour continuer sur le même sujet, écrivez « et dans Excel ? » ou « plus de détails ».\n"
                + "4. Si la réponse ne convient pas, touchez « Pas utile » : je propose autre chose et je m’en souviens.\n"
                + "5. Pour une leçon complète, scannez le code-barres en haut de la page du manuel.");
        topicChips(r);
        return r;
    }

    private Reply contact() {
        return small("Vous pouvez joindre l’équipe HELP-ME :\n"
                + "• Téléphone ou WhatsApp : +227 92 08 12 82 / 87 22 52 02 / 96 42 00 80\n"
                + "• E-mail : bureau@helpme.it\n• Site : www.helpme.it");
    }

    private Reply thanks() {
        Reply r = small(pick("Avec plaisir ! Avez-vous une autre question ?",
                "Je vous en prie. Continuez comme ça, la pratique fait la différence.",
                "De rien ! Je reste à votre disposition."));
        if (lastDoc != null) relatedChips(r, lastDoc);
        return r;
    }

    private Reply clarifyEmpty() {
        Reply r = small("Pouvez-vous préciser votre question ? Par exemple : « comment copier un fichier sur une clé USB ? »");
        r.kind = Reply.Kind.FALLBACK;
        topicChips(r);
        return r;
    }

    private Reply small(String text) {
        Reply r = new Reply();
        r.kind = Reply.Kind.SMALLTALK;
        r.text = text;
        return r;
    }

    private String pick(String... s) { return s[random.nextInt(s.length)]; }

    private static String shortTitle(Doc d) {
        String t = d.title.replaceAll("\\s*[?:.]\\s*$", "").trim();
        return t.length() > 46 ? t.substring(0, 44).trim() + "…" : t;
    }

    // ================================================================ accès pour l'évaluation
    Doc doc(int id) { return docs.get(id); }

    public String debug(String text, int n) {
        Query q = understand(text);
        List<Scored> r = rank(q, false);
        StringBuilder b = new StringBuilder(q.terms + " " + q.notes + "\n");
        for (int i = 0; i < Math.min(n, r.size()); i++)
            b.append(String.format(Locale.ROOT, "  %.2f cov=%.2f conf=%.2f [%s] %s%n", r.get(i).score, r.get(i).coverage,
                    confidence(q, r.get(i)), r.get(i).doc.topic, r.get(i).doc.title));
        return b.toString();
    }
}
