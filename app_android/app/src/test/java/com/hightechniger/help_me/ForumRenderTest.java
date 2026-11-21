package com.hightechniger.help_me;

import android.app.Activity;
import androidx.fragment.app.FragmentActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;
import java.util.ArrayList;

/** Rendu du forum (mise en page + bulles) sans compte Firebase. */
@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class ForumRenderTest {
    static MessageModel m(String txt, String mail, long ago) {
        MessageModel x = new MessageModel(); x.message = txt; x.userEmail = mail;
        x.timestamp = System.currentTimeMillis() - ago; x.date = ""; x.isQuestion = txt.endsWith("?"); return x;
    }
    @Test public void render() throws Exception {
        Activity a = Robolectric.buildActivity(FragmentActivity.class).setup().get();
        a.setTheme(R.style.Theme_HelpMe_Chat);
        a.setContentView(R.layout.activity_forum);
        ArrayList<MessageModel> list = new ArrayList<>();
        list.add(m("Bonjour, comment insérer un sommaire automatique dans Word ?", "aichatou.hamidou@gmail.com", 90_000_000L));
        list.add(m("Il faut d’abord appliquer les styles Titre 1 et Titre 2, puis Références › Table des matières.", "moussa.issa@gmail.com", 80_000_000L));
        list.add(m("Merci beaucoup, ça marche !", "moi@helpme.ne", 3_600_000L));
        list.add(m("Quelqu’un sait comment figer la première ligne dans Excel ?", "fati22@yahoo.fr", 600_000L));
        RecyclerView rv = a.findViewById(R.id.messageRecyclerView);
        LinearLayoutManager lm = new LinearLayoutManager(a); lm.setStackFromEnd(true);
        rv.setLayoutManager(lm);
        rv.setAdapter(new MessageBubbleAdapter(a, list, "moi@helpme.ne"));
        ((android.widget.TextView) a.findViewById(R.id.connectionStatus)).setText("Connecté · les messages s’affichent en direct");
        ScreensTest.shot(a, "f_forum", 0);
        list.clear(); rv.getAdapter().notifyDataSetChanged();
        a.findViewById(R.id.forumEmpty).setVisibility(android.view.View.VISIBLE);
        ScreensTest.shot(a, "f_forum_vide", 0);
    }
}
