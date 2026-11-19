package com.hightechniger.help_me.demo;

/**
 * Surface de dessin minimale, indépendante d'Android.
 * Les scènes de démonstration dessinent uniquement avec cette interface :
 * dans l'application elle est branchée sur un Canvas Android (AndroidGfx),
 * et l'outil d'aperçu la branche sur Java2D pour produire une vidéo de contrôle.
 * Coordonnées logiques : 1280 × 720.
 */
public interface Gfx {
    int LEFT = 0, CENTER = 1, RIGHT = 2;

    void rect(float x, float y, float w, float h, int argb);

    void roundRect(float x, float y, float w, float h, float r, int argb);

    void strokeRoundRect(float x, float y, float w, float h, float r, float stroke, int argb);

    void gradientRect(float x, float y, float w, float h, int argbTop, int argbBottom);

    void line(float x1, float y1, float x2, float y2, float stroke, int argb);

    void circle(float cx, float cy, float r, int argb);

    void strokeCircle(float cx, float cy, float r, float stroke, int argb);

    void polygon(float[] xy, int argb);

    /** y = ligne de base du texte. */
    void text(String s, float x, float y, float size, int argb, boolean bold, int align);

    float textWidth(String s, float size, boolean bold);

    void save();

    void restore();

    void translate(float dx, float dy);

    void scale(float s, float px, float py);

    void clip(float x, float y, float w, float h);
}
