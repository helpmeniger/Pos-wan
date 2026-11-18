package com.hightechniger.help_me.demo;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Outline;
import android.os.SystemClock;
import android.view.View;
import android.view.ViewOutlineProvider;

/** Joue une DemoScene à 60 images/s, mise à l'échelle et centrée (format 16:9). */
public class DemoView extends View {

    public interface Listener { void onTime(float t, boolean playing); }

    private final DemoScene scene;
    private final AndroidGfx gfx = new AndroidGfx();
    private float time = 0f;
    private boolean playing = true;
    private long lastFrame = 0;
    private Listener listener;

    public DemoView(Context ctx, DemoScene scene) {
        super(ctx);
        this.scene = scene;
        setOutlineProvider(new ViewOutlineProvider() {
            @Override public void getOutline(View v, Outline o) {
                o.setRoundRect(0, 0, v.getWidth(), v.getHeight(), 18 * getResources().getDisplayMetrics().density);
            }
        });
        setClipToOutline(true);
        setElevation(8 * ctx.getResources().getDisplayMetrics().density);
    }

    public void setListener(Listener l) { listener = l; }

    public DemoScene getScene() { return scene; }

    public boolean isPlaying() { return playing; }

    public float getTime() { return time; }

    public void setPlaying(boolean p) {
        playing = p;
        lastFrame = 0;
        if (playing && time >= scene.duration()) time = 0;
        invalidate();
        notifyListener();
    }

    public void seek(float t) {
        time = Math.max(0, Math.min(scene.duration(), t));
        lastFrame = 0;
        invalidate();
        notifyListener();
    }

    @Override protected void onMeasure(int wSpec, int hSpec) {
        int w = MeasureSpec.getSize(wSpec);
        setMeasuredDimension(w, Math.round(w * DemoScene.H / DemoScene.W));
    }

    @Override protected void onDraw(Canvas canvas) {
        if (playing) {
            long now = SystemClock.uptimeMillis();
            if (lastFrame != 0) time += (now - lastFrame) / 1000f;
            lastFrame = now;
            if (time >= scene.duration()) { time = scene.duration(); playing = false; }
        }
        float s = Math.min(getWidth() / DemoScene.W, getHeight() / DemoScene.H);
        canvas.save();
        canvas.translate((getWidth() - DemoScene.W * s) / 2f, (getHeight() - DemoScene.H * s) / 2f);
        canvas.scale(s, s);
        gfx.setCanvas(canvas);
        scene.draw(gfx, time);
        canvas.restore();
        notifyListener();
        if (playing) postInvalidateOnAnimation();
    }

    private void notifyListener() { if (listener != null) listener.onTime(time, playing); }
}
