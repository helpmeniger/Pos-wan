package com.hightechniger.help_me;

import android.content.Intent;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.View;
import android.widget.Button;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.gms.tasks.OnCompleteListener;
import com.google.android.gms.tasks.Task;
import com.google.android.material.textfield.TextInputEditText;
import com.google.firebase.auth.AuthResult;
import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.auth.FirebaseUser;
import com.google.firebase.firestore.FirebaseFirestore;

public class Register extends AppCompatActivity {

    TextInputEditText editTextEmail, editTextPassword;
    Button buttonRegister;
    FirebaseAuth mAuth;
    FirebaseFirestore db;
    ProgressBar progressBar;
    TextView textView;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_register);

        mAuth = FirebaseAuth.getInstance();
        db = FirebaseFirestore.getInstance();

        editTextEmail = findViewById(R.id.email);
        editTextPassword = findViewById(R.id.password);
        buttonRegister = findViewById(R.id.btn_register);
        progressBar = findViewById(R.id.progressBar);
        textView = findViewById(R.id.loginNow);

        textView.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Intent intent = new Intent(getApplicationContext(), Login.class);
                startActivity(intent);
                finish();
            }
        });

        buttonRegister.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                progressBar.setVisibility(View.VISIBLE);
                String email, password;
                email = String.valueOf(editTextEmail.getText());
                password = String.valueOf(editTextPassword.getText());

                if (TextUtils.isEmpty(email)) {
                    progressBar.setVisibility(View.GONE);
                    Toast.makeText(Register.this, "Entrez votre adresse e-mail", Toast.LENGTH_SHORT).show();
                    return;
                }
                if (TextUtils.isEmpty(password)) {
                    progressBar.setVisibility(View.GONE);
                    Toast.makeText(Register.this, "Entrez votre mot de passe", Toast.LENGTH_SHORT).show();
                    return;
                }

                // Enregistrement de l'utilisateur
                mAuth.createUserWithEmailAndPassword(email, password)
                        .addOnCompleteListener(new OnCompleteListener<AuthResult>() {
                            @Override
                            public void onComplete(@NonNull Task<AuthResult> task) {
                                progressBar.setVisibility(View.GONE);
                                if (task.isSuccessful()) {
                                    // Utilisateur enregistré avec succès
                                    FirebaseUser user = mAuth.getCurrentUser();
                                    // Ajouter l'utilisateur à Firestore avec isActive = false
                                    addUserToFirestore(user);
                                    Toast.makeText(Register.this, "Enregistrement réussi", Toast.LENGTH_SHORT).show();
                                    // Rediriger vers l'écran de connexion ou la page principale
                                    Intent intent = new Intent(getApplicationContext(), Login.class);
                                    startActivity(intent);
                                    finish();
                                } else {
                                    Toast.makeText(Register.this, "Erreur lors de l'enregistrement", Toast.LENGTH_SHORT).show();
                                }
                            }
                        });
            }
        });
    }

    /**
     * Ajouter l'utilisateur à Firestore avec isActive défini sur false.
     */
    private void addUserToFirestore(FirebaseUser user) {
        String uid = user.getUid();
        String email = user.getEmail();

        // Création d'un document utilisateur avec isActive = false
        User userData = new User(email, false);

        db.collection("users").document(uid)
                .set(userData)
                .addOnCompleteListener(new OnCompleteListener<Void>() {
                    @Override
                    public void onComplete(@NonNull Task<Void> task) {
                        if (task.isSuccessful()) {
                            // Utilisateur ajouté avec succès
                            Toast.makeText(Register.this, "Utilisateur ajouté à Firestore", Toast.LENGTH_SHORT).show();
                        } else {
                            Toast.makeText(Register.this, "Erreur lors de l'ajout de l'utilisateur à Firestore", Toast.LENGTH_SHORT).show();
                        }
                    }
                });
    }

    /**
     * Classe pour l'objet User.
     */
    public static class User {
        private String email;
        private boolean isActive;

        public User() {
            // Constructeur par défaut nécessaire pour Firestore
        }

        public User(String email, boolean isActive) {
            this.email = email;
            this.isActive = isActive;
        }

        public String getEmail() {
            return email;
        }

        public boolean isActive() {
            return isActive;
        }
    }
}
