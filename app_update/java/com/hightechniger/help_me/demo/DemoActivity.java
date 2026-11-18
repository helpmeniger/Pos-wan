package com.hightechniger.help_me.demo;

import android.app.Activity;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.SeekBar;
import android.widget.TextView;

/**
 * Écran de démonstration animée (remplace une vidéo).
 * Lancement : new Intent(ctx, DemoActivity.class).putExtra(DemoActivity.EXTRA_DEMO, "creer_organiser")
 */
public class DemoActivity extends Activity {

    public static final String EXTRA_DEMO = "demo";
    private static final int BG = Color.parseColor("#0F1B2D"), BLUE = Color.parseColor("#5B9BD5");

    private DemoView demo;
    private TextView caption, playBtn;
    private SeekBar seek;
    private String lastCaption = "";
    private boolean userSeeking = false;

    /** Catalogue des démonstrations disponibles (identifiant → scène). */
    public static DemoScene create(String id) {
        if ("creer_organiser".equals(id)) return new CreerOrganiserScene();
        return null;
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        getWindow().setStatusBarColor(BG);
        DemoScene scene = create(getIntent().getStringExtra(EXTRA_DEMO));
        if (scene == null) { finish(); return; }

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(BG);
        root.setPadding(dp(16), dp(20), dp(16), dp(20));

        TextView kicker = label("DÉMONSTRATION HELP-ME", 12, BLUE, true);
        kicker.setLetterSpacing(.15f);
        root.addView(kicker);
        TextView title = label(scene.title(), 22, Color.WHITE, true);
        title.setPadding(0, dp(4), 0, dp(14));
        root.addView(title);

        demo = new DemoView(this, scene);
        root.addView(demo, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        caption = label("", 17, Color.WHITE, false);
        caption.setMinLines(3);
        caption.setGravity(Gravity.CENTER_HORIZONTAL);
        caption.setLineSpacing(0, 1.2f);
        caption.setPadding(dp(4), dp(18), dp(4), dp(8));
        root.addView(caption);

        seek = new SeekBar(this);
        seek.setMax(Math.round(scene.duration() * 10));
        seek.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override public void onProgressChanged(SeekBar s, int p, boolean fromUser) { if (fromUser) demo.seek(p / 10f); }
            @Override public void onStartTrackingTouch(SeekBar s) { userSeeking = true; }
            @Override public void onStopTrackingTouch(SeekBar s) { userSeeking = false; }
        });
        root.addView(seek);

        LinearLayout controls = new LinearLayout(this);
        controls.setGravity(Gravity.CENTER);
        controls.setPadding(0, dp(12), 0, 0);
        controls.addView(button("Précédent", false, v -> jump(-1)));
        playBtn = button("Pause", true, v -> demo.setPlaying(!demo.isPlaying()));
        controls.addView(playBtn);
        controls.addView(button("Suivant", false, v -> jump(1)));
        controls.addView(button("Rejouer", false, v -> { demo.seek(0); demo.setPlaying(true); }));
        root.addView(controls);
        setContentView(root);

        demo.setListener((t, playing) -> {
            if (!userSeeking) seek.setProgress(Math.round(t * 10));
            playBtn.setText(playing ? "Pause" : (t >= scene.duration() ? "Revoir" : "Lecture"));
            String c = scene.caption(t);
            if (!c.equals(lastCaption)) {
                lastCaption = c;
                caption.setAlpha(0f);
                caption.setTranslationY(dp(8));
                caption.setText(c);
                caption.animate().alpha(1f).translationY(0).setDuration(300).start();
            }
        });

        // apparition de l'écran
        root.setAlpha(0f);
        root.animate().alpha(1f).setDuration(350).start();
    }

    private void jump(int dir) {
        float t = demo.getTime();
        float[] st = demo.getScene().steps();
        float target = dir > 0 ? demo.getScene().duration() : 0;
        if (dir > 0) { for (float s : st) if (s > t + .05f) { target = s; break; } }
        else { for (float s : st) if (s < t - 1.0f) target = s; }
        demo.seek(target);
        demo.setPlaying(true);
    }

    @Override protected void onPause() { super.onPause(); if (demo != null) demo.setPlaying(false); }

    private int dp(int v) { return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, getResources().getDisplayMetrics())); }

    private TextView label(String s, int sp, int color, boolean bold) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextColor(color);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        if (bold) t.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        return t;
    }

    private TextView button(String s, boolean primary, View.OnClickListener l) {
        Button b = new Button(this);
        b.setText(s);
        b.setAllCaps(false);
        b.setTextColor(primary ? Color.WHITE : BLUE);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(primary ? BLUE : Color.TRANSPARENT);
        bg.setStroke(dp(2), BLUE);
        bg.setCornerRadius(dp(24));
        b.setBackground(bg);
        b.setOnClickListener(l);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(46));
        lp.setMargins(dp(5), 0, dp(5), 0);
        b.setMinWidth(dp(60));
        b.setPadding(dp(14), 0, dp(14), 0);
        b.setLayoutParams(lp);
        return b;
    }
}
