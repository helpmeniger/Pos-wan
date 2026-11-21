package com.hightechniger.help_me;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Bundle;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.InputMethodManager;
import android.widget.EditText;
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
import com.hightechniger.help_me.ui.Course;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

/**
 * Sites utiles : liste partagée (Firebase, mise à jour en direct et gardée sur le téléphone),
 * recherche instantanée, cartes lisibles et formulaire repliable « Proposer un site ».
 */
public class MainActivity17 extends AppCompatActivity {

    private static final int[] COLORS = {0xFF0F8A5F, 0xFF326FBF, 0xFF6A4FC2, 0xFFC43E1C, 0xFFD9822B, 0xFF1D6F42, 0xFF37474F};

    private DatabaseReference databaseReference;
    private ValueEventListener listener;
    private ItemAdapter itemAdapter;
    private final List<Item> allItems = new ArrayList<>();
    private final List<Item> shown = new ArrayList<>();
    private ProgressBar progressBar;
    private TextView tvLoading, tvCount;
    private View formCard, btnPropose;
    private SharedPreferences sharedPreferences;
    private String filter = "";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main17);
        getWindow().setStatusBarColor(Color.parseColor("#0E7A55"));

        progressBar = findViewById(R.id.progressBar02);
        tvLoading = findViewById(R.id.tvLoading);
        tvCount = findViewById(R.id.tvSitesCount);
        formCard = findViewById(R.id.formCard);
        btnPropose = findViewById(R.id.btnPropose);
        sharedPreferences = getSharedPreferences("HelpMePrefs", Context.MODE_PRIVATE);
        databaseReference = FirebaseDatabase.getInstance().getReference("SITEWEB");

        RecyclerView recyclerView = findViewById(R.id.recyclerView);
        recyclerView.setLayoutManager(new LinearLayoutManager(this));
        itemAdapter = new ItemAdapter(shown);
        recyclerView.setAdapter(itemAdapter);

        ((EditText) findViewById(R.id.etSearch)).addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void onTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void afterTextChanged(Editable s) { filter = s.toString(); applyFilter(); }
        });
        btnPropose.setOnClickListener(v -> showForm(true));
        findViewById(R.id.btnCancelAdd).setOnClickListener(v -> showForm(false));
        findViewById(R.id.btnAdd).setOnClickListener(v -> addItem());

        loadLocalData();          // liste enregistrée : affichage immédiat, même hors connexion
        fetchDataFromFirebase();  // puis mise à jour en direct
    }

    @Override
    protected void onDestroy() {
        if (listener != null) databaseReference.removeEventListener(listener);
        super.onDestroy();
    }

    @Override
    public void onBackPressed() {
        if (formCard.getVisibility() == View.VISIBLE) { showForm(false); return; }
        super.onBackPressed();
    }

    private void showForm(boolean show) {
        if (show) {
            formCard.setVisibility(View.VISIBLE);
            formCard.setTranslationY(formCard.getHeight() > 0 ? formCard.getHeight() : 600);
            formCard.animate().translationY(0).setDuration(280).start();
            btnPropose.setVisibility(View.GONE);
        } else {
            formCard.animate().translationY(formCard.getHeight()).setDuration(220)
                    .withEndAction(() -> formCard.setVisibility(View.GONE)).start();
            btnPropose.setVisibility(View.VISIBLE);
            InputMethodManager imm = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
            if (imm != null) imm.hideSoftInputFromWindow(formCard.getWindowToken(), 0);
        }
    }

    // une seule écoute en direct (l'ancienne version en ajoutait une nouvelle chaque minute)
    private void fetchDataFromFirebase() {
        showLoading(true);
        listener = new ValueEventListener() {
            @Override
            public void onDataChange(@NonNull DataSnapshot snapshot) {
                allItems.clear();
                StringBuilder localData = new StringBuilder();
                for (DataSnapshot data : snapshot.getChildren()) {
                    Item item = data.getValue(Item.class);
                    if (item == null || item.getTitle() == null) continue;
                    allItems.add(item);
                    localData.append(clean(item.getTitle())).append("|").append(clean(item.getLink())).append("|")
                            .append(clean(item.getDescription())).append(";");
                }
                applyFilter();
                sharedPreferences.edit().putString("site_data", localData.toString()).apply();
                showLoading(false);
            }

            @Override
            public void onCancelled(@NonNull DatabaseError error) {
                showLoading(false);
                if (allItems.isEmpty())
                    Toast.makeText(MainActivity17.this, "Impossible de charger la liste. Vérifiez votre connexion.", Toast.LENGTH_SHORT).show();
            }
        };
        databaseReference.addValueEventListener(listener);
    }

    private static String clean(String s) { return s == null ? "" : s.replace("|", "/").replace(";", ","); }

    private void loadLocalData() {
        String savedData = sharedPreferences.getString("site_data", "");
        if (savedData.isEmpty()) return;
        allItems.clear();
        for (String itemData : savedData.split(";")) {
            String[] parts = itemData.split("\\|");
            if (parts.length == 3) allItems.add(new Item(parts[0], parts[1], parts[2]));
        }
        applyFilter();
    }

    private void applyFilter() {
        shown.clear();
        String f = Course.fold(filter).trim();
        for (Item i : allItems) {
            if (f.isEmpty() || Course.fold(i.getTitle() + " " + i.getDescription() + " " + i.getLink()).contains(f)) shown.add(i);
        }
        itemAdapter.notifyDataSetChanged();
        tvCount.setText(allItems.isEmpty() ? "Outils en ligne, études, emploi : notre sélection"
                : f.isEmpty() ? allItems.size() + " sites sélectionnés pour vous"
                : shown.size() + " résultat" + (shown.size() > 1 ? "s" : "") + " pour « " + filter.trim() + " »");
    }

    private void addItem() {
        String title = ((TextView) findViewById(R.id.etTitle)).getText().toString().trim();
        String link = ((TextView) findViewById(R.id.etLink)).getText().toString().trim();
        String description = ((TextView) findViewById(R.id.etDescription)).getText().toString().trim();
        if (TextUtils.isEmpty(title) || TextUtils.isEmpty(link) || TextUtils.isEmpty(description)) {
            Toast.makeText(this, "Remplissez les trois champs.", Toast.LENGTH_SHORT).show();
            return;
        }
        if (!link.startsWith("http://") && !link.startsWith("https://")) link = "https://" + link;
        if (!android.util.Patterns.WEB_URL.matcher(link).matches()) {
            Toast.makeText(this, "L’adresse du site ne semble pas valide.", Toast.LENGTH_SHORT).show();
            return;
        }
        String id = databaseReference.push().getKey();
        if (id == null) return;
        databaseReference.child(id).setValue(new Item(title, link, description)).addOnCompleteListener(task -> {
            if (task.isSuccessful()) {
                Toast.makeText(this, "Merci ! Le site a été ajouté.", Toast.LENGTH_SHORT).show();
                ((TextView) findViewById(R.id.etTitle)).setText("");
                ((TextView) findViewById(R.id.etLink)).setText("");
                ((TextView) findViewById(R.id.etDescription)).setText("");
                showForm(false);
            } else {
                Toast.makeText(this, "Envoi impossible. Vérifiez votre connexion.", Toast.LENGTH_SHORT).show();
            }
        });
    }

    private void showLoading(boolean isLoading) {
        progressBar.setVisibility(isLoading ? View.VISIBLE : View.GONE);
        tvLoading.setVisibility(isLoading ? View.VISIBLE : View.GONE);
    }

    static String domain(String link) {
        try {
            String h = Uri.parse(link.startsWith("http") ? link : "https://" + link).getHost();
            return h == null ? link : h.replaceFirst("^www\\.", "");
        } catch (Exception e) {
            return link;
        }
    }

    private static class ItemAdapter extends RecyclerView.Adapter<ItemAdapter.ViewHolder> {
        private final List<Item> items;

        ItemAdapter(List<Item> items) { this.items = items; }

        @NonNull
        @Override
        public ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new ViewHolder(LayoutInflater.from(parent.getContext()).inflate(R.layout.item_layout, parent, false));
        }

        @Override
        public void onBindViewHolder(@NonNull ViewHolder h, int position) {
            Item item = items.get(position);
            String t = item.getTitle() == null ? "?" : item.getTitle().trim();
            h.title.setText(t);
            h.description.setText(item.getDescription());
            h.domain.setText(item.getLink() == null ? "" : domain(item.getLink()));
            h.initial.setText(t.isEmpty() ? "?" : t.substring(0, 1).toUpperCase(Locale.FRANCE));
            GradientDrawable g = new GradientDrawable();
            g.setCornerRadius(h.itemView.getResources().getDisplayMetrics().density * 12);
            g.setColor(COLORS[Math.abs(t.hashCode()) % COLORS.length]);
            h.initial.setBackground(g);
            h.description.setMaxLines(3);
            h.description.setOnClickListener(v -> h.description.setMaxLines(h.description.getMaxLines() == 3 ? 50 : 3));
            h.itemView.setOnClickListener(v -> {
                Intent intent = new Intent(v.getContext(), WebViewActivity.class);
                intent.putExtra("url", item.getLink());
                v.getContext().startActivity(intent);
            });
        }

        @Override
        public int getItemCount() { return items.size(); }

        static class ViewHolder extends RecyclerView.ViewHolder {
            final TextView title, description, domain, initial;

            ViewHolder(View itemView) {
                super(itemView);
                title = itemView.findViewById(R.id.tvTitle);
                description = itemView.findViewById(R.id.tvDescription);
                domain = itemView.findViewById(R.id.tvDomain);
                initial = itemView.findViewById(R.id.tvInitial);
            }
        }
    }
}
