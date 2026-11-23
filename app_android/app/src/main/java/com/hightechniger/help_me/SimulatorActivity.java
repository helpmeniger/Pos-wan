package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.pm.ActivityInfo;
import android.graphics.Color;
import android.os.Bundle;
import android.view.View;
import android.view.WindowManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.activity.OnBackPressedCallback;
import androidx.appcompat.app.AppCompatActivity;

/**
 * Simulateur Windows 11 hors ligne (assets/sim) : bureau, menu Démarrer, fenêtres, Explorateur,
 * Bloc-notes, Word, Excel, Paint, calculatrice, clavier visuel et exercices guidés.
 * Les fichiers créés restent dans le stockage local de la WebView.
 */
public class SimulatorActivity extends AppCompatActivity {

    private WebView web;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        getWindow().setStatusBarColor(Color.BLACK);
        getWindow().setNavigationBarColor(Color.BLACK);
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_FULLSCREEN
                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY | View.SYSTEM_UI_FLAG_LAYOUT_STABLE);

        web = new WebView(this);
        web.setBackgroundColor(Color.BLACK);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);                    // fichiers et progression des exercices
        s.setAllowFileAccess(true);
        s.setAllowContentAccess(false);
        s.setSupportZoom(false);
        web.setWebViewClient(new WebViewClient());       // aucune navigation externe
        web.addJavascriptInterface(new Bridge(), "HM_ANDROID");
        setContentView(web);
        web.loadUrl("file:///android_asset/sim/index.html");

        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                // le simulateur ferme d'abord ses menus et fenêtres ; sinon il demande à quitter
                web.evaluateJavascript("window.SIM ? SIM.back() : false", v -> { if (!"true".equals(v)) finish(); });
            }
        });
    }

    private class Bridge {
        @JavascriptInterface public void close() { runOnUiThread(SimulatorActivity.this::finish); }
    }

    @Override protected void onDestroy() {
        if (web != null) { web.removeJavascriptInterface("HM_ANDROID"); web.destroy(); }
        super.onDestroy();
    }
}
