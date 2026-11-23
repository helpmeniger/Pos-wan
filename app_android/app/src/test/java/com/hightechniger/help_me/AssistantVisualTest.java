package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.ScrollView;

import com.hightechniger.help_me.brain.Brain;

import org.junit.Assert;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;

/** Réponses de l'assistant : étapes en cartes, images de la leçon, bouton d'animation. */
@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class AssistantVisualTest {
    @Test public void illustratedReplies() throws Exception {
        Activity a = Robolectric.buildActivity(Activity.class).setup().get();
        String json;
        try (java.io.InputStream in = a.getAssets().open("brain.json")) { json = new String(in.readAllBytes(), java.nio.charset.StandardCharsets.UTF_8); }
        java.util.Map<String, String> mem = new java.util.HashMap<>();
        Brain brain = Brain.fromJson(json, new Brain.Memory() {
            public String get(String k) { return mem.get(k); }
            public void put(String k, String v) { mem.put(k, v); }
        });
        ScrollView sv = new ScrollView(a);
        LinearLayout col = new LinearLayout(a);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(30, 30, 30, 30);
        col.setBackgroundColor(0xFFF4F6FA);
        sv.addView(col);
        int withPics = 0;
        for (String q : new String[]{"comment insérer un tableau dans word", "comment créer un dossier sur le bureau", "comment allumer l'ordinateur", "comment faire une somme dans excel"}) {
            Brain.Reply r = brain.ask(q);
            System.out.println("AIDBG " + q + " | kind=" + r.kind + " ch=" + r.chapter + " code=" + r.lessonCode + " img=" + r.image + " | " + r.text.replace("\n", " / ").substring(0, Math.min(160, r.text.length())));
            LinearLayout bubble = new LinearLayout(a);
            bubble.setOrientation(LinearLayout.VERTICAL);
            bubble.setBackgroundColor(0xFFFFFFFF);
            bubble.setPadding(30, 24, 30, 24);
            android.widget.TextView t = new android.widget.TextView(a);
            t.setText(r.text);
            bubble.addView(t);
            ReplyVisuals.decorate(a, bubble, r);
            if (bubble.getChildCount() > 1) withPics++;
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
            lp.bottomMargin = 40;
            col.addView(bubble, lp);
        }
        a.setContentView(sv);
        Assert.assertTrue("des réponses illustrées", withPics >= 2);
        ScreensTest.shot(a, "ai_reponses", 0);
        ScreensTest.shot(a, "ai_reponses_b", 1800);
    }
}
