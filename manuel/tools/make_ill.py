#!/usr/bin/env python3
"""Génère les illustrations vectorielles (SVG) du manuel dans src/ill/."""
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "src" / "ill"
OUT.mkdir(parents=True, exist_ok=True)
F = 'font-family="Inter, sans-serif"'
BLUE, GREEN, RED, AMBER, AI, INK, MUTED = "#1F5FA8", "#18A04A", "#E0182D", "#D97706", "#6D35D6", "#1C2433", "#5B6678"


def save(name, w, h, body):
    (OUT / f"{name}.svg").write_text(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="100%" {F}>{body}</svg>',
        encoding="utf-8")


def esc(s):
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


# ------------------------------------------------------------------ clavier AZERTY
def keyboard():
    U = 10  # largeur d'une touche standard
    rows = [
        [("Échap", 1.25, "esc")] + [(f"F{i}", 1, "fn") for i in range(1, 13)] + [("Impr", 1, "fn"), ("Suppr", 1.25, "del")],
        [("²", 1, "k"), ("&\n1", 1, "n"), ("é\n2", 1, "n"), ('"\n3', 1, "n"), ("'\n4", 1, "n"), ("(\n5", 1, "n"),
         ("-\n6", 1, "n"), ("è\n7", 1, "n"), ("_\n8", 1, "n"), ("ç\n9", 1, "n"), ("à\n0", 1, "n"), (")", 1, "k"),
         ("=", 1, "k"), ("⟵", 1.75, "bk")],
        [("⇥", 1.4, "mod")] + [(c, 1, "k") for c in "AZERTYUIOP"] + [("^", 1, "k"), ("$", 1, "k"), ("Entrée", 1.35, "ent")],
        [("Verr Maj", 1.75, "caps")] + [(c, 1, "k") for c in "QSDFGHJKLM"] + [("ù", 1, "k"), ("*", 1, "k"), ("", 1.0, "ent")],
        [("⇧ Maj", 1.3, "shift"), ("<", 1, "k")] + [(c, 1, "k") for c in "WXCVBN"] + [(",", 1, "k"), (";", 1, "k"), (":", 1, "k"), ("!", 1, "k"), ("⇧ Maj", 2.45, "shift")],
        [("Ctrl", 1.3, "mod"), ("Fn", 1, "mod"), ("⊞", 1.1, "win"), ("Alt", 1.1, "mod"), ("Espace", 5.6, "sp"), ("Alt Gr", 1.2, "mod"),
         ("Ctrl", 1.1, "mod"), ("◀", 0.95, "arr"), ("▲▼", 0.95, "arr"), ("▶", 0.95, "arr")],
    ]
    col = {"k": ("#F4F6F9", INK), "n": ("#F4F6F9", INK), "fn": ("#E2E8F0", MUTED), "esc": (RED, "#fff"),
           "del": ("#F8C9CF", "#9F1020"), "bk": ("#F8C9CF", "#9F1020"), "ent": (GREEN, "#fff"),
           "caps": ("#B45309", "#fff"), "shift": ("#FBBF24", INK), "mod": ("#DDD6FE", "#4B21A6"),
           "win": (BLUE, "#fff"), "sp": ("#BFDBFE", BLUE), "arr": ("#99F6E4", "#0F766E")}
    g = []
    y = 4
    W = 0
    for r, row in enumerate(rows):
        x = 4
        h = 7 if r == 0 else 9
        for lab, w, kind in row:
            bg, fg = col[kind]
            ww = w * U - 1.2
            g.append(f'<rect x="{x:.1f}" y="{y}" width="{ww:.1f}" height="{h}" rx="1.4" fill="{bg}" stroke="#AEB8C6" stroke-width=".35"/>')
            lines = lab.split("\n")
            fs = 3.1 if len(lab) <= 2 or "\n" in lab else 2.6
            for k, t in enumerate(lines):
                ty = y + h / 2 + 1.1 + (k - (len(lines) - 1) / 2) * 3.2
                g.append(f'<text x="{x + ww / 2:.1f}" y="{ty:.1f}" font-size="{fs}" text-anchor="middle" fill="{fg}" font-weight="600">{esc(t)}</text>')
            x += w * U
        W = max(W, x)
        y += h + 1.2
    legend = [("esc", "Échap : annuler"), ("bk", "Retour arrière / Suppr"), ("ent", "Entrée : valider"),
              ("shift", "Maj : majuscule"), ("caps", "Verr Maj"), ("mod", "Ctrl, Alt, Fn"),
              ("win", "Windows"), ("sp", "Espace"), ("arr", "Flèches")]
    ly = y + 3
    for i, (k, t) in enumerate(legend):
        lx = 4 + (i % 3) * 51
        yy = ly + (i // 3) * 6
        g.append(f'<rect x="{lx}" y="{yy}" width="5" height="4" rx="1" fill="{col[k][0]}" stroke="#AEB8C6" stroke-width=".3"/>'
                 f'<text x="{lx + 7}" y="{yy + 3.3}" font-size="3.4" fill="{INK}">{t}</text>')
    H = ly + 18
    save("keyboard", W + 4, H, f'<rect x="1" y="1" width="{W + 2}" height="{y + 1}" rx="3" fill="#CBD3DE"/>' + "".join(g))


# ------------------------------------------------------------------ icônes d'appareils
def device_icon(kind, x, y, s=1, c=BLUE):
    t = f'transform="translate({x},{y}) scale({s})"'
    if kind == "desktop":
        return (f'<g {t}><rect x="0" y="4" width="26" height="18" rx="1.6" fill="#fff" stroke="{c}" stroke-width="1.6"/>'
                f'<rect x="2.5" y="6.5" width="21" height="13" fill="{c}" opacity=".18"/><path d="M10 22v4h6v-4M7 27h12" stroke="{c}" stroke-width="1.6" fill="none"/>'
                f'<rect x="29" y="2" width="9" height="25" rx="1.3" fill="#fff" stroke="{c}" stroke-width="1.6"/><circle cx="33.5" cy="22" r="1.4" fill="{c}"/><path d="M31.5 6h4M31.5 9h4" stroke="{c}" stroke-width="1.2"/></g>')
    if kind == "laptop":
        return (f'<g {t}><rect x="5" y="4" width="28" height="18" rx="1.6" fill="#fff" stroke="{c}" stroke-width="1.6"/>'
                f'<rect x="7.5" y="6.5" width="23" height="13" fill="{c}" opacity=".18"/><path d="M1 24h36l-2.5 3H3.5z" fill="#fff" stroke="{c}" stroke-width="1.6" stroke-linejoin="round"/></g>')
    if kind == "aio":
        return (f'<g {t}><rect x="3" y="2" width="32" height="21" rx="1.6" fill="#fff" stroke="{c}" stroke-width="1.6"/>'
                f'<rect x="5.5" y="4.5" width="27" height="14" fill="{c}" opacity=".18"/><circle cx="19" cy="20.8" r=".9" fill="{c}"/><path d="M15 23l-2 4h12l-2-4" fill="none" stroke="{c}" stroke-width="1.6" stroke-linejoin="round"/></g>')
    if kind == "tablet":
        return (f'<g {t}><rect x="8" y="1" width="22" height="27" rx="2.4" fill="#fff" stroke="{c}" stroke-width="1.6"/>'
                f'<rect x="10.5" y="3.5" width="17" height="20" fill="{c}" opacity=".18"/><circle cx="19" cy="25.8" r="1" fill="{c}"/></g>')
    if kind == "phone":
        return (f'<g {t}><rect x="12" y="1" width="14" height="27" rx="2.4" fill="#fff" stroke="{c}" stroke-width="1.6"/>'
                f'<rect x="14" y="4" width="10" height="19" fill="{c}" opacity=".18"/><path d="M17 2.6h4" stroke="{c}" stroke-width="1.2"/><circle cx="19" cy="25.4" r=".9" fill="{c}"/></g>')
    if kind == "server":
        return (f'<g {t}>' + "".join(f'<rect x="6" y="{2 + i * 9}" width="26" height="7.5" rx="1.2" fill="#fff" stroke="{c}" stroke-width="1.6"/><circle cx="10" cy="{5.75 + i * 9}" r="1.1" fill="{GREEN}"/><path d="M14 {5.75 + i * 9}h14" stroke="{c}" stroke-width="1.2"/>' for i in range(3)) + '</g>')
    return ""


def devices():
    items = [("desktop", "Ordinateur\nde bureau"), ("laptop", "Ordinateur\nportable"), ("aio", "Tout-en-un\n(All-in-one)"),
             ("tablet", "Tablette"), ("phone", "Smartphone")]
    g = []
    for i, (k, lab) in enumerate(items):
        x = 2 + i * 41
        g.append(f'<rect x="{x}" y="2" width="38" height="50" rx="4" fill="#F1F5FB"/>')
        g.append(device_icon(k, x, 6))
        for j, t in enumerate(lab.split("\n")):
            g.append(f'<text x="{x + 19}" y="{42 + j * 5}" font-size="4.6" text-anchor="middle" font-weight="650" fill="{INK}">{esc(t)}</text>')
    save("devices", 207, 55, "".join(g))


# ------------------------------------------------------------------ entrée → traitement → sortie
def ipo():
    def box(x, y, w, h, c, title, sub):
        return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="3" fill="{c}" opacity=".12"/>'
                f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="3" fill="none" stroke="{c}" stroke-width=".8"/>'
                f'<text x="{x + w / 2}" y="{y + 7}" font-size="5.2" font-weight="800" text-anchor="middle" fill="{c}">{title}</text>'
                + "".join(f'<text x="{x + w / 2}" y="{y + 14 + k * 5.4}" font-size="4.3" text-anchor="middle" fill="{INK}">{esc(s)}</text>' for k, s in enumerate(sub)))
    arrow = lambda x1, y1, x2, y2: (f'<path d="M{x1} {y1}L{x2} {y2}" stroke="{MUTED}" stroke-width="1" marker-end="url(#ah)"/>')
    g = [f'<defs><marker id="ah" viewBox="0 0 6 6" refX="5" refY="3" markerWidth="5" markerHeight="5" orient="auto"><path d="M0 0L6 3L0 6z" fill="{MUTED}"/></marker></defs>']
    g.append(box(2, 2, 50, 34, BLUE, "ENTRÉE", ["Clavier, souris", "Micro, webcam", "Scanner, écran tactile"]))
    g.append(box(66, 2, 54, 34, AI, "TRAITEMENT", ["Processeur (CPU)", "Mémoire vive (RAM)", "Programmes"]))
    g.append(box(134, 2, 50, 34, GREEN, "SORTIE", ["Écran, imprimante", "Haut-parleurs", "Vidéoprojecteur"]))
    g.append(box(66, 48, 54, 26, AMBER, "STOCKAGE", ["SSD, disque dur", "Clé USB, cloud"]))
    g += [arrow(52, 19, 65, 19), arrow(120, 19, 133, 19), arrow(90, 36, 90, 47), arrow(96, 47, 96, 37)]
    save("ipo", 186, 76, "".join(g))


# ------------------------------------------------------------------ intérieur de l'unité centrale
def pc_inside():
    g = ['<rect x="2" y="2" width="120" height="92" rx="4" fill="#F1F5FB" stroke="#9AA8BC" stroke-width=".8"/>',
         '<rect x="8" y="8" width="72" height="62" rx="2" fill="#0F5132" opacity=".9"/>',
         '<text x="44" y="66" font-size="3.6" fill="#D1FAE5" text-anchor="middle" font-weight="600">Carte mère</text>']
    # CPU
    g.append('<rect x="16" y="14" width="20" height="20" rx="1.5" fill="#CBD5E1" stroke="#475569" stroke-width=".6"/><rect x="20" y="18" width="12" height="12" fill="#94A3B8"/>')
    # RAM
    for i in range(2):
        g.append(f'<rect x="{44 + i * 6}" y="12" width="4" height="30" rx=".6" fill="#1E3A8A"/>')
        g += [f'<rect x="{44.6 + i * 6}" y="{15 + k * 6}" width="2.8" height="4" fill="#60A5FA"/>' for k in range(4)]
    # GPU
    g.append('<rect x="14" y="44" width="50" height="10" rx="1.4" fill="#334155"/><circle cx="26" cy="49" r="3.6" fill="#64748B"/><circle cx="40" cy="49" r="3.6" fill="#64748B"/>')
    # SSD
    g.append('<rect x="60" y="56" width="16" height="5" rx=".8" fill="#111827"/><rect x="61.5" y="57.2" width="5" height="2.6" fill="#F59E0B"/>')
    # PSU
    g.append('<rect x="86" y="70" width="32" height="20" rx="2" fill="#475569"/><circle cx="102" cy="80" r="7" fill="#64748B"/><path d="M95 80h14M102 73v14" stroke="#94A3B8" stroke-width=".8"/>')
    # HDD
    g.append('<rect x="88" y="10" width="28" height="20" rx="2" fill="#94A3B8"/><circle cx="102" cy="20" r="6.5" fill="#CBD5E1"/><circle cx="102" cy="20" r="1.5" fill="#475569"/>')
    labels = [(26, 14, "Processeur", 140, 12), (50, 12, "Mémoire vive (RAM)", 140, 24), (39, 44, "Carte graphique (GPU)", 140, 38),
              (68, 58, "SSD (stockage rapide)", 140, 52), (102, 20, "Disque dur (HDD)", 140, 66), (102, 72, "Alimentation", 140, 80)]
    for (x, y, t, lx, ly) in labels:
        g.append(f'<path d="M{x} {y}L{lx - 2} {ly}" stroke="{RED}" stroke-width=".5" fill="none"/><circle cx="{x}" cy="{y}" r="1" fill="{RED}"/>'
                 f'<text x="{lx}" y="{ly + 1.6}" font-size="5.2" fill="{INK}" font-weight="600">{t}</text>')
    save("pc-inside", 206, 96, "".join(g))


# ------------------------------------------------------------------ ports
def ports():
    items = [
        ("USB-A", '<rect x="4" y="9" width="22" height="10" rx="1" fill="none" stroke="#334155" stroke-width="1.6"/><rect x="7" y="11.5" width="16" height="3" fill="#334155"/>'),
        ("USB-C", '<rect x="5" y="10" width="20" height="8" rx="4" fill="none" stroke="#334155" stroke-width="1.6"/><rect x="9" y="13" width="12" height="2" rx="1" fill="#334155"/>'),
        ("HDMI", '<path d="M4 10h22v5l-3 4H7l-3-4z" fill="none" stroke="#334155" stroke-width="1.6" stroke-linejoin="round"/><rect x="8" y="12" width="14" height="2" fill="#334155"/>'),
        ("Ethernet\n(RJ45)", '<path d="M7 7h16v12h-4v3h-8v-3H7z" fill="none" stroke="#334155" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 10v3M13 10v3M16 10v3M19 10v3" stroke="#334155" stroke-width="1.2"/>'),
        ("Jack audio\n3,5 mm", '<circle cx="15" cy="14" r="7" fill="none" stroke="#334155" stroke-width="1.6"/><circle cx="15" cy="14" r="2.6" fill="#334155"/>'),
        ("Carte SD", '<path d="M8 6h11l4 4v13H8z" fill="none" stroke="#334155" stroke-width="1.6" stroke-linejoin="round"/><path d="M11 6v4M14 6v4M17 6v4" stroke="#334155" stroke-width="1.2"/>'),
    ]
    g = []
    for i, (lab, icon) in enumerate(items):
        x = 2 + i * 33
        g.append(f'<rect x="{x}" y="2" width="30" height="40" rx="3" fill="#F1F5FB"/><g transform="translate({x},3)">{icon}</g>')
        for j, t in enumerate(lab.split("\n")):
            g.append(f'<text x="{x + 15}" y="{33 + j * 4.6}" font-size="4.3" text-anchor="middle" font-weight="650" fill="{INK}">{t}</text>')
    save("ports", 200, 44, "".join(g))


def arts():
    W = 'fill="none" stroke="#fff" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"'
    art = {
        "art-p1": f'<rect x="4" y="8" width="44" height="30" rx="3" {W}/><path d="M20 38v8h12v-8M14 48h24" {W}/><rect x="52" y="6" width="14" height="42" rx="2" {W}/><circle cx="59" cy="40" r="2" {W}/>',
        "art-p2": f'<rect x="4" y="6" width="60" height="44" rx="3" {W}/><path d="M4 14h60" {W}/><rect x="10" y="20" width="22" height="12" rx="1" {W}/><rect x="36" y="20" width="22" height="24" rx="1" {W}/><rect x="10" y="35" width="22" height="9" rx="1" {W}/>',
        "art-p3": f'<circle cx="34" cy="28" r="22" {W}/><ellipse cx="34" cy="28" rx="10" ry="22" {W}/><path d="M12 28h44M15 17h38M15 39h38" {W}/>',
        "art-p4": f'<path d="M34 4l5 15 15 5-15 5-5 15-5-15-15-5 15-5z" {W}/><path d="M56 38l2.5 6 6 2.5-6 2.5-2.5 6-2.5-6-6-2.5 6-2.5z" {W}/><path d="M12 40l2 5 5 2-5 2-2 5-2-5-5-2 5-2z" {W}/>',
        "art-p5": f'<rect x="4" y="14" width="60" height="30" rx="3" {W}/>' + "".join(f'<rect x="{9 + i * 9}" y="{19 + r * 7}" width="6" height="5" rx="1" {W}/>' for r in range(3) for i in range(6)),
        "art-w": f'<path d="M14 6h28l12 12v38H14z" {W}/><path d="M42 6v12h12M22 28h24M22 35h24M22 42h16" {W}/>',
        "art-x": f'<rect x="8" y="8" width="52" height="44" rx="2" {W}/><path d="M8 19h52M8 30h52M8 41h52M22 8v44M38 8v44" {W}/>',
        "art-a": f'<path d="M10 10h34l10 10v34H10z" {W}/><path d="M18 26h28M18 33h28M18 40h20" {W}/><path d="M26 18l3 3 6-6" {W}/>',
    }
    for k, v in art.items():
        (OUT / f"{k}.svg").write_text(f'<svg xmlns="http://www.w3.org/2000/svg" class="art" viewBox="0 0 68 56">{v}</svg>', encoding="utf-8")


if __name__ == "__main__":
    keyboard(); devices(); ipo(); pc_inside(); ports(); arts()
    print("ok", sorted(p.name for p in OUT.iterdir()))


# ================================================================== WINDOWS 11
def app(x, y, s, color, glyph, gc="#fff", r=None):
    r = r if r is not None else s * 0.22
    return (f'<rect x="{x}" y="{y}" width="{s}" height="{s}" rx="{r}" fill="{color}"/>'
            f'<text x="{x + s / 2}" y="{y + s * 0.7}" font-size="{s * 0.58}" font-weight="800" text-anchor="middle" fill="{gc}">{glyph}</text>')


def winlogo(x, y, s, c="#0B6CD8"):
    q = s * 0.47
    return "".join(f'<rect x="{x + dx}" y="{y + dy}" width="{q}" height="{q}" fill="{c}"/>' for dx in (0, s - q) for dy in (0, s - q))


def callout(n, x, y, tx, ty, c=RED):
    return (f'<path d="M{x} {y}L{tx} {ty}" stroke="{c}" stroke-width=".6"/>'
            f'<circle cx="{tx}" cy="{ty}" r="3.4" fill="{c}"/><text x="{tx}" y="{ty + 1.6}" font-size="4.4" font-weight="800" text-anchor="middle" fill="#fff">{n}</text>')


TASK_APPS = [("#0B6CD8", "⌕"), ("#334155", "▣"), ("#F2B01E", "▬"), ("#0E8A7E", "e"), ("#5B5FC7", "▦"), ("#2B579A", "W"), ("#1D6F42", "X")]


def taskbar_strip(x, y, w, h=9, labels=False):
    g = [f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="#EEF2F8" opacity=".97"/>',
         f'<path d="M{x} {y}h{w}" stroke="#CBD5E1" stroke-width=".3"/>']
    n = len(TASK_APPS) + 1
    s = h * 0.62
    cx0 = x + w / 2 - n * (s + 2) / 2
    g.append(winlogo(cx0, y + (h - s) / 2, s))
    for i, (c, gl) in enumerate(TASK_APPS):
        xx = cx0 + (i + 1) * (s + 2)
        if i == 0:
            g.append(f'<rect x="{xx}" y="{y + (h - s) / 2}" width="{s}" height="{s}" rx="{s / 2}" fill="#fff" stroke="#94A3B8" stroke-width=".3"/><text x="{xx + s / 2}" y="{y + h / 2 + s * .25}" font-size="{s * .7}" text-anchor="middle" fill="#334155">⌕</text>')
        else:
            g.append(app(xx, y + (h - s) / 2, s, c, gl))
        if i in (2, 3):
            g.append(f'<rect x="{xx + s / 2 - 1}" y="{y + h - 1.2}" width="2" height=".7" rx=".35" fill="#0B6CD8"/>')
    # zone de notification
    rx = x + w - 2
    g.append(f'<text x="{rx}" y="{y + h / 2 - .2}" font-size="{h * .27}" text-anchor="end" fill="#1C2433">14:35</text>'
             f'<text x="{rx}" y="{y + h / 2 + h * .3}" font-size="{h * .27}" text-anchor="end" fill="#1C2433">17/11/2026</text>')
    g.append(f'<text x="{rx - h * 2.1}" y="{y + h * .66}" font-size="{h * .42}" text-anchor="end" fill="#334155">⌃  ◠  ◖)  ▭</text>')
    return "".join(g), cx0, s


def win11_desktop():
    W, H = 200, 128
    sx, sy, sw, sh = 22, 6, 156, 98
    g = ['<defs><radialGradient id="wp" cx="55%" cy="60%" r="70%"><stop offset="0" stop-color="#7FB3FF"/><stop offset=".45" stop-color="#2F6FE0"/><stop offset="1" stop-color="#0B2A6B"/></radialGradient></defs>',
         f'<rect x="{sx - 3}" y="{sy - 3}" width="{sw + 6}" height="{sh + 6}" rx="3" fill="#1C2433"/>',
         f'<rect x="{sx}" y="{sy}" width="{sw}" height="{sh}" fill="url(#wp)"/>',
         f'<path d="M{sx + 60} {sy + 80} C {sx + 70} {sy + 40}, {sx + 110} {sy + 30}, {sx + 128} {sy + 60} C {sx + 110} {sy + 50}, {sx + 85} {sy + 55}, {sx + 60} {sy + 80}z" fill="#BFD9FF" opacity=".55"/>',
         f'<path d="M{sx + 70} {sy + 82} C {sx + 82} {sy + 52}, {sx + 118} {sy + 48}, {sx + 140} {sy + 70} C {sx + 118} {sy + 64}, {sx + 95} {sy + 66}, {sx + 70} {sy + 82}z" fill="#E6F0FF" opacity=".5"/>']
    icons = [("#64748B", "♻", "Corbeille"), ("#0B6CD8", "▭", "Ce PC"), ("#0E8A7E", "e", "Edge"), ("#F2B01E", "▬", "Mes cours")]
    for i, (c, gl, lab) in enumerate(icons):
        ix, iy = sx + 4, sy + 4 + i * 15
        g.append(app(ix, iy, 8, c, gl))
        g.append(f'<text x="{ix + 4}" y="{iy + 11.5}" font-size="2.8" text-anchor="middle" fill="#fff">{lab}</text>')
    # raccourci : petite flèche sur l'icône Mes cours
    g.append(f'<rect x="{sx + 4}" y="{sy + 4 + 3 * 15 + 5}" width="3" height="3" fill="#fff"/><path d="M{sx + 4.6} {sy + 4 + 3 * 15 + 7.4}l1.6-1.6m-1 0h1v1" stroke="#0B6CD8" stroke-width=".45" fill="none"/>')
    strip, cx0, s = taskbar_strip(sx, sy + sh - 9, sw)
    g.append(strip)
    # callouts
    g.append(callout(1, sx + 8, sy + 8, 8, 14))
    g.append(callout(2, sx + 8, sy + 54, 8, 62))
    g.append(callout(3, cx0 + s / 2, sy + sh - 4.5, cx0 + s / 2, H - 14))
    g.append(callout(4, cx0 + s + 2 + s / 2, sy + sh - 4.5, cx0 + 30, H - 14))
    g.append(callout(5, sx + sw / 2 + 20, sy + sh - 1, sx + sw / 2 + 30, H - 6))
    g.append(callout(6, sx + sw - 24, sy + sh - 4.5, sx + sw + 12, H - 14))
    g.append(callout(7, sx + sw - 4, sy + sh - 4.5, W - 6, sy + sh - 20))
    g.append(callout(8, sx + 100, sy + 40, W - 6, sy + 30))
    save("win11-desktop", W, H, "".join(g))


def win11_taskbar():
    W, H = 200, 34
    strip, cx0, s = taskbar_strip(2, 12, 196, 11)
    g = [strip]
    pos = [cx0 + s / 2, cx0 + (s + 2) * 1 + s / 2, cx0 + (s + 2) * 2 + s / 2, cx0 + (s + 2) * 4.5]
    for k, px in enumerate(pos):
        g.append(callout(k + 1, px, 15, px, 5))
    g.append(callout(5, 196 - 2 * 11 - 8, 18, 196 - 2 * 11 - 8, 30))
    g.append(callout(6, 192, 18, 192, 30))
    g.append(callout(7, 197.5, 16, 197.5, 5))
    save("win11-taskbar", W, H, "".join(g))


def win11_start():
    W, H = 200, 150
    x, y, w, h = 30, 4, 140, 126
    g = [f'<rect x="0" y="0" width="{W}" height="{H}" fill="#2F6FE0" opacity=".25"/>',
         f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="4" fill="#F7F9FC" stroke="#CBD5E1" stroke-width=".4"/>',
         f'<rect x="{x + 8}" y="{y + 7}" width="{w - 16}" height="9" rx="4.5" fill="#fff" stroke="#94A3B8" stroke-width=".4"/>',
         f'<text x="{x + 13}" y="{y + 13}" font-size="3.6" fill="#64748B">⌕  Rechercher des applications, des paramètres et des documents</text>',
         f'<text x="{x + 10}" y="{y + 25}" font-size="4.2" font-weight="700" fill="#1C2433">Épinglé</text>',
         f'<rect x="{x + w - 36}" y="{y + 20.5}" width="26" height="6" rx="1.5" fill="#E2E8F0"/><text x="{x + w - 23}" y="{y + 24.8}" font-size="3" text-anchor="middle" fill="#1C2433">Toutes ›</text>']
    apps = [("#0E8A7E", "e", "Edge"), ("#2B579A", "W", "Word"), ("#1D6F42", "X", "Excel"), ("#C43E1C", "P", "PowerPoint"), ("#0F6CBD", "O", "Outlook"), ("#6D35D6", "✦", "Copilot"),
            ("#F2B01E", "▬", "Explorateur"), ("#5B6678", "⚙", "Paramètres"), ("#0B6CD8", "▤", "Store"), ("#E0182D", "▶", "Médias"), ("#18A04A", "◔", "Calculatrice"), ("#0EA5E9", "✎", "Paint")]
    for i, (c, gl, lab) in enumerate(apps):
        cx = x + 13 + (i % 6) * 22.5
        cy = y + 31 + (i // 6) * 19
        g.append(app(cx, cy, 9, c, gl))
        g.append(f'<text x="{cx + 4.5}" y="{cy + 13}" font-size="2.9" text-anchor="middle" fill="#1C2433">{lab}</text>')
    g.append(f'<text x="{x + 10}" y="{y + 80}" font-size="4.2" font-weight="700" fill="#1C2433">Recommandé</text>')
    rec = [("#2B579A", "W", "Rapport de stage.docx", "Il y a 2 h"), ("#1D6F42", "X", "Budget 2026.xlsx", "Hier"), ("#E0182D", "▤", "Attestation.pdf", "15 nov.")]
    for i, (c, gl, t, d) in enumerate(rec):
        ry = y + 85 + i * 10.5
        g.append(app(x + 10, ry, 7, c, gl))
        g.append(f'<text x="{x + 20}" y="{ry + 3.3}" font-size="3.3" fill="#1C2433">{t}</text><text x="{x + 20}" y="{ry + 6.6}" font-size="2.8" fill="#64748B">{d}</text>')
    g.append(f'<rect x="{x}" y="{y + h - 13}" width="{w}" height="13" fill="#EEF2F8"/><circle cx="{x + 14}" cy="{y + h - 6.5}" r="3.6" fill="#94A3B8"/>'
             f'<text x="{x + 20}" y="{y + h - 5.2}" font-size="3.5" fill="#1C2433">Aïcha Boubacar</text>'
             f'<circle cx="{x + w - 12}" cy="{y + h - 6.5}" r="3.6" fill="none" stroke="#1C2433" stroke-width=".6"/><path d="M{x + w - 12} {y + h - 10.6}v3.4" stroke="#1C2433" stroke-width=".6"/>')
    strip, cx0, s = taskbar_strip(0, H - 10, W, 10)
    g.append(strip)
    g.append(callout(1, x + 30, y + 11.5, 12, y + 12))
    g.append(callout(2, x + 17, y + 35, 12, y + 35))
    g.append(callout(3, x + w - 23, y + 23.5, W - 10, y + 24))
    g.append(callout(4, x + 14, y + 88, 12, y + 90))
    g.append(callout(5, x + 14, y + h - 6.5, 12, y + h - 6.5))
    g.append(callout(6, x + w - 12, y + h - 6.5, W - 10, y + h - 6.5))
    save("win11-start", W, H, "".join(g))


def win11_explorer():
    W, H = 200, 120
    x, y, w, h = 2, 2, 196, 116
    g = [f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="3" fill="#fff" stroke="#94A3B8" stroke-width=".5"/>',
         f'<rect x="{x}" y="{y}" width="{w}" height="9" rx="3" fill="#EEF2F8"/>',
         f'<rect x="{x + 4}" y="{y + 2}" width="40" height="7" rx="1.5" fill="#fff"/><text x="{x + 7}" y="{y + 6.8}" font-size="3.3" fill="#1C2433">▬ Documents      ✕</text>',
         f'<text x="{x + w - 4}" y="{y + 6.4}" font-size="4" text-anchor="end" fill="#1C2433">—    ▢    ✕</text>',
         f'<rect x="{x}" y="{y + 9}" width="{w}" height="9" fill="#F7F9FC"/>',
         f'<text x="{x + 4}" y="{y + 15}" font-size="3.4" fill="#1C2433">←  →  ↑  ⟳</text>',
         f'<rect x="{x + 28}" y="{y + 10.8}" width="110" height="5.6" rx="1.4" fill="#fff" stroke="#CBD5E1" stroke-width=".3"/><text x="{x + 31}" y="{y + 14.8}" font-size="3.2" fill="#1C2433">Ce PC  ›  Documents  ›  Cours HELP-ME</text>',
         f'<rect x="{x + 142}" y="{y + 10.8}" width="50" height="5.6" rx="1.4" fill="#fff" stroke="#CBD5E1" stroke-width=".3"/><text x="{x + 145}" y="{y + 14.8}" font-size="3.2" fill="#64748B">⌕ Rechercher</text>',
         f'<rect x="{x}" y="{y + 18}" width="{w}" height="9" fill="#fff"/><path d="M{x} {y + 27}h{w}" stroke="#E2E8F0" stroke-width=".4"/>',
         f'<text x="{x + 4}" y="{y + 24}" font-size="3.3" fill="#1C2433">⊕ Nouveau ▾    ✂   ⎘   ⎗   ✎   ⇪   🗑      ⇅ Trier ▾    ☰ Afficher ▾    ⋯</text>']
    nav = ["⌂ Accueil", "▣ Galerie", "☁ OneDrive", "", "▭ Bureau", "↓ Téléchargements", "▬ Documents", "▨ Images", "♪ Musique", "▶ Vidéos", "", "▭ Ce PC", "▭ Clé USB (E:)"]
    g.append(f'<rect x="{x}" y="{y + 27}" width="46" height="{h - 35}" fill="#F7F9FC"/>')
    for i, t in enumerate(nav):
        if not t:
            continue
        ty = y + 33 + i * 6.2
        if "Documents" in t:
            g.append(f'<rect x="{x + 2}" y="{ty - 4}" width="42" height="5.6" rx="1.2" fill="#DCE8F8"/>')
        g.append(f'<text x="{x + 4}" y="{ty}" font-size="3.2" fill="#1C2433">{t}</text>')
    items = [("#F2B01E", "▬", "Exercices"), ("#F2B01E", "▬", "Factures"), ("#F2B01E", "▬", "Photos"), ("#2B579A", "W", "Lettre.docx"),
             ("#1D6F42", "X", "Notes.xlsx"), ("#E0182D", "▤", "CV.pdf"), ("#0EA5E9", "▨", "Logo.png"), ("#6D35D6", "▶", "Cours.mp4")]
    for i, (c, gl, lab) in enumerate(items):
        ix = x + 54 + (i % 4) * 35
        iy = y + 33 + (i // 4) * 30
        if gl == "▬":
            g.append(f'<path d="M{ix} {iy + 3}h7l2 2h11v13h-20z" fill="{c}"/><rect x="{ix}" y="{iy + 6}" width="20" height="12" rx="1" fill="#FCD34D"/>')
        else:
            g.append(f'<path d="M{ix + 3} {iy}h11l5 5v14h-16z" fill="#fff" stroke="#94A3B8" stroke-width=".4"/>' + app(ix + 6, iy + 7, 9, c, gl))
        g.append(f'<text x="{ix + 10}" y="{iy + 24}" font-size="3.2" text-anchor="middle" fill="#1C2433">{lab}</text>')
    g.append(f'<rect x="{x}" y="{y + h - 8}" width="{w}" height="8" fill="#F7F9FC"/><text x="{x + 4}" y="{y + h - 2.8}" font-size="3" fill="#64748B">8 élément(s)</text>')
    for n, (bx, by) in enumerate([(x + 135, y + 13.6), (x + 188, y + 13.6), (x + 128, y + 22.5), (x + 40, y + 58), (x + 186, y + 92), (x + 34, y + h - 4)], 1):
        g.append(callout(n, bx, by, bx, by))
    save("win11-explorer", W, H, "".join(g))


def win11_titlebar():
    W, H = 200, 42
    g = [f'<rect x="2" y="2" width="196" height="16" rx="3" fill="#F7F9FC" stroke="#94A3B8" stroke-width=".5"/>',
         f'<text x="8" y="12" font-size="5" fill="#1C2433">Lettre de motivation – Word</text>']
    for i, (gl, lab, c) in enumerate([("—", "Réduire", "#1C2433"), ("▢", "Agrandir / Restaurer", "#1C2433"), ("✕", "Fermer", "#fff")]):
        bx = 138 + i * 20
        if i == 2:
            g.append(f'<rect x="{bx}" y="2.3" width="20" height="15.4" fill="{RED}"/>')
        g.append(f'<text x="{bx + 10}" y="12.4" font-size="6" text-anchor="middle" fill="{c}">{gl}</text>')
        ly = 27 + i * 6
        g.append(f'<path d="M{bx + 10} 18V{ly - 4}" stroke="{RED}" stroke-width=".6"/><text x="{bx + 10 if i else bx + 4}" y="{ly}" font-size="4.2" font-weight="650" text-anchor="{"end" if i == 1 else "middle"}" fill="{INK}">{lab}</text>')
    g.append(f'<path d="M60 18v6" stroke="{RED}" stroke-width=".6"/><text x="60" y="30" font-size="4.2" font-weight="650" text-anchor="middle" fill="{INK}">Barre de titre (glisser pour déplacer)</text>')
    save("win11-titlebar", W, H, "".join(g))


def win11_snap():
    W, H = 200, 64
    g = [f'<rect x="2" y="2" width="96" height="60" rx="3" fill="#F7F9FC" stroke="#94A3B8" stroke-width=".5"/>',
         f'<text x="50" y="10" font-size="4" text-anchor="middle" fill="#1C2433" font-weight="650">Survoler le bouton ▢</text>']
    layouts = [[(0, 0, .5, 1), (.5, 0, .5, 1)], [(0, 0, .66, 1), (.66, 0, .34, 1)], [(0, 0, .5, 1), (.5, 0, .5, .5), (.5, .5, .5, .5)], [(0, 0, .5, .5), (.5, 0, .5, .5), (0, .5, .5, .5), (.5, .5, .5, .5)]]
    for i, lay in enumerate(layouts):
        lx, ly = 8 + (i % 2) * 44, 15 + (i // 2) * 23
        for k, (a, b, c, d) in enumerate(lay):
            g.append(f'<rect x="{lx + a * 38 + .6}" y="{ly + b * 19 + .6}" width="{c * 38 - 1.2}" height="{d * 19 - 1.2}" rx="1.2" fill="{"#0B6CD8" if (i == 2 and k == 0) else "#CBD5E1"}"/>')
    g.append(f'<path d="M100 32h12" stroke="{MUTED}" stroke-width="1" marker-end="url(#ah2)"/><defs><marker id="ah2" viewBox="0 0 6 6" refX="5" refY="3" markerWidth="5" markerHeight="5" orient="auto"><path d="M0 0L6 3L0 6z" fill="{MUTED}"/></marker></defs>')
    g.append(f'<rect x="116" y="2" width="82" height="60" rx="3" fill="#1C2433"/>'
             f'<rect x="118" y="4" width="38.5" height="55" rx="1.2" fill="#E9EEF7"/><text x="137" y="33" font-size="4" text-anchor="middle" fill="{BLUE}" font-weight="700">Word</text>'
             f'<rect x="158" y="4" width="38" height="26.5" rx="1.2" fill="#E6F2EB"/><text x="177" y="19" font-size="4" text-anchor="middle" fill="#1D6F42" font-weight="700">Excel</text>'
             f'<rect x="158" y="32.5" width="38" height="26.5" rx="1.2" fill="#E0F2FE"/><text x="177" y="47" font-size="4" text-anchor="middle" fill="#0369A1" font-weight="700">Edge</text>')
    save("win11-snap", W, H, "".join(g))


if __name__ == "__main__":
    win11_desktop(); win11_taskbar(); win11_start(); win11_explorer(); win11_titlebar(); win11_snap()


# ================================================================== INTERNET
def arrowdefs(id_="a3", c=MUTED):
    return f'<defs><marker id="{id_}" viewBox="0 0 6 6" refX="5" refY="3" markerWidth="5" markerHeight="5" orient="auto-start-reverse"><path d="M0 0L6 3L0 6z" fill="{c}"/></marker></defs>'


def internet_diagram():
    W, H = 200, 78
    g = [arrowdefs()]
    nodes = [(4, "laptop", "Votre appareil"), (52, "router", "Box Wi-Fi ou\nantenne 4G/5G"), (100, "isp", "Fournisseur\nd’accès (FAI)"), (152, "server", "Serveur du\nsite Web")]
    for x, k, lab in nodes:
        g.append(f'<rect x="{x}" y="8" width="44" height="40" rx="4" fill="#F1F5FB"/>')
        if k == "laptop":
            g.append(device_icon("laptop", x + 3, 12))
        elif k == "router":
            g.append(f'<g transform="translate({x + 4},10)"><rect x="6" y="22" width="26" height="8" rx="2" fill="#fff" stroke="{BLUE}" stroke-width="1.6"/><circle cx="12" cy="26" r="1.2" fill="{GREEN}"/><path d="M10 22l-3-12M28 22l3-12" stroke="{BLUE}" stroke-width="1.6"/><path d="M13 12a8 8 0 0 1 12 0M15.5 15a4.5 4.5 0 0 1 7 0" fill="none" stroke="{BLUE}" stroke-width="1.6"/></g>')
        elif k == "isp":
            g.append(f'<g transform="translate({x + 4},10)"><path d="M19 4l-8 26h16z" fill="none" stroke="{BLUE}" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 20h10M12.5 25h13M16 14h6" stroke="{BLUE}" stroke-width="1.2"/><path d="M8 8a14 14 0 0 0 0 16M30 8a14 14 0 0 1 0 16" fill="none" stroke="{BLUE}" stroke-width="1.4"/></g>')
        else:
            g.append(device_icon("server", x + 3, 12))
        for j, t in enumerate(lab.split("\n")):
            g.append(f'<text x="{x + 22}" y="{56 + j * 5}" font-size="4.2" text-anchor="middle" font-weight="650" fill="{INK}">{t}</text>')
    for x in (48, 96, 148):
        g.append(f'<text x="{x + 2}" y="30" font-size="6" text-anchor="middle" fill="{MUTED}">⇄</text>')
    g.append(f'<path d="M26 70 H174" stroke="{RED}" stroke-width=".8" marker-end="url(#a3)"/><text x="100" y="76.5" font-size="4" text-anchor="middle" fill="{RED}">1. la requête part · 2. le serveur renvoie la page · le tout en moins d’une seconde</text>')
    save("internet", W, H, "".join(g))


def browser():
    W, H = 200, 102
    g = ['<g transform="translate(0,10)">',f'<rect x="2" y="2" width="196" height="88" rx="3" fill="#fff" stroke="#94A3B8" stroke-width=".5"/>',
         f'<rect x="2" y="2" width="196" height="9" rx="3" fill="#E5EAF1"/>',
         f'<rect x="6" y="3.5" width="46" height="7.5" rx="2" fill="#fff"/><text x="9" y="8.6" font-size="3.4" fill="{INK}">◉ HELP-ME · Accueil      ✕</text>',
         f'<rect x="53" y="3.5" width="40" height="7.5" rx="2" fill="#EEF2F7"/><text x="56" y="8.6" font-size="3.4" fill="{MUTED}">G  Google        ✕</text>',
         f'<text x="98" y="9" font-size="5" fill="{MUTED}">+</text>',
         f'<text x="194" y="8.5" font-size="3.6" text-anchor="end" fill="{INK}">—   ▢   ✕</text>',
         f'<rect x="2" y="11" width="196" height="10" fill="#fff"/>',
         f'<text x="6" y="17.6" font-size="4" fill="{INK}">←  →  ⟳</text>',
         f'<rect x="28" y="12.5" width="140" height="7" rx="3.5" fill="#EEF2F7"/>',
         f'<text x="32" y="17.4" font-size="3.6" fill="{INK}">🔒  https://www.helpme.it/cours/internet</text>',
         f'<text x="165" y="17.4" font-size="4" text-anchor="end" fill="#F2B01E">☆</text>',
         f'<text x="194" y="17.6" font-size="4" text-anchor="end" fill="{INK}">⇩  ⋮</text>',
         f'<rect x="2" y="21" width="196" height="6" fill="#F7F9FC"/><text x="6" y="25.4" font-size="3" fill="{MUTED}">★ Favoris :  Gmail   ·   Service public   ·   Cours HELP-ME</text>',
         f'<rect x="10" y="32" width="180" height="16" rx="2" fill="{BLUE}"/><text x="18" y="42" font-size="5.5" font-weight="800" fill="#fff">HELP-ME</text><text x="186" y="42" font-size="3.6" text-anchor="end" fill="#fff">Cours   Exercices   Contact</text>',
         f'<rect x="10" y="52" width="86" height="30" rx="2" fill="#EEF2F7"/><rect x="102" y="52" width="88" height="5" rx="1" fill="#CBD5E1"/><rect x="102" y="60" width="80" height="3" rx="1" fill="#E2E8F0"/><rect x="102" y="65" width="84" height="3" rx="1" fill="#E2E8F0"/><rect x="102" y="70" width="60" height="3" rx="1" fill="#E2E8F0"/>']
    g.append('</g>')
    for n, (x, y, tx, ty) in enumerate([(25, 13, 25, 4), (10, 26, 4, 30), (90, 26, 90, 4), (165, 26, 165, 4), (194, 26, 197, 38), (95, 34, 100, 40), (140, 75, 140, 75)], 1):
        g.append(callout(n, x, y, tx, ty))
    save("browser", W, H, "".join(g))


def url():
    W, H = 200, 34
    parts = [("https://", GREEN, "protocole\nsécurisé (cadenas)"), ("www.", MUTED, "World Wide\nWeb"), ("helpme", BLUE, "nom de\ndomaine"), (".it", AMBER, "extension\n(.ne, .com, .org)"), ("/cours/internet", AI, "chemin\n(page précise)")]
    x = 6
    g = []
    for t, c, lab in parts:
        w = len(t) * 4.25 + 2
        g.append(f'<rect x="{x}" y="4" width="{w}" height="10" rx="1.6" fill="{c}" opacity=".14"/><text x="{x + w / 2}" y="11.4" font-size="6.2" font-weight="700" text-anchor="middle" fill="{c}" font-family="DejaVu Sans Mono, monospace">{t}</text>')
        for j, l in enumerate(lab.split("\n")):
            g.append(f'<text x="{x + w / 2}" y="{21 + j * 4.6}" font-size="3.8" text-anchor="middle" fill="{INK}">{l}</text>')
        x += w + 1.5
    save("url", W, H, "".join(g))


def email_compose():
    W, H = 200, 104
    g = [f'<rect x="20" y="2" width="160" height="92" rx="3" fill="#fff" stroke="#94A3B8" stroke-width=".5"/>',
         f'<rect x="20" y="2" width="160" height="9" rx="3" fill="#EEF2F8"/><text x="25" y="8" font-size="3.8" font-weight="650" fill="{INK}">Nouveau message</text><text x="176" y="8" font-size="3.8" text-anchor="end" fill="{INK}">—  ⤢  ✕</text>']
    rows = [("À", "fatouma.issa@gmail.com"), ("Cc / Cci", ""), ("Objet", "Demande de stage – Service comptabilité")]
    for i, (a, b) in enumerate(rows):
        y = 12 + i * 8
        g.append(f'<path d="M24 {y + 7.5}h152" stroke="#E2E8F0" stroke-width=".4"/><text x="25" y="{y + 5.2}" font-size="3.5" fill="{MUTED}">{a}</text><text x="42" y="{y + 5.2}" font-size="3.5" fill="{INK}">{b}</text>')
    body = ["Madame,", "", "Je me permets de vous adresser ma candidature", "pour un stage de trois mois au sein de votre service.", "Vous trouverez ci-joint mon CV.", "", "Cordialement,", "Ibrahim Moussa"]
    for i, t in enumerate(body):
        g.append(f'<text x="25" y="{43 + i * 4.6}" font-size="3.4" fill="{INK}">{t}</text>')
    g.append(f'<rect x="25" y="80" width="38" height="6" rx="1.5" fill="#F1F5F9" stroke="#CBD5E1" stroke-width=".3"/><text x="28" y="84.2" font-size="3.1" fill="{INK}">📎 CV_Ibrahim.pdf (220 Ko)</text>')
    g.append(f'<rect x="25" y="87.5" width="22" height="6" rx="3" fill="#0B57D0"/><text x="33" y="91.6" font-size="3.4" text-anchor="middle" font-weight="700" fill="#fff">Envoyer</text><path d="M44 89.5l1.5 1.6 1.5-1.6" stroke="#fff" stroke-width=".4" fill="none"/>')
    g.append(f'<text x="52" y="92" font-size="3.8" fill="{MUTED}">A   📎   🔗   ☺   ▲   ▨   🔒   ✎   ⋮                       🗑</text>')
    for n, (x, y) in enumerate([(181, 16), (181, 24), (181, 32), (181, 55), (14, 83), (14, 91), (186, 91)], 1):
        g.append(callout(n, x, y, x, y))
    save("email", W, H, "".join(g))


def cloud_sync():
    W, H = 200, 80
    g = [arrowdefs("a4", BLUE),
         f'<path d="M78 36a14 14 0 0 1 4-27 20 20 0 0 1 37 4 12 12 0 0 1 3 23z" fill="{BLUE}" opacity=".12" stroke="{BLUE}" stroke-width="1"/>',
         f'<text x="100" y="24" font-size="5" text-anchor="middle" font-weight="800" fill="{BLUE}">Cloud</text><text x="100" y="30" font-size="3.6" text-anchor="middle" fill="{INK}">OneDrive · Google Drive</text>']
    g.append(device_icon("laptop", 10, 40))
    g.append(device_icon("phone", 81, 40))
    g.append(device_icon("tablet", 152, 40))
    for x1, y1, x2, y2 in [(32, 42, 78, 32), (100, 40, 100, 37), (168, 42, 122, 32)]:
        g.append(f'<path d="M{x1} {y1}L{x2} {y2}" stroke="{BLUE}" stroke-width=".9" stroke-dasharray="2 1.4" marker-end="url(#a4)" marker-start="url(#a4)"/>')
    for x, t in [(29, "Ordinateur"), (100, "Téléphone"), (171, "Tablette")]:
        g.append(f'<text x="{x}" y="{H - 1}" font-size="3.8" text-anchor="middle" font-weight="650" fill="{INK}">{t}</text>')
    save("cloud", W, H, "".join(g))


def twofa():
    W, H = 200, 54
    g = [arrowdefs("a5")]
    steps = [("1", "Mot de passe", "ce que vous savez", BLUE, '<rect x="9" y="9" width="20" height="12" rx="2" fill="none" stroke="#fff" stroke-width="1.6"/><text x="19" y="18.4" font-size="7" text-anchor="middle" fill="#fff">•••</text>'),
             ("2", "Code reçu", "ce que vous avez (téléphone)", AI, '<rect x="13" y="5" width="12" height="20" rx="2" fill="none" stroke="#fff" stroke-width="1.6"/><text x="19" y="18" font-size="5" text-anchor="middle" fill="#fff" font-weight="800">482</text>'),
             ("✓", "Accès autorisé", "compte protégé", GREEN, '<path d="M11 15l5 5 10-10" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>')]
    for i, (n, t, s, c, ic) in enumerate(steps):
        x = 6 + i * 68
        g.append(f'<rect x="{x}" y="4" width="52" height="46" rx="4" fill="{c}" opacity=".1"/><g transform="translate({x + 7},6)"><circle cx="19" cy="15" r="15" fill="{c}"/>{ic}</g>'
                 f'<text x="{x + 26}" y="41" font-size="4.4" text-anchor="middle" font-weight="800" fill="{c}">{t}</text><text x="{x + 26}" y="46.5" font-size="3.3" text-anchor="middle" fill="{INK}">{s}</text>')
        if i < 2:
            g.append(f'<path d="M{x + 54} 21h10" stroke="{MUTED}" stroke-width="1" marker-end="url(#a5)"/>')
    save("twofa", W, H, "".join(g))


def phishing():
    W, H = 200, 82
    g = [f'<rect x="40" y="2" width="120" height="78" rx="8" fill="#1C2433"/><rect x="44" y="10" width="112" height="64" rx="2" fill="#F1F5F9"/>',
         f'<text x="100" y="7.6" font-size="3.4" text-anchor="middle" fill="#fff">+227 9x xx xx xx</text>',
         f'<rect x="48" y="15" width="100" height="40" rx="4" fill="#fff" stroke="#CBD5E1" stroke-width=".4"/>']
    msg = ["Félicitations ! Vous avez reçu", "un transfert de 150 000 F CFA par erreur.", "Merci de renvoyer le montant au", "9x xx xx xx sous 30 minutes", "sinon votre compte sera BLOQUÉ.", "Confirmez ici : bit.ly/m0ney-c0nfirm"]
    for i, t in enumerate(msg):
        g.append(f'<text x="52" y="{22 + i * 5.3}" font-size="3.5" fill="{INK}">{t}</text>')
    flags = [(1, 52, 22, 10, 22), (2, 120, 43, 190, 43), (3, 140, 48.5, 190, 55), (4, 100, 54, 190, 67)]
    for n, x, y, tx, ty in flags:
        g.append(callout(n, x, y, tx, ty))
    save("phishing", W, H, "".join(g))


if __name__ == "__main__":
    internet_diagram(); browser(); url(); email_compose(); cloud_sync(); twofa(); phishing()


# ================================================================== INTELLIGENCE ARTIFICIELLE
def ai_nested():
    W, H = 200, 104
    rings = [(100, 54, 96, 50, "#EDE7FB", AI, "Intelligence artificielle", "Machines qui imitent des capacités humaines", 12),
             (100, 62, 74, 40, "#DCCFF8", "#5B2BB8", "Apprentissage automatique", "Apprend à partir d’exemples (données)", 30),
             (100, 70, 52, 30, "#C4AEF2", "#4B21A6", "Apprentissage profond", "Réseaux de neurones à nombreuses couches", 47),
             (100, 79, 30, 19, AI, "#fff", "IA générative", "Crée textes, images, sons", 75)]
    g = []
    for cx, cy, rx, ry, fill, tc, t, s, ty in rings:
        g.append(f'<ellipse cx="{cx}" cy="{cy}" rx="{rx}" ry="{ry}" fill="{fill}"/>')
        g.append(f'<text x="100" y="{ty}" font-size="{5 if ty < 70 else 4.6}" font-weight="800" text-anchor="middle" fill="{tc}">{t}</text>'
                 f'<text x="100" y="{ty + 5}" font-size="3.4" text-anchor="middle" fill="{tc if tc == "#fff" else INK}">{s}</text>')
    save("ai-nested", W, H, "".join(g))


def ai_learn():
    W, H = 200, 74
    g = [arrowdefs("a6", AI)]
    steps = [("Données", "Milliers d’exemples\n(photos de feuilles\nsaines ou malades)", BLUE),
             ("Entraînement", "L’algorithme ajuste\nses paramètres\npour réduire l’erreur", AI),
             ("Modèle", "Le « savoir »\nappris, enregistré\ndans un fichier", "#4B21A6"),
             ("Prédiction", "Nouvelle photo :\n« mildiou, 94 % »", GREEN)]
    for i, (t, s, c) in enumerate(steps):
        x = 3 + i * 50
        g.append(f'<rect x="{x}" y="6" width="42" height="56" rx="4" fill="{c}" opacity=".1"/><rect x="{x}" y="6" width="42" height="11" rx="4" fill="{c}"/>'
                 f'<text x="{x + 21}" y="13.6" font-size="4.6" font-weight="800" text-anchor="middle" fill="#fff">{t}</text>')
        for j, l in enumerate(s.split("\n")):
            g.append(f'<text x="{x + 21}" y="{27 + j * 5.4}" font-size="3.6" text-anchor="middle" fill="{INK}">{l}</text>')
        if i < 3:
            g.append(f'<path d="M{x + 43} 34h5.5" stroke="{AI}" stroke-width="1.1" marker-end="url(#a6)"/>')
    g.append(f'<path d="M71 66 C 71 72, 23 72, 23 63" fill="none" stroke="{MUTED}" stroke-width=".6" stroke-dasharray="1.6 1.2" marker-end="url(#a6)"/>'
             f'<text x="47" y="72.5" font-size="3.2" text-anchor="middle" fill="{MUTED}">on corrige et on recommence des milliers de fois</text>')
    save("ai-learn", W, H, "".join(g))


def neural():
    W, H = 200, 84
    layers = [3, 5, 5, 2]
    xs = [30, 80, 130, 175]
    pos = []
    for L, n in enumerate(layers):
        ys = [42 + (k - (n - 1) / 2) * 13 for k in range(n)]
        pos.append([(xs[L], y) for y in ys])
    g = []
    for L in range(3):
        for a in pos[L]:
            for b in pos[L + 1]:
                g.append(f'<path d="M{a[0]} {a[1]}L{b[0]} {b[1]}" stroke="{AI}" stroke-opacity=".28" stroke-width=".5"/>')
    cols = [BLUE, AI, AI, GREEN]
    for L, layer in enumerate(pos):
        for (x, y) in layer:
            g.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#fff" stroke="{cols[L]}" stroke-width="1.3"/>')
    for t, x in [("Entrées", 30), ("Couches cachées", 105), ("Sortie", 175)]:
        g.append(f'<text x="{x}" y="80" font-size="4.2" font-weight="700" text-anchor="middle" fill="{INK}">{t}</text>')
    for t, y in [("pixels", 29), ("de", 42), ("l’image", 55)]:
        g.append(f'<text x="22" y="{y + 1.4}" font-size="3.4" text-anchor="end" fill="{MUTED}">{t}</text>')
    g.append(f'<text x="181" y="{pos[3][0][1] + 1.4}" font-size="3.6" fill="{GREEN}" font-weight="700">chat 97 %</text>'
             f'<text x="181" y="{pos[3][1][1] + 1.4}" font-size="3.6" fill="{MUTED}">chien 3 %</text>')
    save("neural", W, H, "".join(g))


def next_token():
    W, H = 200, 60
    words = ["La", "capitale", "du", "Niger", "est"]
    g = []
    x = 4
    for w in words:
        ww = len(w) * 3.2 + 6
        g.append(f'<rect x="{x}" y="6" width="{ww}" height="10" rx="2" fill="#EEF2F7" stroke="#CBD5E1" stroke-width=".4"/><text x="{x + ww / 2}" y="12.8" font-size="4.4" text-anchor="middle" fill="{INK}">{w}</text>')
        x += ww + 2
    g.append(f'<rect x="{x}" y="6" width="22" height="10" rx="2" fill="{AI}"/><text x="{x + 11}" y="12.8" font-size="4.4" text-anchor="middle" fill="#fff" font-weight="800">?</text>')
    cands = [("Niamey", .92, AI), ("une", .04, "#A78BFA"), ("située", .02, "#C4B5FD"), ("…", .02, "#DDD6FE")]
    for i, (w, p, c) in enumerate(cands):
        y = 24 + i * 8
        g.append(f'<text x="{x - 2}" y="{y + 4.4}" font-size="4" text-anchor="end" fill="{INK}">{w}</text>'
                 f'<rect x="{x}" y="{y}" width="{max(1.5, p * 70)}" height="5.4" rx="1" fill="{c}"/>'
                 f'<text x="{x + max(1.5, p * 70) + 2}" y="{y + 4.4}" font-size="3.6" fill="{MUTED}">{int(p * 100)} %</text>')
    g.append(f'<text x="4" y="30" font-size="3.6" fill="{MUTED}">Le modèle calcule la</text><text x="4" y="35" font-size="3.6" fill="{MUTED}">probabilité de chaque</text><text x="4" y="40" font-size="3.6" fill="{MUTED}">mot suivant possible,</text><text x="4" y="45" font-size="3.6" fill="{MUTED}">choisit, puis recommence</text><text x="4" y="50" font-size="3.6" fill="{MUTED}">mot après mot.</text>')
    save("next-token", W, H, "".join(g))


def ai_chat():
    W, H = 200, 112
    g = [f'<rect x="2" y="2" width="196" height="108" rx="4" fill="#fff" stroke="#94A3B8" stroke-width=".5"/>',
         f'<rect x="2" y="2" width="44" height="108" rx="4" fill="#F5F3FF"/><rect x="40" y="2" width="6" height="108" fill="#F5F3FF"/>',
         f'<rect x="6" y="7" width="36" height="7" rx="2" fill="#fff" stroke="#DDD6FE" stroke-width=".4"/><text x="9" y="11.8" font-size="3.3" fill="{AI}" font-weight="700">✎ Nouvelle discussion</text>']
    for i, t in enumerate(["Lettre de stage", "Formules Excel", "Résumé du cours", "Idées d’exposé"]):
        g.append(f'<text x="8" y="{24 + i * 7}" font-size="3.2" fill="{INK}">{t}</text>')
    g.append(f'<text x="8" y="104" font-size="3" fill="{MUTED}">⚙ Paramètres · Confidentialité</text>')
    g.append(f'<rect x="96" y="8" width="96" height="20" rx="4" fill="#EDE9FE"/>')
    q = ["Tu es conseiller d’orientation. Je suis en", "terminale D au Niger. Propose-moi 3 métiers", "liés à l’agriculture et à l’informatique,", "sous forme de tableau."]
    for i, t in enumerate(q):
        g.append(f'<text x="99" y="{13 + i * 4.2}" font-size="3.1" fill="{INK}">{t}</text>')
    g.append(f'<circle cx="55" cy="36" r="3.6" fill="{AI}"/><text x="55" y="37.6" font-size="4" text-anchor="middle" fill="#fff">✦</text>')
    g.append(f'<text x="61" y="37.4" font-size="3.3" fill="{INK}">Voici trois métiers d’avenir qui combinent ces domaines :</text>')
    rows = [("Métier", "Missions", "Formation"), ("Technicien en agriculture", "Capteurs, irrigation", "BTS, licence"), ("Analyste de données agricoles", "Prévisions, rendements", "Licence, master"), ("Développeur d’applis agricoles", "Conseils aux paysans", "Licence info")]
    for i, r in enumerate(rows):
        y = 42 + i * 7
        fill = AI if i == 0 else ("#F8F7FC" if i % 2 else "#fff")
        g.append(f'<rect x="61" y="{y}" width="130" height="7" fill="{fill}"/>')
        for j, c in enumerate(r):
            g.append(f'<text x="{63 + j * 46}" y="{y + 4.8}" font-size="3" fill="{"#fff" if i == 0 else INK}" font-weight="{700 if i == 0 else 400}">{c}</text>')
    g.append(f'<text x="61" y="76" font-size="3.3" fill="{INK}">Veux-tu que je détaille les écoles au Niger pour chacun ?</text>')
    g.append(f'<text x="61" y="83" font-size="3.4" fill="{MUTED}">⎘  ⟳  👍  👎</text>')
    g.append(f'<rect x="52" y="90" width="140" height="13" rx="6.5" fill="#fff" stroke="#C4B5FD" stroke-width=".6"/><text x="58" y="98" font-size="3.4" fill="{MUTED}">＋  Écrivez votre message…</text>'
             f'<circle cx="185" cy="96.5" r="4.4" fill="{AI}"/><text x="185" y="98.2" font-size="4.6" text-anchor="middle" fill="#fff">↑</text><text x="174" y="98.2" font-size="4" text-anchor="middle" fill="{MUTED}">🎙</text>')
    g.append(f'<text x="122" y="108" font-size="2.7" text-anchor="middle" fill="{MUTED}">L’IA peut se tromper. Vérifiez les informations importantes.</text>')
    for n, (x, y) in enumerate([(43, 10), (43, 33), (192, 18), (192, 58), (78, 83), (52, 96.5), (192, 108)], 1):
        g.append(callout(n, x, y, x, y))
    save("ai-chat", W, H, "".join(g))


if __name__ == "__main__":
    ai_nested(); ai_learn(); neural(); next_token(); ai_chat()


# ================================================================== WORD / EXCEL
def ribbon(x, y, w, color, tabs, active, groups):
    g = [f'<rect x="{x}" y="{y}" width="{w}" height="7" fill="{color}"/>']
    tx = x + 3
    for i, t in enumerate(tabs):
        tw = len(t) * 2.2 + 4
        if i == active:
            g.append(f'<rect x="{tx - 1}" y="{y + 1.2}" width="{tw}" height="5.8" rx="1" fill="#fff"/>')
        g.append(f'<text x="{tx + 1}" y="{y + 5.2}" font-size="3" fill="{color if i == active else "#fff"}" font-weight="{700 if i == active else 400}">{t}</text>')
        tx += tw + 1
    g.append(f'<rect x="{x}" y="{y + 7}" width="{w}" height="15" fill="#fff"/><path d="M{x} {y + 22}h{w}" stroke="#CBD5E1" stroke-width=".4"/>')
    gx = x + 2
    for name, content in groups:
        gw = max(len(name) * 2.3, 18) + 6
        g.append(f'<text x="{gx + gw / 2}" y="{y + 20.6}" font-size="2.5" text-anchor="middle" fill="{MUTED}">{name}</text>'
                 f'<text x="{gx + gw / 2}" y="{y + 14}" font-size="3.4" text-anchor="middle" fill="{INK}">{content}</text>'
                 f'<path d="M{gx + gw} {y + 9}v12" stroke="#E2E8F0" stroke-width=".4"/>')
        gx += gw + 1
    return "".join(g)


def word_window():
    W, H = 200, 140
    c = "#2B579A"
    g = [f'<rect x="2" y="2" width="196" height="128" rx="3" fill="#E9EDF3" stroke="#94A3B8" stroke-width=".5"/>',
         f'<rect x="2" y="2" width="196" height="9" rx="3" fill="{c}"/>',
         f'<text x="6" y="8" font-size="3.4" fill="#fff">💾  ↶  ↷   Enregistrement automatique ●</text>',
         f'<text x="100" y="8" font-size="3.4" text-anchor="middle" fill="#fff">Rapport de stage – Word</text>',
         f'<rect x="128" y="3.6" width="34" height="5.4" rx="2.7" fill="#fff" opacity=".25"/><text x="131" y="7.6" font-size="3" fill="#fff">⌕ Rechercher</text>',
         f'<text x="194" y="8" font-size="3.4" text-anchor="end" fill="#fff">✦  ⇪ Partager  —  ▢  ✕</text>',
         ribbon(2, 11, 196, c, ["Fichier", "Accueil", "Insertion", "Création", "Disposition", "Références", "Publipostage", "Révision", "Affichage"], 1,
                [("Presse-papiers", "📋 ✂ ⎘"), ("Police", "Aptos ▾ 11 ▾ G I S A"), ("Paragraphe", "☰ ≡ ⇤ ⇥ ¶"), ("Styles", "Normal  Titre 1  Titre 2"), ("Édition", "⌕ ⇄"), ("Copilot", "✦")]),
         f'<rect x="46" y="38" width="108" height="84" fill="#fff" stroke="#CBD5E1" stroke-width=".3"/>',
         f'<text x="56" y="50" font-size="5.2" font-weight="800" fill="{c}">Rapport de stage</text>',
         f'<rect x="56" y="55" width="88" height="2" fill="#CBD5E1"/><rect x="56" y="59" width="84" height="2" fill="#CBD5E1"/><rect x="56" y="63" width="86" height="2" fill="#CBD5E1"/><rect x="56" y="67" width="60" height="2" fill="#CBD5E1"/>',
         f'<path d="M117 66v4" stroke="#000" stroke-width=".5"/>',
         f'<rect x="2" y="124" width="196" height="6" fill="#F1F5F9"/><text x="6" y="128.2" font-size="2.9" fill="{MUTED}">Page 1 sur 3   ·   412 mots   ·   Français (France)   ·   ✓ Accessibilité</text>'
         f'<text x="194" y="128.2" font-size="2.9" text-anchor="end" fill="{MUTED}">▤ ▭ ▦   — ●——— +  100 %</text>']
    for n, (x, y) in enumerate([(74, 6.5), (121, 6.5), (23.5, 14.5), (75, 36.5), (152, 36.5), (121, 70), (100, 127), (148, 127)], 1):
        g.append(callout(n, x, y, x, y))
    save("word-window", W, H, "".join(g))


def excel_window():
    W, H = 200, 140
    c = "#1D6F42"
    g = [f'<rect x="2" y="2" width="196" height="128" rx="3" fill="#fff" stroke="#94A3B8" stroke-width=".5"/>',
         f'<rect x="2" y="2" width="196" height="9" rx="3" fill="{c}"/>',
         f'<text x="6" y="8" font-size="3.4" fill="#fff">💾  ↶  ↷</text><text x="100" y="8" font-size="3.4" text-anchor="middle" fill="#fff">Ventes 2026 – Excel</text>',
         f'<text x="194" y="8" font-size="3.4" text-anchor="end" fill="#fff">✦  ⇪ Partager  —  ▢  ✕</text>',
         ribbon(2, 11, 196, c, ["Fichier", "Accueil", "Insertion", "Mise en page", "Formules", "Données", "Révision", "Affichage"], 1,
                [("Presse-papiers", "📋 ✂"), ("Police", "Aptos ▾ 11 G I S"), ("Alignement", "≡ ⇔ ⧉"), ("Nombre", "% 000 ,0"), ("Styles", "▦ ▤"), ("Édition", "Σ ⇅ ⌕")]),
         f'<rect x="2" y="33" width="196" height="7" fill="#F7F9FC"/><rect x="4" y="34" width="18" height="5" fill="#fff" stroke="#CBD5E1" stroke-width=".3"/><text x="6" y="37.7" font-size="3.1" fill="{INK}">D3</text>'
         f'<text x="26" y="37.7" font-size="3.4" fill="{MUTED}">✕ ✓ fx</text><rect x="40" y="34" width="156" height="5" fill="#fff" stroke="#CBD5E1" stroke-width=".3"/><text x="42" y="37.7" font-size="3.1" fill="{INK}">=B3*C3</text>']
    cols = ["", "A", "B", "C", "D", "E", "F"]
    widths = [8, 42, 24, 26, 28, 24, 24]
    data = [["Article", "Quantité", "Prix (F CFA)", "Total"], ["Cahiers", "120", "350", "42 000"], ["Stylos", "300", "100", "30 000"], ["Règles", "80", "250", "20 000"], ["Total", "", "", "92 000"]]
    y0 = 41
    x = 2
    for j, cname in enumerate(cols):
        g.append(f'<rect x="{x}" y="{y0}" width="{widths[j]}" height="5.5" fill="#EEF2F7" stroke="#D9DEE6" stroke-width=".25"/><text x="{x + widths[j] / 2}" y="{y0 + 4}" font-size="3" text-anchor="middle" fill="{MUTED}">{cname}</text>')
        x += widths[j]
    for r in range(12):
        y = y0 + 5.5 + r * 6
        x = 2
        for j in range(7):
            fill = "#EEF2F7" if j == 0 else "#fff"
            if r == 0 and 1 <= j <= 4:
                fill = "#E6F2EB"
            g.append(f'<rect x="{x}" y="{y}" width="{widths[j]}" height="6" fill="{fill}" stroke="#E2E8F0" stroke-width=".25"/>')
            if j == 0:
                g.append(f'<text x="{x + 4}" y="{y + 4.3}" font-size="3" text-anchor="middle" fill="{MUTED}">{r + 1}</text>')
            elif r < len(data) and j <= 4:
                t = data[r][j - 1]
                bold = r in (0, 4)
                anchor, tx = ("end", x + widths[j] - 1.5) if (j > 1 and r > 0) else ("start", x + 1.5)
                g.append(f'<text x="{tx}" y="{y + 4.3}" font-size="3.1" text-anchor="{anchor}" font-weight="{700 if bold else 400}" fill="{INK}">{t}</text>')
            x += widths[j]
    g.append(f'<rect x="{2 + 8 + 42 + 24 + 26}" y="{y0 + 5.5 + 2 * 6}" width="28" height="6" fill="none" stroke="{c}" stroke-width="1"/><rect x="{2 + 8 + 42 + 24 + 26 + 26.5}" y="{y0 + 5.5 + 2 * 6 + 4.5}" width="2.2" height="2.2" fill="{c}"/>')
    g.append(f'<rect x="2" y="118" width="196" height="6" fill="#F7F9FC"/><rect x="12" y="118" width="22" height="6" fill="#fff"/><text x="23" y="122.2" font-size="3.1" text-anchor="middle" fill="{c}" font-weight="700">Feuil1</text><text x="38" y="122.4" font-size="4" fill="{MUTED}">⊕</text>'
             f'<rect x="2" y="124" width="196" height="6" fill="{"#F1F5F9"}"/><text x="6" y="128.2" font-size="2.9" fill="{MUTED}">Prêt</text><text x="194" y="128.2" font-size="2.9" text-anchor="end" fill="{MUTED}">Somme : 30 000   ▦ ▤ ▭  — ●— +  100 %</text>')
    for n, (x, y) in enumerate([(124, 6.5), (23.5, 14.5), (24, 36.5), (62, 36.5), (47, 44), (5, 60), (134.5, 61.5), (40, 121), (140, 127)], 1):
        g.append(callout(n, x, y, x, y))
    save("excel-window", W, H, "".join(g))


def refs():
    W, H = 200, 74
    c = "#1D6F42"
    g = []

    def grid(x0, title, formulas, hl):
        out = [f'<text x="{x0 + 45}" y="6" font-size="4.4" font-weight="800" text-anchor="middle" fill="{c}">{title}</text>']
        hdr = ["", "A", "B", "C"]
        ws = [7, 26, 26, 31]
        x = x0
        for j, h in enumerate(hdr):
            out.append(f'<rect x="{x}" y="9" width="{ws[j]}" height="5" fill="#EEF2F7" stroke="#D9DEE6" stroke-width=".25"/><text x="{x + ws[j] / 2}" y="12.6" font-size="3" text-anchor="middle" fill="{MUTED}">{h}</text>')
            x += ws[j]
        vals = [["Prix", "Qté", "Total"], ["1 000", "2", ""], ["2 500", "4", ""], ["500", "10", ""]]
        for r in range(4):
            x = x0
            y = 14 + r * 6
            for j in range(4):
                fill = "#EEF2F7" if j == 0 else ("#E6F2EB" if (j == 3 and r > 0) else "#fff")
                out.append(f'<rect x="{x}" y="{y}" width="{ws[j]}" height="6" fill="{fill}" stroke="#E2E8F0" stroke-width=".25"/>')
                txt = str(r + 1) if j == 0 else (vals[r][j - 1] if j < 3 or r == 0 else formulas[r - 1])
                out.append(f'<text x="{x + 1.4}" y="{y + 4.2}" font-size="{2.8 if (j == 3 and r > 0) else 3.1}" fill="{INK}" font-family="DejaVu Sans Mono, monospace">{txt}</text>')
                x += ws[j]
        out.append(f'<text x="{x0 + 45}" y="47" font-size="3.4" text-anchor="middle" fill="{INK}">{hl}</text>')
        return "".join(out)
    g.append(grid(4, "Relative : A2 → A3 → A4", ["=A2*B2", "=A3*B3", "=A4*B4"], "En recopiant vers le bas, les références suivent."))
    g.append(grid(106, "Absolue : $E$1 reste fixe", ["=A2*$E$1", "=A3*$E$1", "=A4*$E$1"], "Le $ bloque la colonne et la ligne."))
    g.append(f'<rect x="106" y="52" width="90" height="18" rx="2" fill="#E6F2EB"/><text x="151" y="58.6" font-size="3.4" text-anchor="middle" fill="{c}" font-weight="700">E1 = taux de TVA (19 %)</text><text x="151" y="64.6" font-size="3.2" text-anchor="middle" fill="{INK}">Touche F4 : A1 → $A$1 → A$1 → $A1</text>')
    g.append(f'<rect x="4" y="52" width="92" height="18" rx="2" fill="#F1F5F9"/><text x="50" y="58.6" font-size="3.4" text-anchor="middle" fill="{INK}" font-weight="700">Poignée de recopie ■</text><text x="50" y="64.6" font-size="3.2" text-anchor="middle" fill="{INK}">Faire glisser le petit carré vers le bas</text>')
    save("excel-refs", W, H, "".join(g))


def condformat():
    W, H = 200, 58
    names = ["Abdou", "Fati", "Issa", "Mariam", "Oumar", "Zeinab"]
    notes = [14, 8, 11.5, 17, 6.5, 10]
    g = [f'<text x="4" y="6" font-size="4.2" font-weight="800" fill="#1D6F42">Notes : &lt; 10 en rouge, ≥ 10 en vert</text>']
    for i, (n, v) in enumerate(zip(names, notes)):
        y = 10 + i * 7.6
        ok = v >= 10
        g.append(f'<rect x="4" y="{y}" width="40" height="7" fill="#fff" stroke="#E2E8F0" stroke-width=".3"/><text x="6" y="{y + 4.9}" font-size="3.4" fill="{INK}">{n}</text>'
                 f'<rect x="44" y="{y}" width="22" height="7" fill="{"#DCFCE7" if ok else "#FEE2E2"}" stroke="#E2E8F0" stroke-width=".3"/><text x="64" y="{y + 4.9}" font-size="3.4" text-anchor="end" fill="{"#166534" if ok else "#991B1B"}" font-weight="700">{str(v).replace(".", ",")}</text>')
    g.append(f'<text x="110" y="6" font-size="4.2" font-weight="800" fill="#1D6F42">Barres de données</text>')
    for i, v in enumerate([42, 30, 20, 55, 12, 36]):
        y = 10 + i * 7.6
        g.append(f'<rect x="110" y="{y}" width="80" height="7" fill="#fff" stroke="#E2E8F0" stroke-width=".3"/><rect x="110.5" y="{y + .8}" width="{v * 1.3}" height="5.4" fill="#60A5FA" opacity=".7"/><text x="188" y="{y + 4.9}" font-size="3.4" text-anchor="end" fill="{INK}">{v * 1000:,}'.replace(",", " ") + ' F</text>')
    save("excel-condformat", W, H, "".join(g))


def charts():
    W, H = 200, 50
    g = []
    items = [("Colonnes", "comparer"), ("Barres", "classer"), ("Courbes", "évolution"), ("Secteurs", "parts d’un tout"), ("Nuage", "relation")]
    for i, (t, s) in enumerate(items):
        x = 2 + i * 40
        g.append(f'<rect x="{x}" y="2" width="37" height="46" rx="3" fill="#F1F5FB"/>')
        ox, oy = x + 6, 32
        if i == 0:
            for k, h in enumerate([12, 20, 15, 24]):
                g.append(f'<rect x="{ox + k * 6.5}" y="{oy - h}" width="4.6" height="{h}" fill="{["#1D6F42", "#2B579A", "#D97706", "#6D35D6"][k]}"/>')
        elif i == 1:
            for k, w in enumerate([24, 18, 12, 8]):
                g.append(f'<rect x="{ox}" y="{8 + k * 6}" width="{w}" height="4.4" fill="#2B579A"/>')
        elif i == 2:
            g.append(f'<polyline points="{ox},{oy - 4} {ox + 7},{oy - 10} {ox + 14},{oy - 7} {ox + 21},{oy - 18} {ox + 26},{oy - 22}" fill="none" stroke="#E0182D" stroke-width="1.4"/>')
        elif i == 3:
            g.append(f'<circle cx="{x + 18.5}" cy="20" r="11" fill="#1D6F42"/><path d="M{x + 18.5} 20L{x + 18.5} 9A11 11 0 0 1 {x + 29} 23z" fill="#D97706"/><path d="M{x + 18.5} 20L{x + 29} 23A11 11 0 0 1 {x + 14} 30z" fill="#2B579A"/>')
        else:
            for (a, b) in [(2, 4), (6, 8), (9, 7), (13, 13), (16, 12), (20, 18), (24, 21)]:
                g.append(f'<circle cx="{ox + a}" cy="{oy - b}" r="1.3" fill="#6D35D6"/>')
        if i in (0, 2, 4):
            g.append(f'<path d="M{ox - 1} 8V{oy}H{ox + 28}" fill="none" stroke="#94A3B8" stroke-width=".5"/>')
        g.append(f'<text x="{x + 18.5}" y="39" font-size="4" font-weight="750" text-anchor="middle" fill="{INK}">{t}</text><text x="{x + 18.5}" y="44" font-size="3.2" text-anchor="middle" fill="{MUTED}">{s}</text>')
    save("excel-charts", W, H, "".join(g))


def pivot():
    W, H = 200, 70
    c = "#1D6F42"
    g = [arrowdefs("a7", c)]
    rows = [("Mois", "Produit", "Ventes"), ("Janv.", "Mil", "120"), ("Janv.", "Riz", "80"), ("Févr.", "Mil", "150"), ("Févr.", "Riz", "95"), ("Mars", "Mil", "130"), ("Mars", "Riz", "110")]
    for i, r in enumerate(rows):
        y = 6 + i * 7
        for j, v in enumerate(r):
            g.append(f'<rect x="{4 + j * 22}" y="{y}" width="22" height="7" fill="{c if i == 0 else "#fff"}" stroke="#E2E8F0" stroke-width=".3"/><text x="{6 + j * 22}" y="{y + 4.8}" font-size="3.2" fill="{"#fff" if i == 0 else INK}">{v}</text>')
    g.append(f'<text x="37" y="62" font-size="3.4" text-anchor="middle" fill="{MUTED}">Données brutes (une ligne par vente)</text>')
    g.append(f'<path d="M74 28h20" stroke="{c}" stroke-width="1.2" marker-end="url(#a7)"/><text x="84" y="24" font-size="3.2" text-anchor="middle" fill="{c}" font-weight="700">TCD</text>')
    piv = [("", "Mil", "Riz", "Total"), ("Janv.", "120", "80", "200"), ("Févr.", "150", "95", "245"), ("Mars", "130", "110", "240"), ("Total", "400", "285", "685")]
    for i, r in enumerate(piv):
        y = 10 + i * 8
        for j, v in enumerate(r):
            bold = i in (0, 4) or j in (0, 3)
            g.append(f'<rect x="{100 + j * 23}" y="{y}" width="23" height="8" fill="{"#E6F2EB" if bold else "#fff"}" stroke="#CBD5E1" stroke-width=".3"/><text x="{121 + j * 23 if j else 102}" y="{y + 5.4}" font-size="3.4" text-anchor="{"end" if j else "start"}" font-weight="{700 if bold else 400}" fill="{INK}">{v}</text>')
    g.append(f'<text x="146" y="62" font-size="3.4" text-anchor="middle" fill="{MUTED}">Lignes : Mois · Colonnes : Produit · Valeurs : Somme</text>')
    save("excel-pivot", W, H, "".join(g))


if __name__ == "__main__":
    word_window(); excel_window(); refs(); condformat(); charts(); pivot()
