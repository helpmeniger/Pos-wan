package com.hightechniger.help_me;

import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.os.Bundle;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import com.hightechniger.help_me.ui.Course;
import com.hightechniger.help_me.ui.Progress;
import com.hightechniger.help_me.ui.Ui;

import java.util.Locale;

/**
 * Écran d'un module (Word, Excel, Windows...) : en-tête aux couleurs du module, progression,
 * recherche instantanée et liste des leçons regroupées par section.
 */
public class ModuleActivity extends AppCompatActivity {

    public static final String EXTRA_MODULE = "module";

    public static void open(Context c, String moduleId) {
        c.startActivity(new Intent(c, ModuleActivity.class).putExtra(EXTRA_MODULE, moduleId));
    }

    private Course.Module module;
    private Progress progress;
    private LinearLayout list;
    private ProgressBar bar;
    private TextView barLabel;
    private TextView continueBtn;
    private String filter = "";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        module = Course.get(this).module(getIntent().getStringExtra(EXTRA_MODULE));
        if (module == null) { finish(); return; }
        progress = new Progress(this);
        Ui.systemBars(this, Ui.darker(module.color, 0.82f), false);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Ui.BG);
        ScrollView scroll = new ScrollView(this);
        scroll.setClipToPadding(false);
        scroll.setPadding(0, 0, 0, Ui.dp(this, 96));
        LinearLayout page = Ui.column(this);
        page.addView(header());
        page.addView(searchField());
        list = Ui.column(this);
        list.setPadding(Ui.dp(this, 16), 0, Ui.dp(this, 16), 0);
        page.addView(list);
        scroll.addView(page);
        root.addView(scroll);

        continueBtn = Ui.text(this, "", 16, Color.WHITE, true);
        continueBtn.setGravity(Gravity.CENTER);
        continueBtn.setBackground(Ui.ripple(Ui.rounded(this, module.color, 28), Color.parseColor("#33FFFFFF")));
        continueBtn.setElevation(Ui.dp(this, 6));
        continueBtn.setPadding(Ui.dp(this, 22), Ui.dp(this, 14), Ui.dp(this, 22), Ui.dp(this, 14));
        FrameLayout.LayoutParams fl = new FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.BOTTOM | Gravity.CENTER_HORIZONTAL);
        fl.bottomMargin = Ui.dp(this, 20);
        root.addView(continueBtn, fl);
        Ui.pressable(continueBtn);
        setContentView(root);
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (module == null) return;
        refresh();
    }

    private void refresh() {
        int done = progress.learnedIn(module), all = module.lessons.size();
        int pct = progress.percent(module);
        bar.setProgress(pct, true);
        barLabel.setText(String.format(Locale.FRANCE, "%d sur %d leçons apprises · %d %%", done, all, pct));
        Course.Lesson next = progress.next(module);
        if (next != null) {
            continueBtn.setText(done == 0 ? "Commencer : " + shortTitle(next.title)
                    : done == all ? "Revoir le module" : "Continuer : " + shortTitle(next.title));
            continueBtn.setOnClickListener(v -> LessonReaderActivity.open(this, next.id));
        }
        buildList();
    }

    private static String shortTitle(String t) { return t.length() > 26 ? t.substring(0, 25).trim() + "…" : t; }

    // ---------------------------------------------------------------- en-tête
    private View header() {
        LinearLayout h = Ui.column(this);
        h.setBackground(new android.graphics.drawable.GradientDrawable(
                android.graphics.drawable.GradientDrawable.Orientation.TL_BR,
                new int[]{module.color, Ui.darker(module.color, 0.72f)}));
        int p = Ui.dp(this, 16);
        h.setPadding(p, Ui.dp(this, 8), p, Ui.dp(this, 40));

        LinearLayout top = Ui.row(this);
        ImageView back = Ui.icon(this, R.drawable.ic_m_prev, Color.WHITE, 24);
        back.setPadding(Ui.dp(this, 2), Ui.dp(this, 2), Ui.dp(this, 2), Ui.dp(this, 2));
        back.setContentDescription("Retour");
        back.setOnClickListener(v -> finish());
        top.addView(back, new LinearLayout.LayoutParams(Ui.dp(this, 40), Ui.dp(this, 40)));
        h.addView(top);

        LinearLayout id = Ui.row(this);
        id.setPadding(0, Ui.dp(this, 10), 0, 0);
        FrameLayout badge = Ui.badge(this, iconRes(this, module.id), Color.parseColor("#33FFFFFF"), 56, false);
        id.addView(badge);
        LinearLayout tt = Ui.column(this);
        tt.setPadding(Ui.dp(this, 14), 0, 0, 0);
        TextView title = Ui.title(this, module.title, 26);
        title.setTextColor(Color.WHITE);
        tt.addView(title);
        TextView sub = Ui.text(this, module.subtitle, 14.5f, Color.parseColor("#E6FFFFFF"), false);
        tt.addView(sub);
        id.addView(tt, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        h.addView(id);

        int ex = 0, min = 0;
        for (Course.Lesson l : module.lessons) { ex += l.exercises; min += l.minutes; }
        LinearLayout stats = Ui.row(this);
        stats.setPadding(0, Ui.dp(this, 16), 0, 0);
        stats.addView(stat(module.lessons.size() + " leçons"));
        if (ex > 0) stats.addView(stat(ex + " exercices"));
        stats.addView(stat(min >= 60 ? (min / 60) + " h " + String.format(Locale.FRANCE, "%02d", min % 60) : min + " min"));
        h.addView(stats);

        bar = Ui.bar(this, Color.WHITE, Color.parseColor("#40FFFFFF"));
        bar.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(this, 6), 0, 16, 0, 0));
        h.addView(bar);
        barLabel = Ui.text(this, "", 13, Color.parseColor("#E6FFFFFF"), false);
        barLabel.setPadding(0, Ui.dp(this, 6), 0, 0);
        h.addView(barLabel);
        return h;
    }

    private TextView stat(String s) {
        TextView t = Ui.pill(this, s, Color.WHITE, Color.parseColor("#2EFFFFFF"));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.setMarginEnd(Ui.dp(this, 8));
        t.setLayoutParams(lp);
        return t;
    }

    private View searchField() {
        LinearLayout box = Ui.row(this);
        box.setBackground(Ui.rounded(this, Color.WHITE, 26));
        box.setElevation(Ui.dp(this, 4));
        box.setPadding(Ui.dp(this, 16), 0, Ui.dp(this, 12), 0);
        box.addView(Ui.icon(this, R.drawable.ic_m_search, Ui.MUTED, 20));
        EditText e = new EditText(this);
        e.setHint("Rechercher dans " + module.title);
        e.setBackground(null);
        e.setTextSize(15.5f);
        e.setSingleLine(true);
        e.setTextColor(Ui.TEXT);
        e.setHintTextColor(Ui.MUTED);
        e.setPadding(Ui.dp(this, 10), Ui.dp(this, 14), 0, Ui.dp(this, 14));
        e.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void onTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void afterTextChanged(Editable s) { filter = s.toString(); buildList(); }
        });
        box.addView(e, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        box.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 16, -26, 16, 8));
        return box;
    }

    // ---------------------------------------------------------------- leçons
    private void buildList() {
        list.removeAllViews();
        String f = Course.fold(filter).trim();
        int shown = 0;
        for (Course.Section s : module.sections) {
            LinearLayout cards = Ui.column(this);
            int n = 0;
            for (Course.Lesson l : s.lessons) {
                if (!f.isEmpty() && !Course.fold(l.title + " " + l.summary).contains(f)) continue;
                View card = lessonCard(l);
                card.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 10, 0, 0));
                cards.addView(card);
                if (filter.isEmpty()) Ui.enter(card, shown);
                n++;
                shown++;
            }
            if (n == 0) continue;
            LinearLayout head = Ui.row(this);
            head.setPadding(Ui.dp(this, 2), Ui.dp(this, 22), 0, Ui.dp(this, 2));
            head.addView(Ui.title(this, s.title, 18), new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
            head.addView(Ui.text(this, String.valueOf(n), 14, Ui.MUTED, true));
            list.addView(head);
            list.addView(cards);
        }
        if (shown == 0) {
            TextView t = Ui.text(this, "Aucune leçon ne correspond à « " + filter + " ».\nEssayez un autre mot, ou posez la question à l’assistant.", 15, Ui.MUTED, false);
            t.setGravity(Gravity.CENTER);
            t.setPadding(0, Ui.dp(this, 40), 0, 0);
            list.addView(t);
            TextView ask = Ui.text(this, "Demander à l’assistant", 15, module.color, true);
            ask.setGravity(Gravity.CENTER);
            ask.setPadding(0, Ui.dp(this, 14), 0, 0);
            ask.setOnClickListener(v -> startActivity(new Intent(this, AIChatActivity.class).putExtra("question", filter)));
            list.addView(ask);
        }
    }

    private View lessonCard(Course.Lesson l) {
        boolean learned = progress.isLearned(l.id);
        LinearLayout card = Ui.card(this, v -> LessonReaderActivity.open(this, l.id));
        card.setOrientation(LinearLayout.HORIZONTAL);
        card.setGravity(Gravity.TOP);

        TextView num = Ui.text(this, String.valueOf(l.section.lessons.indexOf(l) + 1), 15, learned ? Color.WHITE : module.color, true);
        num.setGravity(Gravity.CENTER);
        num.setBackground(Ui.circle(learned ? Ui.SUCCESS : Ui.tint(module.color, 0.86f)));
        FrameLayout numBox = new FrameLayout(this);
        numBox.addView(num, new FrameLayout.LayoutParams(Ui.dp(this, 36), Ui.dp(this, 36)));
        if (learned) {
            num.setText("");
            ImageView ck = Ui.icon(this, R.drawable.ic_m_check, Color.WHITE, 20);
            FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(Ui.dp(this, 20), Ui.dp(this, 20), Gravity.CENTER);
            numBox.addView(ck, lp);
        }
        card.addView(numBox, new LinearLayout.LayoutParams(Ui.dp(this, 36), Ui.dp(this, 36)));

        LinearLayout txt = Ui.column(this);
        txt.setPadding(Ui.dp(this, 14), 0, 0, 0);
        txt.addView(Ui.title(this, l.title, 16.5f));
        if (l.summary != null && !l.summary.isEmpty()) {
            TextView sum = Ui.text(this, l.summary, 14, Ui.MUTED, false);
            sum.setMaxLines(2);
            sum.setEllipsize(android.text.TextUtils.TruncateAt.END);
            sum.setPadding(0, Ui.dp(this, 3), 0, 0);
            txt.addView(sum);
        }
        LinearLayout meta = Ui.row(this);
        meta.setPadding(0, Ui.dp(this, 8), 0, 0);
        meta.addView(metaPill(l.minutes + " min"));
        if (l.exercises > 0) meta.addView(metaPill(l.exercises + (l.exercises > 1 ? " exercices" : " exercice")));
        String kind = kindLabel(l);
        if (kind != null) meta.addView(metaPill(kind));
        txt.addView(meta);
        card.addView(txt, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        return card;
    }

    private static String kindLabel(Course.Lesson l) {
        switch (l.kind) {
            case "astuce": return "Astuce";
            case "demo": return "Démonstration";
            case "modeles": return "Modèles";
            case "lien": return "En ligne";
            default: return l.code != null ? "Code " + l.code : null;
        }
    }

    private TextView metaPill(String s) {
        TextView t = Ui.pill(this, s, Ui.MUTED, Color.parseColor("#EEF1F6"));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.setMarginEnd(Ui.dp(this, 6));
        t.setLayoutParams(lp);
        return t;
    }

    /** Icône blanche de chaque module. */
    public static int iconRes(Context c, String moduleId) {
        int r = c.getResources().getIdentifier("ic_m_" + moduleId, "drawable", c.getPackageName());
        return r != 0 ? r : R.drawable.ic_m_ressources;
    }
}
