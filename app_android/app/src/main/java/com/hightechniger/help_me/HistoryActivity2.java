package com.hightechniger.help_me;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

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
import com.hightechniger.help_me.models.HistoryItem;

import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.List;

public class HistoryActivity2 extends AppCompatActivity {
    private LinearLayout loadingLayout;   // 🔥 Nouveau
    private ProgressBar progressLoading;  // 🔥 Nouveau
    private TextView txtLoading;          // 🔥 Nouveau
    private RecyclerView rvHistory;
    private LinearLayout historyContainer; // si tu veux ajouter des infos "aucun match"
    private HistoryAdapter adapter;
    private DatabaseReference historyRef;
    private String myUid;
    private final List<HistoryItem> data = new ArrayList<>();

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_history2);

        rvHistory = findViewById(R.id.rvHistory);
        historyContainer = findViewById(R.id.historyContainer);
        loadingLayout = findViewById(R.id.loadingLayout);
        progressLoading = findViewById(R.id.progressLoading);
        txtLoading = findViewById(R.id.txtLoading);


        rvHistory.setLayoutManager(new LinearLayoutManager(this));
        adapter = new HistoryAdapter(data);
        rvHistory.setAdapter(adapter);

        myUid = FirebaseAuth.getInstance().getCurrentUser().getUid();
        historyRef = FirebaseDatabase.getInstance().getReference()
                .child("history").child(myUid);

        loadingLayout.setVisibility(View.VISIBLE);
        rvHistory.setVisibility(View.GONE);

        historyRef.orderByChild("timestamp").addValueEventListener(new ValueEventListener() {
            @Override public void onDataChange(@NonNull DataSnapshot snapshot) {
                data.clear();
                if (!snapshot.hasChildren()) {
                    showEmptyInfo();
                    adapter.notifyDataSetChanged();


                    loadingLayout.setVisibility(View.GONE);
                    rvHistory.setVisibility(View.VISIBLE);

                    return;
                }
                for (DataSnapshot child : snapshot.getChildren()) {
                    HistoryItem item = child.getValue(HistoryItem.class);
                    if (item != null) data.add(item);
                }
                // Tri décroissant par timestamp (dernier d'abord)
                Collections.sort(data, new Comparator<HistoryItem>() {
                    @Override public int compare(HistoryItem a, HistoryItem b) {
                        long ta = a.timestamp == null ? 0 : a.timestamp;
                        long tb = b.timestamp == null ? 0 : b.timestamp;
                        return Long.compare(tb, ta);
                    }
                });
                hideEmptyInfo();
                adapter.notifyDataSetChanged();

                // 🔥 Masquer loader après chargement
                loadingLayout.setVisibility(View.GONE);
                rvHistory.setVisibility(View.VISIBLE);
            }

            @Override public void onCancelled(@NonNull DatabaseError error) {}
        });
    }

    private void showEmptyInfo() {
        if (historyContainer != null) {
            // simple: tu peux ajouter un TextView ici si besoin
        }
    }

    private void hideEmptyInfo() {
        if (historyContainer != null) {
            // idem
        }
    }
}
