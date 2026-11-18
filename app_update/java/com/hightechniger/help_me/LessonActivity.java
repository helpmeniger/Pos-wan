package com.hightechniger.help_me;

import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Bundle;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.DecelerateInterpolator;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.MediaController;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.VideoView;

import androidx.appcompat.app.AppCompatActivity;

import com.hightechniger.help_me.demo.DemoActivity;
import com.hightechniger.help_me.demo.DemoScene;
import com.hightechniger.help_me.demo.DemoView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.InputStream;
import java.nio.charset.StandardCharsets;

/**
 * Fiche de leçon affichée après le scan d'un code-barres du manuel.
 * Les données viennent de assets/lessons.json (généré depuis le manuel) :
 * chaque code du manuel a une fiche, avec ou sans vidéo.
 */
public class LessonActivity extends AppCompatActivity {

    public static final String EXTRA_CODE = "code";
    private static final int BLUE = Color.parseColor("#1F4D78");
    private static final int BLUE_LIGHT = Color.parseColor("#DEEAF6");
    private static final int RED = Color.parseColor("#E00000");
    private static final int INK = Color.parseColor("#1C2433");
    private static final int GREY = Color.parseColor("#595959");

    private LinearLayout content;
    private int animDelay = 0;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        String code = getIntent().getStringExtra(EXTRA_CODE);
        JSONObject lesson = findLesson(code);

        ScrollView scroll = new ScrollView(this);
        scroll.setBackgroundColor(Color.WHITE);
        scroll.setFillViewport(true);
        content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(dp(20), dp(24), dp(20), dp(32));
        scroll.addView(content, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        setContentView(scroll);

        if (lesson == null) {
            addText("Code non reconnu", 22, RED, true);
            addText("Ce code-barres ne fait pas partie du manuel HELP-ME. Vérifiez que vous scannez bien le code placé en haut de la page.", 16, INK, false);
            addButton("Scanner à nouveau", true, v -> rescan());
            return;
        }

        // En-tête : partie, titre, page
        addChip(lesson.optString("partie", ""));
        addText(lesson.optString("titre", ""), 24, BLUE, true);
        addText("Manuel HELP-ME · page " + lesson.optInt("page"), 14, GREY, false);

        // Vidéo si elle existe dans l'application
        String video = lesson.optString("video", "");
        int resId = video.isEmpty() ? 0 : getResources().getIdentifier(video, "raw", getPackageName());
        if (resId != 0) addVideo(resId);

        // Démonstration animée dessinée en code (remplace une vidéo)
        String demoId = lesson.optString("demo", "");
        DemoScene scene = demoId.isEmpty() ? null : DemoActivity.create(demoId);
        if (scene != null) {
            DemoView dv = new DemoView(this, scene);
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            lp.topMargin = dp(12);
            content.addView(dv, lp);
            appear(dv);
            addButton("Voir la démonstration en grand", false, v ->
                    startActivity(new Intent(this, DemoActivity.class).putExtra(DemoActivity.EXTRA_DEMO, demoId)));
        }

        String resume = lesson.optString("resume", "");
        if (!resume.isEmpty()) {
            addSection("L’essentiel");
            addText(resume, 16, INK, false);
        }

        JSONArray points = lesson.optJSONArray("points_cles");
        if (points != null && points.length() > 0) {
            addSection("Dans cette leçon");
            for (int i = 0; i < points.length(); i++) {
                addText("•  " + points.optString(i).replaceFirst("^\\d+\\.\\d+\\s*", ""), 16, INK, false);
            }
        }

        String retenir = lesson.optString("a_retenir", "");
        if (!retenir.isEmpty()) addCard("À retenir", retenir);

        addButton("Poser une question à l’assistant", true, v -> {
            Intent i = new Intent(this, AIChatActivity.class);
            i.putExtra("question", lesson.optString("titre", ""));
            startActivity(i);
        });
        addButton("Scanner un autre code", false, v -> rescan());
    }

    private void rescan() {
        startActivity(new Intent(this, MainActivity8.class));
        finish();
    }

    // ------------------------------------------------------------------ données
    private JSONObject findLesson(String code) {
        if (code == null) return null;
        try (InputStream is = getAssets().open("lessons.json")) {
            byte[] buf = new byte[is.available()];
            int read = is.read(buf);
            if (read <= 0) return null;
            JSONArray arr = new JSONArray(new String(buf, StandardCharsets.UTF_8));
            for (int i = 0; i < arr.length(); i++) {
                JSONObject o = arr.optJSONObject(i);
                if (o != null && code.trim().equals(o.optString("code"))) return o;
            }
        } catch (Exception ignored) {
        }
        return null;
    }

    // ------------------------------------------------------------------ interface
    private int dp(int v) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, getResources().getDisplayMetrics());
    }

    private void appear(View v) {
        v.setAlpha(0f);
        v.setTranslationY(dp(16));
        v.animate().alpha(1f).translationY(0).setStartDelay(animDelay).setDuration(350)
                .setInterpolator(new DecelerateInterpolator()).start();
        animDelay += 60;
    }

    private TextView addText(String text, int sp, int color, boolean bold) {
        TextView t = new TextView(this);
        t.setText(text);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        t.setTextColor(color);
        t.setLineSpacing(0, 1.2f);
        if (bold) t.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.bottomMargin = dp(8);
        content.addView(t, lp);
        appear(t);
        return t;
    }

    private void addChip(String text) {
        if (text.isEmpty()) return;
        TextView t = new TextView(this);
        t.setText(text.toUpperCase());
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        t.setTextColor(Color.WHITE);
        t.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        t.setPadding(dp(12), dp(6), dp(12), dp(6));
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(Color.parseColor("#5B9BD5"));
        bg.setCornerRadius(dp(16));
        t.setBackground(bg);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.bottomMargin = dp(12);
        content.addView(t, lp);
        appear(t);
    }

    private void addSection(String title) {
        TextView t = addText(title, 18, BLUE, true);
        ((LinearLayout.LayoutParams) t.getLayoutParams()).topMargin = dp(16);
    }

    private void addCard(String title, String body) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(16), dp(14), dp(16), dp(14));
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(BLUE_LIGHT);
        bg.setCornerRadius(dp(14));
        card.setBackground(bg);
        TextView h = new TextView(this);
        h.setText(title);
        h.setTextColor(BLUE);
        h.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        h.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        TextView b = new TextView(this);
        b.setText(body);
        b.setTextColor(INK);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        b.setLineSpacing(0, 1.2f);
        card.addView(h);
        card.addView(b);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.topMargin = dp(16);
        content.addView(card, lp);
        appear(card);
    }

    private void addVideo(int resId) {
        FrameLayout frame = new FrameLayout(this);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(Color.BLACK);
        bg.setCornerRadius(dp(14));
        frame.setBackground(bg);
        frame.setClipToOutline(true);
        VideoView vv = new VideoView(this);
        frame.addView(vv, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(220), Gravity.CENTER));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(220));
        lp.topMargin = dp(12);
        content.addView(frame, lp);
        appear(frame);
        vv.setVideoURI(Uri.parse("android.resource://" + getPackageName() + "/" + resId));
        MediaController mc = new MediaController(this);
        mc.setAnchorView(vv);
        vv.setMediaController(mc);
        vv.setOnPreparedListener(mp -> vv.start());
    }

    private void addButton(String label, boolean primary, View.OnClickListener l) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        b.setTextColor(primary ? Color.WHITE : BLUE);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(primary ? BLUE : Color.WHITE);
        bg.setStroke(dp(2), BLUE);
        bg.setCornerRadius(dp(26));
        b.setBackground(bg);
        b.setOnClickListener(l);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(52));
        lp.topMargin = dp(14);
        content.addView(b, lp);
        appear(b);
    }
}
