package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.view.View;

import androidx.fragment.app.FragmentActivity;
import androidx.test.core.app.ApplicationProvider;

import com.hightechniger.help_me.ui.Course;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;
import org.robolectric.shadows.ShadowLooper;

import java.io.File;
import java.io.FileOutputStream;

@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class ScreensTest {
    static final File OUT = new File(System.getProperty("shots", "build/shots"));

    static void shot(Activity a, String name, int scrollY) throws Exception {
        ShadowLooper.idleMainLooper(2, java.util.concurrent.TimeUnit.SECONDS);
        View root = a.getWindow().getDecorView();
        if (scrollY > 0) {
            View sv = find(root);
            if (sv != null) sv.scrollTo(0, scrollY);
        }
        ShadowLooper.idleMainLooper(2, java.util.concurrent.TimeUnit.SECONDS);
        root.measure(View.MeasureSpec.makeMeasureSpec(root.getWidth(), View.MeasureSpec.EXACTLY), View.MeasureSpec.makeMeasureSpec(root.getHeight(), View.MeasureSpec.EXACTLY));
        Bitmap b = Bitmap.createBitmap(root.getWidth(), root.getHeight(), Bitmap.Config.ARGB_8888);
        root.draw(new Canvas(b));
        OUT.mkdirs();
        try (FileOutputStream f = new FileOutputStream(new File(OUT, name + ".png"))) { b.compress(Bitmap.CompressFormat.PNG, 100, f); }
    }

    static View find(View v) {
        if (v instanceof android.widget.ScrollView) return v;
        if (v instanceof android.view.ViewGroup) {
            android.view.ViewGroup g = (android.view.ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) { View r = find(g.getChildAt(i)); if (r != null) return r; }
        }
        return null;
    }

    @Test public void module() throws Exception {
        Activity a = Robolectric.buildActivity(ModuleActivity.class, new Intent().putExtra("module", "word")).setup().get();
        shot(a, "1_module_word", 0);
        shot(a, "1b_module_word_bas", 1600);
    }

    @Test public void lesson() throws Exception {
        String id = Course.get(ApplicationProvider.getApplicationContext()).module("word").lessons.get(2).id;
        Activity a = Robolectric.buildActivity(LessonReaderActivity.class, new Intent().putExtra("lesson", id)).setup().get();
        shot(a, "2_lecon", 0);
        shot(a, "2b_lecon_suite", 1500);
        String ex = Course.get(ApplicationProvider.getApplicationContext()).module("excel").lessons.get(4).id;
        Activity b = Robolectric.buildActivity(LessonReaderActivity.class, new Intent().putExtra("lesson", ex)).setup().get();
        shot(b, "3_lecon_excel", 1200);
    }

    @Test public void tips() throws Exception {
        Activity a = Robolectric.buildActivity(TipsActivity.class).setup().get();
        shot(a, "4_astuces", 0);
    }

    @Test public void challenge() throws Exception {
        Activity a = Robolectric.buildActivity(ShortcutChallengeActivity.class).setup().get();
        shot(a, "5_defi_intro", 0);
        View start = findText(a.getWindow().getDecorView(), "Commencer le défi");
        start.performClick();
        shot(a, "5b_defi_question", 0);
    }

    @Test public void home() throws Exception {
        FragmentActivity a = Robolectric.buildActivity(FragmentActivity.class).setup().get();
        a.getSupportFragmentManager().beginTransaction().add(android.R.id.content, new HomeFragment()).commitNow();
        ShadowLooper.idleMainLooper(3, java.util.concurrent.TimeUnit.SECONDS);
        Thread.sleep(1500);
        shot(a, "0_accueil", 0);
        shot(a, "0b_accueil_bas", 1300);
        View sim = findText(a.getWindow().getDecorView(), "Simulateur Windows");
        org.junit.Assert.assertNotNull("tuile Simulateur", sim);
        View sv = find(a.getWindow().getDecorView());
        int[] p = new int[2]; sim.getLocationInWindow(p);
        org.junit.Assert.assertTrue("tuile Simulateur visible", ((View) sim.getParent()).getHeight() > 0);
        shot(a, "0c_accueil_fin", 2000);
    }

    static View findText(View v, String s) {
        if (v instanceof android.widget.TextView && s.contentEquals(((android.widget.TextView) v).getText())) return v;
        if (v instanceof android.view.ViewGroup) {
            android.view.ViewGroup g = (android.view.ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) { View r = findText(g.getChildAt(i), s); if (r != null) return r; }
        }
        return null;
    }
}
