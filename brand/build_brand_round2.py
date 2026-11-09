import os
import re
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

def round_svg_path(d):
    return re.sub(r'(\d+\.\d{2})\d+', r'\1', d)

def text_to_paths(text, font_path, font_size, start_x, baseline_y, tracking=0):
    font = TTFont(font_path)
    glyph_set = font.getGlyphSet()
    cmap = font.getBestCmap()
    units_per_em = font['head'].unitsPerEm
    scale = font_size / units_per_em

    cur_x = start_x
    paths = []

    for char in text:
        glyph_name = cmap.get(ord(char))
        if not glyph_name:
            continue

        pen = SVGPathPen(glyph_set)
        t_pen = TransformPen(pen, (scale, 0, 0, -scale, cur_x, baseline_y))
        glyph = glyph_set[glyph_name]
        glyph.draw(t_pen)
        d = pen.getCommands()
        if d:
            paths.append(round_svg_path(d))

        cur_x += glyph.width * scale + tracking

    return " ".join(paths), cur_x

def make_symbols():
    # Concept A (Stripe aesthetic): The Precision S·
    # Perfectly centered (left ~28, right ~28, top ~24, bottom ~24)
    svg_a = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-labelledby="title-a">
  <title id="title-a">SmackChat Concept A — The Precision S-Dot</title>
  <g fill="#17181C">
    <!-- Custom Geometric S with 45° Terminal Cuts -->
    <path d="
      M 166 76
      L 190 52
      C 168 30 138 20 106 20
      C 54 20 24 54 24 100
      C 24 152 74 162 118 172
      C 144 178 158 186 158 202
      C 158 220 138 232 108 232
      C 72 232 44 214 24 190
      L 0 214
      C 26 246 66 262 108 262
      C 164 262 198 230 198 194
      C 198 144 150 134 106 124
      C 80 118 64 110 64 96
      C 64 80 80 66 106 66
      C 132 66 152 72 166 76
      Z
    " transform="translate(26, 7) scale(0.81)"/>
    <!-- Smack Accent Period Dot -->
    <circle cx="204" cy="214" r="15"/>
  </g>
</svg>'''

    # Concept B (Slack aesthetic): The Channel Pinwheel
    svg_b = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-labelledby="title-b">
  <title id="title-b">SmackChat Concept B — The Channel Pinwheel</title>
  <g fill="#17181C">
    <!-- Top-Left Voice (horizontal) -->
    <rect x="46" y="64" width="96" height="36" rx="18"/>
    <!-- Top-Right Voice (vertical) -->
    <rect x="156" y="46" width="36" height="96" rx="18"/>
    <!-- Bottom-Right Voice (horizontal) -->
    <rect x="114" y="156" width="96" height="36" rx="18"/>
    <!-- Bottom-Left Voice (vertical) -->
    <rect x="64" y="114" width="36" height="96" rx="18"/>
    <!-- Center Presence Core -->
    <circle cx="128" cy="128" r="14"/>
  </g>
</svg>'''

    # Concept C (Linear aesthetic): The Kinetic Shutter
    svg_c = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-labelledby="title-c">
  <title id="title-c">SmackChat Concept C — The Kinetic Shutter</title>
  <path fill="#17181C" fill-rule="evenodd" d="
    M 128 36
    A 92 92 0 1 1 127.9 36
    Z
    M 128 62
    A 66 66 0 0 1 194 128
    C 194 146 182 162 164 168
    L 136 140
    C 146 136 152 126 152 116
    C 152 100 138 88 122 88
    A 40 40 0 0 0 88 128
    L 62 128
    C 62 92 92 62 128 62
    Z
    M 128 194
    A 66 66 0 0 1 62 128
    C 62 110 74 94 92 88
    L 120 116
    C 110 120 104 130 104 140
    C 104 156 118 168 134 168
    A 40 40 0 0 0 168 128
    L 194 128
    C 194 164 164 194 128 194
    Z
    M 128 120
    A 8 8 0 1 0 128.1 120
    Z
  "/>
</svg>'''

    with open("brand/concept-a-symbol.svg", "w") as f:
        f.write(svg_a)
    with open("brand/concept-b-symbol.svg", "w") as f:
        f.write(svg_b)
    with open("brand/concept-c-symbol.svg", "w") as f:
        f.write(svg_c)

def make_lockups():
    # Lockup A: Stripe-style lowercase "smack."
    path_a, len_a = text_to_paths("smack", "C:/Windows/Fonts/segoeuib.ttf", 94, 250, 160)
    dot_a = f"M {len_a + 8:.2f} 152 A 8 8 0 1 0 {len_a + 8.1:.2f} 152 Z"
    canvas_w_a = int(len_a + 60)
    
    # Lockup B: Slack-style "SmackChat"
    path_b_bold, len_b_bold = text_to_paths("Smack", "C:/Windows/Fonts/segoeuib.ttf", 88, 250, 160)
    path_b_reg, len_b_reg = text_to_paths("Chat", "C:/Windows/Fonts/segoeui.ttf", 88, len_b_bold + 3, 160)
    canvas_w_b = int(len_b_reg + 60)
    
    # Lockup C: Linear-style "SmackChat"
    path_c, len_c = text_to_paths("SmackChat", "C:/Windows/Fonts/segoeuib.ttf", 86, 250, 160)
    canvas_w_c = int(len_c + 60)

    def wrap_lockup(name, symbol_file, wordmark_paths, canvas_w):
        with open(symbol_file) as sf:
            sym_content = sf.read()
        body = re.search(r"<svg[^>]*>(.*)</svg>", sym_content, re.DOTALL).group(1)
        body = re.sub(r"<title[^>]*>.*?</title>", "", body, flags=re.DOTALL)
        
        return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {canvas_w} 256" width="{canvas_w}" height="256" role="img" aria-labelledby="title-lockup-{name}">
  <title id="title-lockup-{name}">SmackChat {name} Lockup</title>
  <g id="symbol" transform="translate(36, 36) scale(0.71875)">
    {body.strip()}
  </g>
  <g id="wordmark" fill="#17181C">
    {wordmark_paths}
  </g>
</svg>'''

    with open("brand/concept-a-lockup.svg", "w") as f:
        f.write(wrap_lockup("A", "brand/concept-a-symbol.svg", f'<path d="{path_a}"/><path d="{dot_a}"/>', canvas_w_a))
    with open("brand/concept-b-lockup.svg", "w") as f:
        f.write(wrap_lockup("B", "brand/concept-b-symbol.svg", f'<path d="{path_b_bold}"/><path d="{path_b_reg}"/>', canvas_w_b))
    with open("brand/concept-c-lockup.svg", "w") as f:
        f.write(wrap_lockup("C", "brand/concept-c-symbol.svg", f'<path d="{path_c}"/>', canvas_w_c))

make_symbols()
make_lockups()
print("Updated all brand files!")
