package com.hightechniger.help_me;

import android.content.Intent;
import android.graphics.Color;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.HorizontalScrollView;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.PagerSnapHelper;
import androidx.recyclerview.widget.RecyclerView;

import com.hightechniger.help_me.ui.Blocks;
import com.hightechniger.help_me.ui.Course;
import com.hightechniger.help_me.ui.Ui;

import java.util.ArrayList;
import java.util.Calendar;
import java.util.Collections;
import java.util.List;
import java.util.Random;

/**
 * Espace « Trucs et astuces » interactif : défi des raccourcis, cartes d'astuces à faire glisser
 * (une astuce du jour différente chaque jour), et toutes les fiches d'astuces filtrables par thème.
 */
public class TipsActivity extends AppCompatActivity {

    private static final int COLOR = Color.parseColor("#D9822B");
    private Course course;
    private List<Course.Tip> deck;
    private TextView deckCounter;
    private LinearLayout tipList;
    private String filter = null;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        course = Course.get(this);
        Ui.systemBars(this, Ui.darker(COLOR, 0.8f), false);

        ScrollView scroll = new ScrollView(this);
        scroll.setBackgroundColor(Ui.BG);
        LinearLayout page = Ui.column(this);
        page.setPadding(0, 0, 0, Ui.dp(this, 32));
        page.addView(header());
        page.addView(challengeCard());
        page.addView(sectionTitle("Astuce du jour", "Faites glisser pour en découvrir d’autres"));
        page.addView(deck());
        page.addView(sectionTitle("Toutes les fiches d’astuces", null));
        page.addView(filters());
        tipList = Ui.column(this);
        tipList.setPadding(Ui.dp(this, 16), 0, Ui.dp(this, 16), 0);
        page.addView(tipList);
        scroll.addView(page);
        setContentView(scroll);
        buildList();
    }

    private View header() {
        LinearLayout h = Ui.column(this);
        h.setBackground(new android.graphics.drawable.GradientDrawable(android.graphics.drawable.GradientDrawable.Orientation.TL_BR,
                new int[]{COLOR, Ui.darker(COLOR, 0.72f)}));
        h.setPadding(Ui.dp(this, 16), Ui.dp(this, 8), Ui.dp(this, 16), Ui.dp(this, 26));
        ImageView back = Ui.icon(this, R.drawable.ic_m_prev, Color.WHITE, 24);
        back.setPadding(Ui.dp(this, 2), Ui.dp(this, 2), Ui.dp(this, 2), Ui.dp(this, 2));
        back.setLayoutParams(new LinearLayout.LayoutParams(Ui.dp(this, 40), Ui.dp(this, 40)));
        back.setContentDescription("Retour");
        back.setOnClickListener(v -> finish());
        h.addView(back);
        TextView t = Ui.title(this, "Trucs et astuces", 28);
        t.setTextColor(Color.WHITE);
        t.setPadding(0, Ui.dp(this, 8), 0, 0);
        h.addView(t);
        TextView s = Ui.text(this, course.tips.size() + " astuces du manuel, " + course.shortcuts.size()
                + " raccourcis à maîtriser et des fiches pratiques.", 15, Color.parseColor("#E6FFFFFF"), false);
        h.addView(s);
        return h;
    }

    private View challengeCard() {
        LinearLayout c = Ui.card(this, v -> startActivity(new Intent(this, ShortcutChallengeActivity.class)));
        c.setOrientation(LinearLayout.HORIZONTAL);
        c.setGravity(Gravity.CENTER_VERTICAL);
        c.setBackground(Ui.ripple(Ui.gradient(this, Color.parseColor("#2B2F3A"), Color.parseColor("#3D4456"), 20), Color.parseColor("#33FFFFFF")));
        c.addView(Ui.badge(this, R.drawable.ic_m_flame, COLOR, 52, false));
        LinearLayout t = Ui.column(this);
        t.setPadding(Ui.dp(this, 14), 0, Ui.dp(this, 8), 0);
        TextView a = Ui.text(this, "Défi des raccourcis", 18, Color.WHITE, true);
        t.addView(a);
        int best = getSharedPreferences("helpme_progress", MODE_PRIVATE).getInt("shortcut_best", 0);
        t.addView(Ui.text(this, best > 0 ? "Votre record : " + best + " points. Battez-le !" : "10 questions chronométrées. Relevez le défi !",
                14, Color.parseColor("#C9D2E3"), false));
        c.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
        c.addView(Ui.icon(this, R.drawable.ic_m_play, Color.WHITE, 28));
        c.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 16, -14, 16, 0));
        Ui.enter(c, 0);
        return c;
    }

    private View sectionTitle(String title, String sub) {
        LinearLayout l = Ui.column(this);
        l.setPadding(Ui.dp(this, 18), Ui.dp(this, 24), Ui.dp(this, 18), Ui.dp(this, 6));
        l.addView(Ui.title(this, title, 19));
        if (sub != null) l.addView(Ui.text(this, sub, 13.5f, Ui.MUTED, false));
        return l;
    }

    // ---------------------------------------------------------------- cartes à faire glisser
    private View deck() {
        deck = new ArrayList<>(course.tips);
        // astuce du jour : une première carte différente chaque jour, le reste mélangé
        int day = Calendar.getInstance().get(Calendar.DAY_OF_YEAR) + 366 * Calendar.getInstance().get(Calendar.YEAR);
        Collections.shuffle(deck, new Random(day));
        LinearLayout box = Ui.column(this);
        RecyclerView rv = new RecyclerView(this);
        rv.setLayoutManager(new LinearLayoutManager(this, LinearLayoutManager.HORIZONTAL, false));
        rv.setClipToPadding(false);
        rv.setPadding(Ui.dp(this, 16), Ui.dp(this, 4), Ui.dp(this, 16), Ui.dp(this, 8));
        new PagerSnapHelper().attachToRecyclerView(rv);
        rv.setAdapter(new DeckAdapter());
        rv.addOnScrollListener(new RecyclerView.OnScrollListener() {
            @Override public void onScrollStateChanged(@NonNull RecyclerView r, int state) {
                if (state != RecyclerView.SCROLL_STATE_IDLE) return;
                int p = ((LinearLayoutManager) r.getLayoutManager()).findFirstCompletelyVisibleItemPosition();
                if (p >= 0) deckCounter.setText((p + 1) + " / " + deck.size());
            }
        });
        box.addView(rv, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(this, 270)));
        deckCounter = Ui.text(this, "1 / " + deck.size(), 13, Ui.MUTED, true);
        deckCounter.setGravity(Gravity.CENTER);
        box.addView(deckCounter);
        return box;
    }

    private class DeckAdapter extends RecyclerView.Adapter<RecyclerView.ViewHolder> {
        @NonNull @Override
        public RecyclerView.ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            LinearLayout card = Ui.column(TipsActivity.this);
            int w = parent.getWidth() - Ui.dp(TipsActivity.this, 44);
            RecyclerView.LayoutParams lp = new RecyclerView.LayoutParams(w > 0 ? w : ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT);
            lp.setMargins(Ui.dp(TipsActivity.this, 6), Ui.dp(TipsActivity.this, 6), Ui.dp(TipsActivity.this, 6), Ui.dp(TipsActivity.this, 10));
            card.setLayoutParams(lp);
            return new RecyclerView.ViewHolder(card) {};
        }

        @Override
        public void onBindViewHolder(@NonNull RecyclerView.ViewHolder h, int position) {
            LinearLayout card = (LinearLayout) h.itemView;
            card.removeAllViews();
            Course.Tip tip = deck.get(position);
            Course.Module m = course.module(tip.moduleId);
            int color = m != null ? m.color : COLOR;
            card.setBackground(Ui.gradient(TipsActivity.this, Ui.tint(color, 0.9f), Color.WHITE, 22));
            card.setElevation(Ui.dp(TipsActivity.this, 3));
            card.setPadding(Ui.dp(TipsActivity.this, 18), Ui.dp(TipsActivity.this, 16), Ui.dp(TipsActivity.this, 18), Ui.dp(TipsActivity.this, 14));
            LinearLayout top = Ui.row(TipsActivity.this);
            top.addView(Ui.badge(TipsActivity.this, ModuleActivity.iconRes(TipsActivity.this, tip.moduleId), color, 34, false));
            TextView mt = Ui.text(TipsActivity.this, (m != null ? m.title : "") + " · " + tip.title, 13.5f, Ui.darker(color, 0.8f), true);
            mt.setPadding(Ui.dp(TipsActivity.this, 10), 0, 0, 0);
            top.addView(mt);
            card.addView(top);
            TextView x = Ui.text(TipsActivity.this, Blocks.html(tip.html), 16, Ui.TEXT, false);
            x.setLineSpacing(0, 1.28f);
            x.setPadding(0, Ui.dp(TipsActivity.this, 12), 0, 0);
            x.setMaxLines(7);
            x.setEllipsize(android.text.TextUtils.TruncateAt.END);
            card.addView(x, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1));
            Course.Lesson l = course.lesson(tip.lessonId);
            if (l != null) {
                TextView go = Ui.text(TipsActivity.this, "Voir la leçon : " + l.title, 14, color, true);
                go.setSingleLine(true);
                go.setEllipsize(android.text.TextUtils.TruncateAt.END);
                go.setPadding(0, Ui.dp(TipsActivity.this, 8), 0, 0);
                go.setOnClickListener(v -> LessonReaderActivity.open(TipsActivity.this, l.id));
                card.addView(go);
            }
        }

        @Override public int getItemCount() { return deck.size(); }
    }

    // ---------------------------------------------------------------- fiches d'astuces par thème
    private View filters() {
        HorizontalScrollView hs = new HorizontalScrollView(this);
        hs.setHorizontalScrollBarEnabled(false);
        LinearLayout row = Ui.row(this);
        row.setPadding(Ui.dp(this, 16), Ui.dp(this, 4), Ui.dp(this, 16), Ui.dp(this, 4));
        List<String[]> opts = new ArrayList<>();
        opts.add(new String[]{null, "Toutes"});
        for (Course.Module m : course.modules) if (!tipLessons(m).isEmpty()) opts.add(new String[]{m.id, m.title});
        List<TextView> chips = new ArrayList<>();
        for (String[] o : opts) {
            TextView c = Ui.text(this, o[1], 14, Ui.TEXT, true);
            c.setPadding(Ui.dp(this, 14), Ui.dp(this, 8), Ui.dp(this, 14), Ui.dp(this, 8));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            lp.setMarginEnd(Ui.dp(this, 8));
            c.setLayoutParams(lp);
            c.setOnClickListener(v -> { filter = o[0]; styleChips(chips, opts); buildList(); });
            chips.add(c);
            row.addView(c);
        }
        styleChips(chips, opts);
        hs.addView(row);
        return hs;
    }

    private void styleChips(List<TextView> chips, List<String[]> opts) {
        for (int i = 0; i < chips.size(); i++) {
            boolean on = (filter == null && opts.get(i)[0] == null) || (filter != null && filter.equals(opts.get(i)[0]));
            chips.get(i).setBackground(on ? Ui.rounded(this, COLOR, 18) : Ui.outlined(this, Color.WHITE, Ui.BORDER, 18));
            chips.get(i).setTextColor(on ? Color.WHITE : Ui.TEXT);
        }
    }

    private List<Course.Lesson> tipLessons(Course.Module m) {
        List<Course.Lesson> out = new ArrayList<>();
        for (Course.Lesson l : m.lessons) if ("astuce".equals(l.kind) || "astuces".equals(m.id)) out.add(l);
        return out;
    }

    private void buildList() {
        tipList.removeAllViews();
        int i = 0;
        for (Course.Module m : course.modules) {
            if (filter != null && !filter.equals(m.id)) continue;
            for (Course.Lesson l : tipLessons(m)) {
                LinearLayout card = Ui.card(this, v -> LessonReaderActivity.open(this, l.id));
                card.setOrientation(LinearLayout.HORIZONTAL);
                card.setGravity(Gravity.CENTER_VERTICAL);
                card.addView(Ui.badge(this, ModuleActivity.iconRes(this, m.id), m.color, 40, true));
                LinearLayout t = Ui.column(this);
                t.setPadding(Ui.dp(this, 12), 0, Ui.dp(this, 6), 0);
                t.addView(Ui.text(this, m.title, 12.5f, m.color, true));
                t.addView(Ui.title(this, l.title, 15.5f));
                card.addView(t, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1));
                card.addView(Ui.icon(this, R.drawable.ic_m_chevron, Ui.MUTED, 22));
                card.setLayoutParams(Ui.lpMargins(this, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, 0, 10, 0, 0));
                tipList.addView(card);
                Ui.enter(card, i++);
            }
        }
    }
}
