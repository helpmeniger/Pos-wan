package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.res.Configuration;
import android.graphics.Color;
import android.media.AudioAttributes;
import android.media.SoundPool;
import android.os.Bundle;
import android.os.CountDownTimer;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.InputMethodManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ImageButton;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.activity.OnBackPressedCallback;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.card.MaterialCardView;
import com.google.android.material.floatingactionbutton.FloatingActionButton;
import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.database.ChildEventListener;
import com.google.firebase.database.DataSnapshot;
import com.google.firebase.database.DatabaseError;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.MutableData;
import com.google.firebase.database.Transaction;
import com.google.firebase.database.ValueEventListener;
import com.hightechniger.help_me.models.Match;
import com.hightechniger.help_me.models.Question;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

public class MatchActivity extends AppCompatActivity {

    private TextView tvTitle, tvInfo, tvTimer, tvScore, tvNet;
    private LinearLayout listQuestionsLayout, optionsLayout;
    private Button btnEnd;

    private String myUid;
    private String matchId;

    // --- CHAT SYSTEM ---
    private DatabaseReference chatRef;
    private ChildEventListener chatChildListener;
    private boolean isChatOpen() {
        return chatOpen && chatBox.getVisibility() == View.VISIBLE;
    }
    private String lastInfoText = "";
    private TextView tvChatPopup, chatBadge;
    private FloatingActionButton btnOpenChat;
    private LinearLayout chatBox, chatMessages;
    private EditText etChatMessage;
    private ImageButton btnSendChat;
    private ScrollView chatScroll;

    private boolean chatOpen = false;
    private int unreadCount = 0;

    private DatabaseReference root, matchRef, usersRef, historyRef, infoConnectedRef;
    private ValueEventListener matchListener, connectedListener;
    private CountDownTimer countDownTimer;
    private Match currentMatch;
    private SoundPool soundPool;
    private int soundQuestionReceived, soundCorrect, soundWrong;

    long matchStartTime = 0;   // durée réelle
    long matchEndTime = 0;
    private TextView tvElapsed;
    private CountDownTimer elapsedTimer;

    // 🛡️ Anti-triche : états internes
    private boolean isFinishingForCheat = false;  // évite doublons
    private boolean isInMultiWindow = false;      // suivi mode multi-fenêtres
    private boolean isInPip = false;              // suivi mode PiP

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_match);

        AudioAttributes audioAttributes = new AudioAttributes.Builder()
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .setUsage(AudioAttributes.USAGE_GAME)
                .build();

        soundPool = new SoundPool.Builder()
                .setAudioAttributes(audioAttributes)
                .setMaxStreams(3)
                .build();

        // Charger les sons
        soundQuestionReceived = soundPool.load(this, R.raw.send1, 1);
        soundCorrect = soundPool.load(this, R.raw.success1, 1);
        soundWrong   = soundPool.load(this, R.raw.buzzer1, 1);

        tvTitle = findViewById(R.id.tvTitle);
        tvElapsed = findViewById(R.id.tvElapsed);
        tvInfo  = findViewById(R.id.tvInfo);
        tvTimer = findViewById(R.id.tvTimer);
        tvScore = findViewById(R.id.tvScore);
        tvNet   = findViewById(R.id.tvNetStatus);
        listQuestionsLayout = findViewById(R.id.listQuestions);
        optionsLayout = findViewById(R.id.optionsLayout);
        btnEnd = findViewById(R.id.btnEnd);

        // CHAT UI INIT
        tvChatPopup = findViewById(R.id.tvChatPopup);
        btnOpenChat = findViewById(R.id.btnOpenChat);
        chatBox = findViewById(R.id.chatBox);
        etChatMessage = findViewById(R.id.etChatMessage);
        btnSendChat = findViewById(R.id.btnSendChat);
        chatMessages = findViewById(R.id.chatMessages);
        chatScroll = findViewById(R.id.chatScroll);
        chatBadge = findViewById(R.id.chatBadge);

        com.google.firebase.auth.FirebaseUser me = FirebaseAuth.getInstance().getCurrentUser();
        if (me == null) {   // session expirée : retour à la connexion au lieu d'un plantage
            android.widget.Toast.makeText(this, "Votre session a expiré : reconnectez-vous.", android.widget.Toast.LENGTH_SHORT).show();
            startActivity(new android.content.Intent(this, Login.class));
            finish();
            return;
        }
        myUid = me.getUid();
        matchId = getIntent().getStringExtra("matchId");

        root       = FirebaseDatabase.getInstance().getReference();
        matchRef   = root.child("matches").child(matchId);
        usersRef   = root.child("users");
        historyRef = root.child("history");
        chatRef    = matchRef.child("chat");

        infoConnectedRef = FirebaseDatabase.getInstance().getReference(".info/connected");

        connectedListener = new ValueEventListener() {
            @Override public void onDataChange(DataSnapshot snapshot) {
                Boolean connected = snapshot.getValue(Boolean.class);
                tvNet.setText((connected != null && connected) ? "" : "Connexion instable…");
            }
            @Override public void onCancelled(DatabaseError error) {}
        };
        infoConnectedRef.addValueEventListener(connectedListener);

        // Bouton fin de match (abandon propre, scores à jour)
        btnEnd.setOnClickListener(v -> {
            new AlertDialog.Builder(MatchActivity.this)
                    .setTitle("Quitter le match ?")
                    .setMessage("Voulez-vous vraiment mettre fin au match ?")
                    .setPositiveButton("Oui", (dialog, which) -> {

                        matchRef.runTransaction(new Transaction.Handler() {
                            @Override
                            public Transaction.Result doTransaction(@NonNull MutableData currentData) {

                                Match m = currentData.getValue(Match.class);
                                if (m == null) return Transaction.success(currentData);

                                // Mettre les scores à jour UNE DERNIÈRE FOIS
                                int p1 = m.p1Score;
                                int p2 = m.p2Score;

                                // Si currentMatch a des scores plus récents
                                if (currentMatch != null) {
                                    p1 = currentMatch.p1Score;
                                    p2 = currentMatch.p2Score;
                                }

                                m.p1Score = p1;
                                m.p2Score = p2;

                                // Définir la fin propre
                                m.state = "finished";
                                m.endReason = "forfeit";
                                m.endedByUid = myUid;
                                m.cheatDetected = false;
                                m.cheatNote = "";

                                currentData.setValue(m);
                                return Transaction.success(currentData);
                            }

                            @Override
                            public void onComplete(DatabaseError error, boolean committed, DataSnapshot snapshot) {}
                        });

                    })
                    .setNegativeButton("Non", null)
                    .show();
        });

        etChatMessage.clearFocus();
        chatBox.setFocusable(false);
        chatBox.setFocusableInTouchMode(false);

        // Blocage retour
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                new AlertDialog.Builder(MatchActivity.this)
                        .setTitle("Quitter le match ?")
                        .setMessage("Vous pourrez revenir plus tard.\nVoulez-vous vraiment quitter ?")
                        .setPositiveButton("Quitter", (d,w)-> finish())
                        .setNegativeButton("Annuler", null)
                        .show();
            }
        });

        btnOpenChat.setOnClickListener(v -> {
            if (!chatOpen) openChat();
            else closeChat();
        });

        btnSendChat.setOnClickListener(v -> {
            String msg = etChatMessage.getText().toString().trim();
            if (msg.isEmpty()) return;

            Map<String,Object> m = new HashMap<>();
            m.put("from", myUid);
            m.put("message", msg);
            m.put("timestamp", System.currentTimeMillis());

            chatRef.push().setValue(m).addOnSuccessListener(a -> etChatMessage.setText(""));
        });

        chatChildListener = new ChildEventListener() {
            @Override public void onChildAdded(@NonNull DataSnapshot snapshot, String prev) {

                String from = snapshot.child("from").getValue(String.class);
                String msg = snapshot.child("message").getValue(String.class);
                boolean isMine = myUid.equals(from);

                String messageKey = snapshot.getKey();
                addChatMessageToView(from, msg, isMine, messageKey);

                if (!isMine && !chatOpen) {
                    unreadCount++;
                    chatBadge.setText(String.valueOf(unreadCount));
                    chatBadge.setVisibility(View.VISIBLE);
                    showChatPopup(msg);
                }

                scrollChatToBottom();
            }

            @Override public void onChildChanged(@NonNull DataSnapshot snapshot, String previousChildName) {}
            @Override public void onChildRemoved(@NonNull DataSnapshot snapshot) {}
            @Override public void onChildMoved(@NonNull DataSnapshot snapshot, String previousChildName) {}
            @Override public void onCancelled(@NonNull DatabaseError error) {}
        };
        chatRef.addChildEventListener(chatChildListener);

        // -------- MATCH LISTENER --------
        matchListener = new ValueEventListener() {
            @Override public void onDataChange(DataSnapshot ds) {
                currentMatch = ds.getValue(Match.class);
                if (currentMatch == null) return;

                if (currentMatch.startTime == null || currentMatch.startTime == 0L) {
                    matchStartTime = System.currentTimeMillis();
                    matchRef.child("startTime").setValue(matchStartTime);
                } else {
                    matchStartTime = currentMatch.startTime;
                }

                renderMatch(currentMatch);
                startElapsedTimer();
            }

            @Override public void onCancelled(DatabaseError e) {}
        };
        matchRef.addValueEventListener(matchListener);
    }

    private void startElapsedTimer() {
        if (elapsedTimer != null) elapsedTimer.cancel();

        long start = matchStartTime;

        elapsedTimer = new CountDownTimer(Long.MAX_VALUE, 1000) {
            @Override
            public void onTick(long l) {
                long elapsed = System.currentTimeMillis() - start;

                long sec = (elapsed / 1000) % 60;
                long min = (elapsed / 1000) / 60;

                String t = String.format("Temps passé : %02d:%02d", min, sec);
                tvElapsed.setText(t);
            }

            @Override public void onFinish() {}
        };
        elapsedTimer.start();
    }

    // -----------------------------------
    //           RENDER MATCH
    // -----------------------------------
    private void renderMatch(Match m) {

        tvScore.setText(m.p1Name + ": " + m.p1Score + " | " + m.p2Name + ": " + m.p2Score);

        if ("finished".equals(m.state)) {
            endMatch(m);
            return;
        }

        boolean iAmP1        = myUid.equals(m.p1Uid);
        boolean myTurn       = (m.pickerTurn == 0 && iAmP1) || (m.pickerTurn == 1 && !iAmP1);
        boolean iAmAnswerer  = !myTurn;

        // WAITING
        if ("waiting".equals(m.state)) {
            stopTimer();
            tvTimer.setText("");
            maybeRefillQuestions(m);

            if (myTurn) {
                listQuestionsLayout.setVisibility(View.VISIBLE);
                optionsLayout.setVisibility(View.GONE);
                tvInfo.setText("Choisissez une question pour l'adversaire");
                showQuestionList(m);
            } else {
                listQuestionsLayout.setVisibility(View.GONE);
                optionsLayout.setVisibility(View.GONE);
                tvInfo.setText("L’adversaire choisit une question…");
            }
            return;
        }

        // QUESTION SENT
        if ("question_sent".equals(m.state)) {

            Question q = m.questions.get(m.currentQuestionIndex);
            long remain = Math.max(0, m.answerDeadline - System.currentTimeMillis());

            if (!iAmAnswerer) {
                listQuestionsLayout.setVisibility(View.GONE);
                optionsLayout.setVisibility(View.GONE);
                tvInfo.setText("Question envoyée. En attente de la réponse...");
                startTimer(remain, false);
                return;
            }

            // Je dois répondre → jouer le son
            soundPool.play(soundQuestionReceived, 1, 1, 1, 0, 1);

            // Montrer question
            listQuestionsLayout.setVisibility(View.GONE);
            optionsLayout.setVisibility(View.VISIBLE);

            tvInfo.setText(q.question);

            startTimer(remain, true);
            showAnswerOptions(q);
        }
    }

    private void showQuestionList(Match m) {
        listQuestionsLayout.removeAllViews();
        for (int i = 0; i < m.questions.size(); i++) {
            if (m.used.get(i)) continue;

            Question q = m.questions.get(i);
            View item = getLayoutInflater().inflate(R.layout.item_option_choice, null);
            TextView txt = item.findViewById(R.id.tvOptionText);
            txt.setText(q.question);
            ((TextView) item.findViewById(R.id.tvLetter)).setText(String.valueOf(i + 1));

            int idx = i;
            item.setOnClickListener(v -> {

                if (isChatOpen()) {
                    lastInfoText = tvInfo.getText().toString();  // sauvegarde du texte normal
                    tvInfo.setText("Veuillez fermer le chat pour choisir une question.");
                    return;
                }

                // Sinon envoyer la question normalement
                sendQuestion(idx);
            });

            listQuestionsLayout.addView(item);
        }
    }

    private void showAnswerOptions(Question q) {
        optionsLayout.removeAllViews();

        int letter = 0;
        for (String opt : q.options) {
            View item = getLayoutInflater().inflate(R.layout.item_option_choice, null);
            ((TextView) item.findViewById(R.id.tvLetter)).setText(String.valueOf((char) ('A' + letter++)));
            MaterialCardView card = item.findViewById(R.id.cardOption);
            TextView txt = item.findViewById(R.id.tvOptionText);
            txt.setText(opt);

            item.setOnClickListener(v -> {

                if (isChatOpen()) {
                    lastInfoText = tvInfo.getText().toString();
                    tvInfo.setText("Veuillez fermer le chat pour répondre.");
                    return;
                }

                // Puis comportement normal
                boolean correct = opt.equals(q.answer);

                highlightAnswer(card, correct);

                if (correct) soundPool.play(soundCorrect, 1, 1, 1, 0, 1);
                else         soundPool.play(soundWrong,   1, 1, 1, 0, 1);

                showFeedback(correct);
                disableAllAnswerButtons();

                item.postDelayed(() -> submitAnswer(correct), 500);
            });

            optionsLayout.addView(item);
        }
    }

    private void disableAllAnswerButtons() {
        for (int i = 0; i < optionsLayout.getChildCount(); i++) {
            View v = optionsLayout.getChildAt(i);
            v.setEnabled(false);
        }
    }

    private void highlightAnswer(MaterialCardView card, boolean correct) {
        int color = correct ? Color.parseColor("#22C55E") : Color.parseColor("#EF4444");
        int bg = correct ? Color.parseColor("#D1FADF") : Color.parseColor("#FECACA");
        card.setStrokeColor(color);
        card.setCardBackgroundColor(bg);
    }

    private void showFeedback(boolean correct) {
        if (correct) {
            tvInfo.setText("Bonne réponse !");
            tvInfo.setTextColor(Color.parseColor("#22C55E"));
        } else {
            tvInfo.setText("Mauvaise réponse !");
            tvInfo.setTextColor(Color.parseColor("#EF4444"));
        }
    }

    // -----------------------------------
    //          SEND QUESTION
    // -----------------------------------
    private void sendQuestion(int idx) {
        long now = System.currentTimeMillis();

        Map<String,Object> u = new HashMap<>();
        u.put("currentQuestionIndex", idx);
        u.put("state", "question_sent");
        u.put("answerDeadline", now + 60000);
        u.put("answererUid", currentMatch.pickerTurn == 0 ? currentMatch.p2Uid : currentMatch.p1Uid);

        matchRef.updateChildren(u);
    }

    // -----------------------------------
    //          SUBMIT ANSWER
    // -----------------------------------
    private void submitAnswer(boolean correct) {
        if (!"question_sent".equals(currentMatch.state)) return;

        Map<String,Object> upd = new HashMap<>();
        if (correct) {
            if (currentMatch.answererUid.equals(currentMatch.p1Uid))
                upd.put("p1Score", currentMatch.p1Score + 1);
            else
                upd.put("p2Score", currentMatch.p2Score + 1);
        }

        currentMatch.used.set(currentMatch.currentQuestionIndex, true);

        upd.put("used", currentMatch.used);
        upd.put("pickerTurn", currentMatch.pickerTurn == 0 ? 1 : 0);
        upd.put("state","waiting");
        upd.put("currentQuestionIndex", -1);

        matchRef.updateChildren(upd);
    }

    // -----------------------------------
    //         REFILL QUESTIONS
    // -----------------------------------
    private void maybeRefillQuestions(Match m) {
        boolean allUsed = true;
        for (Boolean b : m.used)
            if (!b) { allUsed = false; break; }

        if (allUsed) addQuestions(m);
    }

    private void addQuestions(Match mm) {
        List<Question> newQuestions = loadRandomQuestions(mm);

        matchRef.runTransaction(new Transaction.Handler() {
            @Override public Transaction.Result doTransaction(MutableData current) {
                Match data = current.getValue(Match.class);
                if (data == null) return Transaction.success(current);

                if (data.questions == null) data.questions = new ArrayList<>();
                if (data.used == null) data.used = new ArrayList<>();

                for (Question q : newQuestions) {
                    data.questions.add(q);
                    data.used.add(false);
                }

                current.setValue(data);
                return Transaction.success(current);
            }
            @Override public void onComplete(DatabaseError e, boolean c, DataSnapshot ds) {}
        });
    }

    // -----------------------------------
    //       LOAD QUESTIONS (ORIGINAL)
    // -----------------------------------
    private List<Question> loadRandomQuestions(Match mm) {
        try {
            // Charger toutes les questions JSON
            InputStream is = getAssets().open("questions.json");
            byte[] buf = new byte[is.available()];
            is.read(buf);
            is.close();

            JSONArray arr = new JSONArray(new String(buf, StandardCharsets.UTF_8));
            List<Question> allQuestions = new ArrayList<>();

            // Convert JSON → Objet
            for (int i = 0; i < arr.length(); i++) {
                JSONObject o = arr.getJSONObject(i);

                Question q = new Question();
                q.category = o.optString("category");
                q.question = o.optString("question");
                q.answer = o.optString("answer");
                q.explanation = o.optString("explanation");

                // Options
                JSONArray ops = o.optJSONArray("options");
                q.options = new ArrayList<>();
                for (int k = 0; k < ops.length(); k++) q.options.add(ops.getString(k));

                allQuestions.add(q);
            }

            // Liste des questions déjà utilisées dans le match
            Set<String> usedBefore = new HashSet<>();
            if (mm.questions != null)
                for (Question q : mm.questions)
                    usedBefore.add(q.question.trim().toLowerCase());

            // Filtrer pour retirer les questions déjà utilisées
            List<Question> remaining = new ArrayList<>();
            for (Question q : allQuestions) {
                if (!usedBefore.contains(q.question.trim().toLowerCase())) {
                    remaining.add(q);
                }
            }

            // Mélange aléatoire
            Collections.shuffle(remaining);

            // Mélange des options
            for (Question q : remaining) {
                Collections.shuffle(q.options);
            }

            // Retourne 4 questions maximum
            return remaining.subList(0, Math.min(4, remaining.size()));

        } catch (Exception e) {
            return new ArrayList<>();
        }
    }

    // -----------------------------------
    //             TIMER
    // -----------------------------------
    private void startTimer(long ms, boolean penalizeOnFinish) {
        stopTimer();

        countDownTimer = new CountDownTimer(ms, 1000) {
            @Override public void onTick(long msLeft) {
                tvTimer.setText("Temps restant : " + (msLeft / 1000) + "s");
            }
            @Override public void onFinish() {
                tvTimer.setText("Temps restant : 0s");
                if (penalizeOnFinish) submitAnswer(false);
            }
        }.start();
    }

    private void stopTimer() {
        if (countDownTimer != null) {
            countDownTimer.cancel();
            countDownTimer = null;
        }
    }

    // -----------------------------------
    //              END MATCH
    // -----------------------------------
    private void endMatch(Match m) {
        stopTimer();

        matchEndTime = System.currentTimeMillis();
        long duration = matchEndTime - matchStartTime;

        // Déterminer gagnant / perdant via scores
        String winnerUid = null;
        String loserUid  = null;

        if (elapsedTimer != null)
            elapsedTimer.cancel();

        if (m.p1Score > m.p2Score) {
            winnerUid = m.p1Uid;
            loserUid = m.p2Uid;
        } else if (m.p2Score > m.p1Score) {
            winnerUid = m.p2Uid;
            loserUid = m.p1Uid;
        } else {
            // Égalité
            winnerUid = null;
            loserUid = null;
        }

        // Remettre les deux joueurs disponibles
        usersRef.child(m.p1Uid).child("status").setValue("available");
        usersRef.child(m.p2Uid).child("status").setValue("available");

        // Enregistrer l'historique (avec lastScore pour lobby)
        saveMatchHistory(m, duration);

        // Message final (affiche la raison si présente)
        StringBuilder msg = new StringBuilder();
        msg.append(m.p1Name).append(" : ").append(m.p1Score).append("\n")
                .append(m.p2Name).append(" : ").append(m.p2Score).append("\n\n");

        if (m.endReason != null && !m.endReason.isEmpty()) { // affiche raison si set
            String note = (m.cheatNote != null && !m.cheatNote.isEmpty()) ? ("\n\n" + m.cheatNote) : "";
            switch (m.endReason) {
                case "cheating_detected":
                    msg.append("Le match a été interrompu pour suspicion de triche.").append(note);
                    break;
                case "forfeit":
                    msg.append("Le match a été interrompu (abandon).").append(note);
                    break;
                default:
                    msg.append("Le match a été interrompu.").append(note);
                    break;
            }
            msg.append("\n\n");
        }

        if (winnerUid == null) {
            msg.append("Résultat : Égalité 🤝");
        } else if (winnerUid.equals(myUid)) {
            msg.append("Vous avez gagné 🎉");
        } else {
            msg.append("Vous avez perdu 😞");
        }

        new AlertDialog.Builder(this)
                .setTitle("Match terminé")
                .setMessage(msg.toString())
                .setPositiveButton("OK", (d, w) -> finish())
                .setCancelable(false)
                .show();
    }

    // -----------------------------------
//     SAVE HISTORY + lastScore
// -----------------------------------
    private void saveMatchHistory(Match m, long durationMs) {

        // Est-ce que je suis P1 ?
        boolean iAmP1 = myUid.equals(m.p1Uid);

        // Pour MOI
        String myOppUid  = iAmP1 ? m.p2Uid  : m.p1Uid;
        String myOppName = iAmP1 ? m.p2Name : m.p1Name;
        String myName    = iAmP1 ? m.p1Name : m.p2Name;

        int myScoreInt  = iAmP1 ? m.p1Score : m.p2Score;
        int oppScoreInt = iAmP1 ? m.p2Score : m.p1Score;

        // 🔥 TOUJOURS : monScore - scoreAdversaire
        String score = myScoreInt + "-" + oppScoreInt;

        Map<String,Object> h = new HashMap<>();
        h.put("matchId", m.matchId);
        h.put("opponentUid", myOppUid);
        h.put("opponentName", myOppName);
        h.put("score", score);
        h.put("timestamp", System.currentTimeMillis());
        h.put("durationMs", durationMs);

        // Historique pour MOI
        historyRef.child(myUid).child(m.matchId).setValue(h);
        // Dernier score pour le lobby
        usersRef.child(myUid).child("lastScore").setValue(score);

        // 🔁 Pour L’AUTRE JOUEUR
        // De SON point de vue, son score = oppScoreInt, l’adversaire = myScoreInt
        String oppScoreStr = oppScoreInt + "-" + myScoreInt;

        Map<String,Object> h2 = new HashMap<>();
        h2.put("matchId", m.matchId);
        h2.put("opponentUid", myUid);
        h2.put("opponentName", myName); // pour lui, "opponentName" = mon nom
        h2.put("score", oppScoreStr);
        h2.put("timestamp", System.currentTimeMillis());
        h2.put("durationMs", durationMs);

        historyRef.child(myOppUid).child(m.matchId).setValue(h2);
        usersRef.child(myOppUid).child("lastScore").setValue(oppScoreStr);
    }


    private void addChatMessageToView(String fromUid, String message, boolean isMine, String messageKey) {
        TextView tv = new TextView(this);
        tv.setText(message);
        tv.setTextSize(15f);
        tv.setPadding(18, 12, 18, 12);

        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        );
        lp.setMargins(10, 8, 10, 8);

        // Alignement type WhatsApp
        if (isMine) {
            lp.gravity = Gravity.END;
            tv.setBackgroundResource(R.drawable.chat_bubble_mine);
        } else {
            lp.gravity = Gravity.START;
            tv.setBackgroundResource(R.drawable.chat_bubble_their);
        }

        tv.setLayoutParams(lp);

        if (messageKey != null)
            tv.setTag(messageKey);

        runOnUiThread(() -> {
            chatMessages.addView(tv);
            chatScroll.post(() -> chatScroll.fullScroll(View.FOCUS_DOWN));
        });
    }

    private void scrollChatToBottom() {
        chatScroll.post(() -> chatScroll.fullScroll(View.FOCUS_DOWN));
    }

    private void showChatPopup(String msg) {
        tvChatPopup.setText("Adversaire : " + msg);
        tvChatPopup.setVisibility(View.VISIBLE);
        tvChatPopup.postDelayed(() -> tvChatPopup.setVisibility(View.GONE), 3000);
    }

    private void openChat() {
        chatBox.setVisibility(View.VISIBLE);
        chatOpen = true;
        unreadCount = 0;
        chatBadge.setVisibility(View.GONE);
        scrollChatToBottom();
    }

    private void closeChat() {
        chatBox.setVisibility(View.GONE);
        chatOpen = false;

        etChatMessage.clearFocus();
        forceHideKeyboard();

        if (lastInfoText != null && !lastInfoText.isEmpty()) {
            tvInfo.setText(lastInfoText);
        }
    }

    // ================================
    // 🛡️ ANTI-TRICHE : DÉTECTIONS
    // ================================
    private void endMatchForCheating(@NonNull String note) {
        if (isFinishingForCheat) return;
        if (currentMatch == null) return;
        if ("finished".equals(currentMatch.state)) return;
        isFinishingForCheat = true;

        String myName = myUid.equals(currentMatch.p1Uid) ? currentMatch.p1Name : currentMatch.p2Name;

        Map<String, Object> upd = new HashMap<>();
        upd.put("state", "finished");
        upd.put("endReason", "cheating_detected");
        upd.put("cheatDetected", true);
        upd.put("endedByUid", myUid);
        upd.put("endedByName", myName);
        upd.put("cheatNote", note);
        upd.put("opponentAlert",
                "Tricherie constatée chez votre adversaire : nous avons mis fin au match.");

        matchRef.updateChildren(upd);
    }

    @Override
    public void onUserLeaveHint() { // App passe en arrière-plan via HOME/Recent Apps
        super.onUserLeaveHint();
        if (!isChangingConfigurations()
                && currentMatch != null
                && !"finished".equals(currentMatch.state)) {
            endMatchForCheating("Réduction/minimisation de l’application détectée.");
        }
    }

    @Override
    protected void onStop() {
        super.onStop();
        if (!isChangingConfigurations()
                && currentMatch != null
                && !"finished".equals(currentMatch.state)
                && !isInPip) {
            endMatchForCheating("Application envoyée en arrière-plan.");
        }
    }

    @Override
    public void onMultiWindowModeChanged(boolean isInMultiWindowMode) {
        super.onMultiWindowModeChanged(isInMultiWindowMode);
        isInMultiWindow = isInMultiWindowMode;
        if (isInMultiWindowMode
                && currentMatch != null
                && !"finished".equals(currentMatch.state)) {
            endMatchForCheating("Mode multi-fenêtres détecté.");
        }
    }

    @SuppressLint("NewApi")
    @Override
    public void onPictureInPictureModeChanged(boolean isInPictureInPictureMode, @NonNull Configuration newConfig) {
        super.onPictureInPictureModeChanged(isInPictureInPictureMode, newConfig);
        isInPip = isInPictureInPictureMode;

        if (isInPictureInPictureMode
                && currentMatch != null
                && !"finished".equals(currentMatch.state)) {
            endMatchForCheating("Mode Image-dans-l’image (PiP) détecté.");
        }
    }

    private void forceHideKeyboard() {
        try {
            InputMethodManager imm = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
            if (imm != null) {
                imm.hideSoftInputFromWindow(chatBox.getWindowToken(), 0);
                imm.hideSoftInputFromWindow(etChatMessage.getWindowToken(), 0);
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();

        if (matchRef != null && matchListener != null)
            matchRef.removeEventListener(matchListener);

        if (infoConnectedRef != null && connectedListener != null)
            infoConnectedRef.removeEventListener(connectedListener);

        if (elapsedTimer != null) elapsedTimer.cancel();

        if (chatRef != null && chatChildListener != null)
            chatRef.removeEventListener(chatChildListener);

        stopTimer();
    }
}
