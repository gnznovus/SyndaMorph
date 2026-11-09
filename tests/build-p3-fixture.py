#!/usr/bin/env python3
"""Build a small two-page P3 fixture ZIP; no external packages required."""
from pathlib import Path
import base64
import zipfile

out = Path(__file__).parent / "fixtures" / "p3-sample.zip"
out.parent.mkdir(parents=True, exist_ok=True)
# 1x1 RGBA PNG. Two paths deliberately contain the same bytes.
png = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/OhoAAAAASUVORK5CYII="
)
with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED) as z:
    z.writestr("site/index.html", """<!doctype html><html lang="en"><head>
    <link rel="stylesheet" href="css/main.css"></head><body>
    <section class="hero"><h1>Home page</h1>
    <img src="images/shared.png" alt="Shared photo">
    </section></body></html>""")
    z.writestr("site/rooms.html", """<!doctype html><html lang="en"><head>
    <link rel="stylesheet" href="css/main.css"></head><body>
    <main><h1>Rooms page</h1><p class="lead">A second page.</p>
    <img src="images/duplicate.png" alt="Duplicate asset">
    </main></body></html>""")
    z.writestr("site/css/main.css", ".hero {padding:24px;background:#eef4fa}.lead {font-size:18px}")
    z.writestr("site/images/shared.png", png)
    z.writestr("site/images/duplicate.png", png)
    z.writestr("site/images/unreferenced.png", png)
print(f"Created {out}")
