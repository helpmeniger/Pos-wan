package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ActivityInfo;
import android.graphics.Color;
import android.os.Bundle;
import android.speech.tts.TextToSpeech;
import android.view.View;
import android.view.WindowManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.appcompat.app.AppCompatActivity;

import java.io.IOException;
import java.util.Locale;

/**
 * Leçon animée (remplace les anciennes vidéos MP4) : page HTML/CSS/SVG de assets/anim, pilotée par la voix off
 * (assets/anim/audio/CODE.m4a) ou, à défaut, par la synthèse vocale du téléphone via le pont HM_ANDROID.
 */
public class AnimLessonActivity extends AppCompatActivity {

    public static final String EXTRA_CODE = "code";

    /** Vrai si une leçon animée existe pour ce code-barres. */
    public static boolean exists(Context c, String code) {
        if (code == null || !code.matches("[A-Z0-9]+")) return false;
        try {
            c.getAssets().open("anim/lessons/L" + code + ".js").close();
            return true;
        } catch (IOException e) {
            return false;
        }
    }

    /** Leçons sans code-barres (PowerPoint) reliées à une leçon animée. */
    private static final java.util.Map<String, String> BY_LESSON = new java.util.HashMap<>();
    static {
        BY_LESSON.put("powerpoint-x002", "P001");
        BY_LESSON.put("powerpoint-x001", "P002");
        BY_LESSON.put("powerpoint-x006", "P003");
    }

    /** Code de la leçon animée d'une leçon : ses codes-barres d'abord, puis la table ci-dessus ; null sinon. */
    public static String codeFor(Context c, com.hightechniger.help_me.ui.Course.Lesson lesson) {
        if (lesson == null) return null;
        for (String code : lesson.codes) if (exists(c, code)) return code;
        String m = BY_LESSON.get(lesson.id);
        return m != null && exists(c, m) ? m : null;
    }

    public static void open(Context c, String code) {
        c.startActivity(new Intent(c, AnimLessonActivity.class).putExtra(EXTRA_CODE, code));
    }

    private WebView web;
    private TextToSpeech tts;
    private boolean ttsReady;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        String code = getIntent().getStringExtra(EXTRA_CODE);
        if (code == null || !code.matches("[A-Z0-9]+") || !exists(this, code)) { finish(); return; }
        setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        getWindow().setStatusBarColor(Color.BLACK);
        getWindow().setNavigationBarColor(Color.BLACK);
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_FULLSCREEN
                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY | View.SYSTEM_UI_FLAG_LAYOUT_STABLE);

        tts = new TextToSpeech(this, status -> {
            if (status == TextToSpeech.SUCCESS) {
                tts.setLanguage(Locale.FRANCE);
                tts.setSpeechRate(1.0f);
                ttsReady = true;
            }
        });

        web = new WebView(this);
        web.setBackgroundColor(Color.BLACK);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(true);
        s.setAllowContentAccess(false);
        web.setWebViewClient(new WebViewClient());           // reste dans la WebView, aucun lien externe
        web.addJavascriptInterface(new Bridge(), "HM_ANDROID");
        setContentView(web);
        web.loadUrl("file:///android_asset/anim/player.html?l=" + code + "&autoplay=1");
    }

    /** Pont JavaScript : voix de synthèse et fermeture. */
    private class Bridge {
        @JavascriptInterface public void speak(String text) {
            if (ttsReady && text != null) tts.speak(text, TextToSpeech.QUEUE_ADD, null, "hm");
        }
        @JavascriptInterface public void stop() { if (ttsReady) tts.stop(); }
        @JavascriptInterface public void close() { runOnUiThread(AnimLessonActivity.this::finish); }
    }

    @Override protected void onPause() {
        super.onPause();
        if (web != null) web.evaluateJavascript("window.HM && HM.pause && HM.pause()", null);
        if (ttsReady) tts.stop();
    }

    @Override protected void onDestroy() {
        if (tts != null) tts.shutdown();
        if (web != null) { web.removeJavascriptInterface("HM_ANDROID"); web.destroy(); }
        super.onDestroy();
    }
}
