#!/usr/bin/env python3
"""Découpe les captures de la 1re édition AVEC leurs flèches et légendes (dessinées en vectoriel par-dessus).

Pour chaque image d'une page : zone = image ∪ traits/flèches qui la touchent ∪ légendes courtes reliées aux flèches.
Le texte courant (paragraphes) est retiré avant le rendu pour ne garder que l'illustration.
Usage : crop_orig.py manuel_original.pdf dossier_sortie [pages…]
"""
import sys
import pymupdf

src, out = sys.argv[1], sys.argv[2]
pages = [int(x) for x in sys.argv[3:]] or None
doc = pymupdf.open(src)


def grow(r, m):
    return pymupdf.Rect(r.x0 - m, r.y0 - m, r.x1 + m, r.y1 + m)


for pno in (pages or range(1, doc.page_count + 1)):
    page = doc[pno - 1]
    infos = [i for i in page.get_image_info(xrefs=True)
             if not (pymupdf.Rect(i["bbox"]).width < 40 and pymupdf.Rect(i["bbox"]).height / max(1, pymupdf.Rect(i["bbox"]).width) > 2)
             and pymupdf.Rect(i["bbox"]).get_area() > 1500]
    if not infos:
        continue
    drawings = [d for d in page.get_drawings() if d["rect"].get_area() < page.rect.get_area() * 0.5]
    lines = []
    for b in page.get_text("dict")["blocks"]:
        for l in b.get("lines", []):
            t = "".join(s["text"] for s in l["spans"]).strip()
            if t:
                lines.append((pymupdf.Rect(l["bbox"]), t, l["spans"]))

    def is_label(r, t, spans):
        if any("Bar-Code" in s["font"] for s in spans):
            return False
        if r.y0 > page.rect.height * 0.92 or t in ("•", "-"):
            return False
        size = max(s["size"] for s in spans if s["text"].strip())
        in_box = any(grow(d["rect"], 2).contains(r) and d["rect"].width < 160 and d["rect"].height < 60 for d in drawings)
        return len(t) <= 60 and (size <= 11.2 or (in_box and size <= 12.5))
    for k, info in enumerate(infos):
        reg = pymupdf.Rect(info["bbox"]) & page.rect
        draw_reg = pymupdf.Rect(reg)
        for _ in range(3):
            for d in drawings:
                if grow(d["rect"], 2).intersects(draw_reg) and d["rect"].width < page.rect.width * .95:
                    draw_reg |= d["rect"]
        labels = []
        for r, t, spans in lines:
            if not is_label(r, t, spans):
                continue
            if r.intersects(reg) or grow(r, 5).intersects(draw_reg):
                labels.append(r)              # étiquette sur l'image ou légende reliée par une flèche
        full = pymupdf.Rect(draw_reg)
        for _ in range(2):                    # légendes sur plusieurs lignes
            for r, t, spans in lines:
                if is_label(r, t, spans) and r not in labels and grow(r, 3).intersects(pymupdf.Rect(full)) and any(grow(x, 3).intersects(r) for x in labels):
                    labels.append(r)
            for r in labels:
                full |= r
        full = grow(full, 2) & page.rect
        # copie de la page sans le texte courant
        tmp = pymupdf.open()
        tmp.insert_pdf(doc, from_page=pno - 1, to_page=pno - 1)
        tp = tmp[0]
        keep = labels
        for r, t, spans in lines:
            if any(r.intersects(x) for x in keep):
                continue
            if r.intersects(full):
                tp.add_redact_annot(r, fill=None)
        tp.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE, graphics=pymupdf.PDF_REDACT_LINE_ART_NONE,
                            text=pymupdf.PDF_REDACT_TEXT_REMOVE)
        pix = tp.get_pixmap(dpi=420, clip=full)
        pix.save(f"{out}/p{pno:03d}_{k}.png")
        tmp.close()
