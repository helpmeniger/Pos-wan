package com.hightechniger.help_me;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.card.MaterialCardView;
import com.hightechniger.help_me.models.User;
import com.hightechniger.help_me.push.HttpHelper;

import java.util.List;

public class LeaderboardAdapter extends RecyclerView.Adapter<LeaderboardAdapter.Holder> {

    private final List<User> data;

    private final String API_KEY = BuildConfig.HM_API_KEY;
    private final String HMAC_SECRET = BuildConfig.HM_HMAC_SECRET;
    private final String SERVER_URL = BuildConfig.HM_SERVER_URL;

    private final String myUid;
    private final String myName;

    public LeaderboardAdapter(List<User> data, String myUid, String myName) {
        this.data = data;
        this.myUid = myUid;
        this.myName = myName;
    }

    @NonNull
    @Override
    public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View v = LayoutInflater.from(parent.getContext())
                .inflate(R.layout.item_leaderboard, parent, false);
        return new Holder(v);
    }

    @Override
    public void onBindViewHolder(@NonNull Holder h, int pos) {

        User u = data.get(pos);

        h.tvRank.setText("#" + (pos + 1));
        h.tvName.setText(u.name != null ? u.name : "Joueur");

        long wins = (u.wins == null ? 0 : u.wins);
        long unique = (u.uniqueOpponents == null ? 0 : u.uniqueOpponents);

        h.tvWins.setText("Victoires: " + wins);
        h.tvUnique.setText("Adversaires uniques: " + unique);

        long played = (u.gamesPlayed == null ? 0 : u.gamesPlayed);
        long lost = played - wins;

        h.tvPlayed.setText("Matchs joués: " + played);
        h.tvWon.setText("Matchs gagnés: " + wins);
        h.tvLost.setText("Matchs perdus: " + lost);

        // 🔥 Affichage du status ("busy", "available", "offline")
        String status = u.status == null ? "offline" : u.status;
        switch (status) {
            case "available": h.tvStatus.setText("🟢 Disponible"); break;
            case "busy":      h.tvStatus.setText("🔴 En match"); break;
            default:          h.tvStatus.setText("⚪ Hors ligne"); break;
        }

        // 🔥 NE PAS SE DEFIER SOI-MÊME
        if (u.uid != null && u.uid.equals(myUid)) {
            h.card.setOnClickListener(null);
            h.card.setClickable(false);
            return;
        }

        // 🔥 TOUTE LA CARTE → montre popup + envoie notif
        h.card.setOnClickListener(v -> {

            new com.google.android.material.dialog.MaterialAlertDialogBuilder(v.getContext())
                    .setTitle("Défier " + u.name)
                    .setMessage("Voulez-vous envoyer une demande à " + u.name + " pour un match ?")
                    .setPositiveButton("Oui", (d, w) -> {

                        String json = "{\"name\":\"" + myName + "\",\"targetUid\":\"" + u.uid + "\"}";

                        HttpHelper.postJsonSigned(
                                SERVER_URL, API_KEY, HMAC_SECRET, json, (ok, res) -> {}
                        );

                    })
                    .setNegativeButton("Non", null)
                    .show();
        });
    }

    @Override
    public int getItemCount() {
        return data.size();
    }

    static class Holder extends RecyclerView.ViewHolder {

        MaterialCardView card;
        TextView tvRank, tvName, tvWins, tvUnique, tvPlayed, tvWon, tvLost, tvStatus;

        public Holder(@NonNull View v) {
            super(v);

            card     = (MaterialCardView) v; // 🔥 Toute la carte devient cliquable

            tvRank   = v.findViewById(R.id.tvRank);
            tvName   = v.findViewById(R.id.tvName);
            tvWins   = v.findViewById(R.id.tvWins);
            tvUnique = v.findViewById(R.id.tvUnique);

            tvPlayed = v.findViewById(R.id.tvPlayed);
            tvWon    = v.findViewById(R.id.tvWon);
            tvLost   = v.findViewById(R.id.tvLost);

            tvStatus = v.findViewById(R.id.tvStatus);
        }
    }
}
