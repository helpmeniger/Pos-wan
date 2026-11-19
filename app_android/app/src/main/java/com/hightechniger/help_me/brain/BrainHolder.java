package com.hightechniger.help_me.brain;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Handler;
import android.os.Looper;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.Executors;

/**
 * Charge une seule fois le moteur (assets/brain.json) en arrière-plan et le partage dans l'application.
 * Les avis « Utile / Pas utile » sont conservés dans les préférences de l'appareil.
 */
public final class BrainHolder {

    public interface Callback {
        void onReady(Brain brain);

        void onError(Exception e);
    }

    private static Brain brain;
    private static Exception error;
    private static boolean loading;
    private static final List<Callback> waiting = new ArrayList<>();

    private BrainHolder() {}

    /** Lance le chargement (à appeler tôt, par exemple à l'ouverture de l'accueil). */
    public static void preload(Context c) {
        get(c, null);
    }

    public static synchronized void get(Context context, Callback cb) {
        Handler main = new Handler(Looper.getMainLooper());
        if (brain != null) {
            if (cb != null) main.post(() -> cb.onReady(brain));
            return;
        }
        if (cb != null) waiting.add(cb);
        if (loading) return;
        loading = true;
        error = null;
        Context app = context.getApplicationContext();
        Executors.newSingleThreadExecutor().execute(() -> {
            Brain b = null;
            Exception err = null;
            try {
                SharedPreferences prefs = app.getSharedPreferences("helpme_assistant", Context.MODE_PRIVATE);
                Brain.Memory mem = new Brain.Memory() {
                    @Override public String get(String key) { return prefs.getString(key, null); }

                    @Override public void put(String key, String value) { prefs.edit().putString(key, value).apply(); }
                };
                b = Brain.fromJson(readAsset(app, "brain.json"), mem);
            } catch (Exception e) {
                err = e;
            }
            final Brain fb = b;
            final Exception fe = err;
            main.post(() -> {
                List<Callback> l;
                synchronized (BrainHolder.class) {
                    brain = fb;
                    error = fe;
                    loading = false;
                    l = new ArrayList<>(waiting);
                    waiting.clear();
                }
                for (Callback c : l) {
                    if (fb != null) c.onReady(fb); else c.onError(fe);
                }
            });
        });
    }

    private static String readAsset(Context c, String name) throws Exception {
        try (InputStream is = c.getAssets().open(name)) {
            ByteArrayOutputStream out = new ByteArrayOutputStream(1 << 20);
            byte[] buf = new byte[16384];
            int n;
            while ((n = is.read(buf)) > 0) out.write(buf, 0, n);
            return new String(out.toByteArray(), StandardCharsets.UTF_8);
        }
    }
}
