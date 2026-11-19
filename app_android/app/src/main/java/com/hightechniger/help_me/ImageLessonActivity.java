package com.hightechniger.help_me;

import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.ViewGroup;
import android.view.animation.DecelerateInterpolator;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

/** Affiche l'illustration du manuel qui remplace provisoirement une vidéo de leçon. */
public class ImageLessonActivity extends AppCompatActivity {

    public static final String EXTRA_IMAGE = "image", EXTRA_TITLE = "title";
    private static final int BG = Color.parseColor("#0F1B2D"), BLUE = Color.parseColor("#5B9BD5");

    public static void open(Context c, int imageRes) { open(c, imageRes, null); }

    public static void open(Context c, int imageRes, String title) {
        if (imageRes == 0) {
            Toast.makeText(c, "Contenu bientôt disponible", Toast.LENGTH_SHORT).show();
            return;
        }
        c.startActivity(new Intent(c, ImageLessonActivity.class).putExtra(EXTRA_IMAGE, imageRes).putExtra(EXTRA_TITLE, title));
    }

    private boolean zoomed = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        int res = getIntent().getIntExtra(EXTRA_IMAGE, 0);
        String title = getIntent().getStringExtra(EXTRA_TITLE);
        getWindow().setStatusBarColor(BG);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(BG);
        root.setPadding(dp(16), dp(24), dp(16), dp(20));

        TextView kicker = text("ILLUSTRATION DU MANUEL HELP-ME", 12, BLUE, true);
        kicker.setLetterSpacing(.15f);
        root.addView(kicker);
        if (title != null && !title.isEmpty()) {
            TextView t = text(title, 22, Color.WHITE, true);
            t.setPadding(0, dp(4), 0, 0);
            root.addView(t);
        }

        FrameLayout frame = new FrameLayout(this);
        GradientDrawable card = new GradientDrawable();
        card.setColor(Color.WHITE);
        card.setCornerRadius(dp(18));
        frame.setBackground(card);
        frame.setClipToOutline(true);
        frame.setElevation(dp(8));
        frame.setPadding(dp(8), dp(8), dp(8), dp(8));
        ImageView img = new ImageView(this);
        img.setImageResource(res);
        img.setAdjustViewBounds(true);
        img.setScaleType(ImageView.ScaleType.FIT_CENTER);
        img.setContentDescription(title != null ? title : "Illustration de la leçon");
        frame.addView(img, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT, Gravity.CENTER));
        LinearLayout.LayoutParams fl = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f);
        fl.topMargin = dp(16);
        root.addView(frame, fl);

        // Toucher l'image pour l'agrandir (zoom ×2) ou revenir
        img.setOnClickListener(v -> {
            zoomed = !zoomed;
            img.animate().scaleX(zoomed ? 2f : 1f).scaleY(zoomed ? 2f : 1f).setDuration(250)
                    .setInterpolator(new DecelerateInterpolator()).start();
        });

        TextView info = text("Touchez l’image pour l’agrandir. La vidéo de cette leçon sera bientôt disponible.", 15,
                Color.parseColor("#C9D6E6"), false);
        info.setGravity(Gravity.CENTER_HORIZONTAL);
        info.setPadding(0, dp(14), 0, dp(10));
        root.addView(info);

        Button close = new Button(this);
        close.setText("Fermer");
        close.setAllCaps(false);
        close.setTextColor(Color.WHITE);
        close.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(BLUE);
        bg.setCornerRadius(dp(26));
        close.setBackground(bg);
        close.setOnClickListener(v -> finish());
        root.addView(close, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(52)));
        setContentView(root);

        // Apparition animée
        frame.setAlpha(0f);
        frame.setScaleX(.94f);
        frame.setScaleY(.94f);
        frame.animate().alpha(1f).scaleX(1f).scaleY(1f).setDuration(380).setInterpolator(new DecelerateInterpolator()).start();
    }

    private int dp(int v) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, getResources().getDisplayMetrics()));
    }

    private TextView text(String s, int sp, int color, boolean bold) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextColor(color);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        if (bold) t.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        return t;
    }
}
