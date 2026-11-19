package com.hightechniger.help_me.demo;

import java.util.ArrayList;
import java.util.List;

/**
 * « Créer et organiser » : démonstration animée qui remplace la vidéo vid5.mp4 (17,4 Mo).
 * Contenu fidèle au manuel (Partie 2, chapitre 5 et exercices 4 et 8) :
 * créer un dossier, le nommer, l'ouvrir, créer des sous-dossiers, renommer (F2),
 * ranger par glisser-déposer, supprimer (Suppr) et restaurer depuis la Corbeille.
 */
public class CreerOrganiserScene extends DemoScene {

    // ------------------------------------------------------------------ couleurs
    private static final int WHITE = 0xFFFFFFFF, INK = 0xFF1C2433, MUTED = 0xFF5B6678, LINE = 0xFFD9DEE6,
            BLUE = 0xFF0B6CD8, SEL = 0xFFCCE4F7, SEL_LINE = 0xFF7EB4EA, FOLDER = 0xFFF6C343, FOLDER_D = 0xFFE0A800,
            RED = 0xFFE81123, PANEL = 0xFFF7F9FC, SHADOW = 0x33000000;

    // ------------------------------------------------------------------ géométrie
    private static final float MX = 600, MY = 290, MW = 270;            // menu contextuel du Bureau
    private static final String[] MENU = {"Afficher", "Trier par", "Actualiser", "-", "Nouveau", "-",
            "Paramètres d’affichage", "Personnaliser"};
    private static final String[] SUB = {"Dossier", "Raccourci", "Document Microsoft Word",
            "Document texte", "Feuille de calcul Excel"};
    private static final float SW = 300;
    private static final float WX = 250, WY = 60, WW = 820, WH = 560;  // fenêtre de l'Explorateur
    private static final float CX0 = WX + 200, CY0 = WY + 150, CELL_W = 124, CELL_H = 118;
    private static final float DESK_X = 26, DESK_Y = 18, DESK_STEP = 112;

    private final float[][] cursorKeys;
    private final float[][] clicks;            // {temps, type} 1 = clic, 2 = clic droit, 3 = double-clic

    private static final float[] STEPS = {0f, 5.5f, 10.5f, 15.5f, 19.5f, 25.6f, 30f, 36.3f, 41.3f, 47.2f, 58.3f};
    private static final String[] CAPTIONS = {
            "Bien ranger ses fichiers, c’est créer des dossiers. Commençons sur le Bureau.",
            "Faites un clic droit sur une zone vide du Bureau.",
            "Le nouveau dossier apparaît : tapez son nom, puis appuyez sur Entrée.",
            "Double-cliquez sur le dossier pour l’ouvrir dans l’Explorateur de fichiers.",
            "Plus rapide : Ctrl + Maj + N crée un nouveau dossier. Nommez-le « Drapeau du Niger ».",
            "Créez de la même façon le dossier « Personnel ».",
            "Pour renommer : sélectionnez le dossier, appuyez sur F2 et tapez le nouveau nom.",
            "Pour ranger un fichier, faites-le glisser sur un dossier, puis relâchez.",
            "Pour supprimer : sélectionnez l’élément et appuyez sur Suppr. Il part dans la Corbeille.",
            "Une erreur ? Ouvrez la Corbeille, sélectionnez l’élément et cliquez sur Restaurer.",
            "À retenir"};

    public CreerOrganiserScene() {
        float folderDeskX = DESK_X + 48, folderDeskY = DESK_Y + 2 * DESK_STEP + 30;
        float[] nouveau = menuItemCenter(4), dossier = subItemCenter(0);
        float[] personnel = itemCenter(1), flag = itemCenter(2), drapeau = itemCenter(0), brouillon = itemCenter(2);
        cursorKeys = new float[][]{
                {0f, 900, 520}, {5.6f, 900, 520}, {6.6f, MX + 6, MY + 4},
                {8.0f, MX + 6, MY + 4}, {8.7f, nouveau[0], nouveau[1]}, {9.0f, nouveau[0] + 150, nouveau[1]},
                {9.8f, dossier[0], dossier[1]}, {10.6f, dossier[0], dossier[1]}, {11.3f, 560, 470},
                {15.6f, 560, 470}, {16.8f, folderDeskX, folderDeskY}, {18.3f, folderDeskX, folderDeskY},
                {19.2f, 980, 560}, {30.0f, 980, 560}, {31.0f, personnel[0], personnel[1]},
                {36.4f, personnel[0], personnel[1]}, {37.3f, flag[0], flag[1]}, {37.8f, flag[0], flag[1]},
                {40.3f, drapeau[0], drapeau[1]}, {41.4f, drapeau[0], drapeau[1]}, {42.5f, brouillon[0], brouillon[1]},
                {47.2f, brouillon[0], brouillon[1]}, {48.1f, WX + WW - 22, WY + 18}, {49.3f, WX + WW - 22, WY + 18},
                {50.3f, DESK_X + 48, DESK_Y + 30}, {51.6f, DESK_X + 48, DESK_Y + 30}, {52.6f, CX0 + 48, CY0 + 40},
                {53.1f, CX0 + 48, CY0 + 40}, {53.9f, WX + 330, WY + 103}, {56.0f, WX + 330, WY + 103},
                {57.5f, 980, 560}, {70f, 980, 560}};
        clicks = new float[][]{{7.0f, 2}, {10.2f, 1}, {17.3f, 3}, {31.2f, 1}, {37.6f, 1}, {42.8f, 1},
                {48.4f, 1}, {50.6f, 3}, {52.9f, 1}, {54.2f, 1}};
    }

    @Override public float duration() { return 64.5f; }

    @Override public String title() { return "Créer et organiser ses dossiers"; }

    @Override public float[] steps() { return STEPS; }

    @Override public String caption(float t) {
        int k = 0;
        for (int i = 0; i < STEPS.length; i++) if (t >= STEPS[i]) k = i;
        if (k == 1 && t >= 8.0f) return "Choisissez Nouveau, puis Dossier.";
        return CAPTIONS[k];
    }

    // ================================================================== dessin
    @Override
    public void draw(Gfx g, float t) {
        drawDesktop(g, t);
        drawContextMenu(g, t);

        // Fenêtre « Mes exercices »
        float open = ease(seg(t, 17.6f, 18.2f)) * (1 - ease(seg(t, 48.5f, 49.0f)));
        if (open > 0) drawWindow(g, t, open, false);
        // Fenêtre « Corbeille »
        float bin = ease(seg(t, 50.8f, 51.4f)) * (1 - ease(seg(t, 57.4f, 57.9f)));
        if (bin > 0) drawWindow(g, t, bin, true);

        drawKeyBadge(g, t, 14.4f, 15.0f, new String[]{"Entrée"});
        drawKeyBadge(g, t, 20.0f, 21.3f, new String[]{"Ctrl", "Maj", "N"});
        drawKeyBadge(g, t, 24.2f, 24.8f, new String[]{"Entrée"});
        drawKeyBadge(g, t, 26.0f, 27.0f, new String[]{"Ctrl", "Maj", "N"});
        drawKeyBadge(g, t, 28.8f, 29.3f, new String[]{"Entrée"});
        drawKeyBadge(g, t, 32.0f, 33.0f, new String[]{"F2"});
        drawKeyBadge(g, t, 35.1f, 35.6f, new String[]{"Entrée"});
        drawKeyBadge(g, t, 43.5f, 44.5f, new String[]{"Suppr"});

        drawToast(g, t, 44.4f, 47.0f, "« Brouillon.txt » a été déplacé dans la Corbeille");
        drawToast(g, t, 54.8f, 57.2f, "« Brouillon.txt » a été restauré dans « Mes exercices »");

        drawDrag(g, t);
        if (t < 58.0f) drawCursor(g, t);
        drawSummary(g, t);
    }

    // ------------------------------------------------------------------ Bureau
    private void drawDesktop(Gfx g, float t) {
        g.gradientRect(0, 0, W, H, 0xFF2F7DE1, 0xFF0B2E6B);
        g.circle(980, 760, 520, 0x2297C3FF);
        g.circle(1120, 820, 430, 0x22BFD9FF);
        // icônes du Bureau
        boolean binFull = t >= 44.4f && t < 54.8f;
        deskIcon(g, 0, "Corbeille", binFull ? 2 : 1, false, null, false, 1f);
        deskIcon(g, 1, "Ce PC", 3, false, null, false, 1f);
        if (t >= 10.5f) {
            boolean editing = t < 15.0f;
            String label = editing ? (t < 11.3f ? "Nouveau dossier" : typed("Mes exercices", t, 11.3f, 14.2f)) : "Mes exercices";
            boolean fresh = editing && t < 11.3f;
            boolean selected = (t >= 15.0f && t < 18.3f) || editing;
            float pop = ease(seg(t, 10.5f, 10.9f));
            deskIcon(g, 2, label, 0, selected, editing ? label : null, fresh, pop);
        }
        // barre des tâches
        g.rect(0, H - 48, W, 48, 0xF0EEF2F8);
        float cx = W / 2 - 110;
        winLogo(g, cx, H - 36, 24);
        g.roundRect(cx + 40, H - 37, 26, 26, 13, WHITE);
        g.strokeCircle(cx + 51, H - 26, 6, 1.6f, MUTED);
        g.line(cx + 55, H - 22, cx + 59, H - 18, 1.6f, MUTED);
        folderIcon(g, cx + 80, H - 38, 30, false);
        g.roundRect(cx + 124, H - 37, 26, 26, 6, 0xFF0E8A7E);
        g.text("e", cx + 137, H - 18, 18, WHITE, true, Gfx.CENTER);
        g.roundRect(cx + 164, H - 37, 26, 26, 6, 0xFF2B579A);
        g.text("W", cx + 177, H - 18, 15, WHITE, true, Gfx.CENTER);
        g.text("14:35", W - 20, H - 28, 13, INK, false, Gfx.RIGHT);
        g.text("18/11/2026", W - 20, H - 11, 13, INK, false, Gfx.RIGHT);
    }

    private void deskIcon(Gfx g, int slot, String label, int kind, boolean selected, String editText, boolean fresh, float pop) {
        float x = DESK_X, y = DESK_Y + slot * DESK_STEP;
        g.save();
        g.scale(lerp(.6f, 1f, pop), x + 48, y + 40);
        if (selected && editText == null) g.roundRect(x, y - 4, 96, 104, 6, 0x553D8FE0);
        float ix = x + 20, iy = y + 2;
        if (kind == 0) folderIcon(g, ix, iy, 56, false);
        else if (kind == 1 || kind == 2) binIcon(g, ix + 6, iy, 44, kind == 2);
        else pcIcon(g, ix, iy + 4, 56);
        if (editText != null) labelEditor(g, x + 48, y + 80, editText, fresh, 13, true);
        else wrapLabel(g, label, x + 48, y + 80, 13, WHITE, 92);
        g.restore();
    }

    // ------------------------------------------------------------------ menu contextuel
    private float menuItemY(int i) {
        float y = MY + 6;
        for (int k = 0; k < i; k++) y += MENU[k].equals("-") ? 11 : 34;
        return y;
    }

    private float[] menuItemCenter(int i) {
        return new float[]{MX + 70, menuItemY(i) + 17};
    }

    private float[] subItemCenter(int i) {
        return new float[]{MX + MW - 6 + 70, menuItemY(4) + 6 + i * 34 + 17};
    }

    private void drawContextMenu(Gfx g, float t) {
        float a = seg(t, 7.0f, 7.15f) * (1 - seg(t, 10.3f, 10.45f));
        if (a <= 0) return;
        float h = menuItemY(MENU.length) - MY + 6;
        shadowBox(g, MX, MY, MW, h, 8, a);
        for (int i = 0; i < MENU.length; i++) {
            float y = menuItemY(i);
            if (MENU[i].equals("-")) {
                g.line(MX + 10, y + 5, MX + MW - 10, y + 5, 1, alpha(LINE, a));
                continue;
            }
            boolean hover = i == 4 && t >= 8.6f;
            if (hover) g.roundRect(MX + 4, y, MW - 8, 32, 5, alpha(0xFFE5EDF8, a));
            g.text(MENU[i], MX + 40, y + 22, 15, alpha(INK, a), false, Gfx.LEFT);
            if (i <= 1 || i == 4) g.text("›", MX + MW - 18, y + 22, 18, alpha(MUTED, a), false, Gfx.RIGHT);
        }
        folderIcon(g, MX + 12, menuItemY(4) + 8, 18, false);
        float sa = a * seg(t, 8.8f, 8.95f);
        if (sa > 0) {
            float sx = MX + MW - 6, sy = menuItemY(4);
            shadowBox(g, sx, sy, SW, SUB.length * 34 + 12, 8, sa);
            for (int i = 0; i < SUB.length; i++) {
                float y = sy + 6 + i * 34;
                if (i == 0 && t >= 9.8f) g.roundRect(sx + 4, y, SW - 8, 32, 5, alpha(0xFFE5EDF8, sa));
                if (i == 0) folderIcon(g, sx + 12, y + 8, 18, false);
                g.text(SUB[i], sx + 40, y + 22, 15, alpha(INK, sa), false, Gfx.LEFT);
            }
        }
    }

    // ------------------------------------------------------------------ fenêtres
    private static class Item {
        String name; int kind; String edit; boolean fresh, selected, drop; float alpha = 1;

        Item(String n, int k) { name = n; kind = k; }
    }

    private float[] itemCenter(int i) {
        return new float[]{CX0 + (i % 5) * CELL_W + 48, CY0 + (i / 5) * CELL_H + 40};
    }

    private List<Item> folderItems(float t) {
        List<Item> l = new ArrayList<>();
        if (t >= 20.6f) {
            Item d = new Item("Drapeau du Niger", 0);
            if (t < 24.8f) { d.edit = t < 21.4f ? "Nouveau dossier" : typed("Drapeau du Niger", t, 21.4f, 24.0f); d.fresh = t < 21.4f; }
            d.drop = t >= 39.6f && t < 40.7f;
            l.add(d);
        }
        if (t >= 26.4f) {
            Item p = new Item(t < 35.6f ? "Personnel" : "Mes documents", 0);
            if (t < 29.3f) { p.edit = t < 27.2f ? "Nouveau dossier" : typed("Personnel", t, 27.2f, 28.6f); p.fresh = t < 27.2f; }
            if (t >= 32.3f && t < 35.6f) { p.edit = t < 33.0f ? "Personnel" : typed("Mes documents", t, 33.0f, 35.0f); p.fresh = t < 33.0f; }
            p.selected = t >= 31.2f && t < 36.5f;
            l.add(p);
        }
        if (t < 40.7f) {
            Item f = new Item("Flag_of_Niger.png", 1);
            f.selected = t >= 37.6f;
            f.alpha = t >= 37.8f ? .35f : 1f;
            l.add(f);
        }
        if (t < 44.4f) {
            Item b = new Item("Brouillon.txt", 2);
            b.selected = t >= 42.8f;
            b.alpha = 1 - seg(t, 43.8f, 44.4f);
            l.add(b);
        }
        return l;
    }

    private void drawWindow(Gfx g, float t, float open, boolean corbeille) {
        g.save();
        g.scale(lerp(.94f, 1f, open), WX + WW / 2, WY + WH / 2);
        float a = open;
        shadowBox(g, WX, WY, WW, WH, 10, a);
        // barre de titre et onglet
        g.roundRect(WX, WY, WW, 38, 10, alpha(0xFFE8EDF4, a));
        g.rect(WX, WY + 28, WW, 10, alpha(0xFFE8EDF4, a));
        g.roundRect(WX + 10, WY + 6, 220, 32, 8, alpha(WHITE, a));
        if (corbeille) binIcon(g, WX + 22, WY + 12, 18, false);
        else folderIcon(g, WX + 20, WY + 12, 20, false);
        g.text(corbeille ? "Corbeille" : "Mes exercices", WX + 48, WY + 28, 14, alpha(INK, a), false, Gfx.LEFT);
        cross(g, WX + 208, WY + 22, 4.5f, 1.4f, alpha(MUTED, a));
        g.text("—", WX + WW - 112, WY + 26, 14, alpha(INK, a), false, Gfx.CENTER);
        g.strokeRoundRect(WX + WW - 74, WY + 13, 12, 12, 1, 1.4f, alpha(INK, a));
        boolean hoverClose = !corbeille && t >= 48.1f;
        if (hoverClose) g.rect(WX + WW - 44, WY, 44, 38, alpha(RED, a));
        cross(g, WX + WW - 22, WY + 19, 6, 1.5f, alpha(hoverClose ? WHITE : INK, a));
        // barre d'adresse
        g.rect(WX, WY + 38, WW, 44, alpha(WHITE, a));
        g.text("←   →   ↑", WX + 16, WY + 66, 15, alpha(MUTED, a), false, Gfx.LEFT);
        g.roundRect(WX + 110, WY + 46, 470, 28, 6, alpha(PANEL, a));
        g.text(corbeille ? "Bureau  ›  Corbeille" : "Bureau  ›  Mes exercices", WX + 124, WY + 66, 14, alpha(INK, a), false, Gfx.LEFT);
        g.roundRect(WX + 596, WY + 46, 208, 28, 6, alpha(PANEL, a));
        g.text("Rechercher", WX + 614, WY + 66, 14, alpha(MUTED, a), false, Gfx.LEFT);
        // barre de commandes
        g.rect(WX, WY + 82, WW, 42, alpha(WHITE, a));
        g.line(WX, WY + 124, WX + WW, WY + 124, 1, alpha(LINE, a));
        if (corbeille) {
            boolean hov = t >= 53.9f && t < 54.6f;
            if (hov) g.roundRect(WX + 250, WY + 88, 162, 30, 6, alpha(0xFFE5EDF8, a));
            g.text("Vider la Corbeille", WX + 20, WY + 108, 14, alpha(INK, a), false, Gfx.LEFT);
            g.text("Restaurer la sélection", WX + 260, WY + 108, 14, alpha(t >= 52.9f ? BLUE : MUTED, a), true, Gfx.LEFT);
        } else {
            g.text("+  Nouveau", WX + 20, WY + 108, 14, alpha(INK, a), false, Gfx.LEFT);
            chevron(g, WX + 112, WY + 103, alpha(INK, a));
            g.text("Couper   Copier   Coller   Renommer   Partager   Supprimer", WX + 140, WY + 108, 14, alpha(MUTED, a), false, Gfx.LEFT);
            g.text("Trier", WX + WW - 150, WY + 108, 14, alpha(MUTED, a), false, Gfx.LEFT);
            chevron(g, WX + WW - 108, WY + 103, alpha(MUTED, a));
            g.text("Afficher", WX + WW - 88, WY + 108, 14, alpha(MUTED, a), false, Gfx.LEFT);
            chevron(g, WX + WW - 26, WY + 103, alpha(MUTED, a));
        }
        // volet de navigation
        g.rect(WX, WY + 125, 180, WH - 125 - 30, alpha(PANEL, a));
        String[] nav = {"Accueil", "Bureau", "Documents", "Téléchargements", "Images", "Ce PC"};
        for (int i = 0; i < nav.length; i++) {
            if (i == 1) g.roundRect(WX + 6, WY + 140 + i * 34, 168, 30, 6, alpha(0xFFDCE8F8, a));
            g.text(nav[i], WX + 22, WY + 160 + i * 34, 14, alpha(INK, a), false, Gfx.LEFT);
        }
        // contenu
        List<Item> items;
        if (corbeille) {
            items = new ArrayList<>();
            if (t < 54.8f) {
                Item b = new Item("Brouillon.txt", 2);
                b.selected = t >= 52.9f;
                b.alpha = 1 - seg(t, 54.3f, 54.8f);
                items.add(b);
            }
        } else items = folderItems(t);
        for (int i = 0; i < items.size(); i++) drawItem(g, items.get(i), i, a);
        // barre d'état
        g.rect(WX, WY + WH - 30, WW, 30, alpha(PANEL, a));
        g.text(items.size() + " élément" + (items.size() > 1 ? "s" : ""), WX + 16, WY + WH - 10, 13, alpha(MUTED, a), false, Gfx.LEFT);
        g.restore();
    }

    private void drawItem(Gfx g, Item it, int i, float a) {
        float x = CX0 + (i % 5) * CELL_W, y = CY0 + (i / 5) * CELL_H;
        float al = a * it.alpha;
        if (it.drop) {
            g.roundRect(x - 6, y - 6, 108, 112, 6, alpha(SEL, al));
            g.strokeRoundRect(x - 6, y - 6, 108, 112, 6, 1.5f, alpha(SEL_LINE, al));
        } else if (it.selected && it.edit == null) {
            g.roundRect(x - 6, y - 6, 108, 112, 6, alpha(SEL, al));
        }
        if (it.kind == 0) folderIcon(g, x + 20, y + 4, 56, false, al);
        else fileIcon(g, x + 26, y, 44, it.kind, al);
        if (it.edit != null) labelEditor(g, x + 48, y + 82, it.edit, it.fresh, 13, false);
        else wrapLabel(g, it.name, x + 48, y + 82, 13, alpha(INK, al), 100);
    }

    // ------------------------------------------------------------------ glisser-déposer
    private void drawDrag(Gfx g, float t) {
        if (t < 37.8f || t >= 40.7f) return;
        float[] c = cursor(t);
        fileIcon(g, c[0] - 10, c[1] - 6, 40, 1, .75f);
        if (t >= 39.6f) {
            String s = "→ Déplacer vers Drapeau du Niger";
            float w = g.textWidth(s, 13, false) + 20;
            g.roundRect(c[0] + 18, c[1] + 34, w, 26, 4, 0xFFFFFFFF);
            g.strokeRoundRect(c[0] + 18, c[1] + 34, w, 26, 4, 1, LINE);
            g.text(s, c[0] + 28, c[1] + 52, 13, INK, false, Gfx.LEFT);
        }
    }

    // ------------------------------------------------------------------ éléments d'interface
    private void drawKeyBadge(Gfx g, float t, float a, float b, String[] keys) {
        float al = seg(t, a, a + .15f) * (1 - seg(t, b - .15f, b));
        if (al <= 0) return;
        float total = 0;
        for (String k : keys) total += g.textWidth(k, 22, true) + 34;
        total += (keys.length - 1) * 30;
        float x = W / 2 - total / 2, y = 24;
        g.roundRect(x - 18, y - 12, total + 36, 76, 16, alpha(0xE61C2433, al));
        for (int i = 0; i < keys.length; i++) {
            float kw = g.textWidth(keys[i], 22, true) + 34;
            g.roundRect(x, y + 4, kw, 46, 9, alpha(0xFF9AA5B4, al));
            g.roundRect(x, y, kw, 46, 9, alpha(WHITE, al));
            g.text(keys[i], x + kw / 2, y + 31, 22, alpha(INK, al), true, Gfx.CENTER);
            x += kw;
            if (i < keys.length - 1) {
                g.text("+", x + 15, y + 32, 24, alpha(WHITE, al), true, Gfx.CENTER);
                x += 30;
            }
        }
    }

    private void drawToast(Gfx g, float t, float a, float b, String msg) {
        float al = seg(t, a, a + .25f) * (1 - seg(t, b - .3f, b));
        if (al <= 0) return;
        float w = g.textWidth(msg, 16, false) + 48, y = H - 120 + (1 - ease(seg(t, a, a + .3f))) * 20;
        g.roundRect(W / 2 - w / 2, y, w, 46, 23, alpha(0xF0202A3A, al));
        g.text(msg, W / 2, y + 29, 16, alpha(WHITE, al), false, Gfx.CENTER);
    }

    private void drawSummary(Gfx g, float t) {
        float al = ease(seg(t, 58.3f, 59.0f));
        if (al <= 0) return;
        g.rect(0, 0, W, H, alpha(0xB30B1B33, al));
        float y = 140 + (1 - al) * 30;
        g.roundRect(290, y, 700, 420, 22, alpha(WHITE, al));
        g.text("À retenir", W / 2, y + 66, 34, alpha(0xFF1F4D78, al), true, Gfx.CENTER);
        String[][] rows = {{"Clic droit › Nouveau › Dossier", "ou Ctrl + Maj + N"}, {"Double-clic", "ouvrir un dossier"},
                {"F2", "renommer"}, {"Glisser-déposer", "ranger un fichier"}, {"Suppr", "envoyer dans la Corbeille"},
                {"Corbeille › Restaurer", "récupérer un élément"}};
        for (int i = 0; i < rows.length; i++) {
            float ry = y + 116 + i * 46;
            float p = ease(seg(t, 59.0f + i * .35f, 59.4f + i * .35f));
            g.circle(350, ry - 6, 6, alpha(0xFF5B9BD5, al * p));
            g.text(rows[i][0], 372, ry, 20, alpha(INK, al * p), true, Gfx.LEFT);
            g.text(rows[i][1], 930, ry, 18, alpha(MUTED, al * p), false, Gfx.RIGHT);
        }
    }

    private float[] cursor(float t) {
        float[][] k = cursorKeys;
        if (t <= k[0][0]) return new float[]{k[0][1], k[0][2]};
        for (int i = 0; i < k.length - 1; i++) {
            if (t <= k[i + 1][0]) {
                float p = ease(seg(t, k[i][0], k[i + 1][0]));
                return new float[]{lerp(k[i][1], k[i + 1][1], p), lerp(k[i][2], k[i + 1][2], p)};
            }
        }
        return new float[]{k[k.length - 1][1], k[k.length - 1][2]};
    }

    private void drawCursor(Gfx g, float t) {
        float[] c = cursor(t);
        for (float[] cl : clicks) {
            for (int r = 0; r < (cl[1] == 3 ? 2 : 1); r++) {
                float p = seg(t, cl[0] + r * .18f, cl[0] + r * .18f + .45f);
                if (p > 0 && p < 1) {
                    int col = cl[1] == 2 ? 0xFFFFB020 : 0xFFFFFFFF;
                    g.strokeCircle(c[0], c[1], 8 + 26 * p, 3, alpha(col, 1 - p));
                }
            }
        }
        float x = c[0], y = c[1];
        float[] arrow = {x, y, x, y + 27, x + 7, y + 21, x + 12, y + 32, x + 17, y + 30, x + 12, y + 19, x + 21, y + 19};
        float[] outline = {x - 2, y - 3, x - 2, y + 32, x + 6, y + 25, x + 11, y + 36, x + 19, y + 33, x + 15, y + 22, x + 25, y + 21};
        g.polygon(outline, 0xFF000000);
        g.polygon(arrow, WHITE);
    }

    // ------------------------------------------------------------------ icônes
    private void cross(Gfx g, float cx, float cy, float r, float s, int col) {
        g.line(cx - r, cy - r, cx + r, cy + r, s, col);
        g.line(cx - r, cy + r, cx + r, cy - r, s, col);
    }

    private void chevron(Gfx g, float x, float y, int col) {
        g.line(x - 4, y - 2, x, y + 2, 1.4f, col);
        g.line(x, y + 2, x + 4, y - 2, 1.4f, col);
    }

    private void shadowBox(Gfx g, float x, float y, float w, float h, float r, float a) {
        g.roundRect(x + 2, y + 6, w, h, r, alpha(SHADOW, a));
        g.roundRect(x, y, w, h, r, alpha(WHITE, a));
        g.strokeRoundRect(x, y, w, h, r, 1, alpha(LINE, a));
    }

    private void folderIcon(Gfx g, float x, float y, float s, boolean open) {
        folderIcon(g, x, y, s, open, 1f);
    }

    private void folderIcon(Gfx g, float x, float y, float s, boolean open, float a) {
        g.polygon(new float[]{x, y + s * .12f, x + s * .38f, y + s * .12f, x + s * .48f, y + s * .24f,
                x + s, y + s * .24f, x + s, y + s * .82f, x, y + s * .82f}, alpha(FOLDER_D, a));
        g.roundRect(x, y + s * .3f, s, s * .52f, s * .06f, alpha(FOLDER, a));
    }

    private void fileIcon(Gfx g, float x, float y, float s, int kind, float a) {
        float w = s * .78f, h = s;
        g.polygon(new float[]{x, y, x + w * .7f, y, x + w, y + h * .24f, x + w, y + h, x, y + h}, alpha(0xFFFFFFFF, a));
        g.line(x, y, x + w * .7f, y, 1.2f, alpha(0xFF9AA5B4, a));
        g.line(x + w * .7f, y, x + w, y + h * .24f, 1.2f, alpha(0xFF9AA5B4, a));
        g.line(x + w, y + h * .24f, x + w, y + h, 1.2f, alpha(0xFF9AA5B4, a));
        g.line(x + w, y + h, x, y + h, 1.2f, alpha(0xFF9AA5B4, a));
        g.line(x, y + h, x, y, 1.2f, alpha(0xFF9AA5B4, a));
        if (kind == 1) {   // image : drapeau du Niger
            float fx = x + w * .14f, fy = y + h * .36f, fw = w * .72f, fh = h * .42f;
            g.rect(fx, fy, fw, fh / 3, alpha(0xFFE05206, a));
            g.rect(fx, fy + fh / 3, fw, fh / 3, alpha(0xFFFFFFFF, a));
            g.rect(fx, fy + 2 * fh / 3, fw, fh / 3, alpha(0xFF0DB02B, a));
            g.circle(fx + fw / 2, fy + fh / 2, fh / 8, alpha(0xFFE05206, a));
            g.strokeRoundRect(fx, fy, fw, fh, 1, .8f, alpha(0xFFD0D5DD, a));
        } else {          // texte
            for (int i = 0; i < 4; i++)
                g.line(x + w * .18f, y + h * (.42f + i * .13f), x + w * (i == 3 ? .6f : .82f), y + h * (.42f + i * .13f), 1.6f, alpha(0xFF9AA5B4, a));
        }
    }

    private void binIcon(Gfx g, float x, float y, float s, boolean full) {
        g.roundRect(x + s * .12f, y + s * .2f, s * .76f, s * .78f, s * .08f, 0xFFDCE3EC);
        g.roundRect(x, y + s * .08f, s, s * .14f, s * .05f, 0xFFB8C2D0);
        for (int i = 0; i < 3; i++) g.line(x + s * (.32f + i * .18f), y + s * .34f, x + s * (.32f + i * .18f), y + s * .86f, 2, 0xFF9AA5B4);
        if (full) {
            g.polygon(new float[]{x + s * .2f, y + s * .1f, x + s * .5f, y - s * .14f, x + s * .62f, y + s * .08f}, 0xFFFFFFFF);
            g.polygon(new float[]{x + s * .5f, y + s * .08f, x + s * .78f, y - s * .1f, x + s * .86f, y + s * .1f}, 0xFFE8EEF6);
        }
    }

    private void pcIcon(Gfx g, float x, float y, float s) {
        g.roundRect(x, y, s, s * .64f, s * .05f, 0xFF1D4E89);
        g.rect(x + s * .06f, y + s * .07f, s * .88f, s * .48f, 0xFF7FB3FF);
        g.rect(x + s * .42f, y + s * .64f, s * .16f, s * .12f, 0xFF1D4E89);
        g.roundRect(x + s * .26f, y + s * .76f, s * .48f, s * .06f, s * .03f, 0xFF1D4E89);
    }

    private void winLogo(Gfx g, float x, float y, float s) {
        float q = s * .47f;
        g.rect(x, y, q, q, 0xFF0B6CD8);
        g.rect(x + s - q, y, q, q, 0xFF0B6CD8);
        g.rect(x, y + s - q, q, q, 0xFF0B6CD8);
        g.rect(x + s - q, y + s - q, q, q, 0xFF0B6CD8);
    }

    private void labelEditor(Gfx g, float cx, float y, String text, boolean fresh, float size, boolean onDesktop) {
        float w = Math.max(g.textWidth(text, size, false) + 12, 40);
        g.rect(cx - w / 2, y - size - 2, w, size + 10, WHITE);
        g.strokeRoundRect(cx - w / 2, y - size - 2, w, size + 10, 0, 1.2f, BLUE);
        if (fresh) {
            float tw = g.textWidth(text, size, false);
            g.rect(cx - tw / 2 - 1, y - size, tw + 2, size + 6, BLUE);
            g.text(text, cx, y + 2, size, WHITE, false, Gfx.CENTER);
        } else {
            g.text(text, cx, y + 2, size, INK, false, Gfx.CENTER);
            float tw = g.textWidth(text, size, false);
            g.line(cx + tw / 2 + 2, y - size + 1, cx + tw / 2 + 2, y + 4, 1.4f, INK);
        }
    }

    private void wrapLabel(Gfx g, String s, float cx, float y, float size, int color, float maxW) {
        if (g.textWidth(s, size, false) <= maxW) {
            g.text(s, cx, y + 2, size, color, false, Gfx.CENTER);
            return;
        }
        int cut = s.lastIndexOf(' ', s.length() / 2 + 4);
        if (cut <= 0) cut = s.length() / 2;
        g.text(s.substring(0, cut).trim(), cx, y + 2, size, color, false, Gfx.CENTER);
        g.text(s.substring(cut).trim(), cx, y + 2 + size + 3, size, color, false, Gfx.CENTER);
    }
}
