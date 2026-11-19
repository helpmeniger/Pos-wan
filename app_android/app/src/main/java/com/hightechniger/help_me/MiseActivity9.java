package com.hightechniger.help_me;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.google.firebase.database.DataSnapshot;
import com.google.firebase.database.DatabaseError;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.ValueEventListener;

public class MiseActivity9 extends AppCompatActivity {

    private TextView tvTitle;
    private Button btnUpdate;
    private ProgressBar progressBar;

    // Références Firebase
    private DatabaseReference databaseReference;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_mise9);

        tvTitle = findViewById(R.id.tvTitle09);
        btnUpdate = findViewById(R.id.btnUpdate);
        progressBar = findViewById(R.id.progressBar);

        // Initialiser Firebase Database
        databaseReference = FirebaseDatabase.getInstance().getReference("updateInfo");

        // Afficher la barre de progression et masquer les autres vues
        progressBar.setVisibility(View.VISIBLE);
        tvTitle.setVisibility(View.VISIBLE);
        btnUpdate.setVisibility(View.GONE);

        // Charger les données depuis Firebase
        databaseReference.addValueEventListener(new ValueEventListener() {
            @Override
            public void onDataChange(@NonNull DataSnapshot snapshot) {
                // Récupérer les données
                String title = snapshot.child("title").getValue(String.class);
                String downloadLink = snapshot.child("link").getValue(String.class);

                if (title != null && downloadLink != null) {
                    // Mettre à jour l'interface utilisateur
                    tvTitle.setText(title);
                    btnUpdate.setOnClickListener(v -> {
                        // Ouvrir le lien de téléchargement
                        Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(downloadLink));
                        startActivity(intent);
                    });

                    // Masquer la barre de progression et afficher les autres vues
                    progressBar.setVisibility(View.GONE);
                    tvTitle.setVisibility(View.VISIBLE);
                    btnUpdate.setVisibility(View.VISIBLE);
                } else {
                    Toast.makeText(MiseActivity9.this, "Vous disposez des dernières mises à jour.", Toast.LENGTH_SHORT).show();
                    progressBar.setVisibility(View.GONE);
                }
            }

            @Override
            public void onCancelled(@NonNull DatabaseError error) {
                Toast.makeText(MiseActivity9.this, "Erreur Firebase : " + error.getMessage(), Toast.LENGTH_SHORT).show();
                progressBar.setVisibility(View.GONE);
            }
        });
    }
}
