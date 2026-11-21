package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import androidx.test.core.app.ApplicationProvider;
import com.google.firebase.FirebaseApp;
import com.google.firebase.FirebaseOptions;
import com.hightechniger.help_me.ui.Course;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.shadows.ShadowLooper;
import java.util.ArrayList;
import java.util.List;
import static org.junit.Assert.assertTrue;

/** Ouvre chaque écran et chaque leçon : aucun ne doit planter. */
@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public class SmokeTest {
    final List<String> errors = new ArrayList<>();

    void open(Class<? extends Activity> c, Intent i, String label) {
        try {
            Robolectric.buildActivity(c, i).setup().get();
            ShadowLooper.idleMainLooper(1, java.util.concurrent.TimeUnit.SECONDS);
        } catch (Throwable t) {
            Throwable r = t; while (r.getCause() != null) r = r.getCause();
            errors.add(label + " : " + r);
        }
    }

    @Test public void allScreens() {
        android.content.Context c = ApplicationProvider.getApplicationContext();
        if (FirebaseApp.getApps(c).isEmpty())
            FirebaseApp.initializeApp(c, new FirebaseOptions.Builder().setApplicationId("1:1:android:1")
                .setApiKey("AIzaTEST").setProjectId("test").setDatabaseUrl("https://test.firebaseio.com").build());
        Class<?>[] screens = {Login.class, Register.class, MainActivity2.class, MainActivity.class, QuizActivity.class,
            HistoryActivity.class, HistoryActivity2.class, ScoreActivity.class, NameConfigActivity.class, MainActivity7.class,
            MainActivity9.class, MainActivity16.class, MainActivity17.class, ForumActivity.class, LeaderboardActivity.class,
            MiseActivity9.class, WordActivity9.class, ApproActivity.class, MiniGameActivity.class, AIChatActivity.class,
            TipsActivity.class, ShortcutChallengeActivity.class, MainActivity8.class, MainActivity6.class,
            LobbyActivity.class, MatchActivity.class, LoginActivity2.class, MainActivity10.class, MainActivity5.class, WebViewActivity.class,
            ImageLessonActivity.class, RouterActivity.class};
        for (Class<?> s : screens) open((Class<? extends Activity>) s, new Intent(), s.getSimpleName());
        Course course = Course.get(c);
        for (Course.Module m : course.modules) {
            open(ModuleActivity.class, new Intent().putExtra("module", m.id), "Module " + m.id);
            for (Course.Lesson l : m.lessons) open(LessonReaderActivity.class, new Intent().putExtra("lesson", l.id), "Leçon " + l.id);
        }
        for (String code : new String[]{"1003", "2017", "9999"})
            open(LessonActivity.class, new Intent().putExtra("code", code), "Fiche " + code);
        System.out.println("PLANTAGES : " + errors.size());
        for (String e : errors) System.out.println("  " + e);
        assertTrue(errors.toString(), errors.isEmpty());
    }
}
