#!/usr/bin/env python3
"""
Extraction des textes français de l'interface (hors administration) vers
public/assets/i18n/_source.json, liste de référence des traductions.
Sources : fichiers JavaScript, vues PHP, messages des services/contrôleurs, et
(optionnel) textes collectés à l'écran par tests/e2e/i18n-collect.mjs (tools/i18n/runtime.json).
Usage : python3 tools/i18n/extract.py
"""
import json, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
JS = ['core.js', 'map.js', 'navigation.js', 'shared.js', 'client.js', 'provider.js', 'merchant.js', 'business.js', 'home.js', 'auth.js', 'demo-payment.js']
VIEWS = ['layouts/app.php', 'pages/home.php', 'pages/login.php', 'pages/register.php', 'pages/forgot.php', 'pages/reset.php', 'pages/app.php', 'pages/offline.php', 'pages/error.php', 'pages/receipt.php', 'pages/demo-payment.php']
PHP_DIRS = ['app/Services', 'app/Controllers/Api', 'app/Core', 'app/Middleware']
LETTER = re.compile(r'[A-Za-zÀ-ÿ]')
FRENCH_HINT = re.compile(r'[àâäçéèêëîïôöùûüÿœÀÂÇÉÈÊËÎÏÔÙÛŒ]|\b(le|la|les|un|une|des|du|de|et|ou|à|au|aux|en|pour|par|sur|avec|votre|vos|mon|ma|mes|est|sont|pas|plus|aucun|aucune|ce|cette|dans|nouveau|nouvelle)\b', re.I)

def clean(s):
    s = re.sub(r'\s+', ' ', s).strip()
    return s

def plausible(s):
    if not s or len(s) < 2 or len(s) > 400 or not LETTER.search(s):
        return False
    if re.search(r'[{}<>;=]|=>|\$\{|^\W+$|https?://|\.(js|css|png|svg|php)\b|^#|^/|\\n', s):
        return False
    if re.match(r'^[MmLlHhVvCcZzAaQq][\d\s.,-]{3}', s):  # tracés SVG
        return False
    if re.fullmatch(r'[a-z0-9_\-.:]+', s):  # identifiants, classes CSS, clés
        return False
    if re.fullmatch(r'[A-Z0-9_\-]+', s) and len(s) > 1:  # constantes
        return False
    if re.fullmatch(r'[a-z]+(-[a-z0-9]+)*( [a-z]+(-[a-z0-9]+)*)+', s) and not FRENCH_HINT.search(s):  # listes de classes
        return False
    if s[0].isupper() or FRENCH_HINT.search(s) or re.match(r'^[«(]', s):
        return True
    return False

def js_strings(src):
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        if c == '/' and i + 1 < n and src[i+1] == '/':
            j = src.find('\n', i); i = n if j < 0 else j; continue
        if c == '/' and i + 1 < n and src[i+1] == '*':
            j = src.find('*/', i + 2); i = n if j < 0 else j + 2; continue
        if c in '\'"':
            j, buf = i + 1, []
            while j < n and src[j] != c:
                if src[j] == '\\' and j + 1 < n:
                    buf.append(src[j+1]); j += 2; continue
                if src[j] == '\n': break
                buf.append(src[j]); j += 1
            out.append(('str', ''.join(buf))); i = j + 1; continue
        if c == '`':
            j, depth, buf, parts = i + 1, 0, [], []
            while j < n:
                if src[j] == '\\' and j + 1 < n:
                    buf.append(src[j+1]); j += 2; continue
                if src[j] == '`': break
                if src[j] == '$' and j + 1 < n and src[j+1] == '{':
                    # expression ${...} : on récupère son contenu pour l'analyser récursivement
                    k, d = j + 2, 1
                    while k < n and d:
                        if src[k] == '{': d += 1
                        elif src[k] == '}': d -= 1
                        elif src[k] in '\'"`':
                            q = src[k]; k += 1
                            while k < n and src[k] != q:
                                if src[k] == '\\': k += 1
                                k += 1
                        k += 1
                    expr = src[j+2:k-1]
                    out.extend(js_strings(expr))
                    buf.append('\x00')  # emplacement
                    j = k; continue
                buf.append(src[j]); j += 1
            out.append(('tpl', ''.join(buf))); i = j + 1; continue
        i += 1
    return out

def from_template(t):
    """Texte entre balises HTML ; un ${...} au milieu d'un texte devient {1}, {2}…"""
    res = []
    for run in re.split(r'<[^>]*>', t):
        # attributs placeholder/title/aria-label
        pass
    for m in re.finditer(r'(?:placeholder|title|aria-label|alt)="([^"\x00]+)"', t):
        res.append(m.group(1))
    for run in re.split(r'<[^>]*>', t):
        pieces = [clean(p) for p in run.split('\x00')]
        if sum(1 for p in pieces if p) == 0:
            continue
        # placeholders internes uniquement si du texte des deux côtés
        stripped = run.strip().strip('\x00').strip()
        if '\x00' in stripped and all(LETTER.search(x) for x in [stripped.split('\x00')[0] or 'x']):
            k = 0
            def rep(_):
                nonlocal k
                k += 1
                return '{%d}' % k
            res.append(clean(re.sub('\x00', rep, stripped)))
        for p in pieces:
            res.append(p)
    return res

strings = {}
def add(s, origin):
    s = clean(s.replace('\x00', ' '))
    s = s.strip(' ·—-:,') if s.endswith((' ·', ' —', ' -')) else s
    if plausible(s):
        strings.setdefault(s, origin)

for f in JS:
    src = open(os.path.join(ROOT, 'public/assets/js', f), encoding='utf-8').read()
    for kind, val in js_strings(src):
        if kind == 'str':
            if '<' in val:
                for x in from_template(val): add(x, f)
            else:
                add(val, f)
        else:
            for x in from_template(val): add(x, f)

for v in VIEWS:
    p = os.path.join(ROOT, 'app/Views', v)
    if not os.path.exists(p): continue
    src = open(p, encoding='utf-8').read()
    src = re.sub(r'<\?(php|=).*?\?>', '\x00', src, flags=re.S)
    for x in from_template(src): add(x, v)

import subprocess
php = json.loads(subprocess.run(['php', os.path.join(ROOT, 'tools/i18n/extract-php.php')], capture_output=True, text=True, check=True).stdout)
for x in php:
    if re.search(r"IN \('|d/m/Y|Y-m-|AUTH |%\.|^[A-Z_]+ |\(réf\. $|^\s|\s$", x):
        x = x.strip()
        if not x or re.search(r"IN \('|d/m/Y|Y-m-|AUTH ", x):
            continue
    add(x, 'php')

# Textes réellement affichés, collectés par tests/e2e/i18n-collect.mjs
rt = os.path.join(ROOT, 'tools/i18n/runtime.json')
if os.path.exists(rt):
    NUMS = re.compile(r'\d[\d\s\u202f.,:]*\d|\d')
    for s in json.load(open(rt, encoding='utf-8')):
        if re.search(r'\d', s):
            k = [0]
            def rep(m):
                k[0] += 1
                return '{%d}' % k[0]
            s = NUMS.sub(rep, s)
        add(s, 'écran')

for x in json.load(open(os.path.join(ROOT, 'tools/i18n/extra.json'), encoding='utf-8')):
    strings.setdefault(x, 'modèle')

# Les noms de la base de démonstration et les exemples ne sont pas des textes d'interface
DB = set(json.loads(subprocess.run(['php', '-r', '''require "bootstrap/app.php"; $o=[]; foreach (["SELECT full_name x FROM users","SELECT name x FROM merchants","SELECT name x FROM products","SELECT description x FROM products","SELECT name x FROM product_categories","SELECT address_text x FROM addresses","SELECT label x FROM addresses","SELECT name x FROM landmarks","SELECT name x FROM businesses","SELECT address_text x FROM merchants","SELECT description x FROM merchants","SELECT cuisine x FROM merchants","SELECT name x FROM zones","SELECT name x FROM neighborhoods","SELECT description x FROM promotions"] as $q) { try { foreach (App\\Core\\Database::select($q) as $r) $o[] = (string) $r["x"]; } catch (Throwable $e) {} } echo json_encode($o);'''], capture_output=True, text=True, cwd=ROOT).stdout or '[]'))
for k in list(strings):
    if k in DB:
        del strings[k]
EXCLUDE = re.compile(r'(démo\)|Démo\b|\(démo|marwa\.demo|NG-DEMO|^Position \d|^[A-Z]{2}-[A-Z0-9]{6}$)')
items = sorted(s for s in strings if not EXCLUDE.search(s))
out = os.path.join(ROOT, 'public/assets/i18n/_source.json')
os.makedirs(os.path.dirname(out), exist_ok=True)
json.dump(items, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
print(f'{len(items)} textes extraits -> {os.path.relpath(out, ROOT)}')
