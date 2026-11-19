package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.os.Bundle;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

public class MiniGameActivity extends AppCompatActivity {

    private WebView webView;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        setContentView(R.layout.activity_mini_game);

        webView = findViewById(R.id.webviewMiniGame);

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);           // JS nécessaire pour le jeu
        settings.setDomStorageEnabled(true);           // stockage local si besoin
        settings.setMediaPlaybackRequiresUserGesture(false); // pour lancer la musique plus facilement

        // Gérer les liens dans la WebView
        webView.setWebViewClient(new WebViewClient());

        // Pour le son / console JS
        webView.setWebChromeClient(new WebChromeClient());

        // Charger le fichier HTML local
        webView.loadUrl("file:///android_asset/souris_bureautique.html");
    }

    @Override
    public void onBackPressed() {
        // Si l'utilisateur a navigué dans la WebView, revenir en arrière
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            // Sinon fermer l'activité
            super.onBackPressed();
        }
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (webView != null) {
            webView.onPause(); // pause JS & son
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) {
            webView.onResume();
        }
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.destroy();
        }
        super.onDestroy();
    }
}
