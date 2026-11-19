package com.hightechniger.help_me;

import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.view.View;
import android.webkit.WebView;
import android.widget.Button;

import androidx.appcompat.app.AppCompatActivity;

public class ApproActivity extends AppCompatActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Vérifier si l'activité a déjà été affichée
        SharedPreferences preferences = getSharedPreferences("AppPrefs", MODE_PRIVATE);
        boolean isFirstLaunch = preferences.getBoolean("FirstLaunch", true);

        if (!isFirstLaunch) {
            // Passer directement à la prochaine activité si déjà affichée
            Intent intent = new Intent(ApproActivity.this, MainActivity.class);
            startActivity(intent);
            finish();
            return;
        }

        // Afficher le contenu HTML de "about.html" dans un WebView
        setContentView(R.layout.activity_appro);
        WebView webView = findViewById(R.id.webView);
        webView.loadUrl("file:///android_asset/privacy.html");

        Button acceptButton = findViewById(R.id.acceptButton);
        Button nextButton = findViewById(R.id.nextButton);
        nextButton.setVisibility(View.GONE);

        acceptButton.setOnClickListener(view -> {
            // Charger "privacy.html" dans le WebView
            webView.loadUrl("file:///android_asset/about_help_me.html");
            acceptButton.setVisibility(View.GONE);
            nextButton.setVisibility(View.VISIBLE);
        });

        nextButton.setOnClickListener(view -> {
            // Enregistrer que l'activité a été vue
            SharedPreferences.Editor editor = preferences.edit();
            editor.putBoolean("FirstLaunch", false);
            editor.apply();

            // Ouvrir la prochaine activité
            Intent intent = new Intent(ApproActivity.this, MainActivity.class);
            startActivity(intent);
            finish();
        });
    }
}
