package com.hightechniger.help_me;

import android.animation.ObjectAnimator;
import android.animation.ValueAnimator;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.HapticFeedbackConstants;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.LinearInterpolator;
import android.view.animation.OvershootInterpolator;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import com.hightechniger.help_me.ui.Course;
import com.hightechniger.help_me.ui.Ui;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Locale;
import java.util.Random;

/**
 * Défi des raccourcis clavier : 10 questions chronométrées tirées du manuel (Windows, Word et Excel,
 * navigateur). Une action est proposée, il faut trouver le bon raccourci parmi quatre. Série, score,
 * meilleur score, explication après chaque réponse.
 */
public class ShortcutChallengeActivity extends AppCompatActivity {

    private static final int QUESTIONS = 10, SECONDS = 12;
    private static final int COLOR = Color.parseColor("#D9822B");

    private final Random random = new Random();
    private final Handler ui = new Handler(Looper.getMainLooper());
    private List<Course.Shortcut> pool;
    private List<Course.Shortcut> round;
    private int index, score, streak, bestStreak;
    private long questionStart;
    private boolean answered;

    private LinearLayout page;
    private ProgressBar timer;
    private ObjectAnimator timerAnim;
    private TextView counter, scoreView;
    // sons identiques à ceux du quiz : bonne réponse, mauvaise réponse ou temps écoulé
    private android.media.SoundPool sounds;
    private int soundOk, soundBad;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Ui.systemBars(this, Ui.darker(COLOR, 0.8f), false);
        pool = new ArrayList<>();
        for (Course.Shortcut s : Course.get(this).shortcuts)
            if (!s.keys.contains("/") && s.action.length() <= 60) pool.add(s);

        LinearLayout root = Ui.column(this);
        root.setBackgroundColor(Ui.BG);
        LinearLayout bar = Ui.row(this);
        bar.setBackgroundColor(COLOR);
        bar.setPadding(Ui.dp(this, 6), Ui.dp(this, 8), Ui.dp(this, 16), Ui.dp(this, 8));
        android.widget.ImageView bi = Ui.icon(this, R.drawable.ic_m_prev, Color.WHITE, 24);
        bi.setPadding(Ui.dp(this, 10), Ui.dp(this, 10), Ui.dp(this, 10), Ui.dp(this, 10));
        bi.setLayoutParams(new LinearLayout.LayoutParams(Ui.dp(this, 44), Ui.dp(this, 44)));
        bi.setOnClickListener(v -> finish());
        bar.addView(bi);
        TextView title = Ui.text(this, "Défi des raccourcis", 18, Color.WHITE, true);
        bar.addView(title, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        scoreView = Ui.pill(this, "0 pt", Color.WHITE, Color.parseColor("#33FFFFFF"));
        bar.addView(scoreView);
        root.addView(bar);
        timer = Ui.bar(this, Ui.darker(COLOR, 0.75f), Ui.tint(COLOR, 0.8f));
        timer.setMax(1000);
        timer.setLayoutParams(new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(this, 5)));
        root.addView(timer);

        ScrollView sv = new ScrollView(this);
        page = Ui.column(this);
        page.setPadding(Ui.dp(this, 20), Ui.dp(this, 20), Ui.dp(this, 20), Ui.dp(this, 30));
        sv.addView(page);
        root.addView(sv, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1));
        setContentView(root);
        sounds = new android.media.SoundPool.Builder().setMaxStreams(2).setAudioAttributes(
                new android.media.AudioAttributes.Builder().setUsage(android.media.AudioAttributes.USAGE_GAME)
                        .setContentType(android.media.AudioAttributes.CONTENT_TYPE_SONIFICATION).build()).build();
        soundOk = sounds.load(this, R.raw.success1, 1);
        soundBad = sounds.load(this, R.raw.buzzer1, 1);
        intro();
    }

    @Override
    protected void onDestroy() {
        ui.removeCallbacksAndMessages(null);
        if (timerAnim != null) timerAnim.cancel();
        if (sounds != null) sounds.release();
        super.onDestroy();
    }

    private SharedPreferences prefs() { return getSharedPreferences("helpme_progress", MODE_PRIVATE); }

    // ---------------------------------------------------------------- écran d'accueil du défi
    private void intro() {
        page.removeAllViews();
        timer.setProgress(0);
        page.addView(Ui.badge(this, R.drawable.ic_m_keyboard, COLOR, 72, true));
        TextView t = Ui.title(this, "Êtes-vous rapide au clavier ?", 24);
        t.setPadding(0, Ui.dp(this, 16), 0, 0);
        page.addView(t);
        TextView d = Ui.text(this, QUESTIONS + " questions, " + SECONDS + " secondes chacune. Une action s’affiche : touchez le bon "
                + "raccourci. Plus vous répondez vite, plus vous gagnez de points, et une série de bonnes réponses double les points.", 15.5f, Ui.MUTED, false);
        d.setPadding(0, Ui.dp(this, 8), 0, 0);
        page.addView(d);
        int best = prefs().getInt("shortcut_best", 0);
        if (best > 0) {
            TextView b = Ui.pill(this, "Votre meilleur score : " + best + " points", COLOR, Ui.tint(COLOR, 0.85f));
            b.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 14, 0, 0));
            page.addView(b);
        }
        page.addView(bigButton("Commencer le défi", v -> start()));
        TextView src = Ui.text(this, pool.size() + " raccourcis du manuel : Windows, Word et Excel, navigateur Internet.", 13, Ui.MUTED, false);
        src.setGravity(Gravity.CENTER);
        src.setPadding(0, Ui.dp(this, 12), 0, 0);
        page.addView(src);
        for (int i = 0; i < page.getChildCount(); i++) Ui.enter(page.getChildAt(i), i);
    }

    private void start() {
        List<Course.Shortcut> copy = new ArrayList<>(pool);
        Collections.shuffle(copy, random);
        round = copy.subList(0, Math.min(QUESTIONS, copy.size()));
        index = 0;
        score = 0;
        streak = 0;
        bestStreak = 0;
        showQuestion();
    }

    // ---------------------------------------------------------------- question
    private void showQuestion() {
        answered = false;
        page.removeAllViews();
        Course.Shortcut q = round.get(index);
        scoreView.setText(score + " pt");

        LinearLayout top = Ui.row(this);
        counter = Ui.text(this, String.format(Locale.FRANCE, "Question %d sur %d", index + 1, round.size()), 14, Ui.MUTED, true);
        top.addView(counter, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        if (streak >= 2) top.addView(Ui.pill(this, "Série × " + streak, Color.WHITE, COLOR));
        page.addView(top);

        LinearLayout card = Ui.card(this, null);
        card.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 12, 0, 0));
        card.addView(Ui.pill(this, q.ctx, COLOR, Ui.tint(COLOR, 0.85f)));
        TextView act = Ui.title(this, q.action, 22);
        act.setPadding(0, Ui.dp(this, 12), 0, Ui.dp(this, 4));
        card.addView(act);
        card.addView(Ui.text(this, "Quel raccourci clavier faut-il utiliser ?", 14.5f, Ui.MUTED, false));
        page.addView(card);
        Ui.enter(card, 0);

        List<Course.Shortcut> options = new ArrayList<>();
        options.add(q);
        List<Course.Shortcut> others = new ArrayList<>(pool);
        Collections.shuffle(others, random);
        // distracteurs du même contexte d'abord : plus instructif
        others.sort((a, b) -> Boolean.compare(!a.ctx.equals(q.ctx), !b.ctx.equals(q.ctx)));
        for (Course.Shortcut o : others) {
            if (options.size() == 4) break;
            if (o != q && !o.keys.equals(q.keys) && !o.action.equals(q.action)) options.add(o);
        }
        Collections.shuffle(options, random);
        List<TextView> buttons = new ArrayList<>();
        for (int i = 0; i < options.size(); i++) {
            Course.Shortcut o = options.get(i);
            TextView b = Ui.text(this, o.keys, 19, Ui.TEXT, true);
            b.setTypeface(android.graphics.Typeface.MONOSPACE, android.graphics.Typeface.BOLD);
            b.setGravity(Gravity.CENTER);
            b.setBackground(Ui.ripple(Ui.outlined(this, Color.WHITE, Ui.BORDER, 16), Ui.tint(COLOR, 0.7f)));
            b.setPadding(0, Ui.dp(this, 18), 0, Ui.dp(this, 18));
            b.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 12, 0, 0));
            Ui.pressable(b);
            b.setOnClickListener(v -> answer(o, q, buttons, options));
            buttons.add(b);
            page.addView(b);
            Ui.enter(b, i + 1);
        }

        questionStart = System.currentTimeMillis();
        if (timerAnim != null) timerAnim.cancel();
        timer.setProgress(1000);
        timerAnim = ObjectAnimator.ofInt(timer, "progress", 1000, 0);
        timerAnim.setDuration(SECONDS * 1000L);
        timerAnim.setInterpolator(new LinearInterpolator());
        timerAnim.start();
        ui.removeCallbacksAndMessages(null);
        ui.postDelayed(() -> { if (!answered) answer(null, q, buttons, options); }, SECONDS * 1000L);
    }

    private void answer(Course.Shortcut chosen, Course.Shortcut q, List<TextView> buttons, List<Course.Shortcut> options) {
        if (answered) return;
        answered = true;
        ui.removeCallbacksAndMessages(null);
        if (timerAnim != null) timerAnim.cancel();
        boolean ok = chosen == q;
        if (sounds != null) sounds.play(ok ? soundOk : soundBad, 1, 1, 1, 0, 1);
        page.performHapticFeedback(ok ? HapticFeedbackConstants.VIRTUAL_KEY : HapticFeedbackConstants.LONG_PRESS);
        for (int i = 0; i < buttons.size(); i++) {
            TextView b = buttons.get(i);
            b.setEnabled(false);
            Course.Shortcut o = options.get(i);
            if (o == q) {
                b.setBackground(Ui.rounded(this, Ui.SUCCESS, 16));
                b.setTextColor(Color.WHITE);
                b.animate().scaleX(1.04f).scaleY(1.04f).setDuration(220).setInterpolator(new OvershootInterpolator()).start();
            } else if (o == chosen) {
                b.setBackground(Ui.rounded(this, Ui.DANGER, 16));
                b.setTextColor(Color.WHITE);
                ObjectAnimator shake = ObjectAnimator.ofFloat(b, View.TRANSLATION_X, 0, -18, 18, -10, 10, 0);
                shake.setDuration(380);
                shake.start();
            } else {
                b.setAlpha(0.45f);
            }
        }
        int gained = 0;
        if (ok) {
            streak++;
            bestStreak = Math.max(bestStreak, streak);
            long ms = System.currentTimeMillis() - questionStart;
            gained = 10 + Math.max(0, (int) ((SECONDS * 1000L - ms) / 1000L));   // bonus de rapidité
            if (streak >= 3) gained *= 2;
            score += gained;
            animateScore();
        } else {
            streak = 0;
        }
        TextView fb = Ui.text(this, ok ? "Bonne réponse ! +" + gained + " points" + (streak >= 3 ? " (série × 2)" : "")
                : (chosen == null ? "Temps écoulé. " : "Pas tout à fait. ") + q.action + " : " + q.keys, 15.5f,
                ok ? Ui.SUCCESS : Ui.DANGER, true);
        fb.setPadding(0, Ui.dp(this, 18), 0, 0);
        page.addView(fb);
        Ui.enter(fb, 0);
        page.addView(bigButton(index + 1 < round.size() ? "Question suivante" : "Voir mon résultat", v -> {
            index++;
            if (index < round.size()) showQuestion(); else result();
        }));
    }

    private void animateScore() {
        scoreView.setText(score + " pt");
        scoreView.setScaleX(1.35f);
        scoreView.setScaleY(1.35f);
        scoreView.animate().scaleX(1f).scaleY(1f).setDuration(400).setInterpolator(new OvershootInterpolator(3)).start();
    }

    // ---------------------------------------------------------------- résultat
    private void result() {
        page.removeAllViews();
        timer.setProgress(0);
        int best = prefs().getInt("shortcut_best", 0);
        boolean record = score > best;
        if (record) prefs().edit().putInt("shortcut_best", score).apply();
        page.addView(Ui.badge(this, record ? R.drawable.ic_m_star : R.drawable.ic_m_check, record ? COLOR : Ui.SUCCESS, 80, true));
        TextView t = Ui.title(this, record ? "Nouveau record !" : "Défi terminé", 26);
        t.setPadding(0, Ui.dp(this, 16), 0, 0);
        page.addView(t);
        TextView big = Ui.title(this, "0", 56);
        big.setTextColor(COLOR);
        page.addView(big);
        ValueAnimator va = ValueAnimator.ofInt(0, score);
        va.setDuration(900);
        va.addUpdateListener(a -> big.setText(a.getAnimatedValue() + " points"));
        va.start();
        String msg = score >= 180 ? "Excellent ! Vous maîtrisez vos raccourcis comme un professionnel."
                : score >= 90 ? "Très bien ! Encore un peu de pratique et ils deviendront des réflexes."
                : "Bon début ! Relisez la leçon des raccourcis puis retentez le défi.";
        TextView m = Ui.text(this, msg + "\nMeilleure série : " + bestStreak + " bonnes réponses d’affilée.", 15.5f, Ui.MUTED, false);
        m.setPadding(0, Ui.dp(this, 6), 0, 0);
        page.addView(m);
        page.addView(bigButton("Rejouer", v -> start()));
        Course.Lesson l = Course.get(this).lesson(round.get(0).lessonId);
        if (l != null) {
            TextView lesson = Ui.text(this, "Revoir la leçon « " + l.title + " »", 15, COLOR, true);
            lesson.setGravity(Gravity.CENTER);
            lesson.setPadding(0, Ui.dp(this, 16), 0, 0);
            lesson.setOnClickListener(v -> LessonReaderActivity.open(this, l.id));
            page.addView(lesson);
        }
        for (int i = 0; i < page.getChildCount(); i++) Ui.enter(page.getChildAt(i), i);
    }

    private TextView bigButton(String label, View.OnClickListener l) {
        TextView b = Ui.text(this, label, 16.5f, Color.WHITE, true);
        b.setGravity(Gravity.CENTER);
        b.setBackground(Ui.ripple(Ui.rounded(this, COLOR, 28), Color.parseColor("#33FFFFFF")));
        b.setPadding(0, Ui.dp(this, 16), 0, Ui.dp(this, 16));
        b.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 24, 0, 0));
        Ui.pressable(b);
        b.setOnClickListener(l);
        return b;
    }
}
