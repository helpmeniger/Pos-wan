package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.fragment.app.Fragment;

import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.auth.FirebaseUser;
import com.hightechniger.help_me.brain.BrainHolder;
import com.hightechniger.help_me.ui.Course;
import com.hightechniger.help_me.ui.Progress;
import com.hightechniger.help_me.ui.Ui;

import java.util.Calendar;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.Executors;

/**
 * Accueil : salutation et progression, « reprendre la leçon », recherche dans toutes les leçons,
 * accès rapides (scanner, assistant, quiz, manuel), grille des modules et défi du jour.
 * Secouer le téléphone ouvre toujours le scanner de codes-barres.
 */
public class HomeFragment extends Fragment implements SensorEventListener {

    private Activity context;
    private LinearLayout page, dynamic, results;
    private Course course;
    private Progress progress;

    private SensorManager sensorManager;
    private long lastTime = 0;
    private float lastX = 0, lastY = 0, lastZ = 0;
    private static final int SHAKE_THRESHOLD = 2600;

    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        context = requireActivity();
        progress = new Progress(context);
        ScrollView scroll = new ScrollView(context);
        scroll.setBackgroundColor(Ui.BG);
        scroll.setFillViewport(true);
        page = Ui.column(context);
        page.setPadding(0, 0, 0, Ui.dp(context, 28));
        scroll.addView(page);
        TextView loading = Ui.text(context, "Chargement des cours…", 15, Ui.MUTED, false);
        loading.setGravity(Gravity.CENTER);
        loading.setPadding(0, Ui.dp(context, 80), 0, 0);
        page.addView(loading);
        // cours et assistant chargés en arrière-plan : l'accueil s'affiche sans attente
        Handler main = new Handler(Looper.getMainLooper());
        Executors.newSingleThreadExecutor().execute(() -> {
            Course c = Course.get(context);
            main.post(() -> { if (isAdded()) { course = c; build(); } });
        });
        BrainHolder.preload(context);
        return scroll;
    }

    @Override
    public void onResume() {
        super.onResume();
        if (course != null) refreshDynamic();
        sensorManager = (SensorManager) context.getSystemService(Context.SENSOR_SERVICE);
        Sensor acc = sensorManager != null ? sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER) : null;
        if (acc != null) sensorManager.registerListener(this, acc, SensorManager.SENSOR_DELAY_UI);
    }

    @Override
    public void onPause() {
        super.onPause();
        if (sensorManager != null) sensorManager.unregisterListener(this);
    }

    // ================================================================ construction
    private void build() {
        page.removeAllViews();
        page.addView(hero());
        page.addView(search());
        results = Ui.column(context);
        results.setPadding(Ui.dp(context, 16), 0, Ui.dp(context, 16), 0);
        page.addView(results);
        dynamic = Ui.column(context);
        page.addView(dynamic);
        refreshDynamic();
    }

    private void refreshDynamic() {
        if (dynamic == null) return;
        dynamic.removeAllViews();
        View resume = resumeCard();
        if (resume != null) dynamic.addView(resume);
        dynamic.addView(sectionTitle("Vos modules"));
        dynamic.addView(moduleGrid());
        dynamic.addView(sectionTitle("Accès rapide"));
        dynamic.addView(quickActions());
        dynamic.addView(sectionTitle("Pour s’entraîner"));
        dynamic.addView(practiceCards());
        updateHero();
    }

    private TextView heroPct, heroStreak;
    private ProgressBar heroBar;

    private View hero() {
        LinearLayout h = Ui.column(context);
        h.setBackground(new android.graphics.drawable.GradientDrawable(android.graphics.drawable.GradientDrawable.Orientation.TL_BR,
                new int[]{Ui.BRAND, Ui.BRAND_DARK}));
        h.setPadding(Ui.dp(context, 20), Ui.dp(context, 22), Ui.dp(context, 20), Ui.dp(context, 44));
        int hour = Calendar.getInstance().get(Calendar.HOUR_OF_DAY);
        TextView hello = Ui.title(context, (hour >= 18 || hour < 4 ? "Bonsoir" : "Bonjour") + userName(), 25);
        hello.setTextColor(Color.WHITE);
        h.addView(hello);
        TextView sub = Ui.text(context, "Apprenez l’informatique pas à pas, à votre rythme.", 15, Color.parseColor("#DCE7F7"), false);
        h.addView(sub);
        LinearLayout row = Ui.row(context);
        row.setPadding(0, Ui.dp(context, 18), 0, 0);
        heroPct = Ui.text(context, "", 14, Color.WHITE, true);
        row.addView(heroPct, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        heroStreak = Ui.pill(context, "", Color.WHITE, Color.parseColor("#33FFFFFF"));
        heroStreak.setCompoundDrawablesRelativeWithIntrinsicBounds(R.drawable.ic_m_calendar, 0, 0, 0);
        heroStreak.setCompoundDrawablePadding(Ui.dp(context, 4));
        row.addView(heroStreak);
        h.addView(row);
        heroBar = Ui.bar(context, Color.WHITE, Color.parseColor("#40FFFFFF"));
        heroBar.setLayoutParams(Ui.lpMargins(context, ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(context, 6), 0, 8, 0, 0));
        h.addView(heroBar);
        return h;
    }

    private void updateHero() {
        int pct = progress.totalPercent(course);
        heroPct.setText("Votre parcours : " + pct + " %");
        heroBar.setProgress(pct, true);
        int s = progress.streak();
        heroStreak.setText(s <= 1 ? (s == 1 ? "1 jour" : "Commencez aujourd’hui") : s + " jours de suite");
    }

    private String userName() {
        try {
            FirebaseUser u = FirebaseAuth.getInstance().getCurrentUser();
            if (u == null) return "";
            String n = u.getDisplayName();
            if (n == null || n.trim().isEmpty()) {
                String e = u.getEmail();
                if (e == null || !e.contains("@")) return "";
                n = e.substring(0, e.indexOf('@')).replaceAll("[._\\d]+", " ").trim();
                if (n.contains(" ")) n = n.substring(0, n.indexOf(' '));
            } else if (n.contains(" ")) {
                n = n.substring(0, n.indexOf(' '));
            }
            return n.isEmpty() ? "" : " " + Character.toUpperCase(n.charAt(0)) + n.substring(1).toLowerCase(Locale.FRANCE);
        } catch (Exception e) {
            return "";
        }
    }

    // ================================================================ recherche globale
    private View search() {
        LinearLayout box = Ui.row(context);
        box.setBackground(Ui.rounded(context, Color.WHITE, 26));
        box.setElevation(Ui.dp(context, 4));
        box.setPadding(Ui.dp(context, 16), 0, Ui.dp(context, 12), 0);
        box.addView(Ui.icon(context, R.drawable.ic_m_search, Ui.MUTED, 20));
        EditText e = new EditText(context);
        e.setHint("Que voulez-vous apprendre ?");
        e.setBackground(null);
        e.setSingleLine(true);
        e.setTextSize(15.5f);
        e.setTextColor(Ui.TEXT);
        e.setHintTextColor(Ui.MUTED);
        e.setPadding(Ui.dp(context, 10), Ui.dp(context, 14), 0, Ui.dp(context, 14));
        e.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void onTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void afterTextChanged(Editable s) { showResults(s.toString()); }
        });
        box.addView(e, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        box.setLayoutParams(Ui.lpMargins(context, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 16, -26, 16, 4));
        return box;
    }

    private void showResults(String q) {
        results.removeAllViews();
        boolean searching = q.trim().length() >= 2;
        dynamic.setVisibility(searching ? View.GONE : View.VISIBLE);
        if (!searching) return;
        List<Course.Lesson> found = course.search(q);
        TextView head = Ui.text(context, found.isEmpty() ? "Aucune leçon trouvée." : found.size() + " leçon" + (found.size() > 1 ? "s" : ""), 14, Ui.MUTED, true);
        head.setPadding(Ui.dp(context, 4), Ui.dp(context, 14), 0, Ui.dp(context, 4));
        results.addView(head);
        int n = 0;
        for (Course.Lesson l : found) {
            if (n++ == 25) break;
            LinearLayout card = Ui.card(context, v -> LessonReaderActivity.open(context, l.id));
            card.setOrientation(LinearLayout.HORIZONTAL);
            card.setGravity(Gravity.CENTER_VERTICAL);
            card.addView(Ui.badge(context, ModuleActivity.iconRes(context, l.module.id), l.module.color, 40, true));
            LinearLayout t = Ui.column(context);
            t.setPadding(Ui.dp(context, 12), 0, 0, 0);
            t.addView(Ui.text(context, l.module.title, 12.5f, l.module.color, true));
            t.addView(Ui.title(context, l.title, 15.5f));
            card.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
            card.setLayoutParams(Ui.lpMargins(context, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 8, 0, 0));
            results.addView(card);
        }
        LinearLayout ask = Ui.card(context, v -> startActivity(new Intent(context, AIChatActivity.class).putExtra("question", q)));
        ask.setOrientation(LinearLayout.HORIZONTAL);
        ask.setGravity(Gravity.CENTER_VERTICAL);
        ask.addView(Ui.badge(context, R.drawable.ic_m_chat, Ui.BRAND, 40, false));
        TextView at = Ui.text(context, "Poser la question à l’assistant", 15.5f, Ui.BRAND, true);
        at.setPadding(Ui.dp(context, 12), 0, 0, 0);
        ask.addView(at);
        ask.setLayoutParams(Ui.lpMargins(context, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 12, 0, 0));
        results.addView(ask);
    }

    // ================================================================ sections
    private View sectionTitle(String s) {
        TextView t = Ui.title(context, s, 19);
        t.setPadding(Ui.dp(context, 18), Ui.dp(context, 24), Ui.dp(context, 18), Ui.dp(context, 8));
        return t;
    }

    private View resumeCard() {
        Course.Lesson l = course.lesson(progress.lastLesson());
        boolean first = l == null;
        if (first) l = course.modules.isEmpty() ? null : course.modules.get(0).lessons.get(0);
        if (l == null) return null;
        final Course.Lesson target = l;
        LinearLayout card = Ui.card(context, v -> LessonReaderActivity.open(context, target.id));
        card.setBackground(Ui.ripple(Ui.outlined(context, Color.WHITE, Ui.tint(l.module.color, 0.6f), 20), Ui.tint(l.module.color, 0.75f)));
        LinearLayout r = Ui.row(context);
        r.addView(Ui.badge(context, ModuleActivity.iconRes(context, l.module.id), l.module.color, 48, false));
        LinearLayout t = Ui.column(context);
        t.setPadding(Ui.dp(context, 14), 0, Ui.dp(context, 6), 0);
        t.addView(Ui.text(context, first ? "Commencer le parcours" : "Reprendre · " + l.module.title, 13, l.module.color, true));
        TextView tt = Ui.title(context, l.title, 17);
        tt.setMaxLines(2);
        t.addView(tt);
        r.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        FrameLayout play = Ui.badge(context, R.drawable.ic_m_play, l.module.color, 40, true);
        r.addView(play);
        card.addView(r);
        ProgressBar pb = Ui.bar(context, l.module.color, Ui.tint(l.module.color, 0.85f));
        pb.setLayoutParams(Ui.lpMargins(context, ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(context, 6), 0, 14, 0, 0));
        pb.setProgress(progress.percent(l.module));
        card.addView(pb);
        card.addView(Ui.text(context, progress.learnedIn(l.module) + " sur " + l.module.lessons.size() + " leçons apprises dans ce module", 12.5f, Ui.MUTED, false));
        card.setLayoutParams(Ui.lpMargins(context, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 16, 16, 16, 0));
        Ui.enter(card, 0);
        return card;
    }

    private View quickActions() {
        LinearLayout row = Ui.row(context);
        row.setPadding(Ui.dp(context, 12), 0, Ui.dp(context, 12), 0);
        row.addView(quick("Scanner", "un code", R.drawable.ic_m_scan, Ui.BRAND, MainActivity8.class));
        row.addView(quick("Assistant", "hors ligne", R.drawable.ic_m_chat, Color.parseColor("#6A4FC2"), AIChatActivity.class));
        row.addView(quick("Quiz", "testez-vous", R.drawable.ic_m_quiz, Color.parseColor("#0F8A5F"), QuizActivity.class));
        row.addView(quick("Manuel", "en PDF", R.drawable.ic_m_ressources, Color.parseColor("#C43E1C"), MainActivity6.class));
        return row;
    }

    private View quick(String label, String sub, int icon, int color, Class<?> target) {
        LinearLayout c = Ui.column(context);
        c.setGravity(Gravity.CENTER_HORIZONTAL);
        c.setPadding(Ui.dp(context, 4), Ui.dp(context, 12), Ui.dp(context, 4), Ui.dp(context, 10));
        c.setBackground(Ui.ripple(Ui.outlined(context, Color.WHITE, Ui.BORDER, 18), Ui.tint(color, 0.75f)));
        c.setClickable(true);
        c.setOnClickListener(v -> startActivity(new Intent(context, target)));
        Ui.pressable(c);
        c.addView(Ui.badge(context, icon, color, 44, true));
        TextView t = Ui.text(context, label, 13.5f, Ui.TEXT, true);
        t.setPadding(0, Ui.dp(context, 6), 0, 0);
        c.addView(t);
        TextView s = Ui.text(context, sub, 11, Ui.MUTED, false);
        s.setGravity(Gravity.CENTER);
        c.addView(s);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1);
        lp.setMargins(Ui.dp(context, 4), 0, Ui.dp(context, 4), 0);
        c.setLayoutParams(lp);
        return c;
    }

    private View moduleGrid() {
        LinearLayout grid = Ui.column(context);
        grid.setPadding(Ui.dp(context, 12), 0, Ui.dp(context, 12), 0);
        LinearLayout row = null;
        int i = 0;
        java.util.List<View> cards = new java.util.ArrayList<>();
        for (Course.Module m : course.modules) cards.add(moduleCard(m));
        cards.add(extraCard("Quiz", "Testez vos connaissances", R.drawable.ic_m_quiz, Color.parseColor("#0F8A5F"),
                v -> startActivity(new Intent(context, QuizActivity.class))));
        cards.add(extraCard("Simulateur Windows", "S’exercer comme sur un vrai PC", R.drawable.ic_m_windows, Color.parseColor("#0067C0"),
                v -> startActivity(new Intent(context, SimulatorActivity.class))));
        for (View card : cards) {
            if (i % 2 == 0) {
                row = Ui.row(context);
                row.setGravity(Gravity.TOP);
                grid.addView(row);
            }
            // seule sur sa ligne (dernière tuile d'un nombre impair) : hauteur du contenu, sinon elle serait écrasée
            boolean alone = i % 2 == 0 && i == cards.size() - 1;
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, alone ? ViewGroup.LayoutParams.WRAP_CONTENT : ViewGroup.LayoutParams.MATCH_PARENT, 1);
            lp.setMargins(Ui.dp(context, 4), Ui.dp(context, 4), Ui.dp(context, 4), Ui.dp(context, 4));
            card.setLayoutParams(lp);
            row.addView(card);
            Ui.enter(card, i);
            i++;
        }
        if (i % 2 == 1 && row != null) {
            View filler = new View(context);
            filler.setLayoutParams(new LinearLayout.LayoutParams(0, 1, 1));
            row.addView(filler);
        }
        return grid;
    }

    /** Tuile de la grille des modules qui ouvre un outil (quiz, simulateur). */
    private View extraCard(String title, String sub, int icon, int color, View.OnClickListener open) {
        LinearLayout c = Ui.card(context, open);
        c.setPadding(Ui.dp(context, 14), Ui.dp(context, 14), Ui.dp(context, 14), Ui.dp(context, 14));
        c.addView(Ui.badge(context, icon, color, 44, false));
        TextView t = Ui.title(context, title, 16);
        t.setPadding(0, Ui.dp(context, 10), 0, 0);
        t.setMaxLines(2);
        c.addView(t);
        TextView s = Ui.text(context, sub, 12.5f, Ui.MUTED, false);
        s.setPadding(0, Ui.dp(context, 2), 0, Ui.dp(context, 8));
        c.addView(s);
        View bar = new View(context);
        bar.setBackground(Ui.rounded(context, Ui.tint(color, 0.6f), 3));
        c.addView(bar, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(context, 5)));
        return c;
    }

    private View moduleCard(Course.Module m) {
        View.OnClickListener open = v -> {
            if ("astuces".equals(m.id)) startActivity(new Intent(context, TipsActivity.class));
            else ModuleActivity.open(context, m.id);
        };
        LinearLayout c = Ui.card(context, open);
        c.setPadding(Ui.dp(context, 14), Ui.dp(context, 14), Ui.dp(context, 14), Ui.dp(context, 14));
        c.addView(Ui.badge(context, ModuleActivity.iconRes(context, m.id), m.color, 44, false));
        TextView t = Ui.title(context, m.title, 16);
        t.setPadding(0, Ui.dp(context, 10), 0, 0);
        t.setMaxLines(2);
        c.addView(t);
        int done = progress.learnedIn(m);
        TextView s = Ui.text(context, done == 0 ? m.lessons.size() + " leçons" : done + " / " + m.lessons.size() + " leçons", 12.5f, Ui.MUTED, false);
        s.setPadding(0, Ui.dp(context, 2), 0, Ui.dp(context, 8));
        c.addView(s);
        ProgressBar pb = Ui.bar(context, m.color, Ui.tint(m.color, 0.85f));
        pb.setLayoutParams(new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(context, 5)));
        pb.setProgress(progress.percent(m));
        c.addView(pb);
        return c;
    }

    private View practiceCards() {
        LinearLayout col = Ui.column(context);
        col.setPadding(Ui.dp(context, 16), 0, Ui.dp(context, 16), 0);
        LinearLayout challenge = Ui.card(context, v -> startActivity(new Intent(context, ShortcutChallengeActivity.class)));
        challenge.setOrientation(LinearLayout.HORIZONTAL);
        challenge.setGravity(Gravity.CENTER_VERTICAL);
        challenge.setBackground(Ui.ripple(Ui.gradient(context, Color.parseColor("#2B2F3A"), Color.parseColor("#3D4456"), 18), Color.parseColor("#33FFFFFF")));
        challenge.addView(Ui.badge(context, R.drawable.ic_m_keyboard, Color.parseColor("#D9822B"), 46, false));
        LinearLayout t = Ui.column(context);
        t.setPadding(Ui.dp(context, 14), 0, 0, 0);
        t.addView(Ui.text(context, "Défi des raccourcis", 16.5f, Color.WHITE, true));
        SharedPreferences sp = context.getSharedPreferences("helpme_progress", Context.MODE_PRIVATE);
        int best = sp.getInt("shortcut_best", 0);
        t.addView(Ui.text(context, best > 0 ? "Record : " + best + " points" : "10 questions chronométrées", 13.5f, Color.parseColor("#C9D2E3"), false));
        challenge.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        challenge.addView(Ui.icon(context, R.drawable.ic_m_play, Color.WHITE, 26));
        col.addView(challenge);

        LinearLayout tips = Ui.card(context, v -> startActivity(new Intent(context, TipsActivity.class)));
        tips.setOrientation(LinearLayout.HORIZONTAL);
        tips.setGravity(Gravity.CENTER_VERTICAL);
        tips.addView(Ui.badge(context, R.drawable.ic_m_astuces, Color.parseColor("#D9822B"), 46, true));
        LinearLayout tt = Ui.column(context);
        tt.setPadding(Ui.dp(context, 14), 0, 0, 0);
        tt.addView(Ui.text(context, "Astuce du jour", 16.5f, Ui.TEXT, true));
        tt.addView(Ui.text(context, course.tips.size() + " astuces à découvrir en faisant glisser", 13.5f, Ui.MUTED, false));
        tips.addView(tt, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        tips.addView(Ui.icon(context, R.drawable.ic_m_chevron, Ui.MUTED, 24));
        tips.setLayoutParams(Ui.lpMargins(context, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 10, 0, 0));
        col.addView(tips);
        return col;
    }

    // ================================================================ secouer = scanner
    @Override
    public void onSensorChanged(SensorEvent event) {
        if (event.sensor.getType() != Sensor.TYPE_ACCELEROMETER) return;
        long now = System.currentTimeMillis();
        if ((now - lastTime) > 100) {
            long diff = now - lastTime;
            lastTime = now;
            float x = event.values[0], y = event.values[1], z = event.values[2];
            float shake = Math.abs(x + y + z - lastX - lastY - lastZ) / diff * 10000;
            if (shake > SHAKE_THRESHOLD && lastX != 0) startActivity(new Intent(context, MainActivity8.class));
            lastX = x;
            lastY = y;
            lastZ = z;
        }
    }

    @Override
    public void onAccuracyChanged(Sensor sensor, int accuracy) {}
}
