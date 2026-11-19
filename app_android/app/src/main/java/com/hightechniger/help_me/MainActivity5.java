package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.graphics.Bitmap;
import android.os.Bundle;
import android.view.View;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

public class MainActivity5 extends AppCompatActivity {

    private WebView webView;
    private ProgressBar progressBar;
    private TextView errorMessage;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main5);

        // Initialisation des éléments de l'interface utilisateur
        webView = findViewById(R.id.webview);
        progressBar = findViewById(R.id.progressBar05);
        errorMessage = findViewById(R.id.errorMessage);

        // Configuration du WebViewClient
        webView.setWebViewClient(new WebViewClient() {

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                super.onPageStarted(view, url, favicon);
                // Afficher la barre de progression lors du début du chargement
                progressBar.setVisibility(View.VISIBLE);
                webView.setVisibility(View.INVISIBLE); // Masquer temporairement la WebView
                errorMessage.setVisibility(View.GONE); // Masquer les messages d'erreur
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                // Cacher la barre de progression une fois le chargement terminé
                progressBar.setVisibility(View.GONE);
                webView.setVisibility(View.VISIBLE); // Afficher la WebView après le chargement
            }

            @Override
            public void onReceivedError(WebView view, int errorCode, String description, String failingUrl) {
                super.onReceivedError(view, errorCode, description, failingUrl);
                // Cacher la barre de progression et la WebView en cas d'erreur
                progressBar.setVisibility(View.GONE);
                webView.setVisibility(View.GONE);
                // Afficher un message d'erreur
                errorMessage.setVisibility(View.VISIBLE);
                errorMessage.setText("Impossible de charger la page. Vérifiez votre connexion Internet.");
            }
        });

        // Charger l'URL initiale
        webView.loadUrl("https://windows-web.vercel.app");

        // Configurer les paramètres de la WebView
        WebSettings webSettings = webView.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setUseWideViewPort(true); // Adapter le contenu à la largeur de l'écran
        webSettings.setLoadWithOverviewMode(true); // Ajuster le zoom pour afficher tout le contenu
        webSettings.setBuiltInZoomControls(true); // Activer les contrôles de zoom
        webSettings.setDisplayZoomControls(false); // Masquer les contrôles de zoom à l'écran
    }

    @Override
    public void onBackPressed() {
        // Gestion de la navigation avec le bouton retour
        if (webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
