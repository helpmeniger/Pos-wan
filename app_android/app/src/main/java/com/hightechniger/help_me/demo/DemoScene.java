package com.hightechniger.help_me.demo;

/** Une démonstration animée, dessinée en code (remplace une vidéo). */
public abstract class DemoScene {

    public static final float W = 1280f, H = 720f;

    /** Durée totale en secondes. */
    public abstract float duration();

    /** Titre affiché dans l'écran de démonstration. */
    public abstract String title();

    /** Débuts des étapes (secondes), pour les boutons Précédent / Suivant. */
    public abstract float[] steps();

    /** Sous-titre (explication) à l'instant t. */
    public abstract String caption(float t);

    /** Dessine l'image à l'instant t (secondes). */
    public abstract void draw(Gfx g, float t);

    // ------------------------------------------------------------- outils d'animation
    public static float clamp(float v) {
        return v < 0 ? 0 : (v > 1 ? 1 : v);
    }

    /** Progression 0..1 entre a et b. */
    public static float seg(float t, float a, float b) {
        return clamp((t - a) / (b - a));
    }

    public static float ease(float p) {
        return p < .5f ? 4 * p * p * p : 1 - (float) Math.pow(-2 * p + 2, 3) / 2;
    }

    public static float lerp(float a, float b, float p) {
        return a + (b - a) * p;
    }

    public static int alpha(int argb, float a) {
        int al = Math.round(((argb >>> 24) & 0xFF) * clamp(a));
        return (al << 24) | (argb & 0x00FFFFFF);
    }

    /** Texte tapé progressivement entre a et b. */
    public static String typed(String full, float t, float a, float b) {
        int n = Math.round(full.length() * seg(t, a, b));
        return full.substring(0, Math.max(0, Math.min(full.length(), n)));
    }
}
