package com.hightechniger.help_me;

import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.net.Uri;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.WindowManager;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.MediaController;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.VideoView;

import androidx.appcompat.app.AppCompatActivity;

import com.hightechniger.help_me.ui.Ui;

/** Lecteur plein écran des vidéos d'explication (res/raw/video_CODE.mp4). */
public class VideoPlayerActivity extends AppCompatActivity {

    public static final String EXTRA_RES = "res", EXTRA_TITLE = "title";

    /** Ressource vidéo associée à un code-barres du manuel, ou 0. */
    public static int videoFor(Context c, String code) {
        return code == null ? 0 : c.getResources().getIdentifier("video_" + code, "raw", c.getPackageName());
    }

    public static void open(Context c, int res, String title) {
        c.startActivity(new Intent(c, VideoPlayerActivity.class).putExtra(EXTRA_RES, res).putExtra(EXTRA_TITLE, title));
    }

    private VideoView video;
    private int position;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        getWindow().setStatusBarColor(Color.BLACK);
        getWindow().setNavigationBarColor(Color.BLACK);
        int res = getIntent().getIntExtra(EXTRA_RES, 0);
        if (res == 0) { finish(); return; }
        position = savedInstanceState != null ? savedInstanceState.getInt("pos", 0) : 0;

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.BLACK);
        video = new VideoView(this);
        root.addView(video, new FrameLayout.LayoutParams(FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.WRAP_CONTENT, Gravity.CENTER));
        ProgressBar loading = new ProgressBar(this);
        root.addView(loading, new FrameLayout.LayoutParams(Ui.dp(this, 44), Ui.dp(this, 44), Gravity.CENTER));

        ImageView close = Ui.icon(this, R.drawable.ic_m_prev, Color.WHITE, 24);
        int p = Ui.dp(this, 10);
        close.setPadding(p, p, p, p);
        close.setBackground(Ui.circle(Color.parseColor("#66000000")));
        close.setContentDescription("Fermer la vidéo");
        close.setOnClickListener(v -> finish());
        FrameLayout.LayoutParams cl = new FrameLayout.LayoutParams(Ui.dp(this, 44), Ui.dp(this, 44), Gravity.TOP | Gravity.START);
        cl.setMargins(Ui.dp(this, 14), Ui.dp(this, 14), 0, 0);
        root.addView(close, cl);

        String title = getIntent().getStringExtra(EXTRA_TITLE);
        if (title != null) {
            TextView t = Ui.text(this, title, 15, Color.WHITE, true);
            t.setShadowLayer(6, 0, 1, Color.BLACK);
            FrameLayout.LayoutParams tl = new FrameLayout.LayoutParams(FrameLayout.LayoutParams.WRAP_CONTENT,
                    FrameLayout.LayoutParams.WRAP_CONTENT, Gravity.TOP | Gravity.START);
            tl.setMargins(Ui.dp(this, 68), Ui.dp(this, 24), Ui.dp(this, 16), 0);
            root.addView(t, tl);
        }
        setContentView(root);

        MediaController mc = new MediaController(this);
        mc.setAnchorView(video);
        video.setMediaController(mc);
        video.setVideoURI(Uri.parse("android.resource://" + getPackageName() + "/" + res));
        video.setOnPreparedListener(mp -> {
            loading.setVisibility(View.GONE);
            video.seekTo(position);
            video.start();
        });
        video.setOnCompletionListener(mp -> mc.show(0));
        video.setOnErrorListener((mp, what, extra) -> {
            loading.setVisibility(View.GONE);
            android.widget.Toast.makeText(this, "Impossible de lire la vidéo sur ce téléphone.", android.widget.Toast.LENGTH_LONG).show();
            finish();
            return true;
        });
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (video != null) {
            position = video.getCurrentPosition();
            video.pause();
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        out.putInt("pos", video != null ? video.getCurrentPosition() : position);
    }
}
