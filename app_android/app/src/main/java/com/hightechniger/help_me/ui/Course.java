package com.hightechniger.help_me.ui;

import android.content.Context;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/**
 * Contenu des cours (assets/course.json, produit par app_update/tools/export_cours.py à partir du manuel) :
 * modules -> sections -> leçons -> blocs.
 */
public final class Course {

    public static final class Module {
        public String id, title, subtitle, icon;
        public int color;
        public final List<Section> sections = new ArrayList<>();
        public final List<Lesson> lessons = new ArrayList<>();   // toutes les leçons, dans l'ordre
    }

    public static final class Section {
        public String title;
        public final List<Lesson> lessons = new ArrayList<>();
    }

    public static final class Lesson {
        public String id, title, summary, code, kind;
        public final List<String> codes = new ArrayList<>();
        public int minutes, exercises, index;
        public Module module;
        public Section section;
        public JSONArray blocks;
        public JSONObject action;

        public boolean isManual() { return "Leçons du manuel".equals(section.title); }
    }

    /** Astuce courte (encadré d'une leçon). */
    public static final class Tip {
        public String moduleId, lessonId, title, html;
    }

    /** Raccourci clavier (pour le défi des raccourcis). */
    public static final class Shortcut {
        public String keys, action, ctx, lessonId;
    }

    private static Course instance;
    public final List<Tip> tips = new ArrayList<>();
    public final List<Shortcut> shortcuts = new ArrayList<>();
    public final List<Module> modules = new ArrayList<>();
    private final Map<String, Lesson> byId = new HashMap<>();
    private final Map<String, Module> moduleById = new HashMap<>();

    public static synchronized Course get(Context c) {
        if (instance == null) {
            try {
                instance = new Course(read(c.getApplicationContext(), "course.json"));
            } catch (Exception e) {
                instance = new Course();
            }
        }
        return instance;
    }

    private Course() {}

    private Course(String json) throws Exception {
        JSONObject root = new JSONObject(json);
        JSONArray mods = root.getJSONArray("modules");
        for (int i = 0; i < mods.length(); i++) {
            JSONObject mo = mods.getJSONObject(i);
            Module m = new Module();
            m.id = mo.getString("id");
            m.title = mo.getString("title");
            m.subtitle = mo.optString("subtitle");
            m.icon = mo.optString("icon");
            m.color = Ui.color(mo.optString("color"), Ui.BRAND);
            JSONArray secs = mo.getJSONArray("sections");
            for (int s = 0; s < secs.length(); s++) {
                JSONObject so = secs.getJSONObject(s);
                Section sec = new Section();
                sec.title = so.getString("title");
                JSONArray ls = so.getJSONArray("lessons");
                for (int k = 0; k < ls.length(); k++) {
                    JSONObject lo = ls.getJSONObject(k);
                    Lesson l = new Lesson();
                    l.id = lo.getString("id");
                    l.title = lo.getString("title");
                    l.summary = lo.optString("summary");
                    l.code = lo.has("code") ? lo.getString("code") : null;
                    JSONArray cs = lo.optJSONArray("codes");
                    if (cs != null) for (int q = 0; q < cs.length(); q++) l.codes.add(cs.getString(q));
                    else if (l.code != null) l.codes.add(l.code);
                    l.kind = lo.optString("kind", "lecon");
                    l.minutes = lo.optInt("minutes", 2);
                    l.exercises = lo.optInt("exercises", 0);
                    l.blocks = lo.optJSONArray("blocks");
                    if (l.blocks == null) l.blocks = new JSONArray();
                    l.action = lo.optJSONObject("action");
                    l.module = m;
                    l.section = sec;
                    l.index = m.lessons.size();
                    sec.lessons.add(l);
                    m.lessons.add(l);
                    byId.put(l.id, l);
                }
                m.sections.add(sec);
            }
            modules.add(m);
            moduleById.put(m.id, m);
        }
        JSONArray ts = root.optJSONArray("tips");
        if (ts != null) for (int i = 0; i < ts.length(); i++) {
            JSONObject o = ts.getJSONObject(i);
            Tip t = new Tip();
            t.moduleId = o.optString("module");
            t.lessonId = o.optString("lesson");
            t.title = o.optString("title");
            t.html = o.optString("x");
            tips.add(t);
        }
        JSONArray sc = root.optJSONArray("shortcuts");
        if (sc != null) for (int i = 0; i < sc.length(); i++) {
            JSONObject o = sc.getJSONObject(i);
            Shortcut k = new Shortcut();
            k.keys = o.optString("keys");
            k.action = o.optString("action");
            k.ctx = o.optString("ctx");
            k.lessonId = o.optString("lesson");
            shortcuts.add(k);
        }
    }

    public Module module(String id) { return moduleById.get(id); }

    public Lesson lesson(String id) { return id == null ? null : byId.get(id); }

    /** Leçon associée à un code-barres du manuel (pour la recherche et le scan). */
    public Lesson byCode(String code) {
        for (Module m : modules) for (Lesson l : m.lessons) if (l.codes.contains(code)) return l;
        return null;
    }

    public List<Lesson> all() {
        List<Lesson> out = new ArrayList<>();
        for (Module m : modules) out.addAll(m.lessons);
        return Collections.unmodifiableList(out);
    }

    /** Recherche simple (titre, résumé, nom de section), sans accents ni majuscules. */
    public List<Lesson> search(String q) {
        String f = fold(q);
        List<Lesson> out = new ArrayList<>();
        if (f.trim().isEmpty()) return out;
        String[] words = f.trim().split("\\s+");
        for (Lesson l : all()) {
            String hay = fold(l.title + " " + l.summary + " " + l.module.title);
            boolean ok = true;
            for (String w : words) if (!hay.contains(w)) { ok = false; break; }
            if (ok) out.add(l);
        }
        return out;
    }

    public static String fold(String s) {
        if (s == null) return "";
        return java.text.Normalizer.normalize(s.toLowerCase(), java.text.Normalizer.Form.NFD)
                .replaceAll("\\p{M}+", "").replace('’', '\'');
    }

    static String read(Context c, String name) throws Exception {
        try (InputStream is = c.getAssets().open(name)) {
            ByteArrayOutputStream out = new ByteArrayOutputStream(1 << 19);
            byte[] buf = new byte[16384];
            int n;
            while ((n = is.read(buf)) > 0) out.write(buf, 0, n);
            return new String(out.toByteArray(), StandardCharsets.UTF_8);
        }
    }
}
