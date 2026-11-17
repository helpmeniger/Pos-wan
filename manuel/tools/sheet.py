#!/usr/bin/env python3
"""Planche contact de pages PDF : sheet.py fichier.pdf première dernière sortie.png [colonnes]"""
import sys, io, pymupdf
from PIL import Image
pdf, a, b, out = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4]
cols = int(sys.argv[5]) if len(sys.argv) > 5 else 4
d = pymupdf.open(pdf); b = min(b, d.page_count)
ims = [Image.open(io.BytesIO(d[i - 1].get_pixmap(dpi=110).tobytes("png"))) for i in range(a, b + 1)]
w, h = ims[0].size; rows = (len(ims) + cols - 1) // cols
o = Image.new("RGB", (cols * (w + 8), rows * (h + 8)), "#777")
for k, im in enumerate(ims): o.paste(im, ((k % cols) * (w + 8) + 4, (k // cols) * (h + 8) + 4))
o.save(out)
