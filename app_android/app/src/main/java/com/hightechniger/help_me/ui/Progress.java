package com.hightechniger.help_me.ui;

import android.content.Context;
import android.content.SharedPreferences;

import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Date;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;

/**
 * Progression de l'apprenant, enregistrée sur le téléphone : leçons apprises, dernière leçon ouverte,
 * jours d'apprentissage consécutifs (série) et favoris.
 */
public final class Progress {

    private static final String PREFS = "helpme_progress";
    private final SharedPreferences p;

    public Progress(Context c) {
        p = c.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public boolean isLearned(String lessonId) { return learned().contains(lessonId); }

    public Set<String> learned() { return new HashSet<>(p.getStringSet("learned", new HashSet<>())); }

    public void setLearned(String lessonId, boolean value) {
        Set<String> s = learned();
        if (value) s.add(lessonId); else s.remove(lessonId);
        p.edit().putStringSet("learned", s).apply();
        if (value) touchDay();
    }

    public int learnedIn(Course.Module m) {
        Set<String> s = learned();
        int n = 0;
        for (Course.Lesson l : m.lessons) if (s.contains(l.id)) n++;
        return n;
    }

    public int percent(Course.Module m) {
        return m.lessons.isEmpty() ? 0 : Math.round(100f * learnedIn(m) / m.lessons.size());
    }

    public int totalPercent(Course c) {
        int all = 0, done = 0;
        Set<String> s = learned();
        for (Course.Module m : c.modules) for (Course.Lesson l : m.lessons) { all++; if (s.contains(l.id)) done++; }
        return all == 0 ? 0 : Math.round(100f * done / all);
    }

    public void opened(Course.Lesson l) {
        p.edit().putString("last_lesson", l.id).putLong("last_time", System.currentTimeMillis()).apply();
        touchDay();
    }

    public String lastLesson() { return p.getString("last_lesson", null); }

    /** Première leçon non apprise d'un module (ou la première s'il est terminé). */
    public Course.Lesson next(Course.Module m) {
        Set<String> s = learned();
        for (Course.Lesson l : m.lessons) if (!s.contains(l.id)) return l;
        return m.lessons.isEmpty() ? null : m.lessons.get(0);
    }

    // ---------------------------------------------------------------- série de jours
    private static String day(Date d) { return new SimpleDateFormat("yyyy-MM-dd", Locale.ROOT).format(d); }

    private void touchDay() {
        String today = day(new Date());
        String last = p.getString("streak_day", null);
        int streak = p.getInt("streak", 0);
        if (today.equals(last)) return;
        Calendar y = Calendar.getInstance();
        y.add(Calendar.DAY_OF_YEAR, -1);
        streak = day(y.getTime()).equals(last) ? streak + 1 : 1;
        p.edit().putString("streak_day", today).putInt("streak", streak).apply();
    }

    /** Nombre de jours consécutifs d'apprentissage (0 si la série est rompue). */
    public int streak() {
        String last = p.getString("streak_day", null);
        if (last == null) return 0;
        Calendar y = Calendar.getInstance();
        y.add(Calendar.DAY_OF_YEAR, -1);
        if (last.equals(day(new Date())) || last.equals(day(y.getTime()))) return p.getInt("streak", 0);
        return 0;
    }

    // ---------------------------------------------------------------- favoris
    public boolean isFavorite(String id) { return p.getStringSet("fav", new HashSet<>()).contains(id); }

    public void toggleFavorite(String id) {
        Set<String> s = new HashSet<>(p.getStringSet("fav", new HashSet<>()));
        if (!s.remove(id)) s.add(id);
        p.edit().putStringSet("fav", s).apply();
    }

    public Set<String> favorites() { return new HashSet<>(p.getStringSet("fav", new HashSet<>())); }
}
