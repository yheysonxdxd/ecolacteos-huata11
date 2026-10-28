"""Genera public/oficina-web/tema-dia.css a partir de estilos.css.

Uso (desde ecolacteos-api, cada vez que se cambien colores en estilos.css):
    python scripts/tema-dia.py public/oficina-web/estilos.css public/oficina-web/tema-dia.css

Cada color escrito a mano en estilos.css se pasa a su versión de día según para qué se usa:
fondos oscuros -> blancos azulados, letras claras -> oscuras, sombras más suaves, acentos más profundos.
Lo que va sobre foto o satélite (entrada, mapa) y la barra del menú se quedan como están.
"""
import colorsys
import re
import sys

ORIGEN = sys.argv[1]
DESTINO = sys.argv[2]

css = open(ORIGEN, encoding='utf-8').read()
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)

# --- lector mínimo de CSS: reglas, @media anidadas; se saltan @keyframes, @font-face, @property
def leer(texto):
    i, n, out = 0, len(texto), []
    while i < n:
        j = texto.find('{', i)
        if j < 0:
            break
        cabeza = texto[i:j].strip()
        # buscar la llave que cierra
        prof, k = 1, j + 1
        while k < n and prof:
            if texto[k] == '{': prof += 1
            elif texto[k] == '}': prof -= 1
            k += 1
        cuerpo = texto[j + 1:k - 1]
        i = k
        if cabeza.startswith('@media') or cabeza.startswith('@supports'):
            out.append(('media', cabeza, leer(cuerpo)))
        elif cabeza.startswith('@'):
            continue
        else:
            out.append(('regla', cabeza, cuerpo))
    return out

# --- colores
COLOR = re.compile(r'#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)')

def parse(c):
    if c.startswith('#'):
        h = c[1:]
        if len(h) in (3, 4): h = ''.join(x * 2 for x in h)
        r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
        a = int(h[6:8], 16) / 255 if len(h) == 8 else 1.0
        return r, g, b, a
    nums = [x.strip() for x in c[c.index('(') + 1:-1].replace('/', ',').split(',')]
    r, g, b = (float(x) for x in nums[:3])
    a = float(nums[3]) if len(nums) > 3 else 1.0
    return r, g, b, a

def fmt(r, g, b, a):
    r, g, b = (max(0, min(255, round(x))) for x in (r, g, b))
    if a >= 0.999:
        return '#%02x%02x%02x' % (r, g, b)
    return 'rgba(%d,%d,%d,%s)' % (r, g, b, ('%.3f' % a).rstrip('0').rstrip('.'))

def hls(r, g, b):
    return colorsys.rgb_to_hls(r / 255, g / 255, b / 255)

def de_hls(h, l, s):
    r, g, b = colorsys.hls_to_rgb(h, l, s)
    return r * 255, g * 255, b * 255

TINTA = (18, 33, 48)  # el "negro" del tema de día: azul lago muy oscuro
TONO = 0.57  # el tono de los grises de día: azul del lago (0.57 ≈ 205°)

# acentos conocidos: su versión para letra sobre fondo claro
ACENTO_LETRA = {
    '#4cc38a': '#1d8150', '#2e8b57': '#22704a', '#ef6b6b': '#c23838', '#e8b44c': '#9a6400', '#8fd3ff': '#1d6aa6',
    '#9ff0c3': '#1d8150', '#f3d27a': '#946200', '#f0a35e': '#b0601a', '#5faa78': '#2a7a4b', '#f1cf6b': '#8f6400',
    '#7fe0a8': '#1d8150', '#ffb3b3': '#b93030', '#f8e6b4': '#8a5e00', '#5fb3ff': '#1d6aa6', '#a06d00': '#8a5e00',
}

def es_neutro(r, g, b):
    h, l, s = hls(r, g, b)
    return s < 0.32 or max(r, g, b) - min(r, g, b) < 40

def dia(c, prop, fondo_de_color):
    r, g, b, a = parse(c)
    h, l, s = hls(r, g, b)
    sombra = prop in ('box-shadow', 'text-shadow', 'filter', 'drop-shadow')
    letra = prop in ('color', 'fill', 'stroke', 'caret-color', '-webkit-text-fill-color', 'outline-color', 'text-decoration-color')
    borde = prop.startswith('border') or prop.startswith('outline')

    if sombra:
        if l < 0.5:  # sombras oscuras: más suaves y verdosas
            return fmt(*TINTA, a * 0.42)
        if a < 1 and l > 0.85:  # brillos blancos de borde (inset): se quedan
            return fmt(255, 255, 255, min(1, a * 3))
        return c

    if letra:
        if fondo_de_color:  # letra sobre botón o etiqueta de color: se queda (blanco sobre verde)
            return c
        key = fmt(r, g, b, 1)
        if key in ACENTO_LETRA:
            rr, gg, bb, _ = parse(ACENTO_LETRA[key]); return fmt(rr, gg, bb, a)
        if es_neutro(r, g, b):
            if l > 0.5:  # letra clara -> oscura
                nl = 0.12 + (1 - l) * 0.9
                return fmt(*de_hls(TONO, min(nl, 0.55), 0.22), a)
            return c
        if l > 0.5:  # otro acento claro: más profundo
            return fmt(*de_hls(h, 0.36, min(1, s * 0.9 + 0.1)), a)
        return c

    # fondos, bordes, degradados
    if es_neutro(r, g, b):
        if l > 0.7:  # blanco translúcido (bordes, rellenos sutiles) -> tinta translúcida
            if a >= 0.999 and not borde:
                return c  # blanco sólido: ya es de día
            return fmt(*TINTA, a * (0.9 if borde else 0.75))
        if l < 0.35:  # fondos oscuros -> papel / blanco
            if a < 0.999:
                return fmt(255, 255, 255, min(1, a * 0.9 + 0.08))
            if borde:
                return fmt(*de_hls(TONO, 0.87, 0.22), 1)
            nl = 0.955 + (l - 0.06) * 0.15
            return fmt(*de_hls(TONO, min(nl, 0.995), 0.3), 1)
        return fmt(*de_hls(TONO, 1 - l * 0.45, max(s * 0.4, 0.12)), a)  # grises medios
    # colores con tono
    if l < 0.4:  # rellenos oscuros con tono (verde suave, rojo apagado) -> tinte claro
        return fmt(*de_hls(h, 0.78 if borde else 0.93, min(0.6, s + 0.15)), a)
    return c  # acentos medios/claros en fondos: se quedan (los rellenos translúcidos de color se ven bien en claro)

PROPS = re.compile(r'^\s*(-?[a-z-]+)\s*:\s*(.+?)\s*$', re.S)
SALTAR = re.compile(r'\.login|\.camino|\.campo|\.btn-entrar|\.digitos|\.saludo|\.bienvenida|\.indica|\.riel|\.gota|\.brillos|\.marca-top|\.titulo-entrada|\.sello-form|\.pin2|\.pin-huata|\.leaflet|\.mapa-flota|\.mf-|nav\.menu|#mapa-huata|@media print')

def con_tema(sel):
    partes = []
    for s in sel.split(','):
        s = s.strip()
        if not s: continue
        if s in (':root', 'html'):
            partes.append(':root[data-tema="dia"]')
        elif s.startswith('html') or s.startswith(':root'):
            partes.append(':root[data-tema="dia"]' + s[len('html') if s.startswith('html') else len(':root'):])
        else:
            # nada de esto toca la entrada (va de noche sobre su foto): ":not(.login *)" antes del pseudo-elemento
            k = s.find('::')
            s = s + ':not(.login *)' if k < 0 else s[:k] + ':not(.login *)' + s[k:]
            partes.append(':root[data-tema="dia"] ' + s)
    return ', '.join(partes)

def color_saturado_en(valor):
    for c in COLOR.findall(valor):
        r, g, b, a = parse(c)
        if not es_neutro(r, g, b) and a > 0.6 and hls(r, g, b)[1] < 0.7:
            return True
    return bool(re.search(r'var\(--(verde|exito|alerta|hoja)\)', valor)) and 'gradient' in valor

def regla(sel, cuerpo):
    if SALTAR.search(sel):
        return None
    decls = [d for d in cuerpo.split(';') if d.strip()]
    pares = []
    for d in decls:
        m = PROPS.match(d)
        if m: pares.append((m.group(1), m.group(2)))
    fondo_color = any(p.startswith('background') and color_saturado_en(v) for p, v in pares)
    nuevas = []
    for p, v in pares:
        if p.startswith('--'):
            continue
        if not COLOR.search(v):
            continue
        prop = 'box-shadow' if p in ('box-shadow', 'text-shadow') else p
        # en SVG, fill/stroke de un texto es letra; de una figura (rect, path, circle) es fondo
        if p in ('fill', 'stroke'):
            ultimo = sel.split(',')[0].strip().split(' ')[-1]
            prop = p if re.search(r'text|tspan|g-valor|g-eje', ultimo) else ('border-color' if p == 'stroke' else 'background')
        nv = COLOR.sub(lambda m: dia(m.group(0), prop, fondo_color), v)
        if nv != v:
            nuevas.append('%s: %s' % (p, nv))
    if not nuevas:
        return None
    return '%s { %s; }' % (con_tema(sel), '; '.join(nuevas))

def escribir(items, sangria=''):
    out = []
    for it in items:
        if it[0] == 'media':
            if 'print' in it[1]:
                continue
            dentro = escribir(it[2], sangria + '  ')
            if dentro:
                out.append('%s%s {\n%s\n%s}' % (sangria, it[1], '\n'.join(dentro), sangria))
        else:
            r = regla(it[1], it[2])
            if r: out.append(sangria + r)
    return out

lineas = escribir(leer(css))
open(DESTINO, 'w', encoding='utf-8', newline='\n').write(
    '/* Tema de día: GENERADO desde estilos.css (los colores escritos a mano, en su versión clara).\n'
    '   No editar a mano: se rehace con scripts/tema-dia.py; lo propio del tema de día va en tema-dia-base.css. */\n' + '\n'.join(lineas) + '\n')
print(len(lineas), 'reglas')
