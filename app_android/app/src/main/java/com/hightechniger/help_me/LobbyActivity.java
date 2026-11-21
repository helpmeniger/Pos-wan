package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.google.android.material.card.MaterialCardView;
import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.database.*;
import com.hightechniger.help_me.models.Challenge;
import com.hightechniger.help_me.models.Match;
import com.hightechniger.help_me.models.Question;
import com.hightechniger.help_me.models.User;
import com.hightechniger.help_me.push.HttpHelper;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.*;

public class LobbyActivity extends AppCompatActivity {

    private LinearLayout usersContainer;
    private TextView tvAvailableCount, tvHistoryLink, tvLeaderboardLink, tvLastMatch, tvNetStatus;
    private MaterialCardView cardSearchingPlayers;

    // 🔥 AJOUTS
    private SwipeRefreshLayout swipeRefresh;
    private ProgressBar loadingPlayers;

    private DatabaseReference db, infoConnectedRef;
    private String myUid, myName = "";
    private boolean notifSentThisSession = false;
    static boolean isLobbyVisible = false;

    // config server
    private final String SERVER_URL = BuildConfig.HM_SERVER_URL;
    private final String API_KEY = BuildConfig.HM_API_KEY;
    private final String HMAC_SECRET = BuildConfig.HM_HMAC_SECRET;

    private ChildEventListener invitesListener, challengesListener;
    private ValueEventListener netListener, availableListener, usersListener;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        setContentView(R.layout.activity_lobby);

        // 🔥 Récupération du SwipeRefreshLayout et ProgressBar
        swipeRefresh = findViewById(R.id.swiperefresh);
        loadingPlayers = findViewById(R.id.loadingplayers);
        loadingPlayers.setVisibility(View.GONE);

        usersContainer    = findViewById(R.id.usersContainer);
        tvAvailableCount  = findViewById(R.id.tvAvailableCount);
        tvHistoryLink     = findViewById(R.id.tvHistoryLink);
        tvLeaderboardLink = findViewById(R.id.tvLeaderboardLink);
        tvLastMatch       = findViewById(R.id.tvLastMatch);
        tvNetStatus       = findViewById(R.id.tvNetStatus);
        cardSearchingPlayers = findViewById(R.id.cardSearchingPlayers);
        cardSearchingPlayers.setVisibility(View.GONE);

        swipeRefresh.setOnRefreshListener(() -> refreshLobby());

        db = FirebaseDatabase.getInstance().getReference();
        com.google.firebase.auth.FirebaseUser me = FirebaseAuth.getInstance().getCurrentUser();
        if (me == null) {   // session expirée : retour à la connexion au lieu d'un plantage
            android.widget.Toast.makeText(this, "Votre session a expiré : reconnectez-vous.", android.widget.Toast.LENGTH_SHORT).show();
            startActivity(new android.content.Intent(this, Login.class));
            finish();
            return;
        }
        myUid = me.getUid();

        tvHistoryLink.setOnClickListener(v -> startActivity(new Intent(this, HistoryActivity2.class)));
        tvLeaderboardLink.setOnClickListener(v -> startActivity(new Intent(this, LeaderboardActivity.class)));

        db.child("users").child(myUid).child("name").get().addOnSuccessListener(snap -> {
            String tmp = String.valueOf(snap.getValue());
            if (tmp != null && !tmp.trim().isEmpty()) myName = tmp;
        });

        // Connexion
        db.child("users").child(myUid).child("online").setValue(true);
        db.child("users").child(myUid).child("online").onDisconnect().setValue(false);
        db.child("users").child(myUid).child("status").setValue("available");
        db.child("users").child(myUid).child("status").onDisconnect().setValue("offline");

        infoConnectedRef = FirebaseDatabase.getInstance().getReference(".info/connected");
        netListener = new ValueEventListener() {
            @Override public void onDataChange(@NonNull DataSnapshot snapshot) {
                Boolean ok = snapshot.getValue(Boolean.class);
                tvNetStatus.setText((ok != null && ok) ? "" : "Connexion instable…");
            }
            @Override public void onCancelled(@NonNull DatabaseError error) {}
        };
        infoConnectedRef.addValueEventListener(netListener);

        setupLastMatchUI();
        initListeners();
    }

    private void initListeners() {
// 🔥 Listener joueurs disponibles AVEC loader + protection joueurs en match
        availableListener = new ValueEventListener() {
            @Override public void onDataChange(@NonNull DataSnapshot snapshot) {

                long count = snapshot.getChildrenCount();
                tvAvailableCount.setText("Disponibles: " + count);

                boolean iAmAvailable = snapshot.hasChild(myUid);

                // ❗ Si je ne suis PAS disponible → on remet tout propre
                if (!iAmAvailable) {
                    cardSearchingPlayers.setVisibility(View.GONE);
                    loadingPlayers.setVisibility(View.GONE);
                    notifSentThisSession = false;
                    return;
                }

                // ❗ Si plusieurs joueurs disponibles → inutile de chercher
                if (count > 1) {
                    cardSearchingPlayers.setVisibility(View.GONE);
                    loadingPlayers.setVisibility(View.GONE);
                    notifSentThisSession = false;
                    return;
                }

                // 🔥 Je suis seul → loader ON
                cardSearchingPlayers.setVisibility(View.VISIBLE);
                loadingPlayers.setVisibility(View.VISIBLE);

                // --- NOUVELLE LOGIQUE ---
                // 👉 Avant d’envoyer une notification, on vérifie qu’aucun joueur n’est en match (status = busy)
                if (!notifSentThisSession && iAmAvailable && count == 1 && !isLobbyVisible) {

                    db.child("users").orderByChild("status").equalTo("busy")
                            .addListenerForSingleValueEvent(new ValueEventListener() {
                                @Override
                                public void onDataChange(@NonNull DataSnapshot busySnap) {

                                    // 👉 Si des joueurs sont en match → NE PAS NOTIFIER
                                    if (busySnap.exists()) {
                                        // On laisse juste l’animation de recherche
                                        return;
                                    }

                                    // 👉 Aucun joueur en match → on peut envoyer la notification
                                    String json = "{\"name\":\"" + myName + "\"}";

                                    HttpHelper.postJsonSigned(
                                            SERVER_URL,
                                            API_KEY,
                                            HMAC_SECRET,
                                            json,
                                            (ok, res) -> {}
                                    );

                                    notifSentThisSession = true;
                                }

                                @Override public void onCancelled(@NonNull DatabaseError error) {}
                            });
                }
            }

            @Override public void onCancelled(@NonNull DatabaseError error) {}
        };





        db.child("users").orderByChild("status").equalTo("available")
                .addValueEventListener(availableListener);

        // 🔥 LISTE joueurs online
        usersListener = new ValueEventListener() {
            @Override public void onDataChange(@NonNull DataSnapshot ds) {

                usersContainer.removeAllViews();

                for (DataSnapshot child : ds.getChildren()) {

                    User u = child.getValue(User.class);
                    if (u == null) continue;

                    u.uid = child.getKey();
                    if (myUid.equals(u.uid)) continue;

                    String status = (u.status == null || u.status.trim().isEmpty())
                            ? "available" : u.status;

                    boolean champ = (u.champion != null && u.champion);

                    MaterialCardView card = buildUserCard(u, status, champ);
                    usersContainer.addView(card);

                    View gap = new View(LobbyActivity.this);
                    gap.setLayoutParams(new LinearLayout.LayoutParams(
                            ViewGroup.LayoutParams.MATCH_PARENT, 14));
                    usersContainer.addView(gap);
                }
            }
            @Override public void onCancelled(@NonNull DatabaseError e) {}
        };

        db.child("users").orderByChild("online").equalTo(true)
                .addValueEventListener(usersListener);

        attachInviteSystem();
    }

    // ----------------------------------
    // Dernier match
    // ----------------------------------
    private void setupLastMatchUI() {
        DatabaseReference myHistory = db.child("history").child(myUid);

        myHistory.orderByChild("timestamp").limitToLast(1)
                .addValueEventListener(new ValueEventListener() {
                    @Override
                    public void onDataChange(@NonNull DataSnapshot snapshot) {

                        if (!snapshot.hasChildren()) {
                            tvLastMatch.setText("Aucun match récent.");
                            return;
                        }

                        DataSnapshot s = snapshot.getChildren().iterator().next();

                        String opp = s.child("opponentName").getValue(String.class);
                        String score = s.child("score").getValue(String.class);
                        Long ms = s.child("durationMs").getValue(Long.class);

                        if (opp == null) opp = "Adversaire inconnu";
                        if (score == null) score = "--";

                        String t = (ms != null) ? formatDuration(ms) : "–";

                        // 🔥 Détection du résultat
                        boolean[] res = new boolean[3]; // [win,loss,draw]
                        String result = getMatchResult(score, res);

                        // 🔥 Couleur selon résultat
                        int color;
                        if (res[0])      color = 0xFF22C55E; // vert
                        else if (res[1]) color = 0xFFEF4444; // rouge
                        else             color = 0xFFFACC15; // jaune

                        tvLastMatch.setText("Dernier match vs " + opp +
                                " • " + score + " • " + result +
                                " • " + t);

                        tvLastMatch.setTextColor(color);
                    }

                    @Override public void onCancelled(@NonNull DatabaseError error) {}
                });
    }

    // ----------------------------------
    // Swipe Refresh
    // ----------------------------------
    private void refreshLobby() {

        // Dernier match
        setupLastMatchUI();

        // Joueurs online
        db.child("users").orderByChild("online").equalTo(true)
                .addListenerForSingleValueEvent(usersListener);

        // Joueurs disponibles
        db.child("users").orderByChild("status").equalTo("available")
                .addListenerForSingleValueEvent(availableListener);

        // Status internet
        infoConnectedRef.addListenerForSingleValueEvent(netListener);

        swipeRefresh.postDelayed(() ->
                swipeRefresh.setRefreshing(false), 600);
    }

    // ----------------------------------
    // Invites + Challenges
    // ----------------------------------
    private void attachInviteSystem() {

        invitesListener = new ChildEventListener() {
            @Override public void onChildAdded(@NonNull DataSnapshot ds, String prev) {

                String matchId = ds.getKey();
                if (matchId == null) return;

                db.child("invites").child(myUid).child(matchId).removeValue();

                Intent i = new Intent(LobbyActivity.this, MatchActivity.class);
                i.putExtra("matchId", matchId);
                startActivity(i);
            }
            @Override public void onChildChanged(@NonNull DataSnapshot ds, String prev) {}
            @Override public void onChildRemoved(@NonNull DataSnapshot ds) {}
            @Override public void onChildMoved(@NonNull DataSnapshot ds, String prev) {}
            @Override public void onCancelled(@NonNull DatabaseError error) {}
        };




        db.child("invites").child(myUid).addChildEventListener(invitesListener);

        challengesListener = new ChildEventListener() {
            @Override public void onChildAdded(@NonNull DataSnapshot ds, String prev) {
                Challenge c = ds.getValue(Challenge.class);
                if (c == null) return;
                showChallengeDialog(ds.getKey(), c.fromUid, c.fromName);
            }
            @Override public void onChildChanged(@NonNull DataSnapshot ds, String prev) {}
            @Override public void onChildRemoved(@NonNull DataSnapshot ds) {}
            @Override public void onChildMoved(@NonNull DataSnapshot ds, String prev) {}
            @Override public void onCancelled(@NonNull DatabaseError error) {}
        };

        db.child("challenges").child(myUid).addChildEventListener(challengesListener);

        db.child("refusedMatches").child(myUid)
                .addChildEventListener(new ChildEventListener() {
                    @Override public void onChildAdded(@NonNull DataSnapshot ds, String prev) {

                        String fromName = ds.child("fromName").getValue(String.class);

                        Toast.makeText(
                                LobbyActivity.this,
                                (fromName == null ? "L'autre joueur" : fromName)
                                        + " a refusé le match. Cherchez un autre adversaire.",
                                Toast.LENGTH_LONG
                        ).show();

                        // Nettoyage
                        db.child("refusedMatches").child(myUid).child(ds.getKey()).removeValue();
                    }

                    @Override public void onChildChanged(@NonNull DataSnapshot ds, String prev) {}
                    @Override public void onChildRemoved(@NonNull DataSnapshot ds) {}
                    @Override public void onChildMoved(@NonNull DataSnapshot ds, String prev) {}
                    @Override public void onCancelled(@NonNull DatabaseError e) {}
                });



    }

    // ----------------------------------
    // Cartes de joueurs
    // ----------------------------------
    @SuppressLint("SetTextI18n")
    private MaterialCardView buildUserCard(User u, String status, boolean champion) {

        MaterialCardView card = new MaterialCardView(this);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);

        card.setLayoutParams(lp);
        card.setCardBackgroundColor(getColor(R.color.surface));
        card.setRadius(18f);
        card.setCardElevation(6f);
        card.setUseCompatPadding(true);
        card.setClickable(true);

        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setPadding(20,20,20,20);
        row.setGravity(Gravity.CENTER_VERTICAL);
        card.addView(row);

        TextView badge = new TextView(this);
        badge.setText("●");
        badge.setTextSize(14f);
        badge.setPadding(0,0,16,0);
        badge.setTextColor("available".equals(status) ? 0xFF00FF88 : 0xFFFF5555);
        row.addView(badge);

        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);

        String nm = (champion ? "⭐ " : "") + (u.name == null ? "Joueur" : u.name);

        TextView tvName = new TextView(this);
        tvName.setText(nm);
        tvName.setTextColor(getColor(R.color.on_surface));
        tvName.setTextSize(18f);

        //------------------------------------------------------
        // 🔥 MISE À JOUR DU DERNIER SCORE (Gagné / Perdu / Égalité)
        //------------------------------------------------------

        TextView tvScore = new TextView(this);
        String last = (u.lastScore == null) ? "" : u.lastScore.trim();

        if (last.isEmpty()) {
            tvScore.setText("Dernier score : N/A");
        } else {

            String displayScore = last;   // format "5-2"

            String result = "";
            try {
                // Sécurité : gérer "-" ou ":"
                String raw = last.replace(":", "-");
                String[] parts = raw.split("-");

                if (parts.length == 2) {
                    int a = Integer.parseInt(parts[0].trim());
                    int b = Integer.parseInt(parts[1].trim());

                    if (a > b) result = " (Gagné)";
                    else if (a < b) result = " (Perdu)";
                    else result = " (Égalité)";
                }
            } catch (Exception e) {
                result = "";
            }

            tvScore.setText("Dernier score : " + displayScore + result);
        }

        tvScore.setTextColor(getColor(R.color.on_surface_variant));
        tvScore.setTextSize(14f);

        //------------------------------------------------------

        col.addView(tvName);
        col.addView(tvScore);
        row.addView(col);

        if ("available".equals(status)) {
            card.setOnClickListener(v -> sendChallenge(u));
        }
        else {
            card.setOnClickListener(v ->
                    Toast.makeText(this, "Joueur occupé", Toast.LENGTH_SHORT).show());
        }

        return card;
    }



    private void sendChallenge(User target) {

        db.child("users").child(target.uid).child("status").get()
                .addOnSuccessListener(snap -> {

                    String st = String.valueOf(snap.getValue());
                    if (!"available".equals(st)) {
                        Toast.makeText(this, "Joueur occupé", Toast.LENGTH_SHORT).show();
                        return;
                    }

                    Challenge c = new Challenge(myUid, myName, System.currentTimeMillis());
                    db.child("challenges").child(target.uid).push().setValue(c);

                    Toast.makeText(this,
                            "Demande de match envoyée à " + target.name, Toast.LENGTH_SHORT).show();
                });
    }

    private void showChallengeDialog(String challengeId, String fromUid, String fromName) {

        new com.google.android.material.dialog.MaterialAlertDialogBuilder(this)
                .setTitle("Demande de défi reçue")
                .setMessage(fromName + " veut vous affronter à un quiz.")
                .setPositiveButton("Accepter", (d,w)-> acceptChallenge(challengeId, fromUid, fromName))


                .setNegativeButton("Refuser", (d,w)-> {

                    // 1. Supprimer le challenge comme avant
                    db.child("challenges").child(myUid).child(challengeId).removeValue();

                    // 2. Informer l'autre joueur que le match est refusé
                    Map<String, Object> notice = new HashMap<>();
                    notice.put("type", "refused");
                    notice.put("from", myUid);
                    notice.put("fromName", myName);
                    notice.put("timestamp", System.currentTimeMillis());

                    db.child("refusedMatches").child(fromUid).push().setValue(notice);

                    Toast.makeText(
                            LobbyActivity.this,
                            "Vous avez refusé le Quiz.",
                            Toast.LENGTH_SHORT
                    ).show();
                })
                .setCancelable(false)
                .show();  // 👈 Obligatoire

    }

    private void acceptChallenge(String challengeId, String fromUid, String fromName) {

        String matchId = db.child("matches").push().getKey();
        if (matchId == null) return;

        Match m = new Match();
        m.matchId = matchId;
        m.p1Uid = fromUid; m.p1Name = fromName;
        m.p2Uid = myUid;   m.p2Name = myName;
        m.startTime = System.currentTimeMillis();
        m.p1Score = 0; m.p2Score = 0;
        m.pickerTurn = 0;
        m.currentQuestionIndex = -1;
        m.state = "waiting";
        m.questions = loadFourRandomQuestions();
        m.used = Arrays.asList(false, false, false, false);

        db.child("matches").child(matchId).setValue(m).addOnSuccessListener(v -> {

            db.child("users").child(fromUid).child("status").setValue("busy");
            db.child("users").child(myUid).child("status").setValue("busy");

            db.child("invites").child(fromUid).child(matchId).setValue(true);
            db.child("challenges").child(myUid).child(challengeId).removeValue();

            Intent i = new Intent(this, MatchActivity.class);
            i.putExtra("matchId", matchId);
            startActivity(i);
        });
    }

    private List<Question> loadFourRandomQuestions() {
        try {
            InputStream is = getAssets().open("questions.json");
            byte[] buf = new byte[is.available()];
            is.read(buf);
            is.close();

            JSONArray arr = new JSONArray(new String(buf, StandardCharsets.UTF_8));
            List<Question> all = new ArrayList<>();

            for (int i = 0; i < arr.length(); i++) {

                JSONObject o = arr.getJSONObject(i);

                Question q = new Question();
                q.category = o.optString("category");
                q.question = o.optString("question");
                q.answer = o.optString("answer");
                q.explanation = o.optString("explanation");

                JSONArray ops = o.optJSONArray("options");
                List<String> opts = new ArrayList<>();

                if (ops != null)
                    for (int k = 0; k < ops.length(); k++) opts.add(ops.getString(k));

                q.options = opts;
                all.add(q);
            }

            Collections.shuffle(all);
            return all.subList(0, Math.min(4, all.size()));

        } catch (Exception e) {
            return new ArrayList<>();
        }
    }

    private String formatDuration(long ms) {
        long sec = ms / 1000;
        long min = sec / 60;
        sec = sec % 60;

        if (min > 0) return min + " min " + sec + " s";
        else return sec + " s";
    }
    private String getMatchResult(String score, boolean[] isWinDrawLoss) {

        try {
            // score format "5-2"
            String[] p = score.split("-");
            int a = Integer.parseInt(p[0].trim()); // mon score
            int b = Integer.parseInt(p[1].trim()); // score adversaire

            if (a > b) {
                isWinDrawLoss[0] = true;  // win
                return "Gagné";
            }
            else if (a < b) {
                isWinDrawLoss[1] = true;  // loss
                return "Perdu";
            }
            else {
                isWinDrawLoss[2] = true;  // draw
                return "Égalité";
            }

        } catch (Exception e) {
            isWinDrawLoss[2] = true;
            return "Égalité";
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        isLobbyVisible = true;
    }

    @Override
    protected void onPause() {
        super.onPause();
        isLobbyVisible = false;
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();

        if (invitesListener != null)
            db.child("invites").child(myUid).removeEventListener(invitesListener);

        if (challengesListener != null)
            db.child("challenges").child(myUid).removeEventListener(challengesListener);

        if (netListener != null)
            infoConnectedRef.removeEventListener(netListener);

        db.child("users").child(myUid).child("status").setValue("offline");
        db.child("users").child(myUid).child("online").setValue(false);
    }
}
