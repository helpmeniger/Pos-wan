package com.hightechniger.help_me;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.RecyclerView;

import com.hightechniger.help_me.models.HistoryItem;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.Locale;

public class HistoryAdapter extends RecyclerView.Adapter<HistoryAdapter.Holder> {

    private final List<HistoryItem> data;
    private final SimpleDateFormat sdf =
            new SimpleDateFormat("dd MMM yyyy — HH:mm", Locale.FRENCH);

    public HistoryAdapter(List<HistoryItem> data) {
        this.data = data;
    }

    @NonNull
    @Override
    public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View v = LayoutInflater.from(parent.getContext())
                .inflate(R.layout.item_history, parent, false);
        return new Holder(v);
    }

    @Override
    public void onBindViewHolder(@NonNull Holder h, int pos) {
        HistoryItem it = data.get(pos);

        String opp = (it.opponentName != null) ? it.opponentName : "Adversaire inconnu";
        String score = (it.score != null) ? it.score : "--";

        h.tvOpponent.setText("Vs " + opp);
        h.tvScore.setText("Score : " + score);

        // 🔥 Détection victoire / défaite / égalité
        boolean win = false, loss = false, draw = false;

        try {
            String[] t = score.split("-");
            int a = Integer.parseInt(t[0].trim());
            int b = Integer.parseInt(t[1].trim());

            if (a > b) win = true;
            else if (a < b) loss = true;
            else draw = true;

        } catch (Exception ignored) {}

        String resultText;
        int color;

        if (win) {
            resultText = "Gagné";
            color = 0xFF22C55E; // vert
        }
        else if (loss) {
            resultText = "Perdu";
            color = 0xFFEF4444; // rouge
        }
        else {
            resultText = "Égalité";
            color = 0xFFFACC15; // jaune
        }

        h.tvResult.setText(resultText);
        h.tvResult.setTextColor(color);

        // 🔥 Date + durée
        long dur = (it.durationMs == null ? 0L : it.durationMs);

        if (it.timestamp != null) {
            String d = sdf.format(new Date(it.timestamp));
            h.tvDate.setText(d + " — Durée " + formatDuration(dur));
        } else {
            h.tvDate.setText("Date inconnue — Durée " + formatDuration(dur));
        }
    }

    private String formatDuration(long ms) {
        long s = ms / 1000;
        long min = s / 60;
        long sec = s % 60;
        return String.format(Locale.getDefault(), "%02d:%02d", min, sec);
    }

    @Override
    public int getItemCount() { return data.size(); }

    static class Holder extends RecyclerView.ViewHolder {
        TextView tvOpponent, tvScore, tvDate, tvResult;

        Holder(View v) {
            super(v);
            tvOpponent = v.findViewById(R.id.tvOpponent);
            tvScore    = v.findViewById(R.id.tvScore);
            tvDate     = v.findViewById(R.id.tvDate);
            tvResult   = v.findViewById(R.id.tvResult); // 🔥 nouveau TextView
        }
    }
}
