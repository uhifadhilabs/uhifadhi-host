"""Module idioms: Anthropogenic, Vegetation, Biodiversity, Drought, Air & smoke, Livestock."""
import json
import math
import os
import random

import fires as FI
from charts import F, polyline, FIRE_RAMP, kde

RNG = random.Random(77)
TILES = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'tiles')
YEARS20 = list(range(2000, 2024))


def _nca_ring():
    g = json.load(open(os.path.join(TILES, 'nca.geojson')))
    return g['coordinates'][0] if g['type'] == 'Polygon' else g['coordinates'][0][0]


def _nca_proj(W=470, H=340, pad=18, bot=44):
    ring = _nca_ring()
    lons = [p[0] for p in ring]
    lats = [p[1] for p in ring]
    lon0, lon1, lat0, lat1 = min(lons), max(lons), min(lats), max(lats)
    s = min((W - 2 * pad) / (lon1 - lon0), (H - pad - bot) / (lat1 - lat0))

    def P(lon, lat):
        return pad + (lon - lon0) * s, pad + (lat1 - lat) * s
    return P, ring


def _inside(ring, lon, lat):
    c = False
    n = len(ring)
    for i in range(n):
        x1, y1 = ring[i]
        x2, y2 = ring[(i + 1) % n]
        if (y1 > lat) != (y2 > lat) and lon < (x2 - x1) * (lat - y1) / (y2 - y1) + x1:
            c = not c
    return c


# ═══════════════ ANTHROPOGENIC ═══════════════
def buffer_rings():
    f = F(470, 220, t=18, b=26)
    x = f.sx(2000, 2023)
    y = f.sy(0, 46)
    f.grid_y(x, y, (0, 15, 30, 45), unit="built-up km²")
    rings = [("0–5 km", 2.1, 0.145, "#E05B41"), ("5–10 km", 4.4, 0.095, "#DBA33F"),
             ("10–25 km", 9.5, 0.062, "#87988D")]
    for name, base, rate, col in rings:
        pts = [(x(yr), y(base * math.exp(rate * (yr - 2000)))) for yr in YEARS20]
        f.out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.8"'))
        f.out.append(f'<text x="{pts[-1][0]-4:.1f}" y="{pts[-1][1]-6:.1f}" text-anchor="end" '
                     f'fill="{col}" font-weight="700">{name}</text>')
    for yr in (2000, 2008, 2016, 2023):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">GHSL built-up surface by boundary buffer ring — the inner ring grows FASTEST: pressure is converging on the fence</text>')
    f.baseline()
    return f.done()


def distance_decay():
    f = F(470, 220, t=18, b=30)
    x = f.sx(0, 25)
    y = f.sy(0, 260)
    f.grid_y(x, y, (0, 100, 200), unit="people km⁻²")
    for yr, col, wid in ((2000, "color-mix(in srgb,var(--fog) 55%,transparent)", 1.2), (2020, "#E05B41", 2)):
        k = 0.32 if yr == 2000 else 0.21
        a = 95 if yr == 2000 else 245
        pts = [(x(d / 4), y(a * math.exp(-k * d / 4))) for d in range(0, 101)]
        f.out.append(polyline(pts, style=f'stroke="{col}" stroke-width="{wid}"'))
        f.out.append(f'<text x="{x(1.2):.1f}" y="{y(a)-5:.1f}" fill="{col}" font-weight="700">{yr}</text>')
    for gv in (0, 5, 10, 15, 20, 25):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-140}" y="{f.h-6}">km outside boundary</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">population density vs distance to boundary (WorldPop) — the cliff both RISES and STEEPENS: growth is boundary-seeking</text>')
    f.baseline()
    return f.done()


def edge_pressure_map():
    P, ring = _nca_proj()
    out = ['<svg class="ch" viewBox="0 0 470 340" xmlns="http://www.w3.org/2000/svg">']
    n = len(ring)
    for i in range(n - 1):
        lon0, lat0 = ring[i]
        lon1, lat1 = ring[i + 1]
        east = max(0, (lon0 - 35.45) / 0.5)
        south = max(0, -(lat0 + 3.1) / 0.5)
        v = min(1, 0.15 + east * 0.75 + south * 0.25 + RNG.uniform(0, 0.1))
        col = FIRE_RAMP[min(6, int(v * 6.99))]
        x0, y0 = P(lon0, lat0)
        x1, y1 = P(lon1, lat1)
        out.append(f'<line x1="{x0:.1f}" y1="{y0:.1f}" x2="{x1:.1f}" y2="{y1:.1f}" stroke="{col}" '
                   f'stroke-width="7" stroke-linecap="round" opacity="0.9"/>')
    px, py = P(35.72, -3.32)
    out.append(f'<text x="{px:.0f}" y="{py+16:.0f}" class="anno" text-anchor="middle">Karatu edge — hottest segment</text>')
    px, py = P(35.05, -2.95)
    out.append(f'<text x="{px:.0f}" y="{py:.0f}" class="annoS" text-anchor="middle">Serengeti side: quiet</text>')
    lx = 14
    for i, c in enumerate(FIRE_RAMP):
        out.append(f'<rect x="{lx+i*15}" y="316" width="15" height="7" fill="{c}"/>')
    out.append('<text class="annoS" x="14" y="310">edge pressure index (built-up + cropland + lights, 5 km outside)</text>')
    out.append('</svg>')
    return ''.join(out)


def lodge_map():
    P, ring = _nca_proj()
    out = ['<svg class="ch" viewBox="0 0 470 340" xmlns="http://www.w3.org/2000/svg">']
    out.append('<polygon points="' + ' '.join(f'{P(lon,lat)[0]:.1f},{P(lon,lat)[1]:.1f}' for lon, lat in ring) +
               '" fill="color-mix(in srgb,var(--acc) 6%,transparent)" stroke="var(--acc)" stroke-width="1.3"/>')
    lodges = [("Crater rim cluster", 35.55, -3.20, 420, 1998), ("Sopa rim", 35.66, -3.15, 190, 2004),
              ("Ndutu area camps", 35.02, -3.02, 260, 2010), ("Empakaai eco", 35.82, -2.94, 40, 2018),
              ("Karatu gate lodges", 35.70, -3.31, 350, 2015)]
    for name, lon, lat, beds, yr in lodges:
        x, y = P(lon, lat)
        r = 4 + math.sqrt(beds) / 3.2
        age = (yr - 1995) / 28
        col = f'color-mix(in srgb, #E05B41 {int(age*100)}%, #C9B458)'
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.1f}" fill="{col}" opacity="0.75" '
                   f'stroke="{col}"><title>{name} · {beds} beds · est {yr}</title></circle>')
        out.append(f'<text x="{x:.1f}" y="{y-r-4:.1f}" text-anchor="middle" '
                   f'style="paint-order:stroke;stroke:var(--cv);stroke-width:2.5">{name.split()[0]}</text>')
    out.append('<text class="annoS" x="14" y="316">size = beds · color = newer→ember (OSM history + Google Open Buildings epochs)</text>')
    out.append('<text class="annoS" x="14" y="330">the crater rim carries 47% of all beds on 2% of the area</text>')
    out.append('</svg>')
    return ''.join(out)


def beds_lorenz():
    f = F(470, 220, t=18, b=30)
    x = f.sx(0, 100)
    y = f.sy(0, 100)
    f.grid_y(x, y, (0, 50, 100), unit="% of beds")
    f.out.append(polyline([(x(0), y(0)), (x(100), y(100))],
                          style='stroke="var(--fog)" stroke-width="0.9" stroke-dasharray="4 3"'))
    pts = [(0, 0), (20, 2), (40, 8), (60, 19), (80, 44), (90, 63), (100, 100)]
    f.out.append(polyline([(x(a), y(b)) for a, b in pts], style='stroke="#E05B41" stroke-width="2"'))
    f.out.append(f'<text class="anno" x="{x(62):.1f}" y="{y(30):.1f}">Gini ≈ 0.58</text>')
    for gv in (0, 50, 100):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-150}" y="{f.h-6}">% of sites (small → large)</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">Lorenz curve of bed capacity — tourism load is concentrated in a handful of large operators/corridors</text>')
    return f.done()


def baci_panel():
    f = F(470, 230, t=20, b=30)
    x = f.sx(2014, 2024)
    y = f.sy(0.42, 0.72)
    f.grid_y(x, y, (0.45, 0.55, 0.65), fmt=lambda v: f'{v:.2f}', unit="NDVI")
    xc = x(2019)
    f.out.append(f'<line x1="{xc:.1f}" y1="{f.t}" x2="{xc:.1f}" y2="{f.t+f.ph}" stroke="var(--warn)" '
                 f'stroke-width="1.2" stroke-dasharray="4 3"/>')
    f.out.append(f'<text x="{xc:.1f}" y="{f.t-6}" text-anchor="middle" class="anno">lodge built (2019)</text>')
    rng2 = random.Random(4)
    for name, col, drop in (("control sites ×5", "color-mix(in srgb,var(--fog) 65%,transparent)", 0),
                            ("impact ring 0–500 m", "#E05B41", 0.10)):
        pts = []
        for yr in range(2014, 2025):
            v = 0.62 + rng2.gauss(0, 0.012) - (drop * min(1, max(0, (yr - 2019) / 2.5)))
            pts.append((x(yr), y(v)))
        f.out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.8"'))
        f.out.append(f'<text x="{pts[-1][0]-4:.1f}" y="{pts[-1][1]+11:.1f}" text-anchor="end" '
                     f'fill="{col}" font-weight="700">{name}</text>')
    for yr in (2014, 2019, 2024):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+10}">BACI design (Before-After-Control-Impact) — the divergence AFTER the intervention, not the level, is the effect</text>')
    f.baseline()
    return f.done()


def wildlife_lodge_decay():
    f = F(470, 220, t=18, b=30)
    x = f.sx(0, 5)
    y = f.sy(0, 1.6)
    f.grid_y(x, y, (0, 0.5, 1.0, 1.5), fmt=lambda v: f'{v:.1f}', unit="relative density")
    f.out.append(f'<line class="ref" x1="{f.l}" y1="{y(1):.1f}" x2="{f.w-f.r}" y2="{y(1):.1f}"/>')
    curves = [("eland (shy)", "#87988D", lambda d: 1 - math.exp(-(d / 1.4) ** 2) * 0.85),
              ("wildebeest", "#C9B458", lambda d: 1 - math.exp(-(d / 0.7) ** 2) * 0.45),
              ("spotted hyena (night)", "#E05B41", lambda d: 1 + math.exp(-(d / 0.9) ** 2) * 0.55)]
    for name, col, fn in curves:
        pts = [(x(d / 20), y(fn(d / 20))) for d in range(0, 101)]
        f.out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.8"'))
        f.out.append(f'<text x="{x(4.95):.1f}" y="{pts[-1][1]-5:.1f}" text-anchor="end" fill="{col}" '
                     f'font-weight="700">{name}</text>')
    for gv in (0, 1, 2, 3, 4, 5):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-130}" y="{f.h-6}">km from lodge</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">distance-decay per species (tracking + camera traps) — avoidance dips below 1, waste-attraction rises above it</text>')
    return f.done()


# ═══════════════ VEGETATION ═══════════════
def ndvi_envelope():
    rng2 = random.Random(9)
    W = 960
    f = F(W, 210, l=48, t=18, b=26)
    x = f.sx(0, 364)
    y = f.sy(0.25, 0.80)
    f.grid_y(x, y, (0.3, 0.5, 0.7), fmt=lambda v: f'{v:.1f}', unit="NDVI")

    def clim(d):
        return 0.52 + 0.16 * math.sin((d - 105) / 365 * 2 * math.pi) + 0.05 * math.sin((d - 320) / 365 * 4 * math.pi)
    up = [(x(d), y(clim(d) + 0.07)) for d in range(365)]
    dn = [(x(d), y(clim(d) - 0.07)) for d in reversed(range(365))]
    f.out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in up + dn) +
                 ' Z" fill="color-mix(in srgb,#8FA35F 22%,transparent)"/>')
    f.out.append(polyline([(x(d), y(clim(d))) for d in range(365)],
                          style='stroke="color-mix(in srgb,var(--fog) 60%,transparent)" stroke-width="1"'))
    cur = [(x(d), y(clim(d) - 0.02 - 0.06 * max(0, math.sin((d - 60) / 120 * math.pi)) + rng2.gauss(0, 0.008)))
           for d in range(0, 240, 4)]
    f.out.append(polyline(cur, style='stroke="#E05B41" stroke-width="2"'))
    f.out.append(f'<text class="anno" x="{cur[-1][0]+6:.1f}" y="{cur[-1][1]:.1f}" fill="#E05B41">2024 — running below the envelope since March</text>')
    for m, lab in ((15, 'J'), (46, 'F'), (74, 'M'), (105, 'A'), (135, 'M'), (166, 'J'),
                   (196, 'J'), (227, 'A'), (258, 'S'), (288, 'O'), (319, 'N'), (349, 'D')):
        f.xt(x, m, lab)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">this year against the 2003–2023 envelope (min–max band + median) — the GIMMS/MODIS anomaly plot every vegetation lab uses</text>')
    return f.done()


def hovmoller():
    W = 960
    L, T = 64, 26
    months = 60
    bands = 14
    cw = (W - L - 20) / months
    chh = 15
    H = T + bands * chh + 56
    rng2 = random.Random(12)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for b in range(bands):
        lat = -2.75 - b * 0.06
        for m in range(months):
            seas = 0.55 + 0.18 * math.sin((m % 12 - 3.2) / 12 * 2 * math.pi)
            south_dry = -0.06 * (b / bands)
            drought = -0.16 if (34 <= m <= 39) else 0
            v = max(0.2, min(0.85, seas + south_dry + drought + rng2.gauss(0, 0.02)))
            t = (v - 0.2) / 0.65
            col = f'color-mix(in srgb, #2E6B37 {int(t*100)}%, #C9A45B)'
            out.append(f'<rect x="{L+m*cw:.1f}" y="{T+b*chh:.1f}" width="{cw+0.4:.1f}" height="{chh}" fill="{col}"/>')
        if b % 3 == 0:
            out.append(f'<text x="{L-6}" y="{T+b*chh+chh/2+2.5:.1f}" text-anchor="end">{lat:.1f}°</text>')
    for yi in range(5):
        out.append(f'<text x="{L+yi*12*cw:.1f}" y="{H-38}">{2019+yi}</text>')
        out.append(f'<line x1="{L+yi*12*cw:.1f}" y1="{T}" x2="{L+yi*12*cw:.1f}" y2="{T+bands*chh}" '
                   f'stroke="rgba(0,0,0,0.25)" stroke-width="0.7"/>')
    out.append(f'<rect x="{L+34*cw:.1f}" y="{T-4}" width="{6*cw:.1f}" height="4" fill="#E05B41"/>')
    out.append(f'<text class="annoS" x="{L+34*cw:.1f}" y="{T-8}">2021–22 drought — a vertical brown scar</text>')
    out.append(f'<text class="annoS" x="{L}" y="{H-14}">Hovmöller diagram: latitude × time, color = NDVI — space AND time in one plane; seasonal green waves read as diagonal stripes</text>')
    out.append('</svg>')
    return ''.join(out)


def greenup_shift():
    rng2 = random.Random(3)
    f = F(470, 210, t=18, b=26)
    x = f.sx(2002, 2024)
    y = f.sy(288, 335)
    f.grid_y(x, y, (290, 310, 330), fmt=lambda v: f'day {int(v)}', unit="green-up")
    pts = []
    for yr in range(2003, 2024):
        v = 318 - (yr - 2003) * 0.55 + rng2.gauss(0, 4.5)
        pts.append((yr, v))
        f.out.append(f'<circle cx="{x(yr):.1f}" cy="{y(v):.1f}" r="2.6" fill="#8FA35F" opacity="0.85"/>')
    n = len(pts)
    mx = sum(p[0] for p in pts) / n
    my = sum(p[1] for p in pts) / n
    b1 = sum((a - mx) * (b - my) for a, b in pts) / sum((a - mx) ** 2 for a, _ in pts)
    b0 = my - b1 * mx
    f.out.append(polyline([(x(2003), y(b0 + b1 * 2003)), (x(2023), y(b0 + b1 * 2023))],
                          style='stroke="var(--tx)" stroke-width="1.4" stroke-dasharray="5 3"'))
    for yr in (2003, 2013, 2023):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">short-rains green-up date (MODIS phenology) — advancing ≈{abs(b1)*10:.0f} days/decade; phenology shift is climate change made visible</text>')
    f.baseline()
    return f.done()


def ndvi_anomaly_grid():
    rng2 = random.Random(19)
    W = 470
    L, T = 46, 24
    cw = (W - L - 16) / 12
    chh = 15
    yrs = list(range(2012, 2024))
    H = T + len(yrs) * (chh + 2) + 40
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for j, m in enumerate("JFMAMJJASOND"):
        out.append(f'<text x="{L+j*cw+cw/2:.1f}" y="{T-6}" text-anchor="middle">{m}</text>')
    for i, yr in enumerate(yrs):
        out.append(f'<text x="{L-5}" y="{T+i*(chh+2)+chh/2+2.5:.1f}" text-anchor="end">{yr}</text>')
        for j in range(12):
            v = rng2.gauss(0, 0.6)
            if yr in (2016, 2021, 2022) and 4 <= j <= 10:
                v -= 1.1
            if yr in (2019, 2023) and j >= 9:
                v += 1.2
            t = max(-1, min(1, v / 1.6))
            col = (f'color-mix(in srgb, #2E6B37 {int(t*100)}%, #F2EFE4)' if t >= 0
                   else f'color-mix(in srgb, #B4632C {int(-t*100)}%, #F2EFE4)')
            out.append(f'<rect x="{L+j*cw:.1f}" y="{T+i*(chh+2):.1f}" width="{cw-1.4:.1f}" height="{chh}" '
                       f'rx="2" fill="{col}"/>')
    out.append(f'<text class="annoS" x="{L}" y="{H-8}">NDVI anomaly, month × year (green = above normal, brown = below) — droughts and the 2019/2023 wet short-rains pop instantly</text>')
    out.append('</svg>')
    return ''.join(out)


# ═══════════════ BIODIVERSITY & MOVEMENT ═══════════════
def migration_map():
    out = ['<svg viewBox="0 0 768 768" style="width:100%;height:auto;display:block;border-radius:9px" '
           'xmlns="http://www.w3.org/2000/svg">']
    out.append(FI._mosaic())
    out.append('<rect x="0" y="0" width="768" height="768" fill="#060a08" opacity="0.22"/>')
    loop = [(430, 640, "Dec–Mar", "calving · Ndutu plains"), (250, 560, "Apr–May", "long rains march"),
            (170, 380, "May–Jun", "western corridor"), (240, 170, "Jul–Aug", "Grumeti–Mara crossings"),
            (430, 90, "Aug–Oct", "northern dry-season range"), (560, 330, "Nov", "short rains — the turn south")]
    d = 'M' + ' C'.join([f'{loop[0][0]} {loop[0][1]}'] + [
        f'{(loop[i][0]+loop[(i+1)%6][0])//2+40} {(loop[i][1]+loop[(i+1)%6][1])//2} '
        f'{(loop[i][0]+loop[(i+1)%6][0])//2-40} {(loop[i][1]+loop[(i+1)%6][1])//2} '
        f'{loop[(i+1)%6][0]} {loop[(i+1)%6][1]}' for i in range(6)]) + ' Z'
    out.append(f'<path d="{d}" fill="none" stroke="#F5D76E" stroke-width="7" opacity="0.85" '
               f'stroke-linecap="round" stroke-dasharray="1 14"/>')
    out.append(f'<path d="{d}" fill="none" stroke="#F5D76E" stroke-width="1.6" opacity="0.6"/>')
    for x, y, when, what in loop:
        out.append(f'<circle cx="{x}" cy="{y}" r="6" fill="#F5D76E" stroke="#0A0F0C" stroke-width="2"/>')
        out.append(f'<text x="{x}" y="{y-14}" text-anchor="middle" font-size="17" fill="#F5F7F3" '
                   f'stroke="#0A0F0C" stroke-width="3.5" paint-order="stroke" '
                   f'font-family="JetBrains Mono,monospace" font-weight="700">{when}</text>')
        out.append(f'<text x="{x}" y="{y+26}" text-anchor="middle" font-size="13" fill="#E8E4D8" '
                   f'stroke="#0A0F0C" stroke-width="3" paint-order="stroke" '
                   f'font-family="JetBrains Mono,monospace">{what}</text>')
    out.append('<g><rect x="16" y="700" width="420" height="52" rx="8" fill="rgba(8,13,10,.78)"/>'
               '<text x="30" y="722" font-size="14" fill="#F5F7F3" font-family="JetBrains Mono,monospace">'
               '~1.3 M wildebeest · the annual clockwise loop (Movebank/collar data)</text>'
               '<text x="30" y="740" font-size="12" fill="#9DB8A5" font-family="JetBrains Mono,monospace">'
               'dot spacing = one month · in-app: animated month scrubber</text></g>')
    out.append('</svg>')
    return ''.join(out)


def occurrence_hex():
    rng2 = random.Random(23)
    f = F(470, 300, t=16, b=40)
    pts = []
    for cx, cy, n, spread in ((150, 210, 300, 55), (300, 120, 200, 60), (240, 190, 150, 90), (350, 230, 90, 40)):
        for _ in range(n):
            pts.append((rng2.gauss(cx, spread), rng2.gauss(cy, spread * 0.7)))
    s = 14.0
    counts = {}
    for px, py in pts:
        q = round(px / (s * 1.5))
        r = round((py - (q % 2) * s * 0.87) / (s * 1.73))
        counts[(q, r)] = counts.get((q, r), 0) + 1
    mx = max(counts.values())
    for (q, r), c in counts.items():
        cx = q * s * 1.5
        cy = r * s * 1.73 + (q % 2) * s * 0.87
        if not (f.l < cx < f.w - f.r and f.t < cy < f.t + f.ph):
            continue
        heat = (c / mx) ** 0.6
        col = f'color-mix(in srgb, #C9B458 {int(heat*100)}%, var(--raised))'
        hexpts = ' '.join(f'{cx+s*0.92*math.cos(math.pi/6+i*math.pi/3):.1f},'
                          f'{cy+s*0.92*math.sin(math.pi/6+i*math.pi/3):.1f}' for i in range(6))
        f.out.append(f'<polygon points="{hexpts}" fill="{col}" opacity="0.9"><title>{c} records</title></polygon>')
    f.out.append(f'<text class="annoS" x="{f.l}" y="{f.h-22}">GBIF occurrence records, hex-binned — collection effort AND hotspots; blank hexes are unsurveyed, not empty</text>')
    f.out.append(f'<text class="annoS" x="{f.l}" y="{f.h-8}">the survey-bias caveat belongs ON the figure, always</text>')
    return f.done()


def accumulation_curve():
    f = F(470, 210, t=18, b=30)
    x = f.sx(0, 500)
    y = f.sy(0, 340)
    f.grid_y(x, y, (0, 100, 200, 300), unit="species")
    for col, mult, lab in (("#C9B458", 1.0, "birds"), ("#8FA35F", 0.42, "mammals")):
        up, mid, dn = [], [], []
        for n in range(0, 501, 10):
            v = 330 * mult * (1 - math.exp(-n / 150))
            se = 14 * mult * math.exp(-n / 400)
            mid.append((x(n), y(v)))
            up.append((x(n), y(min(340, v + 1.96 * se))))
            dn.append((x(n), y(max(0, v - 1.96 * se))))
        f.out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in up + dn[::-1]) +
                     f' Z" fill="{col}" opacity="0.15"/>')
        f.out.append(polyline(mid, style=f'stroke="{col}" stroke-width="1.8"'))
        f.out.append(f'<text x="{mid[-1][0]-4:.1f}" y="{mid[-1][1]-6:.1f}" text-anchor="end" '
                     f'fill="{col}" font-weight="700">{lab}</text>')
    for gv in (0, 250, 500):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-90}" y="{f.h-6}">survey effort</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">rarefaction / species-accumulation ± CI — the flattening curve says "the inventory is nearly complete"; a rising one says keep surveying</text>')
    f.baseline()
    return f.done()


def activity_clock():
    W, H, cx, cy, Rm = 470, 300, 160, 152, 106
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for ring in (0.5, 1.0):
        out.append(f'<circle cx="{cx}" cy="{cy}" r="{Rm*ring:.1f}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 26%,transparent)" stroke-width="0.8"/>')
    for h in (0, 6, 12, 18):
        a = h / 24 * 2 * math.pi - math.pi / 2
        out.append(f'<text x="{cx+(Rm+14)*math.cos(a):.1f}" y="{cy+(Rm+14)*math.sin(a)+3:.1f}" '
                   f'text-anchor="middle" class="ttl">{h:02d}h</text>')
    species = [("lion", "#C9B458", lambda h: 0.25 + 0.75 * max(math.exp(-((h - 4) % 24) ** 2 / 18),
                                                               math.exp(-((h - 20) % 24) ** 2 / 18))),
               ("zebra", "#87988D", lambda h: 0.3 + 0.7 * math.exp(-((h - 11) % 24 - 0) ** 2 / 40)),
               ("leopard", "#A46A8C", lambda h: 0.2 + 0.8 * math.exp(-((h - 23) % 24) ** 2 / 26))]
    for name, col, fn in species:
        pts = []
        for hh in range(0, 96):
            h = hh / 4
            a = h / 24 * 2 * math.pi - math.pi / 2
            r = 10 + fn(h) * (Rm - 12)
            pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
        pts.append(pts[0])
        out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.8" opacity="0.9"'))
    ly = 70
    for name, col, _ in species:
        out.append(f'<rect x="330" y="{ly-8}" width="10" height="10" rx="2" fill="{col}"/>')
        out.append(f'<text x="346" y="{ly}">{name}</text>')
        ly += 20
    out.append(f'<text class="annoS" x="330" y="{ly+8}">camera-trap activity clock:</text>')
    out.append(f'<text class="annoS" x="330" y="{ly+20}">crepuscular lion, midday zebra,</text>')
    out.append(f'<text class="annoS" x="330" y="{ly+32}">strictly nocturnal leopard</text>')
    out.append('</svg>')
    return ''.join(out)


def range_overlap():
    sp = ["wildebeest", "zebra", "eland", "buffalo", "elephant"]
    ov = [[100, 82, 44, 51, 38], [82, 100, 47, 55, 41], [44, 47, 100, 39, 39],
          [51, 55, 39, 100, 58], [38, 41, 39, 58, 100]]
    W = 470
    L, T, cs = 92, 46, 52
    H = T + cs * 5 + 36
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for i, s in enumerate(sp):
        out.append(f'<text x="{L+i*cs+cs/2-1:.1f}" y="{T-8}" text-anchor="middle" class="ttl">{s[:5]}</text>')
        out.append(f'<text x="{L-6}" y="{T+i*cs+cs/2+3:.1f}" text-anchor="end" class="ttl">{s}</text>')
        for j in range(5):
            v = ov[i][j]
            op = 0.08 + (v / 100) * 0.85
            out.append(f'<rect x="{L+j*cs:.1f}" y="{T+i*cs:.1f}" width="{cs-3}" height="{cs-3}" rx="4" '
                       f'fill="#C9B458" opacity="{op:.2f}"/>')
            out.append(f'<text x="{L+j*cs+(cs-3)/2:.1f}" y="{T+i*cs+cs/2+2:.1f}" text-anchor="middle" '
                       f'fill="{"#141D18" if v>60 else "var(--tx)"}" font-weight="600">{v}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{H-8}">range-overlap % (IUCN ranges ∩ tracking) — the grazer guild moves together; elephant walks alone</text>')
    out.append('</svg>')
    return ''.join(out)


# ═══════════════ DROUGHT ═══════════════
def spei_bars():
    rng2 = random.Random(29)
    W = 960
    f = F(W, 200, l=48, t=18, b=26)
    months = 288
    x = f.sx(0, months)
    y = f.sy(-2.6, 2.6)
    for gv in (-2, -1, 0, 1, 2):
        cls = 'ax' if gv == 0 else 'grid'
        f.out.append(f'<line class="{cls}" x1="{f.l}" y1="{y(gv):.1f}" x2="{f.w-f.r}" y2="{y(gv):.1f}"/>')
        f.out.append(f'<text x="{f.l-5}" y="{y(gv)+2.5:.1f}" text-anchor="end">{gv:+d}</text>')
    v = 0.0
    for m in range(months):
        v = v * 0.82 + rng2.gauss(0, 0.5)
        if 196 <= m <= 214 or 250 <= m <= 271:
            v -= 0.35
        vv = max(-2.6, min(2.6, v))
        col = "#4A90C2" if vv > 0 else ("#B4632C" if vv > -1.5 else "#8C3A1D")
        bw = f.pw / months
        y0, y1 = sorted((y(0), y(vv)))
        f.out.append(f'<rect x="{f.l+m*bw:.1f}" y="{y0:.1f}" width="{bw+0.3:.1f}" height="{y1-y0:.1f}" fill="{col}"/>')
    for yi in range(0, 25, 4):
        f.xt(x, yi * 12, str(2000 + yi))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">SPEI-6 drought index, monthly since 2000 — blue wet / rust dry / deep-rust severe; the 2016–17 and 2021–22 events dominate the record</text>')
    return f.done()


def drought_area_stack():
    rng2 = random.Random(33)
    f = F(470, 210, t=18, b=26)
    x = f.sx(2018, 2024)
    y = f.sy(0, 100)
    f.grid_y(x, y, (0, 50, 100), unit="% of park")
    cats = [("D0 dry", "#E8D8A0"), ("D1 moderate", "#DBA33F"), ("D2 severe", "#B4632C"), ("D3 extreme", "#8C3A1D")]
    n = 72
    series = []
    for ci in range(4):
        row = []
        for m in range(n):
            yr = 2018 + m / 12
            ev = max(0, 1 - abs(yr - 2021.9) / 0.9) + 0.4 * max(0, 1 - abs(yr - 2019.2) / 0.5)
            base = [38, 26, 16, 9][ci]
            row.append(max(0, base * ev * (0.7 + 0.5 * rng2.random())))
        series.append(row)
    base = [0.0] * n
    for (name, col), row in zip(cats, series):
        top = [b + v for b, v in zip(base, row)]
        up = [(x(2018 + m / 12), y(min(100, t))) for m, t in enumerate(top)]
        dn = [(x(2018 + m / 12), y(min(100, b))) for m, b in reversed(list(enumerate(base)))]
        f.out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in up + dn) +
                     f' Z" fill="{col}" opacity="0.9"/>')
        base = top
    for yr in (2018, 2020, 2022, 2024):
        f.xt(x, yr, str(yr))
    lx = f.l
    for name, col in cats:
        f.out.append(f'<rect x="{lx}" y="{f.t-11}" width="8" height="8" rx="2" fill="{col}"/>')
        f.out.append(f'<text x="{lx+12}" y="{f.t-4}">{name}</text>')
        lx += 12 + 5.2 * len(name) + 12
    f.out.append(f'<text class="annoS" x="{f.l}" y="{f.h-4}">% of park area in each drought class (US Drought Monitor idiom) — severity AND extent in one stacked field</text>')
    return f.done()


def soil_moisture_pct():
    rng2 = random.Random(41)
    f = F(470, 200, t=18, b=26)
    x = f.sx(0, 364)
    y = f.sy(0, 100)
    f.grid_y(x, y, (10, 50, 90), unit="percentile")
    f.out.append(f'<rect x="{f.l}" y="{y(30):.1f}" width="{f.pw}" height="{y(10)-y(30):.1f}" '
                 f'fill="#B4632C" opacity="0.12"/>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r-4}" y="{y(18):.1f}" text-anchor="end">drought watch band</text>')
    v = 55.0
    pts = []
    for d in range(0, 365, 3):
        seas = 18 * math.sin((d - 120) / 365 * 2 * math.pi)
        v = v * 0.9 + (50 + seas) * 0.1 + rng2.gauss(0, 3)
        pts.append((x(d), y(max(2, min(98, v - (26 if 190 < d < 300 else 0))))))
    f.out.append(polyline(pts, style='stroke="#5FA3A0" stroke-width="1.8"'))
    for m, lab in ((15, 'J'), (105, 'A'), (196, 'J'), (288, 'O'), (349, 'D')):
        f.xt(x, m, lab)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">ESA CCI soil moisture as a PERCENTILE of the day-of-year record — "how unusual", not "how much"; the late-year dive into the watch band is the alert</text>')
    f.baseline()
    return f.done()


# ═══════════════ AIR & SMOKE ═══════════════
def aod_envelope():
    rng2 = random.Random(51)
    f = F(470, 200, t=18, b=26)
    x = f.sx(0, 11)
    y = f.sy(0, 0.8)
    f.grid_y(x, y, (0, 0.4, 0.8), fmt=lambda v: f'{v:.1f}', unit="AOD 550 nm")
    clim = [0.12, 0.13, 0.15, 0.14, 0.16, 0.28, 0.48, 0.58, 0.46, 0.24, 0.15, 0.12]
    up = [(x(m), y(min(0.8, c * 1.5))) for m, c in enumerate(clim)]
    dn = [(x(m), y(c * 0.6)) for m, c in reversed(list(enumerate(clim)))]
    f.out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in up + dn) +
                 ' Z" fill="color-mix(in srgb,#B0A79A 30%,transparent)"/>')
    f.out.append(polyline([(x(m), y(c)) for m, c in enumerate(clim)],
                          style='stroke="var(--fog)" stroke-width="1.2"'))
    cur = [(x(m), y(min(0.8, c * (1.35 if 5 <= m <= 8 else 1.0) + rng2.gauss(0, 0.02))))
           for m, c in enumerate(clim[:10])]
    f.out.append(polyline(cur, style='stroke="#E05B41" stroke-width="2"'))
    for m, lab in enumerate("JFMAMJJASOND"):
        f.xt(x, m, lab)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">aerosol optical depth (Sentinel-5P/MODIS): burn-season hump vs the 20-yr envelope — this year (ember) is running dirty</text>')
    f.baseline()
    return f.done()


def smoke_vs_burn():
    rng2 = random.Random(55)
    f = F(470, 220, t=18, b=30)
    x = f.sx(1500, 4500)
    y = f.sy(10, 60)
    f.grid_y(x, y, (20, 40, 60), unit="smoke-days")
    pts = []
    for yr in FI.BURN_YEARS:
        tot = sum(FI.BURNED[yr])
        sd = 8 + tot / 110 + rng2.gauss(0, 4)
        pts.append((tot, sd, yr))
        f.out.append(f'<circle cx="{x(tot):.1f}" cy="{y(sd):.1f}" r="3" fill="#B0A79A" opacity="0.9">'
                     f'<title>{yr}</title></circle>')
        if yr in (2016, 2019, 2023):
            f.out.append(f'<text x="{x(tot)+5:.1f}" y="{y(sd)-4:.1f}">{yr}</text>')
    xs = [p[0] for p in pts]
    ys = [p[1] for p in pts]
    n = len(xs)
    mx, my = sum(xs) / n, sum(ys) / n
    b1 = sum((a - mx) * (b - my) for a, b, _ in pts) / sum((a - mx) ** 2 for a in xs)
    b0 = my - b1 * mx
    f.out.append(polyline([(x(1700), y(b0 + b1 * 1700)), (x(4300), y(b0 + b1 * 4300))],
                          style='stroke="#4A90C2" stroke-width="1.4" stroke-dasharray="5 3"'))
    for gv in (2000, 3000, 4000):
        f.xt(x, gv, f'{gv/1000:.0f}k')
    f.out.append(f'<text class="annoS" x="{f.w-120}" y="{f.h-6}">km² burned</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">smoke-days downwind vs area burned — the airshed cost of the program, quantified for the EIA</text>')
    return f.done()


def plume_rose():
    W, H, cx, cy, Rm = 470, 280, 160, 142, 100
    counts = [2, 1, 1, 2, 6, 14, 22, 18, 9, 4, 2, 2, 3, 2, 1, 1]
    vmax = max(counts)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for ring in (0.5, 1.0):
        out.append(f'<circle cx="{cx}" cy="{cy}" r="{Rm*ring:.1f}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 26%,transparent)" stroke-width="0.8"/>')
    for i, name in enumerate("N NE E SE S SW W NW".split()):
        a = i * math.pi / 4 - math.pi / 2
        out.append(f'<text x="{cx+(Rm+13)*math.cos(a):.1f}" y="{cy+(Rm+13)*math.sin(a)+3:.1f}" '
                   f'text-anchor="middle" class="ttl">{name}</text>')
    for s, v in enumerate(counts):
        a0 = s * 2 * math.pi / 16 - math.pi / 2 - math.pi / 16 + 0.02
        a1 = a0 + 2 * math.pi / 16 - 0.04
        r = 6 + v / vmax * (Rm - 6)
        x0, y0 = cx + r * math.cos(a0), cy + r * math.sin(a0)
        x1, y1 = cx + r * math.cos(a1), cy + r * math.sin(a1)
        out.append(f'<path d="M{cx} {cy} L{x0:.1f} {y0:.1f} A{r:.1f} {r:.1f} 0 0 1 {x1:.1f} {y1:.1f} Z" '
                   f'fill="#B0A79A" opacity="0.85"/>')
    out.append('<text class="annoS" x="322" y="70">plume-bearing rose:</text>')
    out.append('<text class="annoS" x="322" y="84">smoke tracks WSW→ENE with</text>')
    out.append('<text class="annoS" x="322" y="98">the trades — Mara side downwind;</text>')
    out.append('<text class="annoS" x="322" y="112">schedule burns on NE-wind days</text>')
    out.append('</svg>')
    return ''.join(out)


# ═══════════════ LIVESTOCK ═══════════════
def herd_trend():
    f = F(470, 220, t=18, b=26)
    x = f.sx(1960, 2024)
    y = f.sy(0, 1100)
    f.grid_y(x, y, (0, 400, 800), fmt=lambda v: f'{v//1}k' if v else '0', unit="head (thousands)")
    rng2 = random.Random(61)
    cattle = []
    shoats = []
    v1, v2 = 120, 80
    for yr in range(1960, 2025):
        v1 = max(60, v1 + rng2.gauss(2.2, 9) - (16 if yr in (1984, 1997, 2009, 2017, 2022) else 0))
        v2 = max(40, v2 + rng2.gauss(9, 11))
        cattle.append((yr, v1))
        shoats.append((yr, v2))
    f.out.append(polyline([(x(a), y(b)) for a, b in cattle], style='stroke="#C9B458" stroke-width="1.8"'))
    f.out.append(polyline([(x(a), y(b)) for a, b in shoats], style='stroke="#A46A8C" stroke-width="1.8"'))
    f.out.append(f'<text x="{x(2023):.1f}" y="{y(cattle[-1][1])-6:.1f}" text-anchor="end" fill="#C9B458" font-weight="700">cattle</text>')
    f.out.append(f'<text x="{x(2023):.1f}" y="{y(shoats[-1][1])-6:.1f}" text-anchor="end" fill="#A46A8C" font-weight="700">shoats</text>')
    for yr, lab in ((1975, "crater grazing ban"), (2009, "drought crash"), (2022, "drought")):
        f.out.append(f'<line x1="{x(yr):.1f}" y1="{f.t}" x2="{x(yr):.1f}" y2="{f.t+f.ph}" '
                     f'stroke="var(--warn)" stroke-width="0.9" stroke-dasharray="3 3" opacity="0.7"/>')
        f.out.append(f'<text x="{x(yr):.1f}" y="{f.t-6}" text-anchor="middle" class="annoS">{lab}</text>')
    for yr in (1960, 1980, 2000, 2020):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+10}">NCAA census series — the shoat curve is the story: small stock quietly replacing cattle, a drought-adaptation signature</text>')
    f.baseline()
    return f.done()


def grazing_map():
    P, ring = _nca_proj()
    out = ['<svg class="ch" viewBox="0 0 470 340" xmlns="http://www.w3.org/2000/svg">']
    rng2 = random.Random(67)
    lons = [p[0] for p in ring]
    lats = [p[1] for p in ring]
    step = 0.045
    lat = min(lats)
    while lat < max(lats):
        lon = min(lons)
        while lon < max(lons):
            if _inside(ring, lon + step / 2, lat + step / 2):
                highl = max(0, 1 - abs(lon - 35.52) / 0.28) * max(0, 1 - abs(lat + 3.05) / 0.35)
                west = max(0, 1 - abs(lon - 35.1) / 0.4) * 0.5
                v = min(1, (highl * 0.9 + west * 0.6) * (0.6 + 0.7 * rng2.random()))
                if v > 0.08:
                    col = f'color-mix(in srgb, #C9B458 {int(v*100)}%, transparent)'
                    x0, y0 = P(lon, lat + step)
                    x1, y1 = P(lon + step, lat)
                    out.append(f'<rect x="{x0:.1f}" y="{y0:.1f}" width="{x1-x0:.1f}" height="{y1-y0:.1f}" fill="{col}"/>')
            lon += step
        lat += step
    out.append('<polygon points="' + ' '.join(f'{P(lon,lat)[0]:.1f},{P(lon,lat)[1]:.1f}' for lon, lat in ring) +
               '" fill="none" stroke="var(--acc)" stroke-width="1.3"/>')
    px, py = P(35.585, -3.172)
    out.append(f'<circle cx="{px:.1f}" cy="{py:.1f}" r="10" fill="none" stroke="#E05B41" stroke-width="1.6" stroke-dasharray="3 2"/>')
    out.append(f'<text x="{px:.1f}" y="{py+24:.1f}" text-anchor="middle" class="annoS">crater: grazing-free since 1975 — the hole in the map is policy, visible</text>')
    out.append('<text class="annoS" x="14" y="316">livestock density (FAO GLW + NCAA census, gridded) — pressure sits on the highland pastures</text>')
    out.append('</svg>')
    return ''.join(out)


def stocking_balance():
    from charts import _arc  # noqa: F401  (reuse if needed)
    f = F(470, 200, t=20, b=26)
    x = f.sx(2000, 2024)
    y = f.sy(0, 1.8)
    f.grid_y(x, y, (0.5, 1.0, 1.5), fmt=lambda v: f'{v:.1f}', unit="stocking ratio")
    f.out.append(f'<rect x="{f.l}" y="{y(1.8):.1f}" width="{f.pw}" height="{y(1.0)-y(1.8):.1f}" '
                 f'fill="#E05B41" opacity="0.08"/>')
    f.out.append(f'<line class="ref" x1="{f.l}" y1="{y(1.0):.1f}" x2="{f.w-f.r}" y2="{y(1.0):.1f}"/>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r-4}" y="{y(1.06):.1f}" text-anchor="end">carrying capacity (NPP-based)</text>')
    rng2 = random.Random(71)
    v = 0.78
    pts = []
    for yr in range(2000, 2025):
        v = max(0.5, v + rng2.gauss(0.02, 0.05) - (0.18 if yr in (2009, 2017, 2022) else 0))
        pts.append((x(yr), y(min(1.8, v))))
    f.out.append(polyline(pts, style='stroke="#C9B458" stroke-width="2"'))
    for yr in (2000, 2012, 2024):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">herd demand ÷ forage supply (MODIS NPP) — crossing 1.0 = overstocking; droughts reset it the hard way</text>')
    f.baseline()
    return f.done()


def livestock_wildlife():
    f = F(470, 220, t=18, b=30)
    x = f.sx(0, 10)
    y = f.sy(0, 10)
    f.grid_y(x, y, (0, 5, 10), unit="wildlife use")
    rng2 = random.Random(73)
    for _ in range(60):
        lv = rng2.uniform(0, 10)
        wl = max(0.2, 8.5 - 0.62 * lv + rng2.gauss(0, 1.1))
        f.out.append(f'<circle cx="{x(lv):.1f}" cy="{y(min(10, wl)):.1f}" r="2.4" fill="#8FA35F" opacity="0.65"/>')
    f.out.append(polyline([(x(0), y(8.5)), (x(10), y(2.3))],
                          style='stroke="var(--tx)" stroke-width="1.4" stroke-dasharray="5 3"'))
    for gv in (0, 5, 10):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-170}" y="{f.h-6}">livestock use (dung index)</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">shared-transect counts: wildlife use declines ~0.6 per unit livestock — competition, quantified (the coexistence question, drawn)</text>')
    return f.done()


# ═══════════════ WILDLIFE & INVASIVE SPECIES (Objective 3) ═══════════════
def _nca_surface(seed, field, ramp, note, step=0.045):
    """A boundary-masked grid choropleth over the NCA ring — shared by the
    habitat-suitability and invasive-risk surfaces (SDM outputs)."""
    P, ring = _nca_proj()
    rng = random.Random(seed)
    lons = [p[0] for p in ring]
    lats = [p[1] for p in ring]
    out = ['<svg class="ch" viewBox="0 0 470 340" xmlns="http://www.w3.org/2000/svg">']
    lat = min(lats)
    while lat < max(lats):
        lon = min(lons)
        while lon < max(lons):
            if _inside(ring, lon + step / 2, lat + step / 2):
                v = min(1, max(0, field(lon, lat) * (0.55 + 0.6 * rng.random())))
                if v > 0.07:
                    col = f'color-mix(in srgb, {ramp} {int(v*92)}%, transparent)'
                    x0, y0 = P(lon, lat + step)
                    x1, y1 = P(lon + step, lat)
                    out.append(f'<rect x="{x0:.1f}" y="{y0:.1f}" width="{x1-x0:.1f}" height="{y1-y0:.1f}" fill="{col}"/>')
            lon += step
        lat += step
    out.append('<polygon points="' + ' '.join(f'{P(lon,lat)[0]:.1f},{P(lon,lat)[1]:.1f}' for lon, lat in ring) +
               '" fill="none" stroke="var(--tx)" stroke-width="1.1" opacity="0.45"/>')
    out.append(f'<text class="annoS" x="14" y="322">{note}</text>')
    out.append('</svg>')
    return ''.join(out)


def suitability_map():
    def field(lon, lat):
        forest = max(0, 1 - abs(lon - 35.35) / 0.30) * max(0, 1 - abs(lat + 2.95) / 0.32)
        water = max(0, 1 - abs(lon - 35.85) / 0.26) * max(0, 1 - abs(lat + 2.90) / 0.30) * 0.7
        crater = max(0, 1 - abs(lon - 35.585) / 0.11) * max(0, 1 - abs(lat + 3.172) / 0.11)
        return forest * 0.95 + water * 0.7 - crater * 0.6
    return _nca_surface(41, field, "var(--acc)",
                        "predicted habitat suitability (MaxEnt: NDVI · distance-to-water · land cover · terrain) — highest along the Northern Highland forest edge")


def invasive_risk_map():
    def field(lon, lat):
        access = max(0, 1 - abs(lon - 35.78) / 0.34) * max(0, 1 - abs(lat + 3.28) / 0.40)
        settle = max(0, 1 - abs(lon - 35.92) / 0.30) * max(0, 1 - abs(lat + 3.36) / 0.30) * 0.85
        return access * 0.9 + settle * 0.85
    return _nca_surface(7, field, "#E05B41",
                        "invasion-risk surface (invasive records + roads + disturbance) — risk tracks access routes and the settled south-east")


def covariate_importance():
    feats = [("Distance to water", 0.27), ("NDVI (greenness)", 0.22), ("Land cover", 0.18),
             ("Elevation", 0.13), ("Dry-season rainfall", 0.11), ("Slope", 0.09)]
    w, h, l, r, t, b = 470, 300, 158, 42, 18, 30
    pw = w - l - r
    mx = max(v for _, v in feats)
    n = len(feats)
    gap = (h - t - b) / n
    mono = 'font-family="JetBrains Mono,ui-monospace,monospace"'
    out = [f'<svg class="ch" viewBox="0 0 {w} {h}" xmlns="http://www.w3.org/2000/svg">']
    for i, (name, v) in enumerate(feats):
        y = t + i * gap + gap * 0.18
        bh = gap * 0.54
        bw = v / mx * pw
        out.append(f'<text x="{l-10}" y="{y+bh*0.72:.1f}" text-anchor="end" fill="var(--tx)" {mono} font-size="11">{name}</text>')
        out.append(f'<rect x="{l}" y="{y:.1f}" width="{bw:.1f}" height="{bh:.1f}" rx="2.5" fill="var(--acc)" opacity="0.85"/>')
        out.append(f'<text x="{l+bw+7:.1f}" y="{y+bh*0.72:.1f}" fill="var(--fog)" {mono} font-size="10">{int(v*100)}%</text>')
    out.append(f'<text class="annoS" x="{l}" y="{h-8}">permutation importance — water proximity and greenness carry both models</text>')
    out.append('</svg>')
    return ''.join(out)
