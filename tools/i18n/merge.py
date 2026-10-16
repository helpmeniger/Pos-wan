#!/usr/bin/env python3
"""Fusionne tools/i18n/parts/<langue>-<n>.json dans public/assets/i18n/<langue>.json.
Les entrées identiques au français sont omises (le texte d'origine s'affiche). Usage : python3 tools/i18n/merge.py"""
import glob, json, os, re
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
src = json.load(open(os.path.join(ROOT, 'public/assets/i18n/_source.json'), encoding='utf-8'))
for lang in ('ha', 'dje', 'en'):
    d = {}
    for f in sorted(glob.glob(os.path.join(ROOT, f'tools/i18n/parts/{lang}-*.json'))):
        d.update(json.load(open(f, encoding='utf-8')))
    bad = [k for k, v in d.items() if any(p not in v for p in re.findall(r'\{\d\}', k))]
    out = {'_meta': {'language': lang, 'source': 'fr', 'generated': 'traduction automatique assistée', 'reviewed': False}}
    out.update({k: v for k, v in d.items() if v and v != k and k not in bad})
    json.dump(out, open(os.path.join(ROOT, f'public/assets/i18n/{lang}.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
    missing = [s for s in src if s not in d]
    print(f'{lang}: {len(out) - 1} traductions, {len(missing)} textes sans traduction, {len(bad)} rejetées (emplacements)')
