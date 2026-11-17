"""Générateur Code 39 (SVG vectoriel), compatible avec les codes du manuel original.

Le manuel original utilisait la police « Bar-Code-39 » avec le texte *NNNN*
(étoiles = caractères start/stop, pas de clé de contrôle). On reproduit
exactement la même symbologie et la même valeur.
"""

# n = étroit, w = large ; 9 éléments : barre, espace, barre, ... (5 barres, 4 espaces)
PATTERNS = {
    "0": "nnnwwnwnn", "1": "wnnwnnnnw", "2": "nnwwnnnnw", "3": "wnwwnnnnn",
    "4": "nnnwwnnnw", "5": "wnnwwnnnn", "6": "nnwwwnnnn", "7": "nnnwnnwnw",
    "8": "wnnwnnwnn", "9": "nnwwnnwnn", "A": "wnnnnwnnw", "B": "nnwnnwnnw",
    "C": "wnwnnwnnn", "D": "nnnnwwnnw", "E": "wnnnwwnnn", "F": "nnwnwwnnn",
    "G": "nnnnnwwnw", "H": "wnnnnwwnn", "I": "nnwnnwwnn", "J": "nnnnwwwnn",
    "K": "wnnnnnnww", "L": "nnwnnnnww", "M": "wnwnnnnwn", "N": "nnnnwnnww",
    "O": "wnnnwnnwn", "P": "nnwnwnnwn", "Q": "nnnnnnwww", "R": "wnnnnnwwn",
    "S": "nnwnnnwwn", "T": "nnnnwnwwn", "U": "wwnnnnnnw", "V": "nwwnnnnnw",
    "W": "wwwnnnnnn", "X": "nwnnwnnnw", "Y": "wwnnwnnnn", "Z": "nwwnwnnnn",
    "-": "nwnnnnwnw", ".": "wwnnnnwnn", " ": "nwwnnnwnn", "*": "nwnnwnwnn",
}


def modules(value: str, ratio: float = 3.0):
    """Liste de (largeur, est_barre) en unités de module."""
    seq = []
    text = f"*{value.upper()}*"
    for i, ch in enumerate(text):
        pat = PATTERNS[ch]
        for k, e in enumerate(pat):
            seq.append((ratio if e == "w" else 1.0, k % 2 == 0))
        if i < len(text) - 1:
            seq.append((1.0, False))  # espace inter-caractère
    return seq


def svg(value: str, module_mm: float = 0.25, height_mm: float = 8.0,
        quiet: float = 10, ratio: float = 3.0, color: str = "#000") -> str:
    seq = modules(value, ratio)
    total = sum(w for w, _ in seq) + 2 * quiet
    W = total * module_mm
    x = quiet
    rects = []
    for w, bar in seq:
        if bar:
            rects.append(f'<rect x="{x:.3f}" y="0" width="{w:.3f}" height="1"/>')
        x += w
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" class="c39" '
        f'width="{W:.2f}mm" height="{height_mm:.2f}mm" viewBox="0 0 {total:.3f} 1" '
        f'preserveAspectRatio="none" shape-rendering="crispEdges">'
        f'<rect x="0" y="0" width="{total:.3f}" height="1" fill="#fff"/>'
        f'<g fill="{color}">{"".join(rects)}</g></svg>'
    )


if __name__ == "__main__":
    import sys
    print(svg(sys.argv[1] if len(sys.argv) > 1 else "2019"))
