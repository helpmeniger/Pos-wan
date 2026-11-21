package com.hightechniger.help_me.ui;

import android.app.Activity;
import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.Drawable;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.RippleDrawable;
import android.os.Build;
import android.util.TypedValue;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.DecelerateInterpolator;
import android.view.animation.PathInterpolator;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

/**
 * Système de design HELP-ME : couleurs, typographie, formes et animations communes à tous les écrans.
 * Les écrans construits avec ces outils partagent le même aspect (cartes arrondies, couleurs par module,
 * apparitions en cascade) et restent lisibles pour un débutant.
 */
public final class Ui {

    private Ui() {}

    // --------------------------------------------------------------- couleurs
    public static final int BG = Color.parseColor("#F4F6FA");
    public static final int SURFACE = Color.WHITE;
    public static final int TEXT = Color.parseColor("#1B2330");
    public static final int MUTED = Color.parseColor("#667386");
    public static final int BORDER = Color.parseColor("#E2E7EF");
    public static final int BRAND = Color.parseColor("#326FBF");
    public static final int BRAND_DARK = Color.parseColor("#1F4F96");
    public static final int SUCCESS = Color.parseColor("#1E9E62");
    public static final int WARN = Color.parseColor("#C98A00");
    public static final int DANGER = Color.parseColor("#D64545");

    /** Interpolateur « emphasized » de Material : départ vif, arrivée douce. */
    public static final PathInterpolator EMPHASIZED = new PathInterpolator(0.2f, 0f, 0f, 1f);

    public static int color(String hex, int fallback) {
        try { return Color.parseColor(hex); } catch (Exception e) { return fallback; }
    }

    /** Couleur éclaircie (mélange avec du blanc), pour les fonds teintés. */
    public static int tint(int c, float whiteRatio) {
        int r = Math.round(Color.red(c) + (255 - Color.red(c)) * whiteRatio);
        int g = Math.round(Color.green(c) + (255 - Color.green(c)) * whiteRatio);
        int b = Math.round(Color.blue(c) + (255 - Color.blue(c)) * whiteRatio);
        return Color.rgb(r, g, b);
    }

    public static int darker(int c, float f) {
        return Color.rgb(Math.round(Color.red(c) * f), Math.round(Color.green(c) * f), Math.round(Color.blue(c) * f));
    }

    // --------------------------------------------------------------- mesures
    public static int dp(Context c, float v) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, c.getResources().getDisplayMetrics()));
    }

    // --------------------------------------------------------------- formes
    public static GradientDrawable rounded(Context c, int color, float radiusDp) {
        GradientDrawable g = new GradientDrawable();
        g.setColor(color);
        g.setCornerRadius(dp(c, radiusDp));
        return g;
    }

    public static GradientDrawable outlined(Context c, int fill, int stroke, float radiusDp) {
        GradientDrawable g = rounded(c, fill, radiusDp);
        g.setStroke(dp(c, 1), stroke);
        return g;
    }

    public static GradientDrawable gradient(Context c, int from, int to, float radiusDp) {
        GradientDrawable g = new GradientDrawable(GradientDrawable.Orientation.TL_BR, new int[]{from, to});
        g.setCornerRadius(dp(c, radiusDp));
        return g;
    }

    public static GradientDrawable circle(int color) {
        GradientDrawable g = new GradientDrawable();
        g.setShape(GradientDrawable.OVAL);
        g.setColor(color);
        return g;
    }

    /** Fond cliquable avec effet d'onde (ripple) borné à la forme. */
    public static Drawable ripple(Drawable content, int rippleColor) {
        return new RippleDrawable(ColorStateList.valueOf(rippleColor), content, content);
    }

    /** Carte blanche arrondie, bordure légère, cliquable si listener. */
    public static LinearLayout card(Context c, View.OnClickListener click) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.VERTICAL);
        Drawable bg = outlined(c, SURFACE, BORDER, 18);
        l.setBackground(click != null ? ripple(bg, Color.parseColor("#1A326FBF")) : bg);
        l.setElevation(dp(c, 1));
        int p = dp(c, 16);
        l.setPadding(p, p, p, p);
        if (click != null) {
            l.setClickable(true);
            l.setFocusable(true);
            l.setOnClickListener(click);
            pressable(l);
        }
        return l;
    }

    // --------------------------------------------------------------- texte
    public static TextView text(Context c, CharSequence s, float sp, int color, boolean bold) {
        TextView t = new TextView(c);
        t.setText(s);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        t.setTextColor(color);
        if (bold) t.setTypeface(Typeface.create("sans-serif-medium", Typeface.NORMAL));
        t.setLineSpacing(0, 1.15f);
        return t;
    }

    public static TextView title(Context c, CharSequence s, float sp) {
        TextView t = text(c, s, sp, TEXT, true);
        t.setTypeface(Typeface.create("sans-serif-medium", Typeface.BOLD));
        t.setLetterSpacing(-0.01f);
        return t;
    }

    /** Petite pastille de texte (« 5 min », « Manuel »...). */
    public static TextView pill(Context c, String s, int fg, int bg) {
        TextView t = text(c, s, 12, fg, true);
        t.setBackground(rounded(c, bg, 20));
        t.setPadding(dp(c, 10), dp(c, 3), dp(c, 10), dp(c, 3));
        t.setLayoutParams(new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        return t;
    }

    // --------------------------------------------------------------- icônes
    public static ImageView icon(Context c, int res, int color, float sizeDp) {
        ImageView iv = new ImageView(c);
        iv.setImageResource(res);
        iv.setColorFilter(color);
        iv.setLayoutParams(new LinearLayout.LayoutParams(dp(c, sizeDp), dp(c, sizeDp)));
        return iv;
    }

    /** Icône blanche dans un rond de couleur (ou couleur sur rond teinté si soft). */
    public static FrameLayout badge(Context c, int res, int color, float sizeDp, boolean soft) {
        FrameLayout f = new FrameLayout(c);
        f.setBackground(circle(soft ? tint(color, 0.85f) : color));
        ImageView iv = new ImageView(c);
        iv.setImageResource(res);
        iv.setColorFilter(soft ? color : Color.WHITE);
        int s = dp(c, sizeDp * 0.52f);
        FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(s, s);
        lp.gravity = android.view.Gravity.CENTER;
        f.addView(iv, lp);
        f.setLayoutParams(new LinearLayout.LayoutParams(dp(c, sizeDp), dp(c, sizeDp)));
        return f;
    }

    // --------------------------------------------------------------- progression
    public static ProgressBar bar(Context c, int color, int track) {
        ProgressBar pb = new ProgressBar(c, null, android.R.attr.progressBarStyleHorizontal);
        pb.setMax(100);
        pb.setProgressTintList(ColorStateList.valueOf(color));
        pb.setProgressBackgroundTintList(ColorStateList.valueOf(track));
        pb.setLayoutParams(new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(c, 6)));
        return pb;
    }

    // --------------------------------------------------------------- mise en page
    public static LinearLayout.LayoutParams lp(int w, int h) { return new LinearLayout.LayoutParams(w, h); }

    public static LinearLayout.LayoutParams lpMargins(Context c, int w, int h, float l, float t, float r, float b) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(w, h);
        p.setMargins(dp(c, l), dp(c, t), dp(c, r), dp(c, b));
        return p;
    }

    public static LinearLayout row(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.HORIZONTAL);
        l.setGravity(android.view.Gravity.CENTER_VERTICAL);
        return l;
    }

    public static LinearLayout column(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.VERTICAL);
        return l;
    }

    public static View space(Context c, float dpH) {
        View v = new View(c);
        v.setLayoutParams(new LinearLayout.LayoutParams(1, dp(c, dpH)));
        return v;
    }

    // --------------------------------------------------------------- animations
    /** Apparition en cascade : glissement vers le haut + fondu, décalé selon l'index. */
    public static void enter(View v, int index) {
        v.setAlpha(0f);
        v.setTranslationY(dp(v.getContext(), 18));
        v.animate().alpha(1f).translationY(0).setStartDelay(Math.min(index, 12) * 45L)
                .setDuration(420).setInterpolator(EMPHASIZED).start();
    }

    /** Légère réduction au toucher, pour un retour tactile visible. */
    @SuppressWarnings("ClickableViewAccessibility")
    public static void pressable(View v) {
        v.setOnTouchListener((view, e) -> {
            switch (e.getActionMasked()) {
                case android.view.MotionEvent.ACTION_DOWN:
                    view.animate().scaleX(0.97f).scaleY(0.97f).setDuration(90).start();
                    break;
                case android.view.MotionEvent.ACTION_UP:
                case android.view.MotionEvent.ACTION_CANCEL:
                    view.animate().scaleX(1f).scaleY(1f).setDuration(160).setInterpolator(new DecelerateInterpolator()).start();
                    break;
            }
            return false;
        });
    }

    /** Barre d'état et de navigation aux couleurs de l'écran. */
    public static void systemBars(Activity a, int statusColor, boolean lightStatus) {
        a.getWindow().setStatusBarColor(statusColor);
        a.getWindow().setNavigationBarColor(SURFACE);
        View d = a.getWindow().getDecorView();
        int f = d.getSystemUiVisibility();
        f = lightStatus ? (f | View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR) : (f & ~View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR);
        if (Build.VERSION.SDK_INT >= 26) f |= View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR;
        d.setSystemUiVisibility(f);
    }
}
