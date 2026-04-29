#!/usr/bin/env python3
"""Rewrite internal site links from *.html to extensionless URLs ( /page )."""
from __future__ import annotations

import pathlib

REPO_ROOT = pathlib.Path(__file__).resolve().parent.parent

HTML_FILES = [
    "index.html",
    "sobre.html",
    "eventos.html",
    "galeria.html",
    "faq.html",
    "contacto.html",
    "bilhetes.html",
    "ticket.html",
    "links.html",
    "confirmacao.html",
    "cancelamento.html",
]

EXTRA_TEXT = [
    "js/bilhetes.js",
    "server/api/create-checkout.php",
    "server/setup/install.php",
    "public/sitemap.xml",
]

SITE = "https://ecstaticdanceviseu.pt"

PAGES = (
    ("sobre", "/sobre"),
    ("eventos", "/eventos"),
    ("galeria", "/galeria"),
    ("faq", "/faq"),
    ("contacto", "/contacto"),
    ("bilhetes", "/bilhetes"),
    ("ticket", "/ticket"),
    ("links", "/links"),
    ("confirmacao", "/confirmacao"),
    ("cancelamento", "/cancelamento"),
)


def rewrite(text: str) -> str:
    # Absolute URLs (canonical, og:url)
    text = text.replace(f"{SITE}/index.html", f"{SITE}/")
    for name, path in PAGES:
        text = text.replace(f"{SITE}/{name}.html", f"{SITE}{path}")

    # Relative index
    text = text.replace('href="index.html"', 'href="/"')
    text = text.replace("href='index.html'", "href='/'")

    # Relative href=<page>.html
    for _, path in PAGES:
        slug = path.strip("/")  # sobre
        text = text.replace(f'href="{slug}.html', f'href="{path}')
        text = text.replace(f"href='{slug}.html", f"href='{path}")

    # Path-style /slug.html anywhere (Stripe, redirects, strings)
    for name, path in PAGES:
        text = text.replace(f"/{name}.html#", f"{path}#")
        text = text.replace(f"/{name}.html?", f"{path}?")
        text = text.replace(f"/{name}.html", path)

    # Bare slug.html? (e.g. JS template `confirmacao.html?code=`)
    for name, path in PAGES:
        slug = path.strip("/")
        text = text.replace(f"{slug}.html#", f"{path}#")
        text = text.replace(f"{slug}.html?", f"{path}?")

    return text


def main() -> None:
    for rel in HTML_FILES + EXTRA_TEXT:
        path = REPO_ROOT / rel
        if not path.is_file():
            print(f"skip missing: {rel}")
            continue
        raw = path.read_text(encoding="utf-8")
        after = rewrite(raw)
        if after != raw:
            path.write_text(after, encoding="utf-8", newline="\n")
            print(f"updated {rel}")


if __name__ == "__main__":
    main()
