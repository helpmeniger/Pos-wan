package com.hightechniger.help_me;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.os.Handler;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.firebase.database.DataSnapshot;
import com.google.firebase.database.DatabaseError;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.ValueEventListener;

import java.util.ArrayList;
import java.util.List;

public class MainActivity17 extends AppCompatActivity {
    private DatabaseReference databaseReference;
    private RecyclerView recyclerView;
    private ItemAdapter itemAdapter;
    private List<Item> itemList;
    private ProgressBar progressBar;
    private TextView tvLoading;
    private SharedPreferences sharedPreferences;
    private Handler handler = new Handler();
    private static final int REFRESH_INTERVAL = 60000; // Actualisation toutes les 60 secondes

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main17);

        progressBar = findViewById(R.id.progressBar02);
        tvLoading = findViewById(R.id.tvLoading);
        progressBar.setVisibility(View.GONE);
        tvLoading.setVisibility(View.GONE);

        sharedPreferences = getSharedPreferences("HelpMePrefs", Context.MODE_PRIVATE);
        databaseReference = FirebaseDatabase.getInstance().getReference("SITEWEB");

        recyclerView = findViewById(R.id.recyclerView);
        recyclerView.setLayoutManager(new LinearLayoutManager(this));
        itemList = new ArrayList<>();
        itemAdapter = new ItemAdapter(itemList);
        recyclerView.setAdapter(itemAdapter);

        findViewById(R.id.btnAdd).setOnClickListener(v -> addItem());

        loadLocalData(); // Charger les données locales avant Firebase
        fetchDataFromFirebase(); // Récupérer les données de Firebase

        startAutoRefresh();
    }

    private void fetchDataFromFirebase() {
        showLoading(true);
        databaseReference.addValueEventListener(new ValueEventListener() {
            @Override
            public void onDataChange(@NonNull DataSnapshot snapshot) {
                itemList.clear();
                StringBuilder localData = new StringBuilder();

                for (DataSnapshot data : snapshot.getChildren()) {
                    Item item = data.getValue(Item.class);
                    itemList.add(item);
                    localData.append(item.getTitle()).append("|").append(item.getLink()).append("|").append(item.getDescription()).append(";");
                }
                itemAdapter.notifyDataSetChanged();
                saveLocalData(localData.toString());
                showLoading(false);
            }

            @Override
            public void onCancelled(@NonNull DatabaseError error) {
                Toast.makeText(MainActivity17.this, "Erreur de chargement des données", Toast.LENGTH_SHORT).show();
                showLoading(false);
            }
        });
    }

    private void loadLocalData() {
        String savedData = sharedPreferences.getString("site_data", "");
        if (!savedData.isEmpty()) {
            itemList.clear();
            String[] items = savedData.split(";");
            for (String itemData : items) {
                String[] parts = itemData.split("\\|");
                if (parts.length == 3) {
                    itemList.add(new Item(parts[0], parts[1], parts[2]));
                }
            }
            itemAdapter.notifyDataSetChanged();
        }
    }

    private void saveLocalData(String data) {
        sharedPreferences.edit().putString("site_data", data).apply();
    }

    private void addItem() {
        String title = ((TextView) findViewById(R.id.etTitle)).getText().toString();
        String link = ((TextView) findViewById(R.id.etLink)).getText().toString();
        String description = ((TextView) findViewById(R.id.etDescription)).getText().toString();

        if (TextUtils.isEmpty(title) || TextUtils.isEmpty(link) || TextUtils.isEmpty(description)) {
            Toast.makeText(this, "Tous les champs sont obligatoires.", Toast.LENGTH_SHORT).show();
            return;
        }

        String id = databaseReference.push().getKey();
        Item item = new Item(title, link, description);
        if (id != null) {
            databaseReference.child(id).setValue(item).addOnCompleteListener(task -> {
                if (task.isSuccessful()) {
                    Toast.makeText(this, "Site web ajouté", Toast.LENGTH_SHORT).show();
                } else {
                    Toast.makeText(this, "Erreur d'ajout", Toast.LENGTH_SHORT).show();
                }
            });
        }
    }

    private void showLoading(boolean isLoading) {
        progressBar.setVisibility(isLoading ? View.VISIBLE : View.GONE);
        tvLoading.setVisibility(isLoading ? View.VISIBLE : View.GONE);
    }

    private void startAutoRefresh() {
        handler.postDelayed(new Runnable() {
            @Override
            public void run() {
                fetchDataFromFirebase();
                handler.postDelayed(this, REFRESH_INTERVAL);
            }
        }, REFRESH_INTERVAL);
    }

    private static class ItemAdapter extends RecyclerView.Adapter<ItemAdapter.ViewHolder> {
        private final List<Item> items;

        public ItemAdapter(List<Item> items) {
            this.items = items;
        }

        @NonNull
        @Override
        public ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            View view = LayoutInflater.from(parent.getContext()).inflate(R.layout.item_layout, parent, false);
            return new ViewHolder(view);
        }

        @Override
        public void onBindViewHolder(@NonNull ViewHolder holder, int position) {
            Item item = items.get(position);
            holder.title.setText(item.getTitle());
            holder.description.setText(item.getDescription());
            holder.itemView.setOnClickListener(v -> {
                Intent intent = new Intent(v.getContext(), WebViewActivity.class);
                intent.putExtra("url", item.getLink());
                v.getContext().startActivity(intent);
            });
        }

        @Override
        public int getItemCount() {
            return items.size();
        }

        static class ViewHolder extends RecyclerView.ViewHolder {
            TextView title, description;

            ViewHolder(View itemView) {
                super(itemView);
                title = itemView.findViewById(R.id.tvTitle);
                description = itemView.findViewById(R.id.tvDescription);
            }
        }
    }
}
