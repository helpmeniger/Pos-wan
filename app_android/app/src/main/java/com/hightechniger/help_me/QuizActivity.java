package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.media.AudioAttributes;
import android.media.SoundPool;
import android.os.Bundle;
import android.os.CountDownTimer;
import android.os.Handler;
import android.view.View;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.Spinner;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.card.MaterialCardView;
import com.google.android.material.dialog.MaterialAlertDialogBuilder;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Random;
import java.util.Set;

public class QuizActivity extends AppCompatActivity {

    Activity context;
    private Spinner spinnerCategory;
    private TextView tvQuestion, tvQuestionNumber, tvScore, tvTimer, tvExplanation, btnHistory;
    private LinearLayout optionsLayout;

    private JSONArray allQuestions;
    private final List<JSONObject> questionList = new ArrayList<>();
    private final List<String> categoryList = new ArrayList<>();
    private ArrayAdapter<String> adapter;

    private int currentIndex = 0;
    private int score = 0;
    private int wrongStreak = 0;

    private SharedPreferences prefs;
    private static final String PREFS_NAME = "QuizPrefs";
    private static final String KEY_INTRO_SHOWN = "intro_shown_once";
    private static final String KEY_BLOCK_END = "blockEndTime";
    private static final String KEY_ANSWERED_SET = "answered_questions_set";
    private static final String KEY_LAST_CATEGORY = "last_category";
    private static final String KEY_CAT_SET = "stats_categories_set";

    private Set<String> answeredSet = new HashSet<>();
    private static final long BLOCK_DURATION_MS = 10 * 60_000L;

    private CountDownTimer questionTimer, blockTimer;
    private long blockEndTime = 0;

    private final Handler handler = new Handler();
    private boolean pausedByHistory = false;

    private String currentAnswer = null;
    private String currentExplanation = null;
    private String currentQuestionText = null;
    private String currentCategory = null;

    // UI Interactive Lists
    private final List<MaterialCardView> optionCards = new ArrayList<>();
    private final List<TextView> optionTexts = new ArrayList<>();

    // 🔊 SOUNDPOOL
    private SoundPool soundPool;
    private int soundCorrect, soundWrong, soundTimeout;

    // ---------------------------
    // 🔥 CLASS OPTION ITEM
    // ---------------------------
    private static class OptionItem {
        String text;
        boolean isCorrect;
        OptionItem(String t, boolean c) {
            text = t;
            isCorrect = c;
        }
    }

    // ───────────────────────────────────────────────────────────────────────────────

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_quiz);

        // 🔊 Initialisation des sons
        AudioAttributes audioAttributes = new AudioAttributes.Builder()
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .setUsage(AudioAttributes.USAGE_GAME)
                .build();

        soundPool = new SoundPool.Builder()
                .setAudioAttributes(audioAttributes)
                .setMaxStreams(3)
                .build();

        soundCorrect = soundPool.load(this, R.raw.success1, 1);
        soundWrong   = soundPool.load(this, R.raw.buzzer1, 1);
        soundTimeout = soundPool.load(this, R.raw.buzzer1, 1);

        context = this;
        prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        blockEndTime = prefs.getLong(KEY_BLOCK_END, 0L);

        if (System.currentTimeMillis() < blockEndTime) {
            showBlockScreen();
            return;
        }

        bindViews();
        initUIButtons();

        answeredSet = new HashSet<>(prefs.getStringSet(KEY_ANSWERED_SET, new HashSet<>()));

        try {
            String json = loadJSONFromAsset();
            allQuestions = new JSONArray(json);
            initCategories();
        } catch (Exception e) {
            Toast.makeText(this, "Erreur JSON", Toast.LENGTH_LONG).show();
            return;
        }

        if (!prefs.getBoolean(KEY_INTRO_SHOWN, false)) showIntroOnce();
    }

    // ───────────────────────────────────────────────────────────────
    // 🔗 INTERFACE BOUTONS
    // ───────────────────────────────────────────────────────────────

    private void initUIButtons() {
        Button btnMatchOnline = findViewById(R.id.btnMatchOnline);
        MaterialCardView onlineMatchCard = findViewById(R.id.onlineMatchCard);
        TextView tvOnlineMessage = findViewById(R.id.tvOnlineMessage);
        Button btnStartMatch = findViewById(R.id.btnStartMatch);

        btnMatchOnline.setOnClickListener(v -> {
            onlineMatchCard.setVisibility(View.VISIBLE);
            tvOnlineMessage.setText("🔥 Des joueurs sont en ligne, prêts à te défier !");
        });

        btnStartMatch.setOnClickListener(v -> {
            Intent intent = new Intent(QuizActivity.this, LoginActivity2.class);
            startActivity(intent);
        });

        if (btnHistory != null) {
            btnHistory.setOnClickListener(v -> {
                cancelTimers();
                pausedByHistory = true;
                startActivity(new Intent(QuizActivity.this, HistoryActivity.class));
            });
        }
    }

    private void bindViews() {
        spinnerCategory = findViewById(R.id.spinnerCategory);
        tvQuestion = findViewById(R.id.tvQuestion);
        tvQuestionNumber = findViewById(R.id.tvQuestionNumber);
        tvScore = findViewById(R.id.tvScore);
        tvTimer = findViewById(R.id.tvTimer);
        tvExplanation = findViewById(R.id.tvExplanation);
        optionsLayout = findViewById(R.id.optionsLayout);
        btnHistory = findViewById(R.id.btnHistory);
    }

    // ───────────────────────────────────────────────────────────────
    // 🗂️ INITIALISATION CATÉGORIES
    // ───────────────────────────────────────────────────────────────

    private void showIntroOnce() {
        new MaterialAlertDialogBuilder(this)
                .setTitle("Bienvenue 👋")
                .setMessage("\"Vous avez 60 secondes pour chaque question.\n\nEn cas de 3 mauvaises réponses consécutives, vous serez suspendu pendant 10 minutes.\n\nBonne chance ! 🚀")
                .setPositiveButton("Commencer", (d, w) -> {
                    prefs.edit().putBoolean(KEY_INTRO_SHOWN, true).apply();
                    d.dismiss();
                })
                .show();
    }

    private void initCategories() {
        Set<String> categories = new HashSet<>();
        for (int i = 0; i < allQuestions.length(); i++) {
            categories.add(allQuestions.optJSONObject(i).optString("category"));
        }

        categoryList.clear();
        categoryList.addAll(categories);

        adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, categoryList);
        adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        spinnerCategory.setAdapter(adapter);

        String lastCat = prefs.getString(KEY_LAST_CATEGORY, null);
        if (lastCat != null && categoryList.contains(lastCat)) {
            spinnerCategory.setSelection(categoryList.indexOf(lastCat));
        }

        spinnerCategory.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
            @Override public void onItemSelected(AdapterView<?> parent, View view, int pos, long id) {
                loadQuestions(adapter.getItem(pos));
            }
            @Override public void onNothingSelected(AdapterView<?> parent) {}
        });
    }

    // ───────────────────────────────────────────────────────────────
    // 🔄 CHARGER QUESTIONS
    // ───────────────────────────────────────────────────────────────

    private void loadQuestions(String category) {
        cancelTimers();
        currentCategory = category;

        prefs.edit().putString(KEY_LAST_CATEGORY, category).apply();

        questionList.clear();
        for (int i = 0; i < allQuestions.length(); i++) {
            JSONObject q = allQuestions.optJSONObject(i);
            if (q != null && category.equals(q.optString("category"))
                    && !answeredSet.contains(q.optString("question"))) {
                questionList.add(q);
            }
        }

        Set<String> catSet = new HashSet<>(prefs.getStringSet(KEY_CAT_SET, new HashSet<>()));
        if (!catSet.contains(category)) {
            catSet.add(category);
            prefs.edit().putStringSet(KEY_CAT_SET, catSet).apply();
        }

        if (questionList.isEmpty()) {
            askNextCategory();
            return;
        }

        Collections.shuffle(questionList);
        currentIndex = 0;
        score = 0;
        tvScore.setText("Score : " + score);

        showNextQuestion();
    }

    private void askNextCategory() {
        new MaterialAlertDialogBuilder(this)
                .setTitle("Catégorie terminée ✅")
                .setMessage("Voulez-vous passer à la catégorie suivante ?")
                .setPositiveButton("Oui", (d, w) -> {
                    int idx = categoryList.indexOf(currentCategory);
                    int next = (idx + 1) % categoryList.size();
                    spinnerCategory.setSelection(next);
                })
                .setNegativeButton("Non", null)
                .show();
    }

    // ───────────────────────────────────────────────────────────────
    // ⭐ AFFICHAGE QUESTION + SHUFFLE OPTIONS ⭐
    // ───────────────────────────────────────────────────────────────

    private void showNextQuestion() {
        if (System.currentTimeMillis() < blockEndTime) {
            showBlockScreen();
            return;
        }

        tvExplanation.setText("");
        tvExplanation.setVisibility(View.GONE);

        if (currentIndex >= questionList.size()) {
            askNextCategory();
            return;
        }

        JSONObject q = questionList.get(currentIndex);

        String qText = q.optString("question");
        JSONArray options = q.optJSONArray("options");
        String answer = q.optString("answer");
        String explanation = q.optString("explanation");

        tvQuestionNumber.setText("Question " + (currentIndex + 1) + " / " + questionList.size());
        tvQuestion.setText(qText);
        optionsLayout.removeAllViews();

        currentQuestionText = qText;
        currentAnswer = answer;
        currentExplanation = explanation;

        optionCards.clear();
        optionTexts.clear();

        // 🚀 CONSTRUCTION LISTE DES OPTIONS + FLAG
        List<OptionItem> shuffled = new ArrayList<>();
        for (int i = 0; i < options.length(); i++) {
            String opt = options.optString(i);
            boolean isCorrect = opt.trim().equalsIgnoreCase(answer.trim());
            shuffled.add(new OptionItem(opt, isCorrect));
        }


        Collections.shuffle(shuffled, new Random());

        // 🚀 AFFICHAGE UI APRÈS SHUFFLE
        for (int i = 0; i < shuffled.size(); i++) {

            OptionItem itemData = shuffled.get(i);

            View item = getLayoutInflater().inflate(R.layout.item_option_choice, null);
            MaterialCardView card = item.findViewById(R.id.cardOption);
            TextView tvOpt = item.findViewById(R.id.tvOptionText);
            tvOpt.setText(itemData.text);

            item.setEnabled(true); // réactiver au début d'une nouvelle question
            optionCards.add(card);
            optionTexts.add(tvOpt);

            int index = i;
            boolean isCorrect = itemData.isCorrect;

            item.setOnClickListener(v -> handleSelection(index, isCorrect));

            optionsLayout.addView(item);
        }

        startTimer();
    }

    // ───────────────────────────────────────────────────────────────
    // 🎯 GESTION DU CLICK
    // ───────────────────────────────────────────────────────────────

    private void handleSelection(int index, boolean isCorrect) {
        cancelQuestionTimer();

        for (MaterialCardView c : optionCards) {
            c.setEnabled(false);
            c.setCardBackgroundColor(Color.WHITE);
            c.setStrokeColor(Color.parseColor("#CBD5E1"));
        }

        MaterialCardView selected = optionCards.get(index);

        // 🔊 Sons
        if (isCorrect) soundPool.play(soundCorrect, 1, 1, 1, 0, 1);
        else           soundPool.play(soundWrong,   1, 1, 1, 0, 1);

        tvExplanation.setVisibility(View.VISIBLE);
        tvExplanation.setAlpha(0f);
        tvExplanation.animate().alpha(1f).setDuration(250).start();

        String keyBase = "stats_cat_" + currentCategory.replaceAll("\\s+", "_")
                .toLowerCase(Locale.getDefault());

        int total = prefs.getInt(keyBase + "_total", 0) + 1;
        int answered = prefs.getInt(keyBase + "_answered", 0) + 1;
        int correctCount = prefs.getInt(keyBase + "_correct", 0) + (isCorrect ? 1 : 0);

        prefs.edit()
                .putInt(keyBase + "_total", total)
                .putInt(keyBase + "_answered", answered)
                .putInt(keyBase + "_correct", correctCount)
                .putString(KEY_LAST_CATEGORY, currentCategory)
                .apply();

        if (isCorrect) {
            score++;
            wrongStreak = 0;
            selected.setCardBackgroundColor(Color.parseColor("#D1FADF"));
            selected.setStrokeColor(Color.parseColor("#22C55E"));
            tvExplanation.setText("Bonne réponse : " + currentExplanation);
        } else {
            wrongStreak++;
            selected.setCardBackgroundColor(Color.parseColor("#FECACA"));
            selected.setStrokeColor(Color.parseColor("#EF4444"));
            tvExplanation.setText("Mauvaise réponse : " + currentExplanation);
            if (wrongStreak >= 3) { blockUser(); return; }
        }

        answeredSet.add(currentQuestionText);
        prefs.edit().putStringSet(KEY_ANSWERED_SET, answeredSet).apply();

        tvScore.setText("Score : " + score);

        handler.postDelayed(() -> {
            currentIndex++;
            showNextQuestion();
        }, 1200);
    }

    // ───────────────────────────────────────────────────────────────

    private void startTimer() {
        cancelQuestionTimer();
        tvTimer.setText("60");

        questionTimer = new CountDownTimer(60000, 1000) {
            @Override public void onTick(long ms) {
                tvTimer.setText(String.valueOf(ms / 1000));
            }
            @Override public void onFinish() {
                soundPool.play(soundTimeout, 1, 1, 1, 0, 1);

                wrongStreak++;
                tvExplanation.setVisibility(View.VISIBLE);
                tvExplanation.setText("⏰ Temps écoulé");

                if (wrongStreak >= 3) { blockUser(); return; }

                handler.postDelayed(() -> {
                    currentIndex++;
                    showNextQuestion();
                }, 1200);
            }
        }.start();
    }

    private void cancelQuestionTimer() {
        if (questionTimer != null) questionTimer.cancel();
    }

    // ───────────────────────────────────────────────────────────────

    private void blockUser() {
        long end = System.currentTimeMillis() + BLOCK_DURATION_MS;
        prefs.edit().putLong(KEY_BLOCK_END, end).apply();
        blockEndTime = end;
        showBlockScreen();
    }

    private void showBlockScreen() {
        cancelTimers();
        setContentView(R.layout.block_screen);
        TextView tvBlock = findViewById(R.id.tvBlock);

        long remaining = blockEndTime - System.currentTimeMillis();
        blockTimer = new CountDownTimer(remaining, 1000) {
            @Override public void onTick(long ms) {
                long min = ms / 60000, sec = (ms % 60000) / 1000;
                tvBlock.setText(String.format(Locale.getDefault(),
                        "⛔ Vous êtes bloqué temporairement.\nRevenez dans : %02d:%02d", min, sec));
            }
            @Override public void onFinish() { recreate(); }
        }.start();
    }

    private String loadJSONFromAsset() {
        try (InputStream is = getAssets().open("questions.json")) {
            byte[] buffer = new byte[is.available()];
            is.read(buffer);
            return new String(buffer, StandardCharsets.UTF_8);
        } catch (IOException e) {
            return null;
        }
    }

    private void cancelTimers() {
        cancelQuestionTimer();
        if (blockTimer != null) blockTimer.cancel();
    }

    @Override protected void onPause() { super.onPause(); cancelQuestionTimer(); }
    @Override protected void onDestroy() { super.onDestroy(); cancelTimers(); }
}
