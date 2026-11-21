package com.hightechniger.help_me;

import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.os.Bundle;
import android.speech.tts.TextToSpeech;
import android.view.Gravity;
import android.view.HapticFeedbackConstants;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.OvershootInterpolator;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.hightechniger.help_me.demo.DemoActivity;
import com.hightechniger.help_me.ui.Blocks;
import com.hightechniger.help_me.ui.Course;
import com.hightechniger.help_me.ui.Progress;
import com.hightechniger.help_me.ui.Ui;

import org.json.JSONObject;

import java.util.Locale;

/**
 * Lecteur de leçon : contenu du manuel affiché nativement (étapes, fiches, encadrés, exercices, figures
 * zoomables), barre de lecture, lecture à voix haute, favoris, « J'ai compris » et leçon suivante.
 */
public class LessonReaderActivity extends AppCompatActivity {

    public static final String EXTRA_LESSON = "lesson";

    public static void open(Context c, String lessonId) {
        c.startActivity(new Intent(c, LessonReaderActivity.class).putExtra(EXTRA_LESSON, lessonId));
    }

    private Course.Lesson lesson;
    private Progress progress;
    private TextToSpeech tts;
    private boolean ttsReady, speaking;
    private String spoken = "";
    private ImageView listenIcon, favIcon;
    private TextView learnBtn;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        lesson = Course.get(this).lesson(getIntent().getStringExtra(EXTRA_LESSON));
        if (lesson == null) { finish(); return; }
        progress = new Progress(this);
        progress.opened(lesson);
        int color = lesson.module.color;
        Ui.systemBars(this, Ui.darker(color, 0.82f), false);

        LinearLayout root = Ui.column(this);
        root.setBackgroundColor(Ui.BG);

        // ------------------------------------------------ barre du haut
        LinearLayout bar = Ui.row(this);
        bar.setBackgroundColor(color);
        bar.setPadding(Ui.dp(this, 6), Ui.dp(this, 6), Ui.dp(this, 6), Ui.dp(this, 6));
        bar.addView(iconButton(R.drawable.ic_m_prev, "Retour", v -> finish()));
        LinearLayout tt = Ui.column(this);
        tt.setPadding(Ui.dp(this, 6), 0, 0, 0);
        TextView mod = Ui.text(this, lesson.module.title, 12.5f, Color.parseColor("#D9FFFFFF"), true);
        tt.addView(mod);
        TextView pos = Ui.text(this, lesson.section.title + " · " + (lesson.section.lessons.indexOf(lesson) + 1)
                + " sur " + lesson.section.lessons.size(), 15, Color.WHITE, true);
        pos.setSingleLine(true);
        tt.addView(pos);
        bar.addView(tt, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        listenIcon = iconButton(R.drawable.ic_m_volume, "Écouter la leçon", v -> toggleSpeech());
        bar.addView(listenIcon);
        favIcon = iconButton(R.drawable.ic_m_star, "Ajouter aux favoris", v -> toggleFav());
        bar.addView(favIcon);
        refreshFav();
        root.addView(bar);

        ProgressBar readBar = Ui.bar(this, Ui.darker(color, 0.7f), Ui.tint(color, 0.75f));
        readBar.setLayoutParams(new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(this, 3)));
        root.addView(readBar);

        // ------------------------------------------------ contenu
        ScrollView scroll = new ScrollView(this);
        LinearLayout page = Ui.column(this);
        int p = Ui.dp(this, 20);
        page.setPadding(p, Ui.dp(this, 22), p, Ui.dp(this, 32));

        TextView title = Ui.title(this, lesson.title, 26);
        page.addView(title);
        LinearLayout meta = Ui.row(this);
        meta.setPadding(0, Ui.dp(this, 10), 0, 0);
        meta.addView(pill(lesson.minutes + " min de lecture", color));
        if (lesson.exercises > 0) meta.addView(pill(lesson.exercises + (lesson.exercises > 1 ? " exercices" : " exercice"), color));
        if (lesson.isManual()) meta.addView(pill("Manuel HELP-ME", color));
        page.addView(meta);

        LinearLayout content = Ui.column(this);
        Blocks blocks = new Blocks(this, color);
        blocks.render(lesson.blocks, content);
        spoken = lesson.title + ".\n" + blocks.plainText();
        page.addView(content);

        if (lesson.action != null) page.addView(actionButton(lesson.action, color));
        page.addView(endCard(color));
        scroll.addView(page);
        root.addView(scroll, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1));
        scroll.getViewTreeObserver().addOnScrollChangedListener(() -> {
            int max = page.getHeight() - scroll.getHeight();
            readBar.setProgress(max <= 0 ? 100 : Math.min(100, Math.round(100f * scroll.getScrollY() / max)));
        });

        // ------------------------------------------------ barre du bas
        root.addView(bottomBar(color));
        setContentView(root);
        Ui.enter(title, 0);
        Ui.enter(meta, 1);
        Ui.enter(content, 2);

        tts = new TextToSpeech(this, s -> {
            if (s == TextToSpeech.SUCCESS) {
                int r = tts.setLanguage(Locale.FRENCH);
                ttsReady = r != TextToSpeech.LANG_MISSING_DATA && r != TextToSpeech.LANG_NOT_SUPPORTED;
            }
            runOnUiThread(() -> listenIcon.setVisibility(ttsReady ? View.VISIBLE : View.GONE));
        });
    }

    @Override
    protected void onDestroy() {
        if (tts != null) tts.shutdown();
        super.onDestroy();
    }

    private ImageView iconButton(int res, String desc, View.OnClickListener l) {
        ImageView iv = Ui.icon(this, res, Color.WHITE, 24);
        int pd = Ui.dp(this, 10);
        iv.setPadding(pd, pd, pd, pd);
        iv.setLayoutParams(new LinearLayout.LayoutParams(Ui.dp(this, 44), Ui.dp(this, 44)));
        iv.setContentDescription(desc);
        iv.setBackground(Ui.ripple(Ui.circle(Color.TRANSPARENT), Color.parseColor("#33FFFFFF")));
        iv.setOnClickListener(l);
        return iv;
    }

    private TextView pill(String s, int color) {
        TextView t = Ui.pill(this, s, Ui.darker(color, 0.85f), Ui.tint(color, 0.88f));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.setMarginEnd(Ui.dp(this, 6));
        t.setLayoutParams(lp);
        return t;
    }

    // ---------------------------------------------------------------- action (démonstration, galerie...)
    private View actionButton(JSONObject a, int color) {
        TextView b = Ui.text(this, a.optString("label", "Ouvrir"), 16, Color.WHITE, true);
        b.setGravity(Gravity.CENTER);
        b.setBackground(Ui.ripple(Ui.rounded(this, color, 26), Color.parseColor("#33FFFFFF")));
        b.setPadding(0, Ui.dp(this, 15), 0, Ui.dp(this, 15));
        b.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 22, 0, 0));
        Ui.pressable(b);
        b.setOnClickListener(v -> {
            String type = a.optString("type"), target = a.optString("target");
            try {
                if ("demo".equals(type)) {
                    startActivity(new Intent(this, DemoActivity.class).putExtra(DemoActivity.EXTRA_DEMO, target));
                } else if ("activity".equals(type)) {
                    startActivity(new Intent(this, Class.forName(getPackageName() + "." + target)));
                }
            } catch (Exception e) {
                Toast.makeText(this, "Cette fonction n’est pas disponible.", Toast.LENGTH_SHORT).show();
            }
        });
        return b;
    }

    // ---------------------------------------------------------------- fin de leçon
    private View endCard(int color) {
        LinearLayout box = Ui.column(this);
        box.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 30, 0, 0));
        TextView q = Ui.title(this, "Une question sur cette leçon ?", 17);
        box.addView(q);
        TextView sub = Ui.text(this, "L’assistant HELP-ME répond sans connexion Internet.", 14, Ui.MUTED, false);
        sub.setPadding(0, Ui.dp(this, 2), 0, Ui.dp(this, 10));
        box.addView(sub);
        LinearLayout ask = Ui.card(this, v -> startActivity(new Intent(this, AIChatActivity.class).putExtra("question", lesson.title)));
        ask.setOrientation(LinearLayout.HORIZONTAL);
        ask.setGravity(Gravity.CENTER_VERTICAL);
        ask.addView(Ui.badge(this, R.drawable.ic_m_chat, color, 40, true));
        TextView at = Ui.text(this, "Demander à l’assistant", 15.5f, Ui.TEXT, true);
        at.setPadding(Ui.dp(this, 12), 0, 0, 0);
        ask.addView(at, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        ask.addView(Ui.icon(this, R.drawable.ic_m_chevron, Ui.MUTED, 22));
        box.addView(ask);

        Course.Lesson next = neighbour(1);
        if (next != null) {
            TextView nt = Ui.title(this, "Leçon suivante", 17);
            nt.setPadding(0, Ui.dp(this, 22), 0, Ui.dp(this, 10));
            box.addView(nt);
            LinearLayout nc = Ui.card(this, v -> { LessonReaderActivity.open(this, next.id); finish(); });
            nc.addView(Ui.text(this, next.section.title, 12.5f, color, true));
            TextView ntt = Ui.title(this, next.title, 16.5f);
            ntt.setPadding(0, Ui.dp(this, 2), 0, 0);
            nc.addView(ntt);
            if (next.summary != null && !next.summary.isEmpty()) {
                TextView ns = Ui.text(this, next.summary, 14, Ui.MUTED, false);
                ns.setMaxLines(2);
                ns.setEllipsize(android.text.TextUtils.TruncateAt.END);
                nc.addView(ns);
            }
            box.addView(nc);
        }
        return box;
    }

    private Course.Lesson neighbour(int d) {
        int i = lesson.index + d;
        return i >= 0 && i < lesson.module.lessons.size() ? lesson.module.lessons.get(i) : null;
    }

    private View bottomBar(int color) {
        LinearLayout b = Ui.row(this);
        b.setBackgroundColor(Color.WHITE);
        b.setElevation(Ui.dp(this, 10));
        b.setPadding(Ui.dp(this, 10), Ui.dp(this, 10), Ui.dp(this, 10), Ui.dp(this, 10));
        Course.Lesson prev = neighbour(-1), next = neighbour(1);
        b.addView(navButton(R.drawable.ic_m_prev, "Leçon précédente", prev, color));
        learnBtn = Ui.text(this, "", 15.5f, Color.WHITE, true);
        learnBtn.setGravity(Gravity.CENTER);
        learnBtn.setPadding(Ui.dp(this, 12), Ui.dp(this, 13), Ui.dp(this, 12), Ui.dp(this, 13));
        Ui.pressable(learnBtn);
        learnBtn.setOnClickListener(v -> toggleLearned(color, next));
        refreshLearn(color);
        b.addView(learnBtn, Ui.lpMargins(this, 0, ViewGroup.LayoutParams.WRAP_CONTENT, 10, 0, 10, 0));
        ((LinearLayout.LayoutParams) learnBtn.getLayoutParams()).weight = 1;
        b.addView(navButton(R.drawable.ic_m_next, "Leçon suivante", next, color));
        return b;
    }

    private View navButton(int res, String desc, Course.Lesson target, int color) {
        FrameLayout f = new FrameLayout(this);
        f.setBackground(Ui.ripple(Ui.circle(target == null ? Color.parseColor("#EEF1F6") : Ui.tint(color, 0.86f)), Ui.tint(color, 0.6f)));
        ImageView iv = Ui.icon(this, res, target == null ? Color.parseColor("#B8C1CE") : color, 22);
        f.addView(iv, new FrameLayout.LayoutParams(Ui.dp(this, 22), Ui.dp(this, 22), Gravity.CENTER));
        f.setLayoutParams(new LinearLayout.LayoutParams(Ui.dp(this, 48), Ui.dp(this, 48)));
        f.setContentDescription(desc);
        f.setEnabled(target != null);
        if (target != null) f.setOnClickListener(v -> { LessonReaderActivity.open(this, target.id); finish(); });
        return f;
    }

    private void refreshLearn(int color) {
        boolean ok = progress.isLearned(lesson.id);
        learnBtn.setText(ok ? "Leçon apprise" : "J’ai compris");
        learnBtn.setBackground(Ui.ripple(Ui.rounded(this, ok ? Ui.SUCCESS : color, 26), Color.parseColor("#33FFFFFF")));
        learnBtn.setCompoundDrawablesRelativeWithIntrinsicBounds(ok ? R.drawable.ic_m_check : 0, 0, 0, 0);
        learnBtn.setCompoundDrawablePadding(Ui.dp(this, 6));
    }

    private void toggleLearned(int color, Course.Lesson next) {
        boolean now = !progress.isLearned(lesson.id);
        progress.setLearned(lesson.id, now);
        learnBtn.performHapticFeedback(HapticFeedbackConstants.VIRTUAL_KEY);
        refreshLearn(color);
        if (now) {
            learnBtn.setScaleX(0.85f);
            learnBtn.setScaleY(0.85f);
            learnBtn.animate().scaleX(1f).scaleY(1f).setDuration(450).setInterpolator(new OvershootInterpolator(3f)).start();
            int done = progress.learnedIn(lesson.module), all = lesson.module.lessons.size();
            String msg = done == all ? "Bravo ! Module « " + lesson.module.title + " » terminé."
                    : String.format(Locale.FRANCE, "Bravo ! %d sur %d leçons apprises.", done, all);
            Toast.makeText(this, msg, Toast.LENGTH_SHORT).show();
        }
    }

    private void toggleFav() {
        progress.toggleFavorite(lesson.id);
        refreshFav();
        Toast.makeText(this, progress.isFavorite(lesson.id) ? "Ajoutée à vos favoris" : "Retirée des favoris", Toast.LENGTH_SHORT).show();
    }

    private void refreshFav() {
        favIcon.setAlpha(progress.isFavorite(lesson.id) ? 1f : 0.55f);
        favIcon.setColorFilter(progress.isFavorite(lesson.id) ? Color.parseColor("#FFD54F") : Color.WHITE);
    }

    private void toggleSpeech() {
        if (!ttsReady) return;
        if (speaking) {
            tts.stop();
            speaking = false;
            listenIcon.setAlpha(1f);
            return;
        }
        speaking = true;
        listenIcon.setAlpha(0.6f);
        // la synthèse vocale limite la longueur d'un texte : on découpe par paragraphes
        String[] parts = spoken.split("\n");
        for (int i = 0; i < parts.length; i++) {
            if (parts[i].trim().isEmpty()) continue;
            tts.speak(parts[i], i == 0 ? TextToSpeech.QUEUE_FLUSH : TextToSpeech.QUEUE_ADD, null, "p" + i);
        }
        Toast.makeText(this, "Lecture à voix haute… touchez à nouveau pour arrêter", Toast.LENGTH_SHORT).show();
    }
}
