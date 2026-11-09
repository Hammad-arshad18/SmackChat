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
    # Concept A: The Floor Clock
    svg_a = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-labelledby="title-a">
  <title id="title-a">SmackChat Concept A — The Floor Clock</title>
  <path fill="#17181C" fill-rule="evenodd" d="
    M 115 22
    H 149
    A 5 5 0 0 1 154 27
    V 29
    A 5 5 0 0 1 149 34
    H 143
    V 60.8
    A 76 76 0 1 1 69.8 179.6
    C 58 192 48 204 48 218
    C 58 218 92 214 120 208
    A 76 76 0 0 1 121 60.8
    V 34
    H 115
    A 5 5 0 0 1 110 29
    V 27
    A 5 5 0 0 1 115 22
    Z
    M 122 122
    V 90
    A 10 10 0 0 1 142 90
    V 122
    A 18 18 0 0 1 150 136
    A 18 18 0 0 1 132 154
    A 18 18 0 0 1 122 144
    H 86
    A 10 10 0 0 1 86 124
    H 122
    A 18 18 0 0 1 122 122
    Z
    M 132 130
    A 6 6 0 1 0 132.1 130
    Z
  "/>
</svg>'''

    # Concept B: The Kinetic S
    svg_b = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-labelledby="title-b">
  <title id="title-b">SmackChat Concept B — The Kinetic S</title>
  <g fill="#17181C">
    <path d="
      M 196 74
      V 52
      C 176 34 148 26 122 26
      C 76 26 50 56 50 96
      C 50 134 76 150 114 162
      L 134 142
      C 100 132 84 120 84 96
      C 84 72 100 56 122 56
      C 142 56 160 62 170 74
      Z
    "/>
    <path d="
      M 60 182
      V 204
      C 80 222 108 230 134 230
      C 180 230 206 200 206 160
      C 206 122 180 106 142 94
      L 122 114
      C 156 124 172 136 172 160
      C 172 184 156 200 134 200
      C 114 200 96 194 86 182
      Z
    "/>
  </g>
</svg>'''

    # Concept C: The Dialogue Spark
    svg_c = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" aria-labelledby="title-c">
  <title id="title-c">SmackChat Concept C — The Dialogue Spark</title>
  <path fill="#17181C" fill-rule="evenodd" d="
    M 80 36
    H 176
    A 44 44 0 0 1 220 80
    V 140
    A 44 44 0 0 1 176 184
    H 98
    L 48 220
    C 56 200 56 190 56 184
    A 44 44 0 0 1 36 140
    V 80
    A 44 44 0 0 1 80 36
    Z
    M 146 68
    L 96 118
    H 124
    L 110 154
    L 160 104
    H 132
    L 146 68
    Z
    M 174 136
    A 11 11 0 1 0 174.1 136
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
    # Wordmark: "Smack" bold, "Chat" regular
    path_bold, next_x = text_to_paths("Smack", "C:/Windows/Fonts/segoeuib.ttf", 88, 250, 160)
    path_reg, total_x = text_to_paths("Chat", "C:/Windows/Fonts/segoeui.ttf", 88, next_x + 3, 160)
    
    dot_x = round(total_x + 8, 2)
    dot_y = 153
    dot_path = f"M {dot_x} {dot_y} A 7 7 0 1 0 {dot_x + 0.1} {dot_y} Z"
    
    # Margin balance:
    # Left margin = 36 + (left of symbol ~40) => ~76
    # Right of text = total_x + 16 => canvas_w should balance right margin ~50-60
    canvas_w = int(total_x + 60)
    
    def wrap_lockup(name, symbol_file, path_color="#17181C"):
        with open(symbol_file) as sf:
            sym_content = sf.read()
        body = re.search(r"<svg[^>]*>(.*)</svg>", sym_content, re.DOTALL).group(1)
        body = re.sub(r"<title[^>]*>.*?</title>", "", body, flags=re.DOTALL)
        
        lockup_svg = f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {canvas_w} 256" width="{canvas_w}" height="256" role="img" aria-labelledby="title-lockup-{name}">
  <title id="title-lockup-{name}">SmackChat {name} Lockup</title>
  <g id="symbol" transform="translate(36, 36) scale(0.71875)">
    {body.strip()}
  </g>
  <g id="wordmark" fill="{path_color}">
    <path d="{path_bold}"/>
    <path d="{path_reg}"/>
    <path d="{dot_path}"/>
  </g>
</svg>'''
        return lockup_svg

    with open("brand/concept-a-lockup.svg", "w") as f:
        f.write(wrap_lockup("Concept A", "brand/concept-a-symbol.svg"))
    with open("brand/concept-b-lockup.svg", "w") as f:
        f.write(wrap_lockup("Concept B", "brand/concept-b-symbol.svg"))
    with open("brand/concept-c-lockup.svg", "w") as f:
        f.write(wrap_lockup("Concept C", "brand/concept-c-symbol.svg"))

make_symbols()
make_lockups()
print("Updated symbols and lockups successfully!")
