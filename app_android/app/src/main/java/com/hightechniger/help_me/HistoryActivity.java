package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.view.Gravity;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.card.MaterialCardView;

import java.util.Locale;
import java.util.Set;
import java.util.TreeSet;

public class HistoryActivity extends AppCompatActivity {

    private TextView tvAnswered, tvScore, tvWrong, tvLeft, tvEval, tvGlobalRatio;
    private ProgressBar pbCorrect, pbWrong;
    private LinearLayout categoryContainer;

    private static final String PREFS_NAME = "QuizPrefs";
    private static final String KEY_ANSWERED_SET = "answered_questions_set";
    private static final String KEY_ST_TOTAL_ANS = "stats_total_answered";
    private static final String KEY_ST_TOTAL_COR = "stats_total_correct";
    private static final String KEY_ST_TOTAL_WRN = "stats_total_wrong";
    private static final String KEY_CAT_SET      = "stats_categories_set"; // Set<String> des catégories connues
    // Par catégorie : "stats_cat_<cat>_answered", "stats_cat_<cat>_correct", "stats_cat_<cat>_total"

    @SuppressLint("MissingInflatedId")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_history);

        tvAnswered = findViewById(R.id.tvAnswered);
        tvScore    = findViewById(R.id.tvScoreHist);
        tvWrong    = findViewById(R.id.tvWrong);
        tvLeft     = findViewById(R.id.tvLeft);
        tvEval     = findViewById(R.id.tvEval);

        pbCorrect  = findViewById(R.id.pbCorrect);
        pbWrong    = findViewById(R.id.pbWrong);
        tvGlobalRatio = findViewById(R.id.tvGlobalRatio);

        categoryContainer = findViewById(R.id.categoryContainer);

        loadAndRender();
    }

    private void loadAndRender() {
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);

        // 🔥 Correction : lecture fiable des scores globaux
        int totalAnswered = prefs.getInt(KEY_ST_TOTAL_ANS, -1);
        int totalCorrect  = prefs.getInt(KEY_ST_TOTAL_COR, -1);
        int totalWrong    = prefs.getInt(KEY_ST_TOTAL_WRN, -1);

        // 🔥 Si valeurs introuvables → reconstruire depuis catégories
        if (totalAnswered < 0 || totalCorrect < 0 || totalWrong < 0) {
            totalAnswered = 0;
            totalCorrect = 0;
            totalWrong = 0;

            Set<String> catSet0 = prefs.getStringSet(KEY_CAT_SET, new TreeSet<>());

            for (String cat : catSet0) {
                totalAnswered += prefs.getInt(catKey(cat, "answered"), 0);
                totalCorrect  += prefs.getInt(catKey(cat, "correct"), 0);

                int wrongForCat =
                        prefs.getInt(catKey(cat, "answered"), 0) -
                                prefs.getInt(catKey(cat, "correct"), 0);

                if (wrongForCat > 0) totalWrong += wrongForCat;
            }
        }

        // 🔥 Sauvegarde reconstruction
        prefs.edit()
                .putInt(KEY_ST_TOTAL_ANS, totalAnswered)
                .putInt(KEY_ST_TOTAL_COR, totalCorrect)
                .putInt(KEY_ST_TOTAL_WRN, totalWrong)
                .apply();

        // 🔥 Ancien système : questions répondues uniques
        Set<String> answeredSet = prefs.getStringSet(KEY_ANSWERED_SET, new TreeSet<>());

        // 🔥 Recalcule questions restantes globales
        int globalLeft = 0;

        Set<String> catSet = prefs.getStringSet(KEY_CAT_SET, new TreeSet<>());
        for (String cat : catSet) {
            int tot = prefs.getInt(catKey(cat, "total"), 0);
            int ans = prefs.getInt(catKey(cat, "answered"), 0);
            globalLeft += Math.max(0, tot - ans);
        }

        // 🔥 Affichage global
        tvAnswered.setText("Questions répondues : " + totalAnswered);
        tvScore.setText("Bonnes réponses : " + totalCorrect);
        tvWrong.setText("Mauvaises réponses : " + totalWrong);
        tvLeft.setText("Questions restantes : " + globalLeft);

        // 🔥 Évaluation
        String eval;
        if (totalAnswered == 0) {
            eval = "Commence le quiz pour voir ta progression";
        } else {
            float ratio = totalCorrect * 1f / Math.max(1, totalAnswered);
            if (ratio >= 0.90f)      eval = "Excellent niveau ! Continue comme ça";
            else if (ratio >= 0.70f) eval = "Très bien ! Encore un petit effort";
            else if (ratio >= 0.50f) eval = "Pas mal ! Revois quelques points";
            else                     eval = "Courage ! Tu vas progresser 💪";
        }
        tvEval.setText(eval);

        // 🔥 Progressbars globales
        int total = Math.max(1, totalCorrect + totalWrong);
        pbCorrect.setMax(total);
        pbWrong.setMax(total);
        pbCorrect.setProgress(totalCorrect);
        pbWrong.setProgress(totalWrong);

        float pct = (totalAnswered == 0 ? 0f : totalCorrect * 100f / totalAnswered);
        tvGlobalRatio.setText(String.format(Locale.getDefault(),
                "Réussite globale: %d%%", Math.round(pct)));

        // 🔥 Rendu catégories
        categoryContainer.removeAllViews();
        for (String cat : catSet) {
            int cAns = prefs.getInt(catKey(cat, "answered"), 0);
            int cCor = prefs.getInt(catKey(cat, "correct"), 0);
            int cTot = prefs.getInt(catKey(cat, "total"), 0);
            renderCategoryRow(cat, cAns, cCor, cTot);
        }
    }

    private void renderCategoryRow(String category, int answered, int correct, int total) {
        // Carte par catégorie
        MaterialCardView card = new MaterialCardView(this);
        LinearLayout.LayoutParams cardLp = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        cardLp.bottomMargin = dp(12);
        card.setLayoutParams(cardLp);
        card.setUseCompatPadding(true);
        card.setRadius(dp(14));
        card.setCardElevation(dp(1));
        card.setStrokeColor(0xFFE2E7EF);
        card.setStrokeWidth(dp(1));

        LinearLayout wrap = new LinearLayout(this);
        wrap.setOrientation(LinearLayout.VERTICAL);
        wrap.setPadding(dp(16), dp(16), dp(16), dp(16));
        card.addView(wrap);

        // Titre ligne
        TextView title = new TextView(this);
        title.setText(category);
        title.setTextSize(17);
        title.setTypeface(android.graphics.Typeface.create("sans-serif-medium", android.graphics.Typeface.BOLD));
        title.setTextColor(getColor(android.R.color.black));
        wrap.addView(title);

        // Ligne chiffres
        LinearLayout line = new LinearLayout(this);
        line.setOrientation(LinearLayout.HORIZONTAL);
        line.setGravity(Gravity.START);
        line.setPadding(0, dp(8), 0, dp(6));
        wrap.addView(line);

        TextView tAns = new TextView(this);
        tAns.setText("Répondues : " + answered);
        tAns.setTextColor(getColor(android.R.color.black));
        tAns.setLayoutParams(new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        line.addView(tAns);

        TextView tCor = new TextView(this);
        tCor.setText("Bonnes : " + correct);
        tCor.setTextColor(getColor(android.R.color.holo_blue_dark));
        tCor.setLayoutParams(new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        line.addView(tCor);

        TextView tTot = new TextView(this);
        tTot.setText("Total : " + total);
        tTot.setTextColor(getColor(android.R.color.darker_gray));
        tTot.setLayoutParams(new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        line.addView(tTot);

        // Barre de progression (% bonnes sur total répondu)
        ProgressBar rowPb = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        int max = Math.max(1, total); // base: total questions de la catégorie
        rowPb.setMax(max);
        rowPb.setProgress(Math.min(correct, max));
        rowPb.setProgressTintList(android.content.res.ColorStateList.valueOf(0xFF1E9E62));
        wrap.addView(rowPb);

        // Label %
        TextView ratio = new TextView(this);
        float pct = (total == 0) ? 0f : (correct * 100f / total);
        ratio.setText(String.format(Locale.getDefault(), "Progression : %.0f %%", pct));
        ratio.setTextColor(getColor(android.R.color.black));
        ratio.setPadding(0, dp(6), 0, 0);
        wrap.addView(ratio);

        categoryContainer.addView(card);
    }

    private String catKey(String cat, String suffix) {
        // Normalisation de la clé : espaces -> _
        String norm = cat == null ? "default" : cat.replaceAll("\\s+", "_").toLowerCase(Locale.getDefault());
        return "stats_cat_" + norm + "_" + suffix;
    }

    private int dp(int v) {
        return Math.round(getResources().getDisplayMetrics().density * v);
    }
}
