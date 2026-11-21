package com.hightechniger.help_me;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.database.DataSnapshot;
import com.google.firebase.database.DatabaseError;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.ValueEventListener;
import com.hightechniger.help_me.models.User;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

public class LeaderboardActivity extends AppCompatActivity {
    private LinearLayout loadingLayout;

    private RecyclerView rvLeaderboard;
    private LeaderboardAdapter adapter;
    private final List<User> data = new ArrayList<>();

    private DatabaseReference usersRef, historyRef;

    private String myUid, myName = "";

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        setContentView(R.layout.activity_leaderboard);

        // ---------------------------------------------------
        // Récupérer mes informations personnelles
        // ---------------------------------------------------
        com.google.firebase.auth.FirebaseUser me = FirebaseAuth.getInstance().getCurrentUser();
        if (me == null) {   // session expirée : retour à la connexion au lieu d'un plantage
            android.widget.Toast.makeText(this, "Connectez-vous pour voir le classement.", android.widget.Toast.LENGTH_SHORT).show();
            startActivity(new android.content.Intent(this, Login.class));
            finish();
            return;
        }
        myUid = me.getUid();

        DatabaseReference meRef = FirebaseDatabase.getInstance()
                .getReference("users")
                .child(myUid)
                .child("name");

        meRef.get().addOnSuccessListener(s -> {
            String name = String.valueOf(s.getValue());
            if (name != null) myName = name;
        });

        // ---------------------------------------------------
        // Initialisation UI
        loadingLayout = findViewById(R.id.loadingLayout);
        rvLeaderboard = findViewById(R.id.rvLeaderboard);
        rvLeaderboard.setLayoutManager(new LinearLayoutManager(this));

        // 🔥 Adapter corrigé (on envoie myUid + myName)
        adapter = new LeaderboardAdapter(data, myUid, myName);
        rvLeaderboard.setAdapter(adapter);

        usersRef = FirebaseDatabase.getInstance().getReference().child("users");
        historyRef = FirebaseDatabase.getInstance().getReference().child("history");

        loadLeaderboard();
    }

    private void loadLeaderboard() {

        // 🔥 Afficher le chargement
        loadingLayout.setVisibility(View.VISIBLE);
        rvLeaderboard.setVisibility(View.GONE);

        usersRef.addValueEventListener(new ValueEventListener() {
            @Override public void onDataChange(@NonNull DataSnapshot snapshot) {

                data.clear();

                for (DataSnapshot child : snapshot.getChildren()) {
                    User u = child.getValue(User.class);
                    if (u == null) continue;

                    u.uid = child.getKey();
                    data.add(u);
                }

                historyRef.addListenerForSingleValueEvent(new ValueEventListener() {
                    @Override public void onDataChange(@NonNull DataSnapshot histSnap) {

                        for (User u : data) {

                            if (u.uid == null) continue;

                            DataSnapshot userHistory = histSnap.child(u.uid);

                            long played = 0;
                            long wins = 0;

                            for (DataSnapshot matchSnap : userHistory.getChildren()) {

                                String score = matchSnap.child("score").getValue(String.class);
                                if (score == null) continue;

                                try {
                                    String[] parts = score.split("-");
                                    int a = Integer.parseInt(parts[0].trim());
                                    int b = Integer.parseInt(parts[1].trim());

                                    played++;
                                    if (a > b) wins++;

                                } catch (Exception ignored) {}
                            }

                            u.gamesPlayed = played;
                            u.wins = wins;
                            u.losses = played - wins;
                        }

                        Collections.sort(data, (a, b) -> {
                            long wa = (a.wins == null ? 0 : a.wins);
                            long wb = (b.wins == null ? 0 : b.wins);
                            if (wa != wb) return Long.compare(wb, wa);

                            long ua = (a.uniqueOpponents == null ? 0 : a.uniqueOpponents);
                            long ub = (b.uniqueOpponents == null ? 0 : b.uniqueOpponents);
                            if (ua != ub) return Long.compare(ub, ua);

                            String na = (a.name == null ? "" : a.name);
                            String nb = (b.name == null ? "" : b.name);
                            return na.compareToIgnoreCase(nb);
                        });

                        if (data.size() > 10) {
                            List<User> top10 = new ArrayList<>(data.subList(0,10));

                            for (User u : data) {
                                if (!top10.contains(u)) top10.add(u);
                            }

                            data.clear();
                            data.addAll(top10);
                        }

                        adapter.notifyDataSetChanged();

                        // 🔥 Masquer le chargement une fois terminé
                        loadingLayout.setVisibility(View.GONE);
                        rvLeaderboard.setVisibility(View.VISIBLE);
                    }

                    @Override public void onCancelled(@NonNull DatabaseError error) {}
                });
            }

            @Override public void onCancelled(@NonNull DatabaseError error) {}
        });
    }
}
