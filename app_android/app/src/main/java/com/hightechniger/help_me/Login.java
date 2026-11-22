package com.hightechniger.help_me;

import android.content.Intent;
import android.os.Build;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.View;
import android.widget.Button;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.auth.FirebaseUser;
import com.google.firebase.firestore.FirebaseFirestore;
import com.google.firebase.firestore.Source;
import com.google.firebase.messaging.FirebaseMessaging;

public class Login extends AppCompatActivity {

    TextInputEditText editTextEmail, editTextPassword;
    TextInputLayout layoutEmail, layoutPassword;
    Button buttonLogin;
    FirebaseAuth mAuth;
    FirebaseFirestore db;
    ProgressBar progressBar;
    TextView textStatus, tvWelcome;

    private boolean isCheckingStatus = false;

    @Override
    protected void onStart() {
        super.onStart();

        FirebaseUser currentUser = FirebaseAuth.getInstance().getCurrentUser();
        FirebaseMessaging.getInstance().subscribeToTopic("forum_alert");

        if (currentUser != null && !isCheckingStatus) {
            isCheckingStatus = true;
            showLoading("Ouverture de votre espace…");

            // 🔵 ESSAI SERVEUR → Si pas internet → fallback CACHE → PAS de déconnexion
            checkUserStatus(currentUser.getUid());

        } else {
            showLoginUI();
        }
    }

    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_login);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            requestPermissions(new String[]{android.Manifest.permission.POST_NOTIFICATIONS}, 1);
        }

        mAuth = FirebaseAuth.getInstance();
        db = FirebaseFirestore.getInstance();

        editTextEmail = findViewById(R.id.email);
        editTextPassword = findViewById(R.id.password);
        layoutEmail = findViewById(R.id.textInputLayoutEmail);
        layoutPassword = findViewById(R.id.textInputLayoutPassword);
        buttonLogin = findViewById(R.id.btn_login);
        progressBar = findViewById(R.id.progressBar);
        textStatus = findViewById(R.id.textStatus);
        tvWelcome = findViewById(R.id.tvWelcome);

        buttonLogin.setOnClickListener(v -> {

            String email = editTextEmail.getText().toString();
            String password = editTextPassword.getText().toString();

            if (TextUtils.isEmpty(email) || TextUtils.isEmpty(password)) {
                Toast.makeText(Login.this, "Veuillez remplir tous les champs", Toast.LENGTH_SHORT).show();
                return;
            }

            showLoading("Vérification de votre accès…");

            mAuth.signInWithEmailAndPassword(email, password).addOnCompleteListener(task -> {
                if (task.isSuccessful()) {
                    FirebaseUser user = mAuth.getCurrentUser();
                    if (user != null) {
                        checkUserStatus(user.getUid());
                    } else {
                        hideLoading();
                        Toast.makeText(Login.this, "Erreur interne : utilisateur introuvable", Toast.LENGTH_SHORT).show();
                    }
                } else {
                    hideLoading();
                    Toast.makeText(Login.this, "Erreur : " + task.getException().getMessage(), Toast.LENGTH_SHORT).show();
                }
            });
        });
    }

    private void checkUserStatus(String uid) {

        // 🔵 ESSAI SERVEUR D’ABORD
        db.collection("users").document(uid).get(Source.SERVER)
                .addOnSuccessListener(doc -> {

                    isCheckingStatus = false;

                    if (doc == null || !doc.exists()) {
                        hideLoading();
                        FirebaseAuth.getInstance().signOut();
                        Toast.makeText(this, "Compte introuvable", Toast.LENGTH_SHORT).show();
                        showLoginUI();
                        return;
                    }

                    Boolean isOnline = doc.getBoolean("isOnline");
                    Boolean isActive = doc.getBoolean("isActive");

                    String savedUid = getSharedPreferences("APP_PREFS", MODE_PRIVATE)
                            .getString("LOGGED_UID", null);

                    // ❌ Compte désactivé sur Firebase
                    if (isActive != null && !isActive) {
                        hideLoading();
                        FirebaseAuth.getInstance().signOut();
                        Toast.makeText(this, "Compte désactivé", Toast.LENGTH_SHORT).show();
                        showLoginUI();
                        return;
                    }

                    // 🔐 Multi-appareil
                    if (isOnline != null && isOnline) {
                        if (savedUid == null || !savedUid.equals(uid)) {
                            hideLoading();
                            Toast.makeText(this,
                                    "Ce compte est déjà utilisé sur un autre appareil",
                                    Toast.LENGTH_LONG).show();
                            FirebaseAuth.getInstance().signOut();
                            showLoginUI();
                            return;
                        } else {
                            // 🔵 Déjà connecté sur ce même appareil
                            startActivity(new Intent(getApplicationContext(), MainActivity2.class));
                            finish();
                            return;
                        }
                    }

                    // Première connexion normale
                    db.collection("users").document(uid)
                            .update("isOnline", true, "isActive", true);

                    // Sauvegarde locale
                    getSharedPreferences("APP_PREFS", MODE_PRIVATE)
                            .edit()
                            .putString("LOGGED_UID", uid)
                            .apply();

                    startActivity(new Intent(getApplicationContext(), MainActivity2.class));
                    finish();
                })
                .addOnFailureListener(e -> {

                    isCheckingStatus = false;

                    // 🟠 PAS INTERNET → passage en mode OFFLINE LOCAL
                    FirebaseUser localUser = FirebaseAuth.getInstance().getCurrentUser();

                    if (localUser != null) {

                        String savedUid = getSharedPreferences("APP_PREFS", MODE_PRIVATE)
                                .getString("LOGGED_UID", null);

                        // 🔵 Si appareil connu localement → ACCÈS OFFLINE
                        if (savedUid != null && savedUid.equals(uid)) {



                            startActivity(new Intent(getApplicationContext(), MainActivity2.class));
                            finish();
                            return;
                        }
                    }

                    // ❌ Pas internet + pas de profil local
                    hideLoading();
                    Toast.makeText(this,
                            "Connexion impossible : internet requis pour la première connexion.",
                            Toast.LENGTH_LONG).show();
                    FirebaseAuth.getInstance().signOut();
                    showLoginUI();
                });
    }

    private void showLoading(String msg) {
        layoutEmail.setVisibility(View.GONE);
        layoutPassword.setVisibility(View.GONE);
        editTextEmail.setVisibility(View.GONE);
        editTextPassword.setVisibility(View.GONE);
        buttonLogin.setVisibility(View.GONE);

        if (tvWelcome != null) tvWelcome.setVisibility(View.GONE);

        textStatus.setText(msg);
        textStatus.setVisibility(View.VISIBLE);
        progressBar.setVisibility(View.VISIBLE);
    }

    private void hideLoading() {
        textStatus.setVisibility(View.GONE);
        progressBar.setVisibility(View.GONE);
        showLoginUI();
    }

    private void showLoginUI() {
        layoutEmail.setVisibility(View.VISIBLE);
        layoutPassword.setVisibility(View.VISIBLE);
        editTextEmail.setVisibility(View.VISIBLE);
        editTextPassword.setVisibility(View.VISIBLE);
        buttonLogin.setVisibility(View.VISIBLE);

        if (tvWelcome != null) tvWelcome.setVisibility(View.VISIBLE);

        textStatus.setVisibility(View.GONE);
        progressBar.setVisibility(View.GONE);
    }
}
