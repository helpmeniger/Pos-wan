package com.hightechniger.help_me;

import android.content.Context;
import android.graphics.Color;
import android.graphics.Typeface;
import android.text.SpannableStringBuilder;
import android.text.Spanned;
import android.text.style.ForegroundColorSpan;
import android.text.style.StyleSpan;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.HorizontalScrollView;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import com.hightechniger.help_me.brain.Brain;
import com.hightechniger.help_me.ui.Course;
import com.hightechniger.help_me.ui.Ui;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Met en forme une réponse de l'assistant : étapes numérotées en cartes, chemins « Menu › Option » en gras,
 * images explicatives de la leçon correspondante (figures du manuel et illustrations) et accès à l'animation.
 */
final class ReplyVisuals {
    private ReplyVisuals() {}

    private static final Pattern STEP = Pattern.compile("^\\s*(\\d{1,2})[.)]\\s+(.+)$");
    /** « Accueil › Police › Gras » : groupes de mots reliés par ›. */
    private static final Pattern PATH = Pattern.compile("[\\p{Lu}][\\p{L}\\d’'()+.\\-]*(?: [\\p{L}\\d’'()+.\\-]+)?(?: ?[›>] ?[\\p{L}\\d’'()+.\\-]+(?: [\\p{Lu}\\d][\\p{L}\\d’'()+.\\-]*)?)+");

    static void decorate(Context c, LinearLayout bubble, Brain.Reply r) {
        TextView msg = bubble.getChildCount() > 0 && bubble.getChildAt(0) instanceof TextView ? (TextView) bubble.getChildAt(0) : null;
        int at = 1;
        if (msg != null) at = steps(c, bubble, msg, r.text);
        Course.Lesson lesson = lessonFor(c, r);
        List<Pic> pics = pictures(c, r, lesson);
        if (!pics.isEmpty()) { bubble.addView(gallery(c, pics), at++); }
        if (lesson != null) {
            String anim = null;
            for (String code : lesson.codes) if (AnimLessonActivity.exists(c, code)) { anim = code; break; }
            if (anim != null) {
                final String code = anim;
                TextView b = Ui.text(c, "▶  Voir l’explication animée, pas à pas", 14, Color.WHITE, true);
                b.setGravity(Gravity.CENTER);
                b.setBackground(Ui.rounded(c, Ui.BRAND, 20));
                b.setPadding(Ui.dp(c, 14), Ui.dp(c, 10), Ui.dp(c, 14), Ui.dp(c, 10));
                b.setOnClickListener(v -> AnimLessonActivity.open(c, code));
                Ui.pressable(b);
                LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
                lp.topMargin = Ui.dp(c, 10);
                bubble.addView(b, at, lp);
            }
        }
    }

    // ---------------------------------------------------------------- texte : étapes et chemins
    private static int steps(Context c, LinearLayout bubble, TextView msg, String text) {
        String[] lines = text.split("\n");
        StringBuilder intro = new StringBuilder(), outro = new StringBuilder();
        List<String> st = new ArrayList<>();
        for (String l : lines) {
            Matcher m = STEP.matcher(l);
            if (m.matches()) st.add(m.group(2).trim());
            else if (st.isEmpty()) intro.append(intro.length() > 0 ? "\n" : "").append(l);
            else outro.append(outro.length() > 0 ? "\n" : "").append(l);
        }
        if (st.size() < 2) { msg.setText(paths(c, text)); return 1; }
        msg.setText(paths(c, intro.toString().trim()));
        if (intro.toString().trim().isEmpty()) msg.setVisibility(View.GONE);
        int at = 1;
        for (int i = 0; i < st.size(); i++) bubble.addView(stepCard(c, i + 1, st.get(i), i == st.size() - 1), at++);
        if (outro.toString().trim().length() > 0) {
            TextView o = new TextView(c);
            o.setText(paths(c, outro.toString().trim()));
            o.setTextColor(Ui.TEXT);
            o.setTextSize(15);
            o.setPadding(0, Ui.dp(c, 8), 0, 0);
            bubble.addView(o, at++);
        }
        return at;
    }

    private static View stepCard(Context c, int n, String s, boolean last) {
        LinearLayout r = Ui.row(c);
        r.setGravity(Gravity.TOP);
        LinearLayout rail = Ui.column(c);
        rail.setGravity(Gravity.CENTER_HORIZONTAL);
        TextView num = Ui.text(c, String.valueOf(n), 13, Color.WHITE, true);
        num.setGravity(Gravity.CENTER);
        num.setBackground(Ui.circle(Ui.BRAND));
        rail.addView(num, new LinearLayout.LayoutParams(Ui.dp(c, 26), Ui.dp(c, 26)));
        if (!last) {
            View line = new View(c);
            line.setBackgroundColor(Ui.tint(Ui.BRAND, 0.6f));
            rail.addView(line, new LinearLayout.LayoutParams(Ui.dp(c, 2), ViewGroup.LayoutParams.MATCH_PARENT));
        }
        r.addView(rail, new LinearLayout.LayoutParams(Ui.dp(c, 26), ViewGroup.LayoutParams.MATCH_PARENT));
        TextView t = new TextView(c);
        t.setText(paths(c, s));
        t.setTextColor(Ui.TEXT);
        t.setTextSize(15);
        t.setLineSpacing(0, 1.15f);
        t.setBackground(Ui.outlined(c, Color.WHITE, Ui.BORDER, 10));
        t.setPadding(Ui.dp(c, 12), Ui.dp(c, 8), Ui.dp(c, 12), Ui.dp(c, 8));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1);
        lp.setMargins(Ui.dp(c, 10), 0, 0, last ? 0 : Ui.dp(c, 8));
        r.addView(t, lp);
        LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        rp.topMargin = n == 1 ? Ui.dp(c, 10) : 0;
        r.setLayoutParams(rp);
        return r;
    }

    /** Met en valeur les chemins de menus (« Insertion › Tableau ») : gras et couleur de la marque. */
    static CharSequence paths(Context c, String s) {
        s = s.replace(" > ", " › ");
        SpannableStringBuilder b = new SpannableStringBuilder(s);
        Matcher m = PATH.matcher(s);
        while (m.find()) {
            b.setSpan(new StyleSpan(Typeface.BOLD), m.start(), m.end(), Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
            b.setSpan(new ForegroundColorSpan(Ui.darker(Ui.BRAND, 0.9f)), m.start(), m.end(), Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
        }
        return b;
    }

    // ---------------------------------------------------------------- images de la leçon
    static final class Pic {
        final int res;
        final String cap;
        int score;
        Pic(int res, String cap) { this.res = res; this.cap = cap; }
    }

    static Course.Lesson lessonFor(Context c, Brain.Reply r) {
        Course course = Course.get(c);
        if (r.chapter != null) for (Course.Lesson l : course.all()) if (r.chapter.equalsIgnoreCase(l.title)) return l;
        if (r.lessonCode != null) { Course.Lesson l = course.byCode(r.lessonCode); if (l != null) return l; }
        // fiche sans chapitre : leçon du même thème dont le titre, les intertitres et les légendes ressemblent le plus à la réponse
        Set<String> words = words(r.text);
        Course.Lesson best = null;
        int bestScore = 0;
        for (Course.Lesson l : course.all()) {
            if (r.topic != null && l.module != null && !topicMatches(r.topic, l.module.id)) continue;
            int sc = 0;
            for (String w : words(l.title)) if (words.contains(w)) sc += 3;
            if (l.blocks != null) for (int i = 0; i < l.blocks.length(); i++) {
                JSONObject b = l.blocks.optJSONObject(i);
                if (b == null) continue;
                String t = b.optString("t");
                String x = "h".equals(t) ? b.optString("x") : "fig".equals(t) ? b.optString("cap") : null;
                if (x == null) continue;
                for (String w : words(android.text.Html.fromHtml(x, android.text.Html.FROM_HTML_MODE_COMPACT).toString())) if (words.contains(w)) sc += "fig".equals(t) ? 2 : 1;
            }
            if (sc > bestScore) { bestScore = sc; best = l; }
        }
        return bestScore >= 3 ? best : null;
    }

    private static final Set<String> MODULES = new HashSet<>(java.util.Arrays.asList("ordinateur", "windows", "internet", "word", "excel", "powerpoint", "ia"));

    /** Thème qui est un module (word, excel...) : on reste dans ce module ; sinon (général, erreur...) toutes les leçons. */
    private static boolean topicMatches(String topic, String module) {
        return !MODULES.contains(topic) || topic.equals(module);
    }

    /** Images utiles à la réponse : celle de la fiche, puis les figures de la leçon les plus proches du texte. */
    static List<Pic> pictures(Context c, Brain.Reply r, Course.Lesson lesson) {
        List<Pic> out = new ArrayList<>();
        Set<Integer> seen = new HashSet<>();
        if (r.image != null) {
            int id = c.getResources().getIdentifier(r.image, "drawable", c.getPackageName());
            if (id != 0 && seen.add(id)) { Pic p = new Pic(id, "Illustration"); p.score = 99; out.add(p); }
        }
        if (lesson == null || lesson.blocks == null) return out;
        Set<String> words = words(r.text);
        List<Pic> cands = new ArrayList<>();
        JSONArray bl = lesson.blocks;
        for (int i = 0; i < bl.length(); i++) {
            JSONObject b = bl.optJSONObject(i);
            if (b == null || !"fig".equals(b.optString("t"))) continue;
            int id = c.getResources().getIdentifier(b.optString("img"), "drawable", c.getPackageName());
            if (id == 0 || !seen.add(id)) continue;
            String cap = android.text.Html.fromHtml(b.optString("cap"), android.text.Html.FROM_HTML_MODE_COMPACT).toString();
            Pic p = new Pic(id, cap.isEmpty() ? lesson.title : cap);
            for (String w : words(cap)) if (words.contains(w)) p.score += 2;
            cands.add(p);
        }
        int ill = c.getResources().getIdentifier("ill_" + lesson.id.replace('-', '_'), "drawable", c.getPackageName());
        if (ill != 0 && seen.add(ill)) { Pic p = new Pic(ill, lesson.title); p.score = 1; cands.add(p); }
        cands.sort((a, b) -> b.score - a.score);
        for (Pic p : cands) { if (out.size() >= 3) break; out.add(p); }
        return out;
    }

    private static Set<String> words(String s) {
        Set<String> w = new HashSet<>();
        for (String t : Course.fold(s == null ? "" : s).split("[^a-z0-9]+")) if (t.length() > 3) w.add(t);
        return w;
    }

    private static View gallery(Context c, List<Pic> pics) {
        LinearLayout col = Ui.column(c);
        LinearLayout.LayoutParams cl = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        cl.topMargin = Ui.dp(c, 12);
        col.setLayoutParams(cl);
        TextView h = Ui.text(c, pics.size() > 1 ? "En images — faites défiler" : "En image", 12.5f, Ui.MUTED, true);
        col.addView(h);
        HorizontalScrollView hs = new HorizontalScrollView(c);
        hs.setHorizontalScrollBarEnabled(false);
        LinearLayout row = Ui.row(c);
        row.setGravity(Gravity.TOP);
        int w = pics.size() > 1 ? Ui.dp(c, 240) : ViewGroup.LayoutParams.MATCH_PARENT;
        for (Pic p : pics) {
            LinearLayout card = Ui.column(c);
            ImageView iv = new ImageView(c);
            iv.setImageResource(p.res);
            iv.setAdjustViewBounds(true);
            iv.setScaleType(ImageView.ScaleType.FIT_CENTER);
            iv.setBackground(Ui.outlined(c, Color.WHITE, Ui.BORDER, 10));
            iv.setClipToOutline(true);
            iv.setContentDescription(p.cap);
            iv.setOnClickListener(v -> ImageLessonActivity.open(c, p.res, p.cap));
            Ui.pressable(iv);
            card.addView(iv, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            TextView cap = Ui.text(c, p.cap, 12, Ui.MUTED, false);
            cap.setMaxLines(2);
            cap.setPadding(Ui.dp(c, 2), Ui.dp(c, 4), 0, 0);
            card.addView(cap);
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(w, ViewGroup.LayoutParams.WRAP_CONTENT);
            lp.setMarginEnd(Ui.dp(c, 10));
            row.addView(card, lp);
        }
        if (pics.size() > 1) { hs.addView(row); col.addView(hs, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)); }
        else { row.getChildAt(0).getLayoutParams().width = ViewGroup.LayoutParams.MATCH_PARENT; col.addView(row); }
        LinearLayout.LayoutParams hp = (LinearLayout.LayoutParams) h.getLayoutParams();
        if (hp != null) hp.bottomMargin = Ui.dp(c, 6);
        return col;
    }
}
