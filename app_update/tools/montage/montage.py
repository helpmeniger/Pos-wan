#!/usr/bin/env python3
"""Montage des vidéos d'explication HELP-ME (codes-barres du manuel).

Reprend une vidéo source (image + voix off) et ajoute, image par image :
 - un générique d'ouverture et un écran de fin « À retenir » ;
 - les sous-titres exacts du texte lu, calés sur les pauses de la voix, avec surlignage mot à mot ;
 - un bandeau d'étape (« Étape 2 sur 6 · Cliquer ») et une barre de progression ;
 - des loupes animées qui grossissent la partie expliquée de l'image ;
 - des schémas animés (bouton d'alimentation, souris, pavé tactile, fenêtre, barre des tâches).

Usage : python3 montage.py config.json sortie.mp4 --sources DOSSIER_DES_VIDEOS [--preview SECONDES]
"""
import json
import math
import re
import subprocess
import sys
from functools import lru_cache
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

W, H, FPS = 1920, 1080, 30
BRAND, BRAND_DARK, ACCENT = (50, 111, 191), (31, 79, 150), (217, 130, 43)
WHITE, INK = (255, 255, 255), (27, 35, 48)
FONT_DIR = "/usr/share/fonts/opentype/inter/"


@lru_cache(None)
def font(weight, size):
    return ImageFont.truetype(FONT_DIR + f"Inter-{weight}.otf", size)


def ease(x):                    # douce au départ et à l'arrivée
    x = min(1.0, max(0.0, x))
    return x * x * (3 - 2 * x)


def ease_out(x):
    x = min(1.0, max(0.0, x))
    return 1 - (1 - x) ** 3


def env(t, a, b, fade=0.35):
    """Opacité 0..1 d'un élément visible entre a et b (apparition et disparition en fondu)."""
    if t < a or t > b:
        return 0.0
    return ease(min((t - a) / fade, (b - t) / fade, 1.0))


# ====================================================================== alignement texte / voix
def speech_segments(path, noise="-32dB", dur=0.3):
    out = subprocess.run(["ffmpeg", "-i", path, "-af", f"silencedetect=noise={noise}:d={dur}", "-f", "null", "-"],
                         capture_output=True, text=True).stderr
    starts = [float(x) for x in re.findall(r"silence_start: ([\d.]+)", out)]
    ends = [float(x) for x in re.findall(r"silence_end: ([\d.]+)", out)]
    hh, mm, ss = re.search(r"Duration: (\d+):(\d+):([\d.]+)", out).groups()
    total = int(hh) * 3600 + int(mm) * 60 + float(ss)
    gaps = list(zip(starts, ends[:len(starts)]))
    segs, cur = [], 0.0
    for s, e in gaps:
        if s > cur + 0.05:
            segs.append((cur, s))
        cur = e
    if total > cur + 0.05:
        segs.append((cur, total))
    return segs, total


def align(sentences, segs):
    """Associe chaque phrase à un intervalle de voix : les limites tombent sur les pauses
    les plus proches de la position estimée d'après le nombre de caractères."""
    lens = [len(s) for s in sentences]
    speech = sum(b - a for a, b in segs)
    # position « en temps de parole » de chaque pause
    cum, pause_pos = 0.0, []
    for i, (a, b) in enumerate(segs[:-1]):
        cum += b - a
        pause_pos.append((cum, i))
    bounds, used, acc = [], -1, 0
    for k in range(len(sentences) - 1):
        acc += lens[k]
        target = speech * acc / sum(lens)
        # pause suivante la plus proche, en restant dans l'ordre et en laissant des pauses pour la suite
        remaining = len(sentences) - 2 - k
        cands = [(abs(p - target), i) for p, i in pause_pos if i > used and i < len(pause_pos) - remaining]
        _, best = min(cands)
        bounds.append(best)
        used = best
    out, start = [], 0
    for k, s in enumerate(sentences):
        end = bounds[k] if k < len(bounds) else len(segs) - 1
        out.append({"text": s, "t0": segs[start][0], "t1": segs[end][1], "segs": segs[start:end + 1]})
        start = end + 1
    return out


def word_times(item):
    """Instant d'apparition de chaque mot, réparti sur les seuls moments de parole."""
    words = item["text"].split()
    lens = [len(w) + 1 for w in words]
    total = sum(lens)
    speech = sum(b - a for a, b in item["segs"])
    times, acc = [], 0
    for L in lens:
        target = speech * acc / total
        t, rest = item["segs"][0][0], target
        for a, b in item["segs"]:
            if rest <= b - a:
                t = a + rest
                break
            rest -= b - a
        times.append(t)
        acc += L
    return words, times


# ====================================================================== éléments graphiques
def rounded(draw, box, r, fill, outline=None, width=0):
    draw.rounded_rectangle(box, r, fill=fill, outline=outline, width=width)


def shadowed_panel(size, radius, fill=(255, 255, 255, 246), shadow=60):
    w, h = size
    pad = 40
    img = Image.new("RGBA", (w + 2 * pad, h + 2 * pad), (0, 0, 0, 0))
    sh = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle((pad, pad + 10, pad + w, pad + h + 10), radius, fill=(0, 0, 0, shadow))
    img = Image.alpha_composite(img, sh.filter(ImageFilter.GaussianBlur(18)))
    ImageDraw.Draw(img).rounded_rectangle((pad, pad, pad + w, pad + h), radius, fill=fill)
    return img, pad


def wrap(text, fnt, maxw):
    lines, cur = [], ""
    for w in text.split():
        test = (cur + " " + w).strip()
        if fnt.getlength(test) <= maxw:
            cur = test
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


class Caption:
    """Sous-titre en bas de l'image : mots déjà prononcés en blanc, mots à venir estompés."""
    FONT = ("SemiBold", 44)
    MAXW = 1500

    def __init__(self, item):
        self.words, self.times = word_times(item)
        self.t0, self.t1 = item["t0"], item["t1"]
        f = font(*self.FONT)
        self.lines = wrap(" ".join(self.words), f, self.MAXW)
        # si plus de deux lignes, on affiche le texte par pages de deux lignes
        self.pages = [self.lines[i:i + 2] for i in range(0, len(self.lines), 2)]
        self.cache = {}

    def page_of(self, nspoken):
        idx, count = 0, 0
        for p, lines in enumerate(self.pages):
            n = sum(len(l.split()) for l in lines)
            if nspoken <= count + n:
                return p, count
            count += n
            idx = p
        return idx, count - sum(len(l.split()) for l in self.pages[idx])

    def render(self, t):
        spoken = sum(1 for x in self.times if x <= t + 0.08)
        p, first = self.page_of(max(spoken, 1))
        key = (p, spoken)
        if key in self.cache:
            return self.cache[key]
        f = font(*self.FONT)
        lines = self.pages[p]
        lh = 60
        w = int(max(f.getlength(l) for l in lines)) + 80
        h = lh * len(lines) + 44
        panel, pad = shadowed_panel((w, h), 26, fill=(16, 24, 38, 214), shadow=70)
        d = ImageDraw.Draw(panel)
        idx = first
        for i, line in enumerate(lines):
            x = pad + 40
            y = pad + 22 + i * lh
            for word in line.split():
                col = (255, 255, 255, 255) if idx < spoken else (255, 255, 255, 120)
                d.text((x, y), word, font=f, fill=col)
                x += f.getlength(word + " ")
                idx += 1
        self.cache[key] = panel
        return panel


def text_panel(lines, fonts, colors, pad_x=34, pad_y=26, gap=10, fill=(255, 255, 255, 246), radius=24):
    sizes = [(fonts[i].getlength(l), fonts[i].size) for i, l in enumerate(lines)]
    w = int(max(s[0] for s in sizes)) + 2 * pad_x
    h = int(sum(s[1] * 1.25 for s in sizes) + gap * (len(lines) - 1)) + 2 * pad_y
    panel, pad = shadowed_panel((w, h), radius, fill=fill)
    d = ImageDraw.Draw(panel)
    y = pad + pad_y
    for i, l in enumerate(lines):
        d.text((pad + pad_x, y), l, font=fonts[i], fill=colors[i])
        y += fonts[i].size * 1.25 + gap
    return panel, pad


# ====================================================================== schémas animés
def draw_power_symbol(d, cx, cy, r, col, width):
    d.arc((cx - r, cy - r, cx + r, cy + r), start=-60, end=240, fill=col, width=width)
    d.line((cx, cy - r - width * 0.3, cx, cy - r * 0.15), fill=col, width=width)


def card_power(t, local, dur):
    """Le symbole d'alimentation = le 1 (ligne) et le 0 (cercle) du binaire."""
    img, pad = shadowed_panel((520, 430), 30)
    d = ImageDraw.Draw(img)
    ox, oy = pad, pad
    d.text((ox + 34, oy + 26), "Le symbole d’alimentation", font=font("Bold", 30), fill=INK)
    cx, cy = ox + 260, oy + 210
    p = ease_out(local / 1.2)
    # cercle « 0 » et barre « 1 » qui se rejoignent
    shift = int((1 - p) * 120)
    d.ellipse((cx - 70 - shift, cy - 70, cx + 70 - shift, cy + 70), outline=BRAND, width=14)
    d.line((cx + shift, cy - 95, cx + shift, cy + 5), fill=ACCENT, width=14)
    d.text((cx - 70 - shift - 10, cy + 92), "0", font=font("Bold", 34), fill=BRAND)
    d.text((cx + shift - 10, cy + 92), "1", font=font("Bold", 34), fill=ACCENT)
    d.text((ox + 34, oy + 360), "1 = actif        0 = inactif", font=font("SemiBold", 28), fill=INK)
    return img


MOUSE_PARTS = {"deplacer": "body", "cliquer": "left", "double": "left2", "droit": "right", "molette": "wheel"}


def card_mouse(t, local, dur, part):
    img, pad = shadowed_panel((420, 520), 30)
    d = ImageDraw.Draw(img)
    ox, oy = pad, pad
    labels = {"body": "Déplacer", "left": "Clic gauche", "left2": "Double-clic", "right": "Clic droit", "wheel": "Molette"}
    d.text((ox + 30, oy + 24), labels[part], font=font("Bold", 34), fill=INK)
    cx, top = ox + 210, oy + 100
    bw, bh = 220, 340
    dx = int(math.sin(local * 3.2) * 26) if part == "body" else 0
    body = (cx - bw // 2 + dx, top, cx + bw // 2 + dx, top + bh)
    d.rounded_rectangle(body, 105, fill=(244, 246, 250), outline=(70, 80, 95), width=6)
    mid = top + 130
    d.line((cx + dx, top + 6, cx + dx, mid), fill=(70, 80, 95), width=5)
    d.line((body[0] + 6, mid, body[2] - 6, mid), fill=(70, 80, 95), width=5)
    pulse = (math.sin(local * (14 if part == "left2" else 7)) + 1) / 2
    hl = (int(50 + 30 * pulse), int(111 + 40 * pulse), 191)
    if part in ("left", "left2"):
        d.rounded_rectangle((body[0] + 9, top + 9, cx + dx - 4, mid - 4), 60, fill=hl)
        if part == "left2":
            d.text((body[0] + 30, top + 40), "×2", font=font("ExtraBold", 40), fill=WHITE)
    if part == "right":
        d.rounded_rectangle((cx + dx + 4, top + 9, body[2] - 9, mid - 4), 60, fill=hl)
    wy = top + 50 + (int(local * 40) % 30 if part == "wheel" else 0)
    d.rounded_rectangle((cx + dx - 14, top + 40, cx + dx + 14, top + 110), 14,
                        fill=hl if part == "wheel" else (90, 100, 115))
    if part == "wheel":
        for k in range(3):
            d.line((cx + dx - 10, wy + k * 8, cx + dx + 10, wy + k * 8), fill=WHITE, width=3)
    if part == "body":
        d.text((cx - 95, top + bh + 18), "←   bougez   →", font=font("SemiBold", 26), fill=BRAND)
    return img


def card_touchpad(t, local, dur, gesture):
    img, pad = shadowed_panel((480, 470), 30)
    d = ImageDraw.Draw(img)
    ox, oy = pad, pad
    labels = {"move": "Glisser 1 doigt", "tap": "Taper 1 fois", "two_tap": "Taper avec 2 doigts", "scroll": "Glisser 2 doigts"}
    d.text((ox + 30, oy + 24), labels[gesture], font=font("Bold", 32), fill=INK)
    px0, py0, px1, py1 = ox + 50, oy + 100, ox + 430, oy + 400
    d.rounded_rectangle((px0, py0, px1, py1), 26, fill=(236, 240, 246), outline=(160, 170, 185), width=4)
    cx, cy = (px0 + px1) // 2, (py0 + py1) // 2
    ph = (local % 1.6) / 1.6

    def finger(x, y, a=255):
        d.ellipse((x - 26, y - 26, x + 26, y + 26), fill=(255, 206, 170, a), outline=(200, 140, 110, a), width=3)
    if gesture == "move":
        tri = 1 - abs(2 * ph - 1)
        x = px0 + 80 + (px1 - px0 - 160) * ease(tri)
        finger(int(x), cy)
    elif gesture == "tap":
        r = int(30 + 60 * ph)
        d.ellipse((cx - r, cy - r, cx + r, cy + r), outline=BRAND + (int(255 * (1 - ph)),), width=5)
        finger(cx, cy)
    elif gesture == "two_tap":
        r = int(30 + 60 * ph)
        for ddx in (-40, 40):
            d.ellipse((cx + ddx - r, cy - r, cx + ddx + r, cy + r), outline=BRAND + (int(255 * (1 - ph)),), width=5)
            finger(cx + ddx, cy)
    elif gesture == "scroll":
        y = py0 + 70 + (py1 - py0 - 140) * ease(ph)
        for ddx in (-40, 40):
            finger(cx + ddx, int(y))
        d.polygon([(px1 - 30, py0 + 40), (px1 - 46, py0 + 70), (px1 - 14, py0 + 70)], fill=BRAND)
        d.polygon([(px1 - 30, py1 - 40), (px1 - 46, py1 - 70), (px1 - 14, py1 - 70)], fill=BRAND)
    return img


def card_press(t, local, dur):
    img, pad = shadowed_panel((420, 400), 30)
    d = ImageDraw.Draw(img)
    d.text((pad + 30, pad + 24), "Une simple pression", font=font("Bold", 32), fill=INK)
    cx, cy = pad + 210, pad + 225
    ph = (local % 1.4) / 1.4
    r = int(70 + 90 * ph)
    d.ellipse((cx - r, cy - r, cx + r, cy + r), outline=BRAND + (int(220 * (1 - ph)),), width=6)
    s = 0.9 if ph < 0.15 else 1.0
    rr = int(70 * s)
    d.ellipse((cx - rr, cy - rr, cx + rr, cy + rr), fill=BRAND)
    draw_power_symbol(d, cx, cy, int(32 * s), WHITE, 9)
    return img


def card_wait(t, local, dur):
    img, pad = shadowed_panel((440, 300), 30)
    d = ImageDraw.Draw(img)
    d.text((pad + 30, pad + 24), "Démarrage en cours", font=font("Bold", 32), fill=INK)
    cx, cy = pad + 220, pad + 175
    for k in range(10):                       # roue de chargement façon Windows
        a = local * 5 - k * 0.35
        x, y = cx + 52 * math.cos(a), cy + 52 * math.sin(a)
        al = int(255 * (1 - k / 10))
        d.ellipse((x - 9, y - 9, x + 9, y + 9), fill=BRAND + (al,))
    return img


CARDS = {"power": card_power, "press": card_press, "wait": card_wait}


# ====================================================================== composition
def paste(frame, img, x, y, alpha=1.0, scale=1.0):
    """Colle une image RGBA (PIL) sur la trame numpy RGB, avec opacité et échelle."""
    if alpha <= 0.01:
        return
    if scale != 1.0:
        img = img.resize((max(1, int(img.width * scale)), max(1, int(img.height * scale))), Image.BILINEAR)
    a = np.asarray(img, dtype=np.float32)
    x, y = int(x), int(y)
    x0, y0 = max(x, 0), max(y, 0)
    x1, y1 = min(x + img.width, W), min(y + img.height, H)
    if x1 <= x0 or y1 <= y0:
        return
    sub = a[y0 - y:y1 - y, x0 - x:x1 - x]
    al = sub[..., 3:4] / 255.0 * alpha
    region = frame[y0:y1, x0:x1].astype(np.float32)
    frame[y0:y1, x0:x1] = (region * (1 - al) + sub[..., :3] * al).astype(np.uint8)


def magnifier(frame, src, ev, t):
    """Loupe ronde : grossit la zone (cx, cy) de l'image et l'affiche à (px, py) avec un trait de rappel."""
    al = env(t, ev["t0"], ev["t1"], 0.4)
    if al <= 0:
        return
    cx, cy, zoom = ev["cx"], ev["cy"], ev.get("zoom", 2.2)
    R = ev.get("radius", 190)
    px, py = ev["px"], ev["py"]
    grow = ease_out((t - ev["t0"]) / 0.5)
    r_src = int(R / zoom)
    x0, y0 = max(0, cx - r_src), max(0, cy - r_src)
    crop = src[y0:y0 + 2 * r_src, x0:x0 + 2 * r_src]
    if crop.size == 0:
        return
    crop = cv2.resize(crop, (2 * R, 2 * R), interpolation=cv2.INTER_CUBIC)
    lens = Image.new("RGBA", (2 * R + 40, 2 * R + 40), (0, 0, 0, 0))
    sh = Image.new("RGBA", lens.size, (0, 0, 0, 0))
    ImageDraw.Draw(sh).ellipse((20, 30, 20 + 2 * R, 30 + 2 * R), fill=(0, 0, 0, 90))
    lens = Image.alpha_composite(lens, sh.filter(ImageFilter.GaussianBlur(14)))
    mask = Image.new("L", (2 * R, 2 * R), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, 2 * R - 1, 2 * R - 1), fill=255)
    lens.paste(Image.fromarray(crop), (20, 20), mask)
    ImageDraw.Draw(lens).ellipse((20, 20, 20 + 2 * R, 20 + 2 * R), outline=WHITE, width=10)
    # repère sur la zone d'origine + trait vers la loupe
    over = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    od = ImageDraw.Draw(over)
    rr = r_src + 8
    od.ellipse((cx - rr, cy - rr, cx + rr, cy + rr), outline=ACCENT + (255,), width=6)
    ang = math.atan2(py - cy, px - cx)
    sx, sy = cx + rr * math.cos(ang), cy + rr * math.sin(ang)
    ex, ey = px - R * math.cos(ang), py - R * math.sin(ang)
    od.line((sx, sy, ex, ey), fill=ACCENT + (255,), width=5)
    bbox = over.getbbox()
    if bbox:
        paste(frame, over.crop(bbox), bbox[0], bbox[1], al)
    s = 0.6 + 0.4 * grow
    paste(frame, lens, px - (R + 20) * s, py - (R + 20) * s, al, s)
    if ev.get("label"):
        lab, pad = text_panel([ev["label"]], [font("Bold", 30)], [WHITE], pad_x=22, pad_y=12, fill=ACCENT + (250,), radius=18)
        paste(frame, lab, px - lab.width / 2, py + R * s - pad + 6, al)


def zoom_frame(src, t, zooms):
    """Poussée de caméra douce vers un point (Ken Burns)."""
    z, fx, fy = 1.0, W / 2, H / 2
    for ev in zooms:
        k = env(t, ev["t0"], ev["t1"], 0.8)
        if k > 0:
            z = 1 + (ev.get("zoom", 1.35) - 1) * k
            fx = W / 2 + (ev["cx"] - W / 2) * k
            fy = H / 2 + (ev["cy"] - H / 2) * k
    if z <= 1.001:
        return src
    cw, ch = W / z, H / z
    x0 = min(max(fx - cw / 2, 0), W - cw)
    y0 = min(max(fy - ch / 2, 0), H - ch)
    M = np.float32([[z, 0, -x0 * z], [0, z, -y0 * z]])
    return cv2.warpAffine(src, M, (W, H), flags=cv2.INTER_LINEAR)


def header(cfg, step, nsteps, label):
    f1, f2 = font("Bold", 28), font("SemiBold", 26)
    txt = f"Étape {step} sur {nsteps}"
    w = int(f1.getlength(txt) + f2.getlength(label) + 120)
    img, pad = shadowed_panel((w, 64), 32, fill=(255, 255, 255, 240), shadow=40)
    d = ImageDraw.Draw(img)
    d.rounded_rectangle((pad + 8, pad + 8, pad + 8 + f1.getlength(txt) + 36, pad + 56), 24, fill=BRAND)
    d.text((pad + 26, pad + 15), txt, font=f1, fill=WHITE)
    d.text((pad + f1.getlength(txt) + 64, pad + 17), label, font=f2, fill=INK)
    return img


@lru_cache(None)
def brand_tag():
    img, pad = shadowed_panel((250, 64), 32, fill=(255, 255, 255, 235), shadow=40)
    d = ImageDraw.Draw(img)
    d.ellipse((pad + 12, pad + 12, pad + 52, pad + 52), fill=BRAND)
    d.text((pad + 21, pad + 17), "H", font=font("ExtraBold", 26), fill=WHITE)
    d.text((pad + 66, pad + 15), "HELP-ME", font=font("ExtraBold", 30), fill=BRAND_DARK)
    return img


# ====================================================================== générique et fin
def blur_bg(src):
    small = cv2.resize(src, (W // 8, H // 8))
    small = cv2.GaussianBlur(small, (0, 0), 6)
    bg = cv2.resize(small, (W, H)).astype(np.float32)
    tint = np.array(BRAND_DARK, dtype=np.float32)
    return (bg * 0.35 + tint * 0.65).astype(np.uint8)


def intro_frame(cfg, bg, t, dur):
    frame = bg.copy()
    k = ease_out(t / 0.9)
    tag = brand_tag()
    paste(frame, tag, W / 2 - tag.width / 2, 250 - 40 * (1 - k), k)
    f = font("ExtraBold", 84)
    lines = wrap(cfg["title"], f, 1500)
    y = 400
    for i, l in enumerate(lines):
        kk = ease_out((t - 0.25 - 0.12 * i) / 0.8)
        img = Image.new("RGBA", (int(f.getlength(l)) + 10, 110), (0, 0, 0, 0))
        ImageDraw.Draw(img).text((0, 0), l, font=f, fill=WHITE)
        paste(frame, img, W / 2 - img.width / 2, y + 40 * (1 - kk), kk)
        y += 105
    sub = font("Medium", 36)
    kk = ease_out((t - 0.7) / 0.8)
    img = Image.new("RGBA", (int(sub.getlength(cfg["subtitle"])) + 10, 60), (0, 0, 0, 0))
    ImageDraw.Draw(img).text((0, 0), cfg["subtitle"], font=sub, fill=(220, 230, 245))
    paste(frame, img, W / 2 - img.width / 2, y + 30, kk)
    # trait animé
    lw = int(320 * ease_out((t - 0.4) / 1.0))
    if lw > 0:
        cv2.rectangle(frame, (W // 2 - lw // 2, y + 110), (W // 2 + lw // 2, y + 116), ACCENT, -1)
    fade = env(t, 0, dur, 0.3)
    return (frame.astype(np.float32) * fade).astype(np.uint8)


def outro_frame(cfg, bg, t, dur):
    frame = bg.copy()
    k = ease_out(t / 0.8)
    f_t, f_b = font("ExtraBold", 64), font("Medium", 40)
    img = Image.new("RGBA", (900, 90), (0, 0, 0, 0))
    ImageDraw.Draw(img).text((0, 0), "À retenir", font=f_t, fill=WHITE)
    paste(frame, img, 260, 190 + 30 * (1 - k), k)
    y = 330
    for i, b in enumerate(cfg["retenir"]):
        kk = ease_out((t - 0.35 - 0.25 * i) / 0.7)
        lines = wrap(b, f_b, 1280)
        h = 58 * len(lines) + 44
        panel, pad = shadowed_panel((1400, h), 26, fill=(255, 255, 255, 244))
        d = ImageDraw.Draw(panel)
        d.ellipse((pad + 30, pad + h / 2 - 20, pad + 70, pad + h / 2 + 20), fill=ACCENT)
        d.text((pad + 41, pad + h / 2 - 17), str(i + 1), font=font("Bold", 28), fill=WHITE)
        for j, l in enumerate(lines):
            d.text((pad + 100, pad + 22 + 58 * j), l, font=f_b, fill=INK)
        paste(frame, panel, 260 - pad + 60 * (1 - kk), y - pad, kk)
        y += h + 26
    kk = ease_out((t - 1.4) / 0.8)
    note = font("SemiBold", 32)
    msg = "Scannez les codes-barres du manuel avec l’application HELP-ME"
    img = Image.new("RGBA", (int(note.getlength(msg)) + 10, 50), (0, 0, 0, 0))
    ImageDraw.Draw(img).text((0, 0), msg, font=note, fill=(220, 230, 245))
    paste(frame, img, W / 2 - img.width / 2, H - 140, kk)
    fade = env(t, 0, dur + 0.01, 0.3) if t < 0.3 else min(1.0, (dur - t) / 0.6)
    return (frame.astype(np.float32) * max(0.0, fade)).astype(np.uint8)


# ====================================================================== rendu
def main():
    cfg = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    out = sys.argv[2]
    preview = float(sys.argv[sys.argv.index("--preview") + 1]) if "--preview" in sys.argv else None
    base = Path(sys.argv[1]).parent
    # vidéo source : dossier donné par --sources (par défaut à côté du fichier de configuration)
    src_dir = Path(sys.argv[sys.argv.index("--sources") + 1]) if "--sources" in sys.argv else base
    src_path = str((src_dir / cfg["source"]).resolve())

    segs, total = speech_segments(src_path, cfg.get("noise", "-32dB"))
    items = align(cfg["sentences"], segs)
    for it in items:
        print(f"  {it['t0']:6.2f}-{it['t1']:6.2f}  {it['text'][:70]}")
    caps = [Caption(it) for it in items]
    steps = cfg["steps"]                                   # [{"from": index de phrase, "label": ...}]
    events = cfg.get("events", [])
    for ev in events:                                      # les événements peuvent viser une phrase
        if "sentence" in ev:
            it = items[ev["sentence"]]
            ev.setdefault("t0", it["t0"] + ev.get("delay", 0.0))
            ev.setdefault("t1", it["t1"] + ev.get("extra", 0.3))
    zooms = [e for e in events if e["type"] == "zoom"]

    INTRO, OUTRO = cfg.get("intro", 3.0), cfg.get("outro", 5.0)
    dur_main = total
    n_total = int((INTRO + dur_main + OUTRO) * FPS)
    if preview:
        n_total = min(n_total, int(preview * FPS))

    dec = subprocess.Popen(["ffmpeg", "-v", "error", "-i", src_path, "-vf", f"fps={FPS},scale={W}:{H}",
                            "-f", "rawvideo", "-pix_fmt", "rgb24", "-"], stdout=subprocess.PIPE)
    first = np.frombuffer(dec.stdout.read(W * H * 3), np.uint8).reshape(H, W, 3).copy()
    bg_intro = blur_bg(first)
    tmp_video = out + ".video.mp4"
    enc = subprocess.Popen(["ffmpeg", "-v", "error", "-y", "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}",
                            "-r", str(FPS), "-i", "-", "-c:v", "libx264", "-preset", "medium", "-crf", "21",
                            "-pix_fmt", "yuv420p", tmp_video], stdin=subprocess.PIPE)
    pending, last = first, first
    bg_outro = None
    nsteps = len(steps)
    for n in range(n_total):
        T = n / FPS
        if T < INTRO:
            frame = intro_frame(cfg, bg_intro, T, INTRO)
            # transition : l'image source apparaît sous le générique en fin d'intro
            k = ease((T - (INTRO - 0.5)) / 0.5)
            if k > 0:
                frame = (frame.astype(np.float32) * (1 - k) + first.astype(np.float32) * k).astype(np.uint8)
        elif T < INTRO + dur_main:
            t = T - INTRO
            if pending is not None:
                src = pending
                pending = None
            else:
                buf = dec.stdout.read(W * H * 3)
                src = np.frombuffer(buf, np.uint8).reshape(H, W, 3).copy() if len(buf) == W * H * 3 else last
            last = src
            frame = zoom_frame(src, t, zooms).copy()
            # loupes
            for ev in events:
                if ev["type"] == "magnifier":
                    magnifier(frame, frame.copy(), ev, t)
            # schémas
            for ev in events:
                if ev["type"] == "card":
                    a = env(t, ev["t0"], ev["t1"], 0.4)
                    if a > 0:
                        local = t - ev["t0"]
                        kind = ev["kind"]
                        if kind == "mouse":
                            img = card_mouse(t, local, 0, ev["part"])
                        elif kind == "touchpad":
                            img = card_touchpad(t, local, 0, ev["gesture"])
                        else:
                            img = CARDS[kind](t, local, 0)
                        s = 0.85 + 0.15 * ease_out(local / 0.5)
                        x = ev.get("x", W - img.width - 40)
                        y = ev.get("y", 150)
                        paste(frame, img, x + img.width * (1 - s) / 2, y + img.height * (1 - s) / 2, a, s)
            # étape en cours
            cur = 0
            for i, it in enumerate(items):
                if t >= it["t0"] - 0.2:
                    cur = i
            si = max(k for k, s in enumerate(steps) if s["from"] <= cur)
            hdr = header(cfg, si + 1, nsteps, steps[si]["label"])
            a_h = min(1.0, t / 0.4)
            paste(frame, hdr, 20, 14, a_h)
            tag = brand_tag()
            paste(frame, tag, W - tag.width - 10, 14, a_h)
            # sous-titre
            for c in caps:
                a = env(t, c.t0 - 0.15, c.t1 + 0.35, 0.2)
                if a > 0:
                    img = c.render(t)
                    paste(frame, img, W / 2 - img.width / 2, H - img.height - 40, a)
            # barre de progression
            p = t / dur_main
            cv2.rectangle(frame, (0, H - 8), (W, H), (30, 40, 55), -1)
            cv2.rectangle(frame, (0, H - 8), (int(W * p), H), BRAND, -1)
            if T + 1 / FPS >= INTRO + dur_main:
                bg_outro = blur_bg(last)
        else:
            if bg_outro is None:
                bg_outro = blur_bg(last)
            t = T - INTRO - dur_main
            frame = outro_frame(cfg, bg_outro, t, OUTRO)
            k = ease(t / 0.5)
            if k < 1:
                frame = (last.astype(np.float32) * (1 - k) + frame.astype(np.float32) * k).astype(np.uint8)
        enc.stdin.write(frame.tobytes())
        if n % 150 == 0:
            print(f"  image {n}/{n_total}", flush=True)
    enc.stdin.close()
    enc.wait()
    dec.kill()
    # voix d'origine, décalée de la durée du générique ; fondu de sortie
    total_len = n_total / FPS
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", tmp_video, "-i", src_path, "-filter_complex",
                    f"[1:a]adelay={int(INTRO * 1000)}|{int(INTRO * 1000)},apad,atrim=0:{total_len:.3f},"
                    f"afade=t=out:st={max(0, total_len - 1.2):.3f}:d=1.2,loudnorm=I=-16:TP=-1.5[a]",
                    "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "aac", "-b:a", "160k",
                    "-movflags", "+faststart", out], check=True)
    Path(tmp_video).unlink()
    print("écrit :", out)


if __name__ == "__main__":
    main()
