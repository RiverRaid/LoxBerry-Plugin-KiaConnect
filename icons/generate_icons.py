"""
Erzeugt icon_64.png / icon_128.png / icon_256.png / icon_512.png aus dem
Kia2Lox-Icon-Design (Monogramm K mit Blitz), passend zu icons/icon.svg.

Wird nur einmalig lokal ausgefuehrt, ist nicht Teil des LoxBerry-Plugins
selbst (die erzeugten PNGs werden separat ins Repository aufgenommen).

Aufruf:
    python generate_icons.py
"""

import os

from PIL import Image, ImageDraw

BG_COLOR = (18, 24, 31, 255)      # #12181F
K_COLOR = (237, 239, 241, 255)    # #EDEFF1
BOLT_COLOR = (232, 93, 76, 255)   # #E85D4C

# Coordinates from icons/icon.svg, in the original 64x64 viewBox
K_POINTS = [
    (14, 12), (14, 52), (21, 52), (21, 34),
    (37, 52), (47, 52), (28, 31), (45, 12),
    (36, 12), (21, 29), (21, 12),
]
BOLT_POINTS = [
    (44, 24), (38, 38), (44, 38), (41, 52),
    (54, 34), (47, 34), (51, 24),
]

SIZES = [64, 128, 256, 512]
OUT_DIR = os.path.dirname(__file__)


def render(size: int) -> Image.Image:
    # Render at 4x supersampling, then downscale for clean anti-aliased edges
    scale = 4
    canvas_size = size * scale
    factor = canvas_size / 64

    img = Image.new("RGBA", (canvas_size, canvas_size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    radius = 14 * factor
    draw.rounded_rectangle([0, 0, canvas_size, canvas_size], radius=radius, fill=BG_COLOR)

    k_scaled = [(x * factor, y * factor) for x, y in K_POINTS]
    draw.polygon(k_scaled, fill=K_COLOR)

    bolt_scaled = [(x * factor, y * factor) for x, y in BOLT_POINTS]
    draw.polygon(bolt_scaled, fill=BOLT_COLOR)

    return img.resize((size, size), Image.LANCZOS)


def main() -> None:
    for size in SIZES:
        img = render(size)
        out_path = os.path.join(OUT_DIR, f"icon_{size}.png")
        img.save(out_path)
        print(f"geschrieben: {out_path}")


if __name__ == "__main__":
    main()
