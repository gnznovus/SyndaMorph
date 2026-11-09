#!/usr/bin/env python3
"""Create P4-A fixture: two pages, nested CSS background image, native typography."""
from pathlib import Path
import struct
import zlib
import zipfile

path = Path(__file__).parent / "fixtures" / "p4a-background-typography.zip"
path.parent.mkdir(parents=True, exist_ok=True)
def gradient_png(width=480, height=180):
    """Generate a prominent blue/teal gradient with standard-library PNG encoding."""
    raw = bytearray()
    for y in range(height):
        raw.append(0)  # No PNG row filter.
        for x in range(width):
            t = (x / (width - 1) + y / (height - 1)) / 2
            raw.extend((int(16 + 30 * t), int(80 + 95 * t), int(155 - 20 * t), 255))
    def chunk(kind, payload):
        return (struct.pack(">I", len(payload)) + kind + payload +
                struct.pack(">I", zlib.crc32(kind + payload) & 0xffffffff))
    return (b"\x89PNG\r\n\x1a\n" +
            chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0)) +
            chunk(b"IDAT", zlib.compress(raw, 8)) +
            chunk(b"IEND", b""))

png = gradient_png()
with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr("hotel/pages/home.html", """<!doctype html><html><head>
    <link rel="stylesheet" href="../assets/css/site.css">
    </head><body><section class="hero">
      <h1 class="title">Background image from CSS</h1>
      <p class="lead">Native typography and reusable image.</p>
      </section></body></html>""")
    z.writestr("hotel/pages/dining.html", """<!doctype html><html><head>
    <style>.hero { background-image:url('../assets/images/hero.png'); padding:32px; }</style>
    </head><body><main><div class="hero"><h2>Embedded CSS image</h2>
    <p style="font-weight:700;line-height:1.5">Another independently editable page</p>
    </div></main></body></html>""")
    z.writestr("hotel/assets/css/site.css", """/* CSS URLs are relative to THIS css file */
    .hero { background-image:url('../images/hero.png'); background-size:cover;
      background-color:#eef4fa; padding:32px 24px; }
    .title { font-size:32px; font-weight:700; line-height:1.25; }
    .lead { font-size:18px; line-height:1.6; }
    """)
    z.writestr("hotel/assets/images/hero.png", png)
    z.writestr("hotel/assets/images/unused.png", png)
print(path)
