package com.hightechniger.help_me;

import android.content.Intent;
import android.os.Bundle;
import android.view.WindowManager;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.google.zxing.integration.android.IntentIntegrator;
import com.google.zxing.integration.android.IntentResult;

/**
 * Scanner des codes-barres du manuel.
 * Chaque code du manuel ouvre la leçon complète (LessonReaderActivity) ; un code inconnu ouvre LessonActivity,
 * qui lit assets/lessons.json et joue la vidéo correspondante si elle existe.
 */
public class MainActivity8 extends AppCompatActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main8);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        startBarcodeScanner();
    }

    private void startBarcodeScanner() {
        IntentIntegrator integrator = new IntentIntegrator(MainActivity8.this);
        integrator.setDesiredBarcodeFormats(IntentIntegrator.ALL_CODE_TYPES);
        integrator.setPrompt("Placez le code-barres en haut de la page du manuel dans le cadre");
        integrator.setCameraId(0);
        integrator.setOrientationLocked(false);   // les codes du manuel sont verticaux
        integrator.setBeepEnabled(true);
        integrator.setBarcodeImageEnabled(false);
        integrator.initiateScan();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        IntentResult result = IntentIntegrator.parseActivityResult(requestCode, resultCode, data);
        if (result == null) {
            super.onActivityResult(requestCode, resultCode, data);
            return;
        }
        if (result.getContents() == null) {
            Toast.makeText(this, "Scan annulé", Toast.LENGTH_SHORT).show();
            backToHome();
            return;
        }
        // Code 39 : certains lecteurs renvoient les astérisques de début et de fin
        String code = result.getContents().replace("*", "").trim();
        // le code ouvre directement la leçon complète du manuel ; sinon la fiche du code
        com.hightechniger.help_me.ui.Course.Lesson lesson = com.hightechniger.help_me.ui.Course.get(this).byCode(code);
        if (lesson != null) {
            LessonReaderActivity.open(this, lesson.id);
        } else {
            Intent i = new Intent(this, LessonActivity.class);
            i.putExtra(LessonActivity.EXTRA_CODE, code);
            startActivity(i);
        }
        finish();
    }

    private void backToHome() {
        startActivity(new Intent(MainActivity8.this, MainActivity.class));
        finish();
    }

    @Override
    public void onBackPressed() {
        super.onBackPressed();
        backToHome();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
    }
}
