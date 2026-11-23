package com.hightechniger.help_me.ui;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.graphics.Color;
import android.graphics.Typeface;
import android.text.Html;
import android.text.Spanned;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import com.hightechniger.help_me.ImageLessonActivity;
import com.hightechniger.help_me.R;

import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Affiche les blocs d'une leçon (paragraphes, étapes, tableaux, encadrés, exercices, figures) en vues natives,
 * avec une présentation adaptée au téléphone : les tableaux deviennent des fiches empilées.
 */
public final class Blocks {

    private final Context c;
    private final int accent;
    private final StringBuilder plain = new StringBuilder();   // texte brut, pour la lecture à voix haute

    public Blocks(Context c, int accent) {
        this.c = c;
        this.accent = accent;
    }

    public String plainText() { return plain.toString(); }

    public static Spanned html(String s) { return Html.fromHtml(s == null ? "" : s, Html.FROM_HTML_MODE_COMPACT); }

    private void say(String htmlText) {
        plain.append(html(htmlText).toString().replace("›", ", ")).append(".\n");
    }

    public void render(JSONArray blocks, LinearLayout into) {
        for (int i = 0; i < blocks.length(); i++) {
            JSONObject b = blocks.optJSONObject(i);
            if (b == null) continue;
            View v = block(b);
            if (v != null) into.addView(v);
        }
    }

    private int dp(float v) { return Ui.dp(c, v); }

    private LinearLayout.LayoutParams gap(float top) {
        return Ui.lpMargins(c, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, top, 0, 0);
    }

    private TextView body(String h) {
        TextView t = Ui.text(c, html(h), 16, Ui.TEXT, false);
        t.setLineSpacing(0, 1.3f);
        return t;
    }

    private View block(JSONObject b) {
        String type = b.optString("t");
        switch (type) {
            case "p": {
                say(b.optString("x"));
                TextView t = body(b.optString("x"));
                t.setLayoutParams(gap(12));
                return t;
            }
            case "note": {
                TextView t = Ui.text(c, html(b.optString("x")), 14, Ui.MUTED, false);
                t.setTypeface(Typeface.defaultFromStyle(Typeface.ITALIC));
                t.setLayoutParams(gap(8));
                return t;
            }
            case "h": {
                say(b.optString("x"));
                LinearLayout r = Ui.row(c);
                View bar = new View(c);
                bar.setBackground(Ui.rounded(c, accent, 2));
                r.addView(bar, new LinearLayout.LayoutParams(dp(4), dp(22)));
                TextView t = Ui.title(c, b.optString("x"), 19);
                t.setPadding(dp(10), 0, 0, 0);
                r.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
                r.setLayoutParams(gap(28));
                return r;
            }
            case "h4": {
                TextView t = Ui.title(c, b.optString("x"), 16.5f);
                t.setLayoutParams(gap(16));
                return t;
            }
            case "ul":
            case "ol":
                return list(b.optJSONArray("items"), "ol".equals(type));
            case "table":
                return table(b);
            case "box":
                return box(b);
            case "goals":
                return goals(b.optJSONArray("items"));
            case "ex":
                return exercise(b);
            case "prompt":
                return prompt(b);
            case "code": {
                TextView t = Ui.text(c, html(b.optString("x")), 16, Ui.TEXT, true);
                t.setGravity(Gravity.CENTER);
                t.setTypeface(Typeface.MONOSPACE);
                t.setBackground(Ui.outlined(c, Ui.tint(accent, 0.92f), Ui.tint(accent, 0.7f), 12));
                t.setPadding(dp(14), dp(12), dp(14), dp(12));
                t.setLayoutParams(gap(12));
                return t;
            }
            case "cards":
                return cards(b.optJSONArray("items"));
            case "gloss":
                return glossary(b.optJSONArray("items"));
            case "fig":
                return figure(b);
            default:
                return null;
        }
    }

    // ---------------------------------------------------------------- listes et étapes
    private View list(JSONArray items, boolean ordered) {
        LinearLayout col = Ui.column(c);
        col.setLayoutParams(gap(10));
        if (items == null) return col;
        int n = items.length();
        for (int i = 0; i < n; i++) {
            String it = items.optString(i);
            say(it);
            LinearLayout r = Ui.row(c);
            r.setGravity(Gravity.TOP);
            if (ordered) {
                // étape : pastille numérotée reliée à la suivante + carte de texte
                LinearLayout rail = Ui.column(c);
                rail.setGravity(Gravity.CENTER_HORIZONTAL);
                TextView num = Ui.text(c, String.valueOf(i + 1), 14, Color.WHITE, true);
                num.setGravity(Gravity.CENTER);
                num.setBackground(Ui.circle(accent));
                rail.addView(num, new LinearLayout.LayoutParams(dp(30), dp(30)));
                if (i < n - 1) {
                    View line = new View(c);
                    line.setBackgroundColor(Ui.tint(accent, 0.6f));
                    rail.addView(line, new LinearLayout.LayoutParams(dp(2), ViewGroup.LayoutParams.MATCH_PARENT));
                }
                r.addView(rail, new LinearLayout.LayoutParams(dp(30), ViewGroup.LayoutParams.MATCH_PARENT));
                TextView t = body(it);
                t.setTextSize(15.5f);
                t.setBackground(Ui.outlined(c, Ui.SURFACE, Ui.BORDER, 12));
                t.setPadding(dp(14), dp(10), dp(14), dp(10));
                LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1);
                lp.setMargins(dp(12), 0, 0, i < n - 1 ? dp(10) : 0);
                r.addView(t, lp);
                r.setLayoutParams(gap(0));
            } else {
                View mark = new View(c);
                mark.setBackground(Ui.circle(accent));
                r.addView(mark, Ui.lpMargins(c, dp(7), dp(7), 8, 9, 14, 0));
                TextView t = body(it);
                r.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
                r.setLayoutParams(gap(i == 0 ? 0 : 10));
            }
            col.addView(r);
        }
        return col;
    }

    // ---------------------------------------------------------------- tableaux -> fiches
    private View table(JSONObject b) {
        JSONArray head = b.optJSONArray("head");
        JSONArray rows = b.optJSONArray("rows");
        LinearLayout col = Ui.column(c);
        col.setBackground(Ui.outlined(c, Ui.SURFACE, Ui.BORDER, 14));
        col.setLayoutParams(gap(12));
        if (rows == null) return col;
        for (int i = 0; i < rows.length(); i++) {
            JSONArray r = rows.optJSONArray(i);
            if (r == null || r.length() == 0) continue;
            LinearLayout item = Ui.column(c);
            item.setPadding(dp(14), dp(12), dp(14), dp(12));
            if (i > 0) {
                View sep = new View(c);
                sep.setBackgroundColor(Ui.BORDER);
                col.addView(sep, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 1));
            }
            TextView first = Ui.text(c, html(r.optString(0)), 15.5f, Ui.darker(accent, 0.85f), true);
            item.addView(first);
            StringBuilder spoken = new StringBuilder(r.optString(0));
            for (int k = 1; k < r.length(); k++) {
                String label = head != null && head.length() > k && r.length() > 2 ? head.optString(k) + " : " : "";
                TextView t = body((label.isEmpty() ? "" : "<b>" + label + "</b>") + r.optString(k));
                t.setTextSize(15);
                t.setLayoutParams(gap(3));
                item.addView(t);
                spoken.append(" : ").append(r.optString(k));
            }
            say(spoken.toString());
            col.addView(item);
        }
        return col;
    }

    // ---------------------------------------------------------------- encadrés
    private View box(JSONObject b) {
        String k = b.optString("k");
        int color = "warn".equals(k) ? Ui.WARN : "retenir".equals(k) ? Ui.SUCCESS : accent;
        // encadré sobre : barre de couleur à gauche, titre en petites capitales, sans pictogramme
        LinearLayout outer = Ui.row(c);
        outer.setGravity(Gravity.TOP);
        outer.setBackground(Ui.rounded(c, Ui.tint(color, 0.93f), 12));
        outer.setLayoutParams(gap(14));
        View bar = new View(c);
        bar.setBackground(Ui.rounded(c, color, 2));
        outer.addView(bar, new LinearLayout.LayoutParams(dp(4), ViewGroup.LayoutParams.MATCH_PARENT));
        LinearLayout box = Ui.column(c);
        box.setPadding(dp(14), dp(12), dp(14), dp(14));
        String title = b.optString("title");
        if (title.isEmpty()) title = "warn".equals(k) ? "Attention" : "retenir".equals(k) ? "À retenir" : "Bon à savoir";
        TextView t = Ui.text(c, title.toUpperCase(java.util.Locale.FRENCH), 12.5f, Ui.darker(color, 0.8f), true);
        t.setLetterSpacing(0.06f);
        box.addView(t);
        say(title + " : " + b.optString("x"));
        TextView x = body(b.optString("x"));
        x.setTextSize(15);
        x.setLayoutParams(gap(6));
        box.addView(x);
        outer.addView(box, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        return outer;
    }

    private View goals(JSONArray items) {
        LinearLayout box = Ui.column(c);
        box.setBackground(Ui.outlined(c, Ui.SURFACE, Ui.tint(accent, 0.6f), 14));
        box.setPadding(dp(14), dp(12), dp(14), dp(12));
        box.setLayoutParams(gap(14));
        TextView t = Ui.text(c, "Dans cette leçon, vous allez", 13, accent, true);
        box.addView(t);
        if (items != null) for (int i = 0; i < items.length(); i++) {
            LinearLayout r = Ui.row(c);
            r.setGravity(Gravity.TOP);
            r.addView(Ui.icon(c, R.drawable.ic_m_check, Ui.SUCCESS, 18), Ui.lpMargins(c, dp(18), dp(18), 0, 2, 8, 0));
            TextView x = body(items.optString(i));
            x.setTextSize(15);
            r.addView(x, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
            r.setLayoutParams(gap(6));
            box.addView(r);
        }
        return box;
    }

    private View exercise(JSONObject b) {
        LinearLayout box = Ui.column(c);
        box.setBackground(Ui.outlined(c, Ui.tint(accent, 0.95f), Ui.tint(accent, 0.75f), 16));
        box.setPadding(dp(16), dp(14), dp(16), dp(16));
        box.setLayoutParams(gap(16));
        LinearLayout head = Ui.row(c);
        TextView t = Ui.title(c, b.optString("title"), 16);
        t.setPadding(0, 0, dp(8), 0);
        head.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        String lvl = b.optString("lvl");
        if (!lvl.isEmpty()) {
            int lc = lvl.startsWith("déb") ? Ui.SUCCESS : lvl.startsWith("inter") ? Ui.WARN : Ui.DANGER;
            head.addView(Ui.pill(c, lvl, lc, Ui.tint(lc, 0.85f)));
        }
        box.addView(head);
        say(b.optString("title"));
        JSONArray sub = b.optJSONArray("blocks");
        if (sub != null) {
            LinearLayout inner = Ui.column(c);
            render(sub, inner);
            box.addView(inner);
        }
        return box;
    }

    private View prompt(JSONObject b) {
        LinearLayout box = Ui.column(c);
        box.setBackground(Ui.rounded(c, Color.parseColor("#1E2533"), 14));
        box.setPadding(dp(14), dp(12), dp(14), dp(14));
        box.setLayoutParams(gap(14));
        LinearLayout head = Ui.row(c);
        TextView t = Ui.text(c, b.optString("title"), 13, Color.parseColor("#9FB3D1"), true);
        head.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        TextView copy = Ui.text(c, "Copier", 13, Color.parseColor("#8AB4FF"), true);
        copy.setPadding(dp(8), dp(4), 0, dp(4));
        String text = html(b.optString("x")).toString();
        copy.setOnClickListener(v -> {
            ClipboardManager cm = (ClipboardManager) c.getSystemService(Context.CLIPBOARD_SERVICE);
            if (cm != null) cm.setPrimaryClip(ClipData.newPlainText("prompt", text));
            Toast.makeText(c, "Exemple copié : collez-le dans votre assistant IA", Toast.LENGTH_SHORT).show();
        });
        head.addView(copy);
        box.addView(head);
        say(b.optString("x"));
        TextView x = Ui.text(c, text, 15, Color.parseColor("#F1F4F9"), false);
        x.setLineSpacing(0, 1.3f);
        x.setLayoutParams(gap(8));
        box.addView(x);
        return box;
    }

    private View cards(JSONArray items) {
        LinearLayout col = Ui.column(c);
        col.setLayoutParams(gap(10));
        if (items != null) for (int i = 0; i < items.length(); i++) {
            JSONObject it = items.optJSONObject(i);
            LinearLayout card = Ui.card(c, null);
            card.setLayoutParams(gap(i == 0 ? 0 : 10));
            card.addView(Ui.text(c, html(it.optString("title")), 15.5f, accent, true));
            TextView x = body(it.optString("x"));
            x.setTextSize(15);
            x.setLayoutParams(gap(4));
            card.addView(x);
            say(it.optString("title") + " : " + it.optString("x"));
            col.addView(card);
        }
        return col;
    }

    private View glossary(JSONArray items) {
        LinearLayout col = Ui.column(c);
        col.setLayoutParams(gap(8));
        if (items != null) for (int i = 0; i < items.length(); i++) {
            JSONObject it = items.optJSONObject(i);
            TextView t = body("<b>" + it.optString("term") + "</b> : " + it.optString("def"));
            t.setTextSize(15);
            t.setLayoutParams(gap(i == 0 ? 0 : 10));
            col.addView(t);
        }
        return col;
    }

    // ---------------------------------------------------------------- figures du manuel
    private View figure(JSONObject b) {
        int res = c.getResources().getIdentifier(b.optString("img"), "drawable", c.getPackageName());
        if (res == 0) return null;
        LinearLayout col = Ui.column(c);
        col.setLayoutParams(gap(16));
        ImageView iv = new ImageView(c);
        iv.setImageResource(res);
        iv.setAdjustViewBounds(true);
        iv.setBackground(Ui.outlined(c, Color.WHITE, Ui.BORDER, 12));
        iv.setPadding(dp(6), dp(6), dp(6), dp(6));
        iv.setClipToOutline(true);
        iv.setContentDescription(b.has("cap") ? html(b.optString("cap")).toString() : "Illustration");
        String cap = b.has("cap") ? html(b.optString("cap")).toString() : null;
        iv.setOnClickListener(v -> {
            if (cap != null) ImageLessonActivity.open(c, res, cap); else ImageLessonActivity.open(c, res);
        });
        Ui.pressable(iv);
        col.addView(iv, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        LinearLayout r = Ui.row(c);
        r.setPadding(dp(2), dp(6), 0, 0);
        TextView capV = Ui.text(c, cap != null ? cap : "Touchez l’image pour l’agrandir", 13, Ui.MUTED, false);
        r.addView(capV, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        col.addView(r);
        return col;
    }
}
