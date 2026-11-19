package com.hightechniger.help_me;

import android.os.Bundle;
import android.view.View;
import android.webkit.WebChromeClient;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.appcompat.app.AppCompatActivity;

public class WebViewActivity extends AppCompatActivity {

    private WebView webView;
    private View loadingLayout;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_web_view);

        webView = findViewById(R.id.webView17);
        loadingLayout = findViewById(R.id.loadingLayout);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                super.onPageStarted(view, url, favicon);
                // Afficher l'indicateur de chargement
                loadingLayout.setVisibility(View.VISIBLE);
            }
            @Override
            public void onReceivedError(WebView view, int errorCode, String description, String failingUrl) {
                super.onReceivedError(view, errorCode, description, failingUrl);
                loadingLayout.setVisibility(View.GONE);
                webView.loadData("<h1>Erreur de chargement</h1><p>Impossible d'accéder à la page demandée contacter Help-me : +227 87 22 52 02 - 92 08 12 82.</p>",
                        "text/html", "UTF-8");
            }
            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                // Masquer l'indicateur de chargement
                loadingLayout.setVisibility(View.GONE);
            }


        });

        webView.setWebChromeClient(new WebChromeClient());

        webView.getSettings().setJavaScriptEnabled(true);

        // Charger l'URL transmis depuis l'activité précédente
        String url = getIntent().getStringExtra("url");
        if (url != null) {
            webView.loadUrl(url);
        }
    }
}
