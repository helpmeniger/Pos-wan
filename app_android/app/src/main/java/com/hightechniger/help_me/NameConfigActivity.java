package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.widget.Button;
import android.widget.EditText;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

public class NameConfigActivity extends AppCompatActivity {

    EditText etName;
    Button btnSave;
    SharedPreferences prefs;

    @SuppressLint("MissingInflatedId")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        prefs = getSharedPreferences("HelpMePrefs", MODE_PRIVATE);
        String savedName = prefs.getString("username", null);

        // Si le nom est déjà enregistré, on passe directement au QuizActivity
        if (savedName != null && !savedName.isEmpty()) {
            startActivity(new Intent(this, QuizActivity.class));
            finish();
            return;
        }

        setContentView(R.layout.activity_name_config);

        etName = findViewById(R.id.etName);
        btnSave = findViewById(R.id.btnSave);

        btnSave.setOnClickListener(v -> {
            String name = etName.getText().toString().trim();
            if (name.isEmpty()) {
                Toast.makeText(this, "Veuillez entrer votre nom", Toast.LENGTH_SHORT).show();
            } else {
                prefs.edit().putString("username", name).apply();
                Toast.makeText(this, "Nom enregistré !", Toast.LENGTH_SHORT).show();
                startActivity(new Intent(this, QuizActivity.class));
                finish();
            }
        });
    }
}
