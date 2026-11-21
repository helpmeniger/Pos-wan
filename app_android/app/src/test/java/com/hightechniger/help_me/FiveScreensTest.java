package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import android.view.View;
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
import org.robolectric.shadows.ShadowLooper;

@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class FiveScreensTest {
    @Before public void fb() {
        android.content.Context c = ApplicationProvider.getApplicationContext();
        if (FirebaseApp.getApps(c).isEmpty())
            FirebaseApp.initializeApp(c, new FirebaseOptions.Builder().setApplicationId("1:1:android:1")
                .setApiKey("AIzaTEST").setProjectId("test").setDatabaseUrl("https://test.firebaseio.com").build());
        c.getSharedPreferences("QuizPrefs", 0).edit().putBoolean("intro_shown_once", true).apply();
        c.getSharedPreferences("HelpMePrefs", 0).edit().putString("site_data",
            "Radio Garden|https://radio.garden|Écoutez des radios du monde entier en faisant tourner un globe interactif.;"
          + "Google Scholar|https://scholar.google.com|Moteur de recherche académique : articles, mémoires et thèses fiables.;"
          + "Smallpdf|https://smallpdf.com|Convertir, compresser, fusionner ou signer des fichiers PDF.;"
          + "Grammarly|https://www.grammarly.com|Correcteur d’orthographe et de style pour vos textes.;").apply();
    }
    void shot(Class<? extends Activity> c, String name) {
        try { Activity a = Robolectric.buildActivity(c, new Intent()).setup().get(); ScreensTest.shot(a, name, 0); }
        catch (Throwable t) { System.out.println("ECHEC " + name + " : " + t); t.printStackTrace(System.out); }
    }
    @Test public void quiz() throws Exception {
        Activity a = Robolectric.buildActivity(QuizActivity.class, new Intent()).setup().get();
        ScreensTest.shot(a, "q1_quiz", 0);
        View opt = ((android.view.ViewGroup) a.findViewById(R.id.optionsLayout)).getChildAt(1);
        opt.performClick();
        ShadowLooper.idleMainLooper(1, java.util.concurrent.TimeUnit.SECONDS);
        ScreensTest.shot(a, "q2_quiz_reponse", 0);
    }
    @Test public void others() {
        shot(MainActivity9.class, "q3_champions");
        shot(MainActivity17.class, "q4_sites");
    }
}
