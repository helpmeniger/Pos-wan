package com.hightechniger.help_me;

import android.animation.ObjectAnimator;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.text.TextUtils;
import android.util.Patterns;
import android.view.View;
import android.view.animation.AccelerateDecelerateInterpolator;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.airbnb.lottie.LottieAnimationView;
import com.airbnb.lottie.LottieDrawable;
import com.google.firebase.database.DataSnapshot;
import com.google.firebase.database.DatabaseError;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.Query;
import com.google.firebase.database.ValueEventListener;

import java.util.HashMap;
import java.util.Locale;
import java.util.Map;

public class MainActivity9 extends AppCompatActivity {

    // UI
    private View headerCard;
    private LottieAnimationView headerLottie;
    private View sacsMain, pcLeft, pcRight;
    private TextView questionText, counterText;
    private EditText nameEditText, phoneEditText, answerEditText;
    private Button submitButton;
    private ProgressBar loadingProgressBar, sendingProgressBar;

    // Firebase
    private FirebaseDatabase database;
    private DatabaseReference questionRef, responsesRef;

    // State
    private long candidateCount = 0L;

    // Anti-spam local
    private static final String PREFS = "helpme_prefs";
    private static final String KEY_LAST_SUBMIT_TS = "last_submit_ts";
    private static final String KEY_LAST_CONTACT = "last_contact";
    private static final long COOLDOWN_MS = 60_000L; // 60s

    // Anti-duplication 24h
    private static final long ONE_DAY_MS = 24L * 60L * 60L * 1000L;

    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main9);

        // Bind UI
        headerCard = findViewById(R.id.headerCard);
        headerLottie = findViewById(R.id.headerLottie);

        sacsMain = findViewById(R.id.sacsMain);
        pcLeft = findViewById(R.id.pcLeft);
        pcRight = findViewById(R.id.pcRight);

        questionText = findViewById(R.id.questionText);
        counterText = findViewById(R.id.counterText);
        nameEditText = findViewById(R.id.nameEditText);
        phoneEditText = findViewById(R.id.phoneEditText);
        answerEditText = findViewById(R.id.answerEditText);
        submitButton = findViewById(R.id.submitButton);
        loadingProgressBar = findViewById(R.id.loadingProgressBar);
        sendingProgressBar = findViewById(R.id.sendingProgressBar);

        // Firebase
        database = FirebaseDatabase.getInstance();
        questionRef = database.getReference("questions");
        responsesRef = database.getReference("responses");

        loadQuestion();
        updateCandidateCount();
        setupHeaderAnimation();

        submitButton.setOnClickListener(v -> submitResponse());
    }

    private void loadQuestion() {
        show(loadingProgressBar, true);
        questionRef.child("current_question").get().addOnCompleteListener(task -> {
            show(loadingProgressBar, false);
            if (task.isSuccessful() && task.getResult() != null) {
                String question = task.getResult().getValue(String.class);
                if (!TextUtils.isEmpty(question)) {
                    questionText.setText(question);
                } else {
                    questionText.setText("Question non disponible pour le moment.");
                }
            } else {
                questionText.setText("Impossible de charger la question.");
            }
        });
    }

    private void submitResponse() {
        String name = safeTrim(nameEditText.getText().toString());
        String contact = safeTrim(phoneEditText.getText().toString());
        String answer = safeTrim(answerEditText.getText().toString());

        if (name.length() < 3) {
            toast("Veuillez entrer un nom et prénom valides.");
            return;
        }
        if (!isValidContact(contact)) {
            toast("Veuillez entrer un numéro ou email valide.");
            return;
        }
        if (answer.length() < 5) {
            toast("Votre réponse est trop courte.");
            return;
        }

        String contactKey = makeContactKey(contact);
        if (isInCooldown(contactKey)) {
            toast("Veuillez patienter avant une nouvelle soumission.");
            return;
        }

        setSubmitting(true);
        long now = System.currentTimeMillis();

        Query q = responsesRef.orderByChild("contactKey").equalTo(contactKey).limitToFirst(1);
        q.addListenerForSingleValueEvent(new ValueEventListener() {
            @Override public void onDataChange(DataSnapshot snapshot) {
                if (snapshot.exists()) {
                    for (DataSnapshot child : snapshot.getChildren()) {
                        Long ts = child.child("timestamp").getValue(Long.class);
                        if (ts != null && (now - ts) < ONE_DAY_MS) {
                            setSubmitting(false);
                            toast("Vous avez déjà participé récemment.");
                            return;
                        }
                    }
                }
                saveResponse(name, contact, contactKey, answer, now);
            }

            @Override public void onCancelled(DatabaseError error) {
                setSubmitting(false);
                toast("Erreur : " + error.getMessage());
            }
        });
    }

    private void saveResponse(String name, String contact, String contactKey, String answer, long timestamp) {
        String id = responsesRef.push().getKey();
        if (id == null) {
            setSubmitting(false);
            toast("Impossible d'envoyer.");
            return;
        }
        Map<String, Object> data = new HashMap<>();
        data.put("id", id);
        data.put("name", name);
        data.put("contact", contact);
        data.put("contactKey", contactKey);
        data.put("answer", answer);
        data.put("timestamp", timestamp);
        data.put("question", questionText.getText().toString());

        responsesRef.child(id).setValue(data).addOnCompleteListener(t -> {
            setSubmitting(false);
            if (t.isSuccessful()) {
                toast("Réponse envoyée, merci !");
                setLocalSubmit(contactKey, timestamp);
                resetForm();
                updateCandidateCount();
            } else {
                toast("Échec de l'envoi");
            }
        });
    }

    private void setupHeaderAnimation() {
        if (headerLottie != null) {
            headerLottie.setRepeatCount(LottieDrawable.INFINITE);
            headerLottie.setSpeed(0.7f);
            if (!headerLottie.isAnimating()) headerLottie.playAnimation();
        }

        animateFloat(headerCard, 0f, 8f, 2200);
        animateFloat(sacsMain, 0f, -6f, 2600);
        animateFloat(pcLeft, 0f, 8f, 3000);
        animateFloat(pcRight, 0f, 7f, 2800);
    }

    private void animateFloat(View target, float from, float to, long duration) {
        if (target == null) return;
        ObjectAnimator anim = ObjectAnimator.ofFloat(target, "translationY", from, to);
        anim.setDuration(duration);
        anim.setRepeatCount(ObjectAnimator.INFINITE);
        anim.setRepeatMode(ObjectAnimator.REVERSE);
        anim.setInterpolator(new AccelerateDecelerateInterpolator());
        anim.start();
    }

    private void updateCandidateCount() {
        responsesRef.addListenerForSingleValueEvent(new ValueEventListener() {
            @Override public void onDataChange(DataSnapshot snapshot) {
                long c = 0;
                for (DataSnapshot child : snapshot.getChildren()) {
                    if (child.hasChild("name") && child.hasChild("answer") && child.hasChild("contact"))
                        c++;
                }
                candidateCount = c;
                counterText.setText("Candidats : " + c);
            }

            @Override public void onCancelled(DatabaseError error) {
                toast("Erreur compteur.");
            }
        });
    }

    private void setSubmitting(boolean submitting) {
        submitButton.setEnabled(!submitting);
        submitButton.setText(submitting ? "Patientez..." : "Envoyer");
        show(sendingProgressBar, submitting);
    }

    private void resetForm() {
        nameEditText.setText("");
        phoneEditText.setText("");
        answerEditText.setText("");
    }

    private void show(View v, boolean visible) {
        if (v != null) v.setVisibility(visible ? View.VISIBLE : View.GONE);
    }

    private void toast(String msg) {
        Toast.makeText(this, msg, Toast.LENGTH_SHORT).show();
    }

    private String safeTrim(String s) {
        return s == null ? "" : s.trim();
    }

    private boolean isValidContact(String c) {
        if (TextUtils.isEmpty(c)) return false;
        if (Patterns.EMAIL_ADDRESS.matcher(c).matches()) return true;
        String digits = c.replaceAll("[^0-9+]", "");
        return digits.length() >= 8 && digits.length() <= 16;
    }

    private String makeContactKey(String contactRaw) {
        return contactRaw.toLowerCase(Locale.ROOT).replaceAll("[^a-z0-9]", "");
    }

    private boolean isInCooldown(String contactKey) {
        SharedPreferences sp = getSharedPreferences(PREFS, MODE_PRIVATE);
        long lastTs = sp.getLong(KEY_LAST_SUBMIT_TS, 0L);
        String lastContact = sp.getString(KEY_LAST_CONTACT, "");
        return contactKey.equals(lastContact) && (System.currentTimeMillis() - lastTs) < COOLDOWN_MS;
    }

    private void setLocalSubmit(String contactKey, long ts) {
        getSharedPreferences(PREFS, MODE_PRIVATE).edit()
                .putLong(KEY_LAST_SUBMIT_TS, ts)
                .putString(KEY_LAST_CONTACT, contactKey)
                .apply();
    }
}
