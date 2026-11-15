"""Genera las imágenes de la tienda DataSense (1200x900).

- Productos físicos: la foto original completa con una línea del color de la categoría (sin texto).
- Productos digitales y servicios: ilustración propia que muestra el producto (cifras de ejemplo) y una frase corta.

Requiere Pillow y la fuente Inter (https://github.com/rsms/inter, archivos Inter-*.ttf).
Uso: python tienda_imagenes.py <dir_fotos_originales> <logo.png> <dir_fuentes> <dir_salida>
  <dir_fotos_originales> contiene <id_producto>.jpg para los productos físicos (13 a 29).
Escribe <dir_salida>/<id_producto>.jpg; luego se copian a img/productos/ con nombre prod_<16 hex>.jpg.
"""
import sys
from PIL import Image, ImageDraw, ImageFilter, ImageFont

originales, logo_path, fuentes, salida = sys.argv[1:5]
W, H = 1200, 900
NAVY, TEAL, GREEN = (10, 46, 79), (15, 118, 110), (63, 174, 73)
GREEN_D, BLUE, AMBER, RED = (21, 128, 61), (29, 78, 216), (217, 119, 6), (220, 38, 38)
SLATE, SLATE_L, LINE, PANEL = (71, 85, 105), (148, 163, 184), (226, 232, 240), (248, 250, 252)
WHITE = (255, 255, 255)

CAT = {  # categoría: (inicio, fin, color claro)
    1: ((6, 78, 59), (21, 128, 61), (187, 247, 208)),
    2: ((10, 46, 79), (15, 118, 110), (134, 239, 172)),
    3: ((30, 27, 75), (91, 33, 182), (221, 214, 254)),
    4: ((10, 46, 79), (29, 78, 216), (191, 219, 254)),
    5: ((10, 46, 79), (14, 116, 144), (165, 243, 252)),
    6: ((15, 23, 42), (51, 65, 85), (203, 213, 225)),
    7: ((10, 46, 79), (21, 128, 61), (187, 247, 208)),
    8: ((23, 37, 84), (67, 56, 202), (199, 210, 254)),
}
FISICOS = {13: 6, 14: 6, 15: 6, 16: 6, 17: 1, 18: 1, 19: 1, 20: 1, 21: 1, 22: 1, 23: 1, 24: 1, 25: 7, 26: 7, 27: 7, 28: 7, 29: 7}


def F(nombre, tam):
    return ImageFont.truetype(f"{fuentes}/Inter-{nombre}.ttf", tam)


def MONO(tam, negrita=False):
    return ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSansMono%s.ttf" % ("-Bold" if negrita else ""), tam)


def mezcla(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))


def fondo(c0, c1):
    im = Image.new("RGB", (W, H))
    px = im.load()
    for y in range(H):
        for x in range(W):
            px[x, y] = mezcla(c0, c1, min(1.0, 0.35 * x / W + 0.65 * y / H) ** 1.6)
    im = im.convert("RGBA")
    puntos = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    pd = ImageDraw.Draw(puntos)
    for yy in range(30, H, 34):
        for xx in range(30, W, 34):
            pd.ellipse((xx - 2, yy - 2, xx + 2, yy + 2), fill=(255, 255, 255, 20))
    return Image.alpha_composite(im, puntos)


def sombra(img, caja, radio, alfa=90, desenfoque=16, dx=6, dy=14):
    capa = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    x0, y0, x1, y1 = caja
    ImageDraw.Draw(capa).rounded_rectangle((x0 + dx, y0 + dy, x1 + dx, y1 + dy), radius=radio, fill=(0, 0, 0, alfa))
    return Image.alpha_composite(img, capa.filter(ImageFilter.GaussianBlur(desenfoque)))


def ventana(img, titulo, pastilla=None, caja=(60, 170, 1140, 690)):
    """Ventana blanca con barra de título. Devuelve (img, área de contenido)."""
    x0, y0, x1, y1 = caja
    img = sombra(img, caja, 26)
    d = ImageDraw.Draw(img)
    d.rounded_rectangle(caja, radius=26, fill=WHITE)
    d.rounded_rectangle((x0, y0, x1, y0 + 60), radius=26, fill=PANEL)
    d.rectangle((x0, y0 + 38, x1, y0 + 60), fill=PANEL)
    d.line((x0, y0 + 60, x1, y0 + 60), fill=LINE, width=2)
    for i, c in enumerate(((248, 113, 113), (251, 191, 36), (74, 222, 128))):
        cx = x0 + 34 + i * 24
        d.ellipse((cx - 7, y0 + 23, cx + 7, y0 + 37), fill=c)
    d.text((x0 + 118, y0 + 16), titulo, font=F("Bold", 25), fill=NAVY)
    if pastilla:
        fp = F("SemiBold", 18)
        ancho = d.textlength(pastilla, font=fp) + 44
        d.rounded_rectangle((x1 - 26 - ancho, y0 + 14, x1 - 26, y0 + 46), radius=16, fill=(220, 252, 231))
        d.ellipse((x1 - 26 - ancho + 14, y0 + 26, x1 - 26 - ancho + 22, y0 + 34), fill=GREEN_D)
        d.text((x1 - 26 - ancho + 30, y0 + 19), pastilla, font=fp, fill=GREEN_D)
    return img, (x0 + 36, y0 + 84, x1 - 36, y1 - 28)


def indicadores(d, caja, items):
    """Fila de indicadores; devuelve la y donde termina."""
    x0, y0, x1, _ = caja
    n, hueco, alto = len(items), 20, 98
    ancho = (x1 - x0 - hueco * (n - 1)) / n
    for i, (titulo, valor, color) in enumerate(items):
        kx = x0 + i * (ancho + hueco)
        d.rounded_rectangle((kx, y0, kx + ancho, y0 + alto), radius=14, fill=PANEL, outline=LINE, width=2)
        d.rounded_rectangle((kx, y0 + 16, kx + 7, y0 + alto - 16), radius=3, fill=color)
        d.text((kx + 24, y0 + 14), titulo, font=F("Medium", 19), fill=SLATE)
        d.text((kx + 24, y0 + 42), valor, font=F("ExtraBold", 38), fill=color)
    return y0 + alto


def barras_v(d, caja, valores, color, resalta=None, etiquetas=None):
    x0, y0, x1, y1 = caja
    base = y1 - (22 if etiquetas else 0)
    d.line((x0, base, x1, base), fill=LINE, width=2)
    n = len(valores)
    paso = (x1 - x0) / n
    ancho = paso * 0.58
    tope = max(valores)
    for i, v in enumerate(valores):
        bx = x0 + i * paso + (paso - ancho) / 2
        alto = (base - y0) * v / tope
        d.rounded_rectangle((bx, base - alto, bx + ancho, base), radius=6, fill=(resalta if resalta and i == n - 1 else color))
        if etiquetas:
            t = etiquetas[i]
            d.text((bx + ancho / 2 - d.textlength(t, font=F("Medium", 14)) / 2, base + 5), t, font=F("Medium", 14), fill=SLATE_L)


def barras_h(d, caja, filas, c_ini, c_fin, sufijo=" %", ancho_etq=150, tope=100):
    x0, y0, x1, y1 = caja
    paso = (y1 - y0) / len(filas)
    bx0, bx1 = x0 + ancho_etq, x1 - 64
    for i, (nombre, v) in enumerate(filas):
        y = y0 + i * paso + paso / 2 - 11
        d.text((x0, y - 1), nombre, font=F("Medium", 18), fill=SLATE)
        d.rounded_rectangle((bx0, y, bx1, y + 22), radius=11, fill=(241, 245, 249))
        d.rounded_rectangle((bx0, y, bx0 + (bx1 - bx0) * v / tope, y + 22), radius=11, fill=mezcla(c_ini, c_fin, i / max(1, len(filas) - 1)))
        d.text((bx1 + 12, y - 1), f"{v}{sufijo}", font=F("Bold", 18), fill=NAVY)


def linea(d, caja, valores, color, grosor=5):
    x0, y0, x1, y1 = caja
    lo, hi = min(valores), max(valores)
    pts = [(x0 + (x1 - x0) * i / (len(valores) - 1), y1 - (y1 - y0) * (v - lo) / (hi - lo or 1)) for i, v in enumerate(valores)]
    d.line(pts, fill=color, width=grosor, joint="curve")
    for px_, py_ in pts:
        d.ellipse((px_ - 6, py_ - 6, px_ + 6, py_ + 6), fill=WHITE, outline=color, width=3)


def dona(d, cx, cy, r, grosor, partes, texto, subtexto):
    ini = -90
    for frac, color in partes:
        fin = ini + 360 * frac
        d.arc((cx - r, cy - r, cx + r, cy + r), ini + 1.2, fin - 1.2, fill=color, width=grosor)
        ini = fin
    fb = F("ExtraBold", 36)
    d.text((cx - d.textlength(texto, font=fb) / 2, cy - 28), texto, font=fb, fill=NAVY)
    fs = F("Medium", 16)
    d.text((cx - d.textlength(subtexto, font=fs) / 2, cy + 14), subtexto, font=fs, fill=SLATE)


def pie(img, etiqueta, frase, claro):
    """Etiqueta y frase de beneficio abajo a la izquierda (no el nombre del producto)."""
    d = ImageDraw.Draw(img)
    x, y = 70, 730
    fe = F("Bold", 24)
    d.rounded_rectangle((x, y + 8, x + 40, y + 15), radius=4, fill=claro)
    xe = x + 56
    for letra in etiqueta:
        d.text((xe, y - 4), letra, font=fe, fill=claro)
        xe += d.textlength(letra, font=fe) + 3
    for tam in (40, 38, 36, 34, 32):
        ft = F("ExtraBold", tam)
        filas, fila = [], ""
        for p in frase.split(" "):
            prueba = (fila + " " + p).strip()
            if d.textlength(prueba, font=ft) <= 720:
                fila = prueba
            else:
                filas.append(fila)
                fila = p
        filas.append(fila)
        if len(filas) <= 2:
            break
    yt = y + 40
    for fila in filas:
        d.text((x, yt), fila, font=ft, fill=WHITE)
        yt += int(tam * 1.22)
    return img


LOGO = Image.open(logo_path).convert("RGBA")
LOGO = LOGO.resize((300, round(LOGO.height * 300 / LOGO.width)), Image.LANCZOS)


def sello(img):
    caja = (818, 712, 1170, 870)
    img = sombra(img, caja, 24, alfa=70, desenfoque=10, dx=4, dy=8)
    ImageDraw.Draw(img).rounded_rectangle(caja, radius=24, fill=WHITE)
    img.alpha_composite(LOGO, (caja[0] + (caja[2] - caja[0] - LOGO.width) // 2, caja[1] + (caja[3] - caja[1] - LOGO.height) // 2))
    return img


def guardar(img, pid):
    img.convert("RGB").save(f"{salida}/{pid}.jpg", "JPEG", quality=88, optimize=True)
    print(pid)


# ---------- Productos físicos: foto limpia ----------
for pid, cat in FISICOS.items():
    foto = Image.open(f"{originales}/{pid}.jpg").convert("RGB")
    if foto.size != (W, H):
        foto = foto.resize((W, H), Image.LANCZOS)
    ImageDraw.Draw(foto).rectangle((0, H - 12, W, H), fill=CAT[cat][1])
    foto.save(f"{salida}/{pid}.jpg", "JPEG", quality=90, optimize=True)
    print(pid)


# ---------- 1. Plantilla Power BI · Tablero de ventas ----------
c0, c1, claro = CAT[2]
img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), "Ventas · Resumen del mes", "Actualizado hoy")
d = ImageDraw.Draw(img)
fin = indicadores(d, (x0, y0, x1, y1), (("Ventas", "$ 842 M", NAVY), ("Margen", "31,4 %", GREEN_D), ("Clientes activos", "1.206", BLUE)))
d.text((x0, fin + 22), "Ventas por mes", font=F("Bold", 21), fill=NAVY)
barras_v(d, (x0, fin + 62, x0 + 560, y1), (42, 48, 45, 55, 52, 60, 58, 66, 63, 71, 76, 84), (148, 197, 233), resalta=GREEN,
         etiquetas=("E", "F", "M", "A", "M", "J", "J", "A", "S", "O", "N", "D"))
d.line((x0 + 600, fin + 24, x0 + 600, y1), fill=LINE, width=2)
d.text((x0 + 632, fin + 22), "Ventas por zona", font=F("Bold", 21), fill=NAVY)
barras_h(d, (x0 + 632, fin + 62, x1, y1), (("Centro", 38), ("Norte", 27), ("Sur", 21), ("Oriente", 14)), GREEN, TEAL, ancho_etq=96, tope=40)
guardar(sello(pie(img, "PLANTILLA POWER BI", "Ventas, margen y clientes en un solo tablero", claro)), 1)

# ---------- 2. Plantilla Power BI · Estado financiero y flujo de caja ----------
img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), "Estado financiero · Flujo de caja", "Cierre mensual")
d = ImageDraw.Draw(img)
fin = indicadores(d, (x0, y0, x1, y1), (("Ingresos", "$ 1.250 M", NAVY), ("Utilidad neta", "$ 186 M", GREEN_D), ("Razón de liquidez", "1,8", AMBER)))
d.text((x0, fin + 22), "Ingresos y gastos", font=F("Bold", 21), fill=NAVY)
gx0, gy0, gx1, gy1 = x0, fin + 62, x0 + 520, y1 - 22
d.line((gx0, gy1, gx1, gy1), fill=LINE, width=2)
ingresos, gastos = (70, 78, 74, 86, 92, 100), (58, 61, 63, 66, 70, 74)
paso = (gx1 - gx0) / 6
for i in range(6):
    bx = gx0 + i * paso + 14
    for k, (v, col) in enumerate(((ingresos[i], NAVY), (gastos[i], (148, 163, 184)))):
        alto = (gy1 - gy0) * v / 100
        d.rounded_rectangle((bx + k * 30, gy1 - alto, bx + k * 30 + 26, gy1), radius=5, fill=col)
    t = ("Jul", "Ago", "Sep", "Oct", "Nov", "Dic")[i]
    d.text((bx + 28 - d.textlength(t, font=F("Medium", 14)) / 2, gy1 + 5), t, font=F("Medium", 14), fill=SLATE_L)
d.line((x0 + 560, fin + 24, x0 + 560, y1), fill=LINE, width=2)
d.text((x0 + 592, fin + 22), "Flujo de caja acumulado", font=F("Bold", 21), fill=NAVY)
linea(d, (x0 + 600, fin + 80, x1 - 12, y1 - 16), (12, 18, 15, 24, 31, 29, 40), GREEN)
guardar(sello(pie(img, "PLANTILLA POWER BI", "Resultados, balance y flujo de caja al día", claro)), 2)

# ---------- 4. Kit de plantillas de KPIs en Excel ----------
EXCEL = (16, 124, 65)
img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), "KPIs.xlsx · Indicadores comerciales")
d = ImageDraw.Draw(img)
cols = (("Indicador", 300), ("Meta", 130), ("Real", 130), ("Cumplimiento", 270), ("Tendencia", 178))
y = y0 - 6
d.rectangle((x0, y, x1, y + 44), fill=EXCEL)
x = x0
for nombre, ancho in cols:
    d.text((x + 14, y + 10), nombre, font=F("Bold", 19), fill=WHITE)
    x += ancho
filas = (("Ventas del mes", "$ 800 M", "$ 842 M", 105, (3, 4, 4, 5, 6)), ("Tasa de conversión", "18 %", "16,4 %", 91, (5, 5, 4, 4, 4)),
         ("Tiquete promedio", "$ 320 mil", "$ 351 mil", 110, (2, 3, 3, 4, 5)), ("Clientes nuevos", "120", "104", 87, (4, 3, 4, 3, 3)),
         ("Recompra", "35 %", "38 %", 108, (3, 3, 4, 4, 5)), ("Cartera vencida", "5 %", "6,1 %", 82, (2, 3, 3, 4, 4)))
alto_f = (y1 - (y + 44)) / len(filas)
for i, (ind, meta, real, cumple, serie) in enumerate(filas):
    fy = y + 44 + i * alto_f
    if i % 2:
        d.rectangle((x0, fy, x1, fy + alto_f), fill=(244, 250, 246))
    d.line((x0, fy + alto_f, x1, fy + alto_f), fill=LINE, width=1)
    ty = fy + alto_f / 2 - 12
    d.text((x0 + 14, ty), ind, font=F("SemiBold", 19), fill=NAVY)
    d.text((x0 + 314, ty), meta, font=F("Regular", 19), fill=SLATE)
    d.text((x0 + 444, ty), real, font=F("Bold", 19), fill=NAVY)
    col = GREEN if cumple >= 100 else (AMBER if cumple >= 88 else RED)
    bx = x0 + 574
    d.rounded_rectangle((bx, ty + 2, bx + 170, ty + 22), radius=10, fill=(241, 245, 249))
    d.rounded_rectangle((bx, ty + 2, bx + 170 * min(cumple, 110) / 110, ty + 22), radius=10, fill=col)
    d.text((bx + 182, ty), f"{cumple} %", font=F("Bold", 18), fill=col)
    sx0 = x0 + 844
    pts = [(sx0 + k * 32, fy + alto_f - 14 - (v - 2) * (alto_f - 28) / 4) for k, v in enumerate(serie)]
    d.line(pts, fill=EXCEL, width=4, joint="curve")
    d.ellipse((pts[-1][0] - 5, pts[-1][1] - 5, pts[-1][0] + 5, pts[-1][1] + 5), fill=EXCEL)
x = x0
for nombre, ancho in cols[:-1]:
    x += ancho
    d.line((x, y, x, y1), fill=(203, 213, 225), width=1)
d.rectangle((x0, y, x1, y1), outline=(203, 213, 225), width=2)
guardar(sello(pie(img, "PLANTILLAS EXCEL", "25 indicadores listos para medir sin programar", claro)), 4)


# ---------- Cursos: aula virtual ----------
def aula(pid, titulo, lecciones, frase, dibujo, boton='centro'):
    c0, c1, claro = CAT[3]
    img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), titulo, "Certificado incluido")
    d = ImageDraw.Draw(img)
    vx0, vy0, vx1, vy1 = x0, y0 - 6, x0 + 610, y1 - 34
    d.rounded_rectangle((vx0, vy0, vx1, vy1), radius=16, fill=(17, 24, 39))
    dibujo(d, (vx0 + 26, vy0 + 24, vx1 - 26, vy1 - 24))
    cx, cy = {'centro': ((vx0 + vx1) / 2, (vy0 + vy1) / 2), 'abajo': (vx1 - 76, vy1 - 76), 'arriba': (vx1 - 76, vy0 + 76)}[boton]
    d.ellipse((cx - 44, cy - 44, cx + 44, cy + 44), fill=WHITE)
    d.polygon(((cx - 12, cy - 20), (cx - 12, cy + 20), (cx + 22, cy)), fill=mezcla(c0, c1, 0.6))
    d.rounded_rectangle((vx0, y1 - 18, vx1, y1 - 8), radius=5, fill=(226, 232, 240))
    d.rounded_rectangle((vx0, y1 - 18, vx0 + (vx1 - vx0) * 0.42, y1 - 8), radius=5, fill=mezcla(c0, c1, 0.7))
    lx = vx1 + 30
    d.text((lx, y0 - 6), "Contenido del curso", font=F("Bold", 21), fill=NAVY)
    for i, (texto, hecho) in enumerate(lecciones):
        ly = y0 + 34 + i * 62
        d.rounded_rectangle((lx, ly, x1, ly + 50), radius=12, fill=PANEL, outline=LINE, width=2)
        if hecho:
            d.ellipse((lx + 12, ly + 11, lx + 40, ly + 39), fill=GREEN)
            d.line(((lx + 19, ly + 25), (lx + 24, ly + 31), (lx + 33, ly + 19)), fill=WHITE, width=4)
        else:
            d.ellipse((lx + 12, ly + 11, lx + 40, ly + 39), outline=SLATE_L, width=3)
        d.text((lx + 52, ly + 13), texto, font=F("SemiBold", 19), fill=NAVY if hecho else SLATE)
    guardar(sello(pie(img, "CURSO EN LÍNEA", frase, claro)), pid)


def dibujo_powerbi(d, c):
    x0, y0, x1, y1 = c
    for i, v in enumerate((40, 62, 51, 78, 70, 96)):
        bx = x0 + 20 + i * 44
        d.rounded_rectangle((bx, y1 - 20 - v * 1.6, bx + 30, y1 - 20), radius=5, fill=(96, 165, 250))
    d.arc((x1 - 190, y0 + 30, x1 - 40, y0 + 180), -90, 150, fill=(74, 222, 128), width=26)
    d.arc((x1 - 190, y0 + 30, x1 - 40, y0 + 180), 150, 270, fill=(71, 85, 105), width=26)
    for k in range(3):
        d.rounded_rectangle((x1 - 200, y1 - 96 + k * 28, x1 - 40 - k * 36, y1 - 80 + k * 28), radius=7, fill=(71, 85, 105))


def dibujo_sql(d, c):
    x0, y0, x1, y1 = c
    f = MONO(21)
    lila, celeste, verde = (196, 181, 253), (125, 211, 252), (134, 239, 172)
    filas = ((("SELECT", lila), (" cliente,", WHITE)), (("       SUM", celeste), ("(valor) ", WHITE), ("AS", lila), (" total", WHITE)),
             (("FROM", lila), (" ventas", WHITE)), (("WHERE", lila), (" fecha >= ", WHITE), ("'2026-01-01'", verde)),
             (("GROUP BY", lila), (" cliente", WHITE)), (("ORDER BY", lila), (" total ", WHITE), ("DESC", lila), (";", WHITE)))
    for i, partes in enumerate(filas):
        x = x0 + 44
        d.text((x0, y0 + i * 38), str(i + 1), font=f, fill=(100, 116, 139))
        for texto, color in partes:
            d.text((x, y0 + i * 38), texto, font=f, fill=color)
            x += d.textlength(texto, font=f)


def dibujo_python(d, c):
    x0, y0, x1, y1 = c
    f = MONO(20)
    lila, celeste = (196, 181, 253), (125, 211, 252)
    filas = ((("import", lila), (" pandas ", WHITE), ("as", lila), (" pd", WHITE)),
             (("modelo", WHITE), (" = ", celeste), ("Pronostico", (253, 224, 71)), ("()", WHITE)),
             (("modelo.", WHITE), ("fit", celeste), ("(ventas)", WHITE)))
    for i, partes in enumerate(filas):
        x = x0
        for texto, color in partes:
            d.text((x, y0 + i * 34), texto, font=f, fill=color)
            x += d.textlength(texto, font=f)
    gy0, gy1 = y0 + 130, y1
    real = (30, 36, 33, 44, 41, 52, 49, 58)
    pts = [(x0 + i * (x1 - x0) * 0.62 / 7, gy1 - (v - 25) * (gy1 - gy0) / 50) for i, v in enumerate(real)]
    d.line(pts, fill=(96, 165, 250), width=5, joint="curve")
    futuro = [pts[-1]] + [(pts[-1][0] + (k + 1) * (x1 - pts[-1][0]) / 4, gy1 - (v - 25) * (gy1 - gy0) / 50) for k, v in enumerate((61, 60, 68, 72))]
    for a, b in zip(futuro, futuro[1:]):
        mx, my = (a[0] + b[0]) / 2, (a[1] + b[1]) / 2
        d.line((a[0], a[1], mx, my), fill=(74, 222, 128), width=5)
    d.ellipse((futuro[-1][0] - 7, futuro[-1][1] - 7, futuro[-1][0] + 7, futuro[-1][1] + 7), fill=(74, 222, 128))


aula(5, "Aula virtual · Power BI", (("Modelo de datos", True), ("Medidas DAX", True), ("Visualizaciones", False), ("Publicación", False), ("Proyecto final", False)),
     "Aprende Power BI con casos reales", dibujo_powerbi)
aula(6, "Aula virtual · SQL", (("Consultas básicas", True), ("Uniones de tablas", True), ("Agrupar y resumir", False), ("Funciones de ventana", False), ("Retos semanales", False)),
     "Consulta y analiza tus datos con SQL", dibujo_sql, boton='abajo')
aula(7, "Aula virtual · Python y ML", (("Pandas para datos", True), ("Modelos predictivos", True), ("Segmentación", False), ("Clasificación", False), ("Proyecto de pronóstico", False)),
     "Modelos predictivos aplicados al negocio", dibujo_python, boton='arriba')

# ---------- 8. DataSense Cloud ----------
c0, c1, claro = CAT[4]
img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), "DataSense Cloud · Mis tableros", "Actualización automática")
d = ImageDraw.Draw(img)
nombres = ("Ventas", "Finanzas", "Inventario", "Clientes", "Operación", "Talento humano")
hueco = 22
tw, th = (x1 - x0 - 2 * hueco) / 3, (y1 - y0 + 6 - hueco) / 2
for i, nombre in enumerate(nombres):
    tx, ty = x0 + (i % 3) * (tw + hueco), y0 - 6 + (i // 3) * (th + hueco)
    d.rounded_rectangle((tx, ty, tx + tw, ty + th), radius=14, fill=PANEL, outline=LINE, width=2)
    d.text((tx + 18, ty + 14), nombre, font=F("Bold", 20), fill=NAVY)
    d.ellipse((tx + tw - 30, ty + 20, tx + tw - 18, ty + 32), fill=GREEN)
    area = (tx + 20, ty + 58, tx + tw - 20, ty + th - 18)
    if i % 3 == 0:
        barras_v(d, area, (3, 5, 4, 6, 7, 8), (147, 197, 253), resalta=BLUE)
    elif i % 3 == 1:
        pts = [(area[0] + k * (area[2] - area[0]) / 5, area[3] - v * (area[3] - area[1]) / 8) for k, v in enumerate((2, 4, 3, 6, 5, 8))]
        d.line(pts, fill=BLUE, width=5, joint="curve")
    else:
        cx, cy, r = (area[0] + area[2]) / 2, (area[1] + area[3]) / 2, (area[3] - area[1]) / 2
        d.arc((cx - r, cy - r, cx + r, cy + r), -90, 160, fill=BLUE, width=18)
        d.arc((cx - r, cy - r, cx + r, cy + r), 160, 270, fill=(203, 213, 225), width=18)
guardar(sello(pie(img, "LICENCIA ANUAL", "Tus tableros en la nube, siempre al día", claro)), 8)

# ---------- 9. SenseBot ----------
img = fondo(c0, c1)
caja = (90, 170, 690, 690)
img = sombra(img, caja, 26)
d = ImageDraw.Draw(img)
d.rounded_rectangle(caja, radius=26, fill=WHITE)
d.rounded_rectangle((90, 170, 690, 250), radius=26, fill=NAVY)
d.rectangle((90, 224, 690, 250), fill=NAVY)
d.ellipse((114, 186, 162, 234), fill=GREEN)
d.rounded_rectangle((127, 200, 149, 220), radius=5, fill=WHITE)
d.ellipse((132, 206, 137, 211), fill=GREEN)
d.ellipse((140, 206, 145, 211), fill=GREEN)
d.text((178, 186), "SenseBot", font=F("Bold", 24), fill=WHITE)
d.ellipse((178, 222, 188, 232), fill=(74, 222, 128))
d.text((194, 216), "En línea", font=F("Medium", 17), fill=(203, 213, 225))


def burbuja(texto, y, bot=True):
    f = F("Medium", 20)
    ancho = d.textlength(texto, font=f) + 36
    if bot:
        d.rounded_rectangle((114, y, 114 + ancho, y + 48), radius=18, fill=(241, 245, 249))
        d.text((132, y + 11), texto, font=f, fill=NAVY)
    else:
        d.rounded_rectangle((666 - ancho, y, 666, y + 48), radius=18, fill=NAVY)
        d.text((684 - ancho, y + 11), texto, font=f, fill=WHITE)


burbuja("¡Hola! Soy SenseBot. ¿En qué te ayudo?", 274)
burbuja("Quiero agendar una asesoría", 336, bot=False)
burbuja("¡Claro! ¿Qué día prefieres?", 398)
xq = 114
for texto in ("Mañana", "Esta semana", "Otro día"):
    aq = d.textlength(texto, font=F("SemiBold", 18)) + 32
    d.rounded_rectangle((xq, 462, xq + aq, 502), radius=20, outline=GREEN_D, width=2, fill=(240, 253, 244))
    d.text((xq + 16, 471), texto, font=F("SemiBold", 18), fill=GREEN_D)
    xq += aq + 10
d.rounded_rectangle((114, 612, 600, 664), radius=26, fill=PANEL, outline=LINE, width=2)
d.text((138, 626), "Escribe tu mensaje...", font=F("Regular", 19), fill=SLATE_L)
d.ellipse((612, 612, 664, 664), fill=GREEN)
d.polygon(((628, 626), (628, 650), (652, 638)), fill=WHITE)
for i, (titulo, valor, color) in enumerate((("Conversaciones hoy", "128", NAVY), ("Contactos capturados", "37", GREEN_D), ("Citas agendadas", "12", BLUE))):
    ty = 190 + i * 164
    cj = (740, ty, 1110, ty + 140)
    img = sombra(img, cj, 20, alfa=60, desenfoque=12, dx=4, dy=8)
    d = ImageDraw.Draw(img)
    d.rounded_rectangle(cj, radius=20, fill=WHITE)
    d.rounded_rectangle((740, ty + 22, 748, ty + 118), radius=4, fill=color)
    d.text((772, ty + 24), titulo, font=F("Medium", 21), fill=SLATE)
    d.text((772, ty + 58), valor, font=F("ExtraBold", 54), fill=color)
guardar(sello(pie(img, "LICENCIA ANUAL", "Atiende a tus clientes 24/7 con IA", claro)), 9)

# ---------- 10. Diagnóstico de calidad de datos ----------
c0, c1, claro = CAT[5]
CIAN = (14, 116, 144)
img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), "Diagnóstico de calidad de datos", "3 fuentes revisadas")
d = ImageDraw.Draw(img)
d.text((x0, y0 - 6), "Calidad general", font=F("Bold", 21), fill=NAVY)
dona(d, x0 + 170, y0 + 196, 128, 34, ((0.82, GREEN), (0.18, (226, 232, 240))), "82/100", "puntaje")
d.line((x0 + 380, y0, x0 + 380, y1), fill=LINE, width=2)
d.text((x0 + 412, y0 - 6), "Por dimensión", font=F("Bold", 21), fill=NAVY)
for i, (nombre, v, color) in enumerate((("Completitud", 91, GREEN), ("Unicidad", 94, GREEN), ("Consistencia", 78, AMBER), ("Actualidad", 66, AMBER))):
    by = y0 + 44 + i * 56
    d.text((x0 + 412, by), nombre, font=F("Medium", 19), fill=SLATE)
    d.rounded_rectangle((x0 + 560, by + 2, x1 - 70, by + 24), radius=11, fill=(241, 245, 249))
    d.rounded_rectangle((x0 + 560, by + 2, x0 + 560 + (x1 - 70 - x0 - 560) * v / 100, by + 24), radius=11, fill=color)
    d.text((x1 - 58, by), f"{v} %", font=F("Bold", 19), fill=NAVY)
py, px0 = y0 + 286, x0 + 412
for texto, color, fondo_c in (("12 hallazgos priorizados", RED, (254, 226, 226)), ("Hoja de ruta de 90 días", GREEN_D, (220, 252, 231))):
    ancho = d.textlength(texto, font=F("SemiBold", 19)) + 40
    d.rounded_rectangle((px0, py, px0 + ancho, py + 44), radius=22, fill=fondo_c)
    d.text((px0 + 20, py + 10), texto, font=F("SemiBold", 19), fill=color)
    px0 += ancho + 14
guardar(sello(pie(img, "SERVICIO EXPRESS", "Qué tan confiables son tus datos y qué corregir", claro)), 10)

# ---------- 11. Bolsa de horas de consultoría ----------
img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), "Consultoría · Bolsa de horas", "Agenda en línea")
d = ImageDraw.Draw(img)
d.text((x0, y0 - 6), "Horas disponibles", font=F("Bold", 21), fill=NAVY)
dona(d, x0 + 170, y0 + 196, 128, 34, ((0.6, CIAN), (0.4, (226, 232, 240))), "6 de 10", "horas")
d.line((x0 + 380, y0, x0 + 380, y1), fill=LINE, width=2)
d.text((x0 + 412, y0 - 6), "Sesiones", font=F("Bold", 21), fill=NAVY)
for i, (texto, dur, hecha) in enumerate((("Ajuste de tablero", "2 h", True), ("Modelo de datos", "1 h", True), ("Automatización", "1 h", True), ("Próxima sesión", "Agendar", False))):
    sy = y0 + 40 + i * 78
    d.rounded_rectangle((x0 + 412, sy, x1, sy + 62), radius=14, fill=PANEL if hecha else (236, 254, 255), outline=LINE if hecha else CIAN, width=2)
    if hecha:
        d.ellipse((x0 + 428, sy + 16, x0 + 458, sy + 46), fill=GREEN)
        d.line(((x0 + 436, sy + 31), (x0 + 441, sy + 37), (x0 + 451, sy + 25)), fill=WHITE, width=4)
    else:
        d.ellipse((x0 + 428, sy + 16, x0 + 458, sy + 46), outline=CIAN, width=3)
        d.line((x0 + 443, sy + 23, x0 + 443, sy + 39), fill=CIAN, width=3)
        d.line((x0 + 435, sy + 31, x0 + 451, sy + 31), fill=CIAN, width=3)
    d.text((x0 + 474, sy + 18), texto, font=F("SemiBold", 20), fill=NAVY)
    fd = F("Bold", 19)
    d.text((x1 - 24 - d.textlength(dur, font=fd), sy + 19), dur, font=fd, fill=SLATE if hecha else CIAN)
guardar(sello(pie(img, "SERVICIO EXPRESS", "Un consultor senior cuando lo necesites", claro)), 11)

# ---------- 12. Integración API ----------
img, (x0, y0, x1, y1) = ventana(fondo(c0, c1), "Integración API · Estado", "Sincronizado")
d = ImageDraw.Draw(img)
cy = (y0 + y1) / 2 - 44
for nx, titulo, sub, color in ((x0 + 20, "ERP", "Ventas y facturas", NAVY), (x1 - 250, "Tableros", "Indicadores al día", GREEN_D)):
    d.rounded_rectangle((nx, cy - 70, nx + 230, cy + 70), radius=18, fill=PANEL, outline=color, width=3)
    d.rounded_rectangle((nx + 22, cy - 46, nx + 70, cy + 2), radius=12, fill=color)
    for k in range(3):
        d.rounded_rectangle((nx + 32, cy - 36 + k * 13, nx + 60, cy - 30 + k * 13), radius=3, fill=WHITE)
    d.text((nx + 84, cy - 44), titulo, font=F("Bold", 26), fill=NAVY)
    d.text((nx + 22, cy + 22), sub, font=F("Medium", 18), fill=SLATE)
hx = (x0 + x1) / 2
d.ellipse((hx - 84, cy - 84, hx + 84, cy + 84), fill=CIAN)
d.ellipse((hx - 70, cy - 70, hx + 70, cy + 70), outline=(165, 243, 252), width=3)
ft = F("ExtraBold", 34)
d.text((hx - d.textlength("API", font=ft) / 2, cy - 34), "API", font=ft, fill=WHITE)
fs = F("SemiBold", 17)
d.text((hx - d.textlength("DataSense", font=fs) / 2, cy + 8), "DataSense", font=fs, fill=(207, 250, 254))
for ax0, ax1 in ((x0 + 262, hx - 96), (hx + 96, x1 - 262)):
    for dy, sentido in ((-16, 1), (16, -1)):
        d.line((ax0, cy + dy, ax1, cy + dy), fill=CIAN, width=5)
        px_ = ax1 if sentido == 1 else ax0
        d.polygon(((px_, cy + dy), (px_ - 16 * sentido, cy + dy - 10), (px_ - 16 * sentido, cy + dy + 10)), fill=CIAN)
ly = y1 - 76
d.rounded_rectangle((x0, ly, x1, y1), radius=14, fill=(15, 23, 42))
fm = MONO(18)
d.text((x0 + 20, ly + 12), "10:42  POST /facturas   200 OK   184 registros", font=fm, fill=(134, 239, 172))
d.text((x0 + 20, ly + 40), "10:57  GET  /clientes   200 OK   sin cambios", font=fm, fill=(148, 163, 184))
guardar(sello(pie(img, "SERVICIO EXPRESS", "Conecta tus sistemas con tus tableros", claro)), 12)


# ---------- Publicaciones ----------
def portada(ancho, alto, c0, c1, cabecera, titulo_lineas, pie_texto, numero=None):
    """Portada de una publicación de DataSense."""
    im = Image.new("RGB", (ancho, alto))
    px = im.load()
    for y in range(alto):
        for x in range(ancho):
            px[x, y] = mezcla(c0, c1, min(1.0, 0.4 * x / ancho + 0.6 * y / alto))
    d = ImageDraw.Draw(im)
    d.text((30, 30), cabecera, font=F("Bold", 20), fill=(199, 210, 254))
    if numero:
        fn = F("ExtraBold", 22)
        an = d.textlength(numero, font=fn) + 28
        d.rounded_rectangle((ancho - 30 - an, 24, ancho - 30, 62), radius=19, fill=(134, 239, 172))
        d.text((ancho - 30 - an + 14, 30), numero, font=fn, fill=(6, 78, 59))
    y = 78
    for texto, tam in titulo_lineas:
        d.text((30, y), texto, font=F("ExtraBold", tam), fill=WHITE)
        y += int(tam * 1.12)
    base = alto - 92
    alturas = (50, 86, 70, 118, 100, 150)
    paso = (ancho - 60) / len(alturas)
    for k, v in enumerate(alturas):
        bx = 30 + k * paso
        d.rounded_rectangle((bx, base - v, bx + paso * 0.62, base), radius=6, fill=(134, 239, 172) if k == len(alturas) - 1 else (165, 180, 252))
    d.line((30, base + 14, ancho - 30, base + 14), fill=(199, 210, 254), width=2)
    d.text((30, base + 28), pie_texto, font=F("SemiBold", 19), fill=WHITE)
    return im.convert("RGBA")


def publicacion(pid, etiqueta, frase, capas):
    c0, c1, claro = CAT[8]
    img = fondo(c0, c1)
    for capa, (x, y), angulo in capas:
        hoja = capa.rotate(angulo, expand=True, resample=Image.BICUBIC)
        marca = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        marca.paste((0, 0, 0, 95), (x + 10, y + 18), hoja.split()[3])
        img = Image.alpha_composite(img, marca.filter(ImageFilter.GaussianBlur(16)))
        img.alpha_composite(hoja, (x, y))
    guardar(sello(pie(img, etiqueta, frase, claro)), pid)


def tableta(contenido):
    ancho, alto = contenido.width + 36, contenido.height + 36
    t = Image.new("RGBA", (ancho, alto), (0, 0, 0, 0))
    ImageDraw.Draw(t).rounded_rectangle((0, 0, ancho - 1, alto - 1), radius=34, fill=(17, 24, 39))
    t.alpha_composite(contenido, (18, 18))
    return t


def pagina(ancho, alto):
    p = Image.new("RGBA", (ancho, alto), (255, 255, 255, 255))
    d = ImageDraw.Draw(p)
    d.rectangle((24, 28, ancho - 24, 120), fill=(199, 210, 254))
    for k in range(7):
        d.rounded_rectangle((24, 146 + k * 28, ancho - 24 - (k % 3) * 40, 158 + k * 28), radius=6, fill=(226, 232, 240))
    return p


INDIGO0, INDIGO1 = (23, 37, 84), (67, 56, 202)
revista = portada(360, 480, INDIGO0, INDIGO1, "DATASENSE", (("Insights", 62), ("Analítica que decide", 24)), "Casos de éxito · BI · IA", numero="N.° 12")
publicacion(30, "REVISTA IMPRESA", "Casos, tendencias y entrevistas en 84 páginas",
            ((pagina(340, 460), (250, 200), 9), (revista, (420, 170), -5)))
digital = portada(330, 450, INDIGO0, INDIGO1, "DATASENSE", (("Insights", 58), ("Edición digital", 24)), "Tendencias de BI e IA")
publicacion(31, "REVISTA DIGITAL", "Seis ediciones al año en tu correo",
            ((pagina(300, 400), (170, 230), -9), (pagina(300, 400), (700, 215), 8), (tableta(digital), (418, 168), 0)))
ebook = portada(330, 450, (15, 23, 42), (190, 24, 93), "E-BOOK · DATASENSE", (("Guía práctica", 40), ("de KPIs", 54), ("para gerentes", 30)), "Fichas de indicadores por área")
publicacion(32, "E-BOOK", "Elige, mide y presenta los indicadores clave",
            ((pagina(300, 400), (660, 230), 8), (tableta(ebook), (400, 168), -3)))
informe = portada(360, 480, (10, 46, 79), (67, 56, 202), "INFORME SECTORIAL", (("Analítica de", 38), ("datos en", 38), ("Colombia 2026", 38)), "Más de 300 organizaciones")
publicacion(33, "INFORME SECTORIAL", "Madurez analítica por sector, inversión y talento",
            ((pagina(340, 460), (590, 205), 8), (informe, (400, 170), -4)))
