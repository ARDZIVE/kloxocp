#!/usr/bin/env python3
"""Second rebrand step: AKLOR WebControl -> Kloxo CP.
Run on the tree produced by the first step. Same safety rules as before:
text files only, attribution lines untouched, SQL files get same-length edits only
(aklor and kloxo are both 5 characters, so PHP-serialized strings stay valid)."""
import os, re, sys
ROOT = sys.argv[1]
ATTRIB = re.compile(r"copyright|created by|modified by|added by|\bby\s+mustafa|@lxcenter\.org|dterweij|\(c\)\s*\d{4}|author", re.I)
SKIP_TOP = {"docs", "tools", "README.md", "NOTICE", "LICENSE.txt"}
FULL = [
    (r"Hiawatha_for_AKLOR", "Hiawatha_for_KloxoCP"),
    (r"AKLOR WebControl", "Kloxo CP"),
    (r"AKLOR (Official|6\.)", r"Kloxo \1"),
    (r"\bAKLOR\b", "Kloxo CP"),
    (r"aklor\.invalid", "kloxocp.invalid"),
    (r"AKLOR", "KLOXO"), (r"Aklor", "Kloxo"), (r"aklor", "kloxo"),
]
SAME = [(r"AKLOR", "KLOXO"), (r"Aklor", "Kloxo"), (r"aklor", "kloxo")]
n_files = n_rep = 0
for dp, dns, fns in os.walk(ROOT):
    rel = os.path.relpath(dp, ROOT)
    if rel == ".":
        dns[:] = [d for d in dns if d not in SKIP_TOP]
        fns = [f for f in fns if f not in SKIP_TOP]
    for fn in fns:
        p = os.path.join(dp, fn)
        if os.path.islink(p): continue
        raw = open(p, "rb").read()
        if b"\x00" in raw[:8192]: continue
        rules = SAME if fn.endswith((".sql", ".db_schema")) else FULL
        out, tot = [], 0
        for line in raw.decode("latin-1").splitlines(keepends=True):
            if ATTRIB.search(line): out.append(line); continue
            for pat, rep in rules:
                line, k = re.subn(pat, rep, line); tot += k
            out.append(line)
        if tot:
            open(p, "wb").write("".join(out).encode("latin-1")); n_files += 1; n_rep += tot
ren = 0
for dp, dns, fns in os.walk(ROOT, topdown=False):
    for name in fns + dns:
        new = re.sub("aklor", "kloxo", name)
        if new != name:
            os.rename(os.path.join(dp, name), os.path.join(dp, new)); ren += 1
print(f"files edited: {n_files}, replacements: {n_rep}, paths renamed: {ren}")
