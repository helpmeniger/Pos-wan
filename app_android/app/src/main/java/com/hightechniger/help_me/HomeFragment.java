package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageButton;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.fragment.app.Fragment;

import java.util.Locale;
import java.util.Set;
import java.util.TreeSet;

public class HomeFragment extends Fragment implements SensorEventListener {

    Activity context;

    private static final String PREFS_NAME = "QuizPrefs";
    private static final String KEY_LAST_CATEGORY = "last_category";
    private static final String KEY_CAT_SET = "stats_categories_set"; // Ensemble des catégories jouées

    /** Shake System **/
    private SensorManager sensorManager;
    private long lastTime = 0;
    private float lastX = 0, lastY = 0, lastZ = 0;
    private static final int SHAKE_THRESHOLD = 2200; // plus haut = moins sensible

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        context = getActivity();
        return inflater.inflate(R.layout.fragment_home, container, false);
    }





    @Override
    public void onStart() {
        super.onStart();

        // --- Register accelerometer ---
        sensorManager = (SensorManager) requireActivity().getSystemService(Context.SENSOR_SERVICE);
        Sensor accelerometer = sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER);
        if (accelerometer != null)
            sensorManager.registerListener(this, accelerometer, SensorManager.SENSOR_DELAY_UI);

        // --- Navigation Buttons ---
        addNav(R.id.ordi, MainActivity4.class);
        addNav(R.id.msword, MainActivity3.class);
        addNav(R.id.windows, MainActivity5.class);
        addNav(R.id.msexcel, ExcelActivity9.class);
        addNav(R.id.mspoint, PointActivity9.class);
        addNav(R.id.iaid, AIChatActivity.class);
        addNav(R.id.msastuce, AstuceActivity9.class);
        addNav(R.id.quiz2, QuizActivity.class);
// Loader pour quiz2
        ProgressBar loadingQuiz = context.findViewById(R.id.loadingQuiz);
        TextView loadingQuizText = context.findViewById(R.id.loadingQuizText);
        ImageButton quiz2Btn = context.findViewById(R.id.iaid);

        if (quiz2Btn != null) {
            quiz2Btn.setOnClickListener(v -> {

                // ⏳ Afficher un loader immédiat (corrige le retard visible)
                loadingQuiz.setVisibility(View.VISIBLE);
                loadingQuizText.setVisibility(View.VISIBLE);
                quiz2Btn.setEnabled(false);

                // Petit délai pour laisser le loader s'afficher correctement
                new android.os.Handler().postDelayed(() -> {

                    Intent intent = new Intent(context, AIChatActivity.class);
                    startActivity(intent);

                    // Cache le loader une fois terminé
                    loadingQuiz.setVisibility(View.GONE);
                    loadingQuizText.setVisibility(View.GONE);
                    quiz2Btn.setEnabled(true);

                }, 2000); // 600 ms = affichage fluide
            });
        }

        // --- Continue button ---
        View continueButton = context.findViewById(R.id.btnContinue);
        if (continueButton != null) continueButton.setOnClickListener(v -> resumeQuiz());

        // --- Load last quiz progress ---
        loadProgress();
    }

    private void addNav(int id, Class<?> cls) {
        ImageButton btn = context.findViewById(id);
        if (btn != null)
            btn.setOnClickListener(v -> startActivity(new Intent(context, cls)));
    }

    private void resumeQuiz() {
        SharedPreferences prefs = context.getSharedPreferences(PREFS_NAME, Activity.MODE_PRIVATE);
        String lastCat = prefs.getString(KEY_LAST_CATEGORY, null);

        Intent i = new Intent(context, QuizActivity.class);
        if (lastCat != null) i.putExtra("category", lastCat);
        startActivity(i);
    }

    /** ----------- Affiche la progression de la dernière catégorie ----------- **/
    private void loadProgress() {
        TextView tvTitle = context.findViewById(R.id.tvProgressTitle);
        TextView tvSub = context.findViewById(R.id.tvProgressSub);
        TextView tvCatSummary = context.findViewById(R.id.tvCategorySummary); // 🆕 nouveau texte pour le résumé global
        ProgressBar pb = context.findViewById(R.id.pbHero);

        SharedPreferences prefs = context.getSharedPreferences(PREFS_NAME, Activity.MODE_PRIVATE);
        String lastCat = prefs.getString(KEY_LAST_CATEGORY, null);

        // --- Récupération des catégories connues ---
        Set<String> catSet = prefs.getStringSet(KEY_CAT_SET, new TreeSet<>());

        // --- Calcul des catégories terminées ---
        int completedCats = 0;
        for (String cat : catSet) {
            String ck = cat.replaceAll("\\s+", "_").toLowerCase(Locale.getDefault());
            int total = prefs.getInt("stats_cat_" + ck + "_total", 0);
            int answered = prefs.getInt("stats_cat_" + ck + "_answered", 0);
            if (total > 0 && answered >= total) completedCats++;
        }

        // --- Mise à jour du résumé global ---
        if (tvCatSummary != null) {
            if (catSet.isEmpty()) {
                tvCatSummary.setText("Aucune catégorie commencée");
            } else {
                tvCatSummary.setText(String.format(
                        Locale.getDefault(),
                        "Catégories complétées : %d sur %d",
                        completedCats, catSet.size()
                ));
            }
        }

        if (lastCat == null) {
            tvTitle.setText("Commencez votre apprentissage");
            tvSub.setText("Aucun quiz démarré");
            pb.setProgress(0);
            return;
        }

        String key = lastCat.replaceAll("\\s+", "_").toLowerCase(Locale.getDefault());

        int total = prefs.getInt("stats_cat_" + key + "_total", 0);
        int correct = prefs.getInt("stats_cat_" + key + "_correct", 0);
        int answered = prefs.getInt("stats_cat_" + key + "_answered", 0);

        // si aucune donnée trouvée, essayer dans le set global
        if (total == 0) {
            for (String c : catSet) {
                String ck = c.replaceAll("\\s+", "_").toLowerCase(Locale.getDefault());
                if (ck.equals(key)) {
                    total = prefs.getInt("stats_cat_" + ck + "_total", 0);
                    correct = prefs.getInt("stats_cat_" + ck + "_correct", 0);
                    answered = prefs.getInt("stats_cat_" + ck + "_answered", 0);
                    break;
                }
            }
        }

        // --- Calcul du pourcentage réel ---
        int pct = (total <= 0) ? 0 : Math.round((correct * 100f) / total);

        tvTitle.setText("Reprenez : " + lastCat);
        if (total > 0) {
            tvSub.setText(String.format(Locale.getDefault(),
                    "Progression : %d bonnes / %d questions (%d%%)", correct, total, pct));
        } else {
            tvSub.setText("Aucune question encore enregistrée");
        }

        pb.setMax(100);
        pb.setProgress(pct);
    }

    /** **************** SHAKE SENSOR LOGIC **************** **/
    @Override
    public void onSensorChanged(SensorEvent event) {
        if (event.sensor.getType() != Sensor.TYPE_ACCELEROMETER) return;

        long currentTime = System.currentTimeMillis();
        if ((currentTime - lastTime) > 100) {
            long diffTime = currentTime - lastTime;
            lastTime = currentTime;

            float x = event.values[0];
            float y = event.values[1];
            float z = event.values[2];

            float shake = Math.abs(x + y + z - lastX - lastY - lastZ) / diffTime * 10000;

            if (shake > SHAKE_THRESHOLD) {
                Intent intent = new Intent(context, MainActivity8.class);
                startActivity(intent);
            }

            lastX = x;
            lastY = y;
            lastZ = z;
        }
    }

    @Override
    public void onAccuracyChanged(Sensor sensor, int accuracy) {}

    @Override
    public void onPause() {
        super.onPause();
        if (sensorManager != null) sensorManager.unregisterListener(this);
    }

    @Override
    public void onStop() {
        super.onStop();
        if (sensorManager != null) sensorManager.unregisterListener(this);
    }
}
