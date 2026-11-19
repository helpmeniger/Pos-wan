package com.hightechniger.help_me;

import android.animation.ObjectAnimator;
import android.animation.ValueAnimator;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.speech.RecognizerIntent;
import android.speech.tts.TextToSpeech;
import android.text.TextUtils;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.HapticFeedbackConstants;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputMethodManager;
import android.view.animation.DecelerateInterpolator;
import android.view.animation.OvershootInterpolator;
import android.widget.EditText;
import android.widget.HorizontalScrollView;
import android.widget.ImageButton;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;

import com.hightechniger.help_me.brain.Brain;
import com.hightechniger.help_me.brain.BrainHolder;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

/**
 * Assistant HELP-ME, 100 % hors ligne.
 * Le moteur (brain/Brain.java) comprend la question ; cet écran affiche la conversation :
 * bulles animées, indicateur « en train d'écrire », suggestions, avis « Utile / Pas utile »,
 * dictée vocale et lecture à voix haute (si le téléphone les propose hors ligne).
 */
public class AIChatActivity extends AppCompatActivity {

    private static final int BRAND = Color.parseColor("#326FBF");
    private static final int TEXT = Color.parseColor("#1C2430");
    private static final int MUTED = Color.parseColor("#6B778A");

    private EditText inputBox;
    private LinearLayout chatContainer;
    private ScrollView scroll;
    private TextView statusText;
    private ImageButton sendButton;
    private final Handler ui = new Handler(Looper.getMainLooper());

    private Brain brain;
    private String pendingQuestion;
    private View typingView;
    private TextToSpeech tts;
    private boolean ttsReady;
    private ActivityResultLauncher<Intent> speech;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_aichat);
        getWindow().setStatusBarColor(Color.parseColor("#2A5FA6"));

        inputBox = findViewById(R.id.inputBox);
        chatContainer = findViewById(R.id.chatContainer);
        scroll = findViewById(R.id.scrollView);
        statusText = findViewById(R.id.statusText);
        sendButton = findViewById(R.id.sendButton);
        ImageButton micButton = findViewById(R.id.micButton);

        findViewById(R.id.backButton).setOnClickListener(v -> finish());
        findViewById(R.id.newChatButton).setOnClickListener(v -> newConversation());
        sendButton.setOnClickListener(v -> sendTyped());
        inputBox.setOnEditorActionListener((v, actionId, e) -> {
            if (actionId == EditorInfo.IME_ACTION_SEND) { sendTyped(); return true; }
            return false;
        });

        // dictée vocale (Google ou moteur du téléphone, hors ligne si la langue est téléchargée)
        speech = registerForActivityResult(new ActivityResultContracts.StartActivityForResult(), res -> {
            if (res.getData() == null) return;
            ArrayList<String> r = res.getData().getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS);
            if (r != null && !r.isEmpty()) send(r.get(0));
        });
        Intent probe = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
        if (probe.resolveActivity(getPackageManager()) == null) micButton.setVisibility(View.GONE);
        micButton.setOnClickListener(v -> startDictation());

        tts = new TextToSpeech(this, status -> {
            if (status == TextToSpeech.SUCCESS) {
                int r = tts.setLanguage(Locale.FRENCH);
                ttsReady = r != TextToSpeech.LANG_MISSING_DATA && r != TextToSpeech.LANG_NOT_SUPPORTED;
            }
        });

        String fromLesson = getIntent().getStringExtra("question");
        if (fromLesson != null && !fromLesson.trim().isEmpty()) pendingQuestion = fromLesson.trim();

        setReady(false);
        showTyping();
        BrainHolder.get(this, new BrainHolder.Callback() {
            @Override public void onReady(Brain b) {
                brain = b;
                hideTyping();
                setReady(true);
                if (pendingQuestion != null) {
                    String q = pendingQuestion;
                    pendingQuestion = null;
                    send(q);
                } else {
                    showReply(brain.welcome(), false);
                }
            }

            @Override public void onError(Exception e) {
                hideTyping();
                statusText.setText("Indisponible");
                addAssistantText("Je n’arrive pas à charger mes fiches. Fermez puis rouvrez l’application.", null);
            }
        });
    }

    @Override
    protected void onDestroy() {
        if (tts != null) tts.shutdown();
        ui.removeCallbacksAndMessages(null);
        super.onDestroy();
    }

    // ================================================================ envoi

    private void sendTyped() {
        String q = inputBox.getText().toString().trim();
        if (TextUtils.isEmpty(q)) return;
        inputBox.setText("");
        send(q);
    }

    private void send(String q) {
        if (brain == null) { pendingQuestion = q; return; }
        hideKeyboard();
        addUserText(q);
        answerLater(brain.ask(q));
    }

    private void openDoc(Brain.Chip chip) {
        addUserText(chip.label);
        answerLater(brain.open(Integer.parseInt(chip.arg)));
    }

    /** Petit délai avec l'indicateur de saisie, proportionnel à la longueur de la réponse. */
    private void answerLater(Brain.Reply r) {
        setReady(false);
        showTyping();
        long delay = Math.min(1100, 350 + r.text.length() / 3);
        ui.postDelayed(() -> {
            hideTyping();
            showReply(r, true);
            setReady(true);
        }, delay);
    }

    private void newConversation() {
        if (brain == null) return;
        brain.reset();
        if (tts != null) tts.stop();
        chatContainer.removeAllViews();
        showReply(brain.welcome(), false);
    }

    private void startDictation() {
        Intent i = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
        i.putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM);
        i.putExtra(RecognizerIntent.EXTRA_LANGUAGE, "fr-FR");
        i.putExtra(RecognizerIntent.EXTRA_PREFER_OFFLINE, true);
        i.putExtra(RecognizerIntent.EXTRA_PROMPT, "Posez votre question");
        try {
            speech.launch(i);
        } catch (ActivityNotFoundException e) {
            Toast.makeText(this, "La dictée vocale n’est pas disponible sur ce téléphone.", Toast.LENGTH_SHORT).show();
        }
    }

    // ================================================================ affichage

    private void showReply(Brain.Reply r, boolean animate) {
        LinearLayout bubble = addAssistantText(r.text, r.note);
        // illustration si l'application en possède une pour cette fiche
        if (r.image != null) {
            int id = getResources().getIdentifier(r.image, "drawable", getPackageName());
            if (id != 0) {
                ImageView iv = new ImageView(this);
                iv.setImageResource(id);
                iv.setAdjustViewBounds(true);
                iv.setPadding(0, dp(10), 0, 0);
                iv.setOnClickListener(v -> ImageLessonActivity.open(this, id));
                bubble.addView(iv, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT));
            }
        }
        if (r.askFeedback && r.docId >= 0) bubble.addView(actionsRow(r));
        if (!r.chips.isEmpty()) addChips(r.chipsTitle, r.chips);
        scrollDown();
    }

    private LinearLayout addAssistantText(String text, String note) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setPadding(0, dp(6), dp(36), dp(2));

        TextView avatar = new TextView(this);
        avatar.setText("HM");
        avatar.setTextColor(Color.WHITE);
        avatar.setTextSize(11);
        avatar.setTypeface(Typeface.DEFAULT_BOLD);
        avatar.setGravity(Gravity.CENTER);
        avatar.setBackgroundResource(R.drawable.chat_avatar_bg);
        LinearLayout.LayoutParams ap = new LinearLayout.LayoutParams(dp(32), dp(32));
        ap.setMarginEnd(dp(8));
        row.addView(avatar, ap);

        LinearLayout bubble = new LinearLayout(this);
        bubble.setOrientation(LinearLayout.VERTICAL);
        bubble.setBackgroundResource(R.drawable.chat_bubble_ai);
        bubble.setPadding(dp(14), dp(10), dp(14), dp(10));
        bubble.setElevation(dp(1));

        TextView msg = new TextView(this);
        msg.setText(text);
        msg.setTextColor(TEXT);
        msg.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15.5f);
        msg.setLineSpacing(0, 1.18f);
        msg.setTextIsSelectable(false);
        msg.setOnLongClickListener(v -> { copy(text); return true; });
        bubble.addView(msg);

        if (note != null) {
            TextView n = new TextView(this);
            n.setText(note);
            n.setTextColor(MUTED);
            n.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
            n.setPadding(0, dp(8), 0, 0);
            bubble.addView(n);
        }
        row.addView(bubble, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        appear(row, false);
        chatContainer.addView(row);
        return bubble;
    }

    private void addUserText(String text) {
        LinearLayout row = new LinearLayout(this);
        row.setGravity(Gravity.END);
        row.setPadding(dp(56), dp(10), 0, dp(2));
        TextView msg = new TextView(this);
        msg.setText(text);
        msg.setTextColor(Color.WHITE);
        msg.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15.5f);
        msg.setBackgroundResource(R.drawable.chat_bubble_user);
        msg.setPadding(dp(14), dp(10), dp(14), dp(10));
        row.addView(msg);
        appear(row, true);
        chatContainer.addView(row);
        scrollDown();
    }

    /** Ligne « Utile · Pas utile · Écouter » sous une réponse. */
    private View actionsRow(Brain.Reply r) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(0, dp(10), 0, 0);
        TextView useful = action("Utile");
        TextView useless = action("Pas utile");
        row.addView(useful);
        row.addView(useless);
        if (ttsReady) {
            TextView listen = action("Écouter");
            listen.setOnClickListener(v -> speak(r.text));
            row.addView(listen);
        }
        View.OnClickListener vote = v -> {
            boolean ok = v == useful;
            v.performHapticFeedback(HapticFeedbackConstants.VIRTUAL_KEY);
            useful.setEnabled(false);
            useless.setEnabled(false);
            useful.setVisibility(View.GONE);
            useless.setVisibility(View.GONE);
            TextView thanks = action(ok ? "Merci pour votre avis" : "Je cherche autre chose…");
            thanks.setEnabled(false);
            row.addView(thanks, 0);
            Brain.Reply other = brain.feedback(r.docId, ok);
            if (other != null) answerLater(other);
        };
        useful.setOnClickListener(vote);
        useless.setOnClickListener(vote);
        return row;
    }

    private TextView action(String label) {
        TextView t = new TextView(this);
        t.setText(label);
        t.setTextColor(BRAND);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13);
        t.setTypeface(Typeface.DEFAULT_BOLD);
        t.setPadding(0, dp(4), dp(18), dp(4));
        TypedValue tv = new TypedValue();
        getTheme().resolveAttribute(android.R.attr.selectableItemBackground, tv, true);
        t.setBackgroundResource(tv.resourceId);
        return t;
    }

    private void addChips(String title, List<Brain.Chip> chips) {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(40), dp(6), 0, dp(4));
        if (title != null) {
            TextView t = new TextView(this);
            t.setText(title);
            t.setTextColor(MUTED);
            t.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12.5f);
            t.setPadding(dp(2), 0, 0, dp(4));
            box.addView(t);
        }
        HorizontalScrollView hs = new HorizontalScrollView(this);
        hs.setHorizontalScrollBarEnabled(false);
        hs.setOverScrollMode(View.OVER_SCROLL_NEVER);
        LinearLayout line = new LinearLayout(this);
        line.setOrientation(LinearLayout.HORIZONTAL);
        int i = 0;
        for (Brain.Chip c : chips) {
            TextView chip = new TextView(this);
            chip.setText(c.label);
            boolean lesson = Brain.Chip.LESSON.equals(c.action);
            chip.setTextColor(lesson ? Color.WHITE : BRAND);
            chip.setBackgroundResource(lesson ? R.drawable.chat_chip_action : R.drawable.chat_chip);
            chip.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
            chip.setPadding(dp(14), dp(8), dp(14), dp(8));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT);
            lp.setMarginEnd(dp(8));
            chip.setOnClickListener(v -> onChip(c, box));
            chip.setAlpha(0f);
            chip.setTranslationX(dp(24));
            chip.animate().alpha(1f).translationX(0).setStartDelay(80L * i++).setDuration(260)
                    .setInterpolator(new DecelerateInterpolator()).start();
            line.addView(chip, lp);
        }
        hs.addView(line);
        box.addView(hs);
        chatContainer.addView(box);
    }

    private void onChip(Brain.Chip c, View box) {
        if (brain == null || !sendButton.isEnabled()) return;
        switch (c.action) {
            case Brain.Chip.LESSON:
                startActivity(new Intent(this, LessonActivity.class).putExtra(LessonActivity.EXTRA_CODE, c.arg));
                return;
            case Brain.Chip.DOC:
                openDoc(c);
                break;
            default:
                send(c.arg);
        }
        box.animate().alpha(0.45f).setDuration(200).start();
    }

    // ================================================================ indicateur « en train d'écrire »

    private void showTyping() {
        if (typingView != null) return;
        LinearLayout row = new LinearLayout(this);
        row.setPadding(dp(40), dp(8), 0, dp(4));
        LinearLayout bubble = new LinearLayout(this);
        bubble.setBackgroundResource(R.drawable.chat_bubble_ai);
        bubble.setPadding(dp(14), dp(14), dp(14), dp(14));
        for (int i = 0; i < 3; i++) {
            View dot = new View(this);
            dot.setBackgroundResource(R.drawable.chat_dot);
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(dp(8), dp(8));
            if (i > 0) lp.setMarginStart(dp(5));
            bubble.addView(dot, lp);
            ObjectAnimator a = ObjectAnimator.ofFloat(dot, View.TRANSLATION_Y, 0, -dp(5), 0);
            a.setDuration(600);
            a.setStartDelay(150L * i);
            a.setRepeatCount(ValueAnimator.INFINITE);
            a.start();
        }
        row.addView(bubble);
        typingView = row;
        chatContainer.addView(row);
        scrollDown();
    }

    private void hideTyping() {
        if (typingView != null) {
            chatContainer.removeView(typingView);
            typingView = null;
        }
    }

    // ================================================================ outils

    private void appear(View v, boolean fromRight) {
        v.setAlpha(0f);
        v.setTranslationY(dp(14));
        v.setTranslationX(fromRight ? dp(18) : 0);
        v.setScaleX(0.97f);
        v.setScaleY(0.97f);
        v.animate().alpha(1f).translationY(0).translationX(0).scaleX(1f).scaleY(1f).setDuration(280)
                .setInterpolator(fromRight ? new OvershootInterpolator(0.8f) : new DecelerateInterpolator()).start();
    }

    private void setReady(boolean ready) {
        sendButton.setEnabled(ready);
        sendButton.animate().alpha(ready ? 1f : 0.5f).setDuration(150).start();
        statusText.setText(brain == null ? "Préparation…" : ready ? "Hors ligne · prêt à répondre" : "En train d’écrire…");
    }

    private void speak(String text) {
        if (!ttsReady) return;
        tts.stop();
        tts.speak(text.replace("•", "").replace("›", ", "), TextToSpeech.QUEUE_FLUSH, null, "helpme");
    }

    private void copy(String text) {
        ClipboardManager cm = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        if (cm != null) {
            cm.setPrimaryClip(ClipData.newPlainText("HELP-ME", text));
            Toast.makeText(this, "Réponse copiée", Toast.LENGTH_SHORT).show();
        }
    }

    private void scrollDown() {
        scroll.post(() -> scroll.smoothScrollTo(0, chatContainer.getBottom()));
    }

    private void hideKeyboard() {
        InputMethodManager imm = (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
        if (imm != null) imm.hideSoftInputFromWindow(inputBox.getWindowToken(), 0);
    }

    private int dp(float v) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, getResources().getDisplayMetrics()));
    }
}
