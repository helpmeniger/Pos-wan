package com.hightechniger.help_me;

import android.app.Activity;
import androidx.fragment.app.FragmentActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import com.hightechniger.help_me.models.HistoryItem;
import com.hightechniger.help_me.models.User;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;
import java.util.ArrayList;
import java.util.List;

/** Rendu des écrans du quiz en ligne avec des données d'exemple (sans compte Firebase). */
@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class OnlineRenderTest {
    Activity make(int layout) {
        Activity a = Robolectric.buildActivity(FragmentActivity.class).setup().get();
        a.setTheme(R.style.Theme_HelpMe_Chat);
        a.setContentView(layout);
        return a;
    }
    static User u(String name, String st, long w, long played, long uniq) {
        User x = new User(); x.name = name; x.status = st; x.wins = w; x.gamesPlayed = played; x.uniqueOpponents = uniq; x.uid = name; return x;
    }
    static HistoryItem h(String opp, String sc, long ago, long dur) {
        HistoryItem x = new HistoryItem(); x.opponentName = opp; x.score = sc; x.timestamp = System.currentTimeMillis() - ago; x.durationMs = dur; return x;
    }
    @Test public void screens() throws Exception {
        Activity l = make(R.layout.activity_leaderboard);
        l.findViewById(R.id.loadingLayout).setVisibility(android.view.View.GONE);
        List<User> us = new ArrayList<>();
        us.add(u("Mohamed", "available", 27, 101, 3)); us.add(u("salifou", "offline", 13, 79, 1));
        us.add(u("Aïchatou", "busy", 9, 20, 4)); us.add(u("Ouseini", "offline", 0, 10, 0)); us.add(u("rachid", "available", 0, 9, 0));
        RecyclerView rv = l.findViewById(R.id.rvLeaderboard); rv.setLayoutManager(new LinearLayoutManager(l));
        rv.setAdapter(new LeaderboardAdapter(us, "x", "Moi"));
        ScreensTest.shot(l, "o_classement", 0);

        Activity hh = make(R.layout.activity_history2);
        hh.findViewById(R.id.loadingLayout).setVisibility(android.view.View.GONE);
        List<HistoryItem> hs = new ArrayList<>();
        hs.add(h("salifou", "1-0", 3_600_000L, 75_000L)); hs.add(h("salifou", "10-4", 90_000_000L, 655_000L));
        hs.add(h("rachid", "0-0", 95_000_000L, 14_211_000L)); hs.add(h("rachid", "2-3", 200_000_000L, 169_000L));
        RecyclerView rh = hh.findViewById(R.id.rvHistory); rh.setLayoutManager(new LinearLayoutManager(hh));
        rh.setAdapter(new HistoryAdapter(hs));
        ScreensTest.shot(hh, "o_historique", 0);

        ScreensTest.shot(make(R.layout.activity_login2), "o_pseudo", 0);
        Activity lb = make(R.layout.activity_lobby);
        ((android.widget.TextView) lb.findViewById(R.id.tvLastMatch)).setText("Dernier match contre salifou · 1-0 · Gagné · 15 avr.");
        ScreensTest.shot(lb, "o_salon", 0);
        ScreensTest.shot(make(R.layout.activity_match), "o_match", 0);
        ScreensTest.shot(make(R.layout.activity_login), "o_connexion", 0);
    }
}
