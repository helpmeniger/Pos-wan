package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import androidx.test.core.app.ApplicationProvider;
import com.google.firebase.FirebaseApp;
import com.google.firebase.FirebaseOptions;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;

@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class OldScreensTest {
    @Before public void fb() {
        if (FirebaseApp.getApps(ApplicationProvider.getApplicationContext()).isEmpty())
            FirebaseApp.initializeApp(ApplicationProvider.getApplicationContext(), new FirebaseOptions.Builder()
                .setApplicationId("1:1:android:1").setApiKey("AIzaTEST").setProjectId("test").setDatabaseUrl("https://test.firebaseio.com").build());
    }
    @Test public void menu() throws Exception {
        Activity a = Robolectric.buildActivity(MainActivity.class, new Intent()).setup().get();
        androidx.drawerlayout.widget.DrawerLayout d = findDrawer(a.getWindow().getDecorView());
        org.junit.Assert.assertNotNull(d);
        d.openDrawer(androidx.core.view.GravityCompat.START, false);
        ScreensTest.shot(a, "o_menu", 0);
    }

    static androidx.drawerlayout.widget.DrawerLayout findDrawer(android.view.View v) {
        if (v instanceof androidx.drawerlayout.widget.DrawerLayout) return (androidx.drawerlayout.widget.DrawerLayout) v;
        if (v instanceof android.view.ViewGroup) {
            android.view.ViewGroup g = (android.view.ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) { androidx.drawerlayout.widget.DrawerLayout r = findDrawer(g.getChildAt(i)); if (r != null) return r; }
        }
        return null;
    }

    void run(Class<? extends Activity> c, String name, Intent i) {
        try {
            Activity a = Robolectric.buildActivity(c, i).setup().get();
            ScreensTest.shot(a, name, 0);
        } catch (Throwable t) { System.out.println("ECHEC " + name + " : " + t); }
    }
    @Test public void all() {
        run(Login.class, "o_login", new Intent());
        run(Register.class, "o_register", new Intent());
        run(MainActivity2.class, "o_main2", new Intent());
        run(QuizActivity.class, "o_quiz", new Intent());
        run(LessonActivity.class, "o_lesson_code", new Intent().putExtra("code", "1003"));
        run(MainActivity7.class, "o_notes", new Intent());
        run(MainActivity16.class, "o_certificat", new Intent());
        run(LeaderboardActivity.class, "o_leaderboard", new Intent());
        run(HistoryActivity.class, "o_history", new Intent());
        run(NameConfigActivity.class, "o_name", new Intent());
        run(ScoreActivity.class, "o_score", new Intent());
        run(MainActivity9.class, "o_champions", new Intent());
        run(ForumActivity.class, "o_forum", new Intent());
        run(MiseActivity9.class, "o_mise", new Intent());
        run(MainActivity.class, "o_main", new Intent());
    }
}
