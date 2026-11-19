package com.hightechniger.help_me.demo;

import android.graphics.Canvas;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.Shader;
import android.graphics.Typeface;

/** Implémentation Android de Gfx (dessin direct sur un Canvas). */
public class AndroidGfx implements Gfx {
    private Canvas c;
    private final Paint fill = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint stroke = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint text = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.SUBPIXEL_TEXT_FLAG);
    private final Path path = new Path();
    private final Typeface regular = Typeface.create("sans-serif", Typeface.NORMAL);
    private final Typeface bold = Typeface.create("sans-serif", Typeface.BOLD);

    public AndroidGfx() {
        fill.setStyle(Paint.Style.FILL);
        stroke.setStyle(Paint.Style.STROKE);
        stroke.setStrokeCap(Paint.Cap.ROUND);
        stroke.setStrokeJoin(Paint.Join.ROUND);
    }

    public void setCanvas(Canvas canvas) { this.c = canvas; }

    @Override public void rect(float x, float y, float w, float h, int argb) { fill.setShader(null); fill.setColor(argb); c.drawRect(x, y, x + w, y + h, fill); }

    @Override public void roundRect(float x, float y, float w, float h, float r, int argb) { fill.setShader(null); fill.setColor(argb); c.drawRoundRect(x, y, x + w, y + h, r, r, fill); }

    @Override public void strokeRoundRect(float x, float y, float w, float h, float r, float s, int argb) {
        stroke.setColor(argb); stroke.setStrokeWidth(s); c.drawRoundRect(x, y, x + w, y + h, r, r, stroke); }

    @Override public void gradientRect(float x, float y, float w, float h, int top, int bottom) {
        fill.setShader(new LinearGradient(x, y, x, y + h, top, bottom, Shader.TileMode.CLAMP));
        c.drawRect(x, y, x + w, y + h, fill);
        fill.setShader(null); }

    @Override public void line(float x1, float y1, float x2, float y2, float s, int argb) {
        stroke.setColor(argb); stroke.setStrokeWidth(s); c.drawLine(x1, y1, x2, y2, stroke); }

    @Override public void circle(float cx, float cy, float r, int argb) { fill.setShader(null); fill.setColor(argb); c.drawCircle(cx, cy, r, fill); }

    @Override public void strokeCircle(float cx, float cy, float r, float s, int argb) {
        stroke.setColor(argb); stroke.setStrokeWidth(s); c.drawCircle(cx, cy, r, stroke); }

    @Override public void polygon(float[] xy, int argb) {
        path.reset(); path.moveTo(xy[0], xy[1]);
        for (int i = 2; i < xy.length; i += 2) path.lineTo(xy[i], xy[i + 1]);
        path.close(); fill.setShader(null); fill.setColor(argb); c.drawPath(path, fill); }

    @Override public void text(String s, float x, float y, float size, int argb, boolean b, int align) {
        text.setTypeface(b ? bold : regular); text.setTextSize(size); text.setColor(argb);
        text.setTextAlign(align == CENTER ? Paint.Align.CENTER : align == RIGHT ? Paint.Align.RIGHT : Paint.Align.LEFT);
        c.drawText(s, x, y, text); }

    @Override public float textWidth(String s, float size, boolean b) {
        text.setTypeface(b ? bold : regular); text.setTextSize(size); return text.measureText(s); }

    @Override public void save() { c.save(); }

    @Override public void restore() { c.restore(); }

    @Override public void translate(float dx, float dy) { c.translate(dx, dy); }

    @Override public void scale(float s, float px, float py) { c.scale(s, s, px, py); }

    @Override public void clip(float x, float y, float w, float h) { c.clipRect(x, y, x + w, y + h); }
}
