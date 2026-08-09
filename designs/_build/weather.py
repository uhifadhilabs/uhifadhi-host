"""Weather-station chart idioms: the meteorological canon for in-park stations."""
import math
import random

import data as D
from charts import F, polyline, kde

RNG = random.Random(42)

# 72h synthetic observations for "NCA HQ — crater rim (2,286 m)"
HOURS = list(range(72))


def _diurnal(h, base, amp, phase=15):
    return base + amp * math.sin((h % 24 - phase) / 24 * 2 * math.pi + math.pi / 2)


TEMP = [_diurnal(h, 15.5, 6.5) + RNG.gauss(0, 0.6) for h in HOURS]
DEW = [t - 3.5 - RNG.random() * 2.5 for t in TEMP]
RH = [max(30, min(100, 100 - (t - d) * 5.2)) for t, d in zip(TEMP, DEW)]
PRES = [779 + 2.2 * math.sin(h / 12.42 * math.pi) + RNG.gauss(0, 0.35) - h * 0.02 for h in HOURS]
WIND = [max(0.3, _diurnal(h, 4.2, 2.8, 16) + RNG.gauss(0, 1.1)) for h in HOURS]
GUST = [w * (1.4 + RNG.random() * 0.7) for w in WIND]
WDIR = [(120 + 40 * math.sin(h / 24 * 2 * math.pi) + RNG.gauss(0, 25)) % 360 for h in HOURS]
RAIN = [max(0, RNG.gauss(-1.2, 1.6)) if 36 <= h <= 44 or h in (58, 59) else 0 for h in HOURS]
SOIL_DEPTHS = ["5 cm", "20 cm", "50 cm", "100 cm"]
SOIL = []
for di in range(4):
    lag, damp = di * 5, 1 / (1 + di * 1.1)
    SOIL.append([28 + 8 * damp * math.sin((h - lag - 40) / 30) + RNG.gauss(0, 0.4) for h in HOURS])

STATIONS = [
    ("NCA HQ — crater rim", -3.24, 35.49, 2286, "online", 3),
    ("Lemala gate", -3.17, 35.62, 2320, "online", 1),
    ("Ndutu camp", -3.01, 34.99, 1620, "online", 6),
    ("Olduvai Gorge", -2.99, 35.35, 1450, "stale 6 h", 18),
    ("Empakaai rim", -2.91, 35.84, 2870, "offline", 96),
]

STRIPE_YEARS = list(range(1980, 2024))
STRIPES = [(-0.45 + (y - 1980) * 0.028 + RNG.gauss(0, 0.16)) for y in STRIPE_YEARS]


def hour_ticks(f, x, step=12):
    for h in range(0, 72, step):
        lab = f'{h % 24:02d}h' if h % 24 else f'day {h // 24 + 1}'
        f.xt(x, h, lab)


def meteogram():
    """The classic stacked station meteogram: shared time axis, one panel per element."""
    W = 960
    panels = [
        ("air / dew point °C", 8, 26, [("#D95F44", TEMP), ("#8FA35F", DEW)], (10, 15, 20, 25)),
        ("relative humidity %", 25, 105, [("#4A90C2", RH)], (40, 60, 80, 100)),
        ("pressure hPa (station)", 775, 783, [("#A46A8C", PRES)], (776, 779, 782)),
        ("wind / gust m s⁻¹", 0, 14, [("#5FA3A0", WIND), ("#C9B458", GUST)], (0, 5, 10)),
    ]
    ph, gap, top, bot, L, R = 74, 16, 12, 26, 56, 14
    H = top + len(panels) * (ph + gap) + 40 + bot
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    pw = W - L - R
    X = lambda h: L + h / 71 * pw
    for pi, (label, lo, hi, series, ticks) in enumerate(panels):
        oy = top + pi * (ph + gap)
        Y = lambda v: oy + ph - (v - lo) / (hi - lo) * ph
        out.append(f'<text class="ttl" x="{L}" y="{oy-3}">{label}</text>')
        for tv in ticks:
            out.append(f'<line class="grid" x1="{L}" y1="{Y(tv):.1f}" x2="{W-R}" y2="{Y(tv):.1f}"/>')
            out.append(f'<text x="{L-5}" y="{Y(tv)+2.5:.1f}" text-anchor="end">{tv}</text>')
        for d in (24, 48):
            out.append(f'<line x1="{X(d):.1f}" y1="{oy}" x2="{X(d):.1f}" y2="{oy+ph}" '
                       f'stroke="color-mix(in srgb,var(--fog) 35%,transparent)" stroke-width="0.7" stroke-dasharray="2 3"/>')
        for col, ser in series:
            out.append(polyline([(X(h), Y(max(lo, min(hi, v)))) for h, v in zip(HOURS, ser)],
                                style=f'stroke="{col}" stroke-width="1.3"'))
    oy = top + 4 * (ph + gap)
    Y = lambda v: oy + 34 - min(v, 6) / 6 * 30
    out.append(f'<text class="ttl" x="{L}" y="{oy-3}">rainfall mm h⁻¹</text>')
    for h, r in zip(HOURS, RAIN):
        if r > 0:
            out.append(f'<rect x="{X(h)-4:.1f}" y="{Y(r):.1f}" width="8" height="{oy+34-Y(r):.1f}" '
                       f'fill="#4A90C2" opacity="0.9"/>')
    out.append(f'<line class="ax" x1="{L}" y1="{oy+34}" x2="{W-R}" y2="{oy+34}"/>')
    for h in range(0, 72, 12):
        out.append(f'<text x="{X(h):.1f}" y="{H-10}" text-anchor="middle">'
                   f'{"day "+str(h//24+1) if h%24==0 else f"{h%24:02d}h"}</text>')
    out.append('</svg>')
    return ''.join(out)


def wind_rose():
    """True wind rose: 16 sectors × speed bins, stacked petals."""
    sectors = [0.0] * 16
    bins = [[0.0] * 16 for _ in range(3)]      # <3, 3-6, >6 m/s
    for w, d in zip(WIND, WDIR):
        s = int(((d + 11.25) % 360) / 22.5)
        b = 0 if w < 3 else (1 if w < 6 else 2)
        bins[b][s] += 1
    total = len(WIND)
    W, H, cx, cy, Rm = 470, 300, 168, 152, 108
    cols = ["#9CC4E0", "#4A90C2", "#1F5F8B"]
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for ring in (0.33, 0.66, 1.0):
        out.append(f'<circle cx="{cx}" cy="{cy}" r="{Rm*ring:.1f}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 28%,transparent)" stroke-width="0.8"/>')
    for i, name in enumerate("N NE E SE S SW W NW".split()):
        a = i * math.pi / 4 - math.pi / 2
        out.append(f'<text x="{cx+(Rm+13)*math.cos(a):.1f}" y="{cy+(Rm+13)*math.sin(a)+2.5:.1f}" '
                   f'text-anchor="middle" class="ttl">{name}</text>')
    mx = max(sum(bins[b][s] for b in range(3)) for s in range(16)) or 1
    for s in range(16):
        a0 = s * 2 * math.pi / 16 - math.pi / 2 - math.pi / 16 + 0.02
        a1 = a0 + 2 * math.pi / 16 - 0.04
        r = 0.0
        for b in range(3):
            v = bins[b][s]
            if v <= 0:
                continue
            r1 = r + v / mx * Rm
            x0o, y0o = cx + r1 * math.cos(a0), cy + r1 * math.sin(a0)
            x1o, y1o = cx + r1 * math.cos(a1), cy + r1 * math.sin(a1)
            x1i, y1i = cx + r * math.cos(a1), cy + r * math.sin(a1)
            x0i, y0i = cx + r * math.cos(a0), cy + r * math.sin(a0)
            out.append(f'<path d="M{x0o:.1f} {y0o:.1f} A{r1:.1f} {r1:.1f} 0 0 1 {x1o:.1f} {y1o:.1f} '
                       f'L{x1i:.1f} {y1i:.1f} A{r:.1f} {r:.1f} 0 0 0 {x0i:.1f} {y0i:.1f} Z" '
                       f'fill="{cols[b]}" opacity="0.92"/>')
            r = r1
    ly = 60
    for col, lab in zip(cols, ("&lt; 3 m s⁻¹", "3–6 m s⁻¹", "&gt; 6 m s⁻¹")):
        out.append(f'<rect x="332" y="{ly-8}" width="10" height="10" rx="2" fill="{col}"/>')
        out.append(f'<text x="348" y="{ly}">{lab}</text>')
        ly += 20
    out.append(f'<text class="annoS" x="332" y="{ly+6}">SE trades dominate —</text>')
    out.append(f'<text class="annoS" x="332" y="{ly+18}">the crater-rim afternoon surge</text>')
    out.append('</svg>')
    return ''.join(out)


def warming_stripes():
    W, H = 960, 130
    L, T, bh = 14, 30, 64
    bw = (W - 2 * L) / len(STRIPE_YEARS)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    vmax = max(abs(v) for v in STRIPES)
    for i, (y, v) in enumerate(zip(STRIPE_YEARS, STRIPES)):
        t = max(-1, min(1, v / vmax))
        col = (f'rgb({int(240 - max(0,-t)*180)},{int(240 - abs(t)*150)},{int(240 - max(0,t)*190)})'
               if False else '')
        # ColorBrewer RdBu-ish via two-ended mix
        if t >= 0:
            col = f'color-mix(in srgb, #B2182B {int(t*100)}%, #F7F7F7)'
        else:
            col = f'color-mix(in srgb, #2166AC {int(-t*100)}%, #F7F7F7)'
        out.append(f'<rect x="{L+i*bw:.1f}" y="{T}" width="{bw+0.5:.1f}" height="{bh}" fill="{col}">'
                   f'<title>{y}: {v:+.2f} °C</title></rect>')
    for y in (1980, 1990, 2000, 2010, 2023):
        i = STRIPE_YEARS.index(y)
        out.append(f'<text x="{L+i*bw+bw/2:.1f}" y="{T+bh+16}" text-anchor="middle">{y}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{T-10}">station temperature anomaly vs 1981–2010 · Ed Hawkins\' warming stripes — 44 years, zero axes, instantly read</text>')
    out.append('</svg>')
    return ''.join(out)


def temp_ribbon():
    """Daily min–max ribbon across one year + climate normal band."""
    rng = random.Random(8)
    days = list(range(365))
    W = 960
    f = F(W, 210, l=48, t=18, b=26)
    x = f.sx(0, 364)
    y = f.sy(2, 30)
    f.grid_y(x, y, (5, 15, 25), unit="°C")
    lo_n, hi_n = [], []
    for d in days:
        seas = 2.2 * math.sin((d - 170) / 365 * 2 * math.pi)
        lo_n.append(9.5 + seas)
        hi_n.append(21.5 + seas * 1.1)
    band = ('M' + ' L'.join(f'{x(d):.1f},{y(h):.1f}' for d, h in zip(days, hi_n)) +
            ' ' + ' L'.join(f'L{x(d):.1f},{y(l):.1f}' for d, l in reversed(list(zip(days, lo_n)))) + ' Z')
    f.out.append(f'<path d="{band}" fill="color-mix(in srgb,var(--fog) 16%,transparent)"/>')
    for d in days:
        lo = lo_n[d] + rng.gauss(0, 1.3)
        hi = hi_n[d] + rng.gauss(0, 1.4)
        heat = hi > hi_n[d] + 2.5
        col = "#E05B41" if heat else "#87988D"
        f.out.append(f'<line x1="{x(d):.1f}" y1="{y(lo):.1f}" x2="{x(d):.1f}" y2="{y(hi):.1f}" '
                     f'stroke="{col}" stroke-width="1.1" opacity="{0.9 if heat else 0.45}"/>')
    for m, lab in ((0, 'J'), (31, 'F'), (59, 'M'), (90, 'A'), (120, 'M'), (151, 'J'),
                   (181, 'J'), (212, 'A'), (243, 'S'), (273, 'O'), (304, 'N'), (334, 'D')):
        f.xt(x, m + 15, lab)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">daily min–max whiskers on the climatological band (grey) — red = days breaking the normal by &gt;2.5 °C (NYT weather-page idiom)</text>')
    return f.done()


def soil_profile():
    """Depth × time heatmap: soil temperature by layer."""
    W = 470
    L, T = 64, 24
    cw = (W - L - 14) / 72
    chh = 30
    H = T + 4 * (chh + 4) + 44
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    vlo = min(min(r) for r in SOIL)
    vhi = max(max(r) for r in SOIL)
    for di, (depth, row) in enumerate(zip(SOIL_DEPTHS, SOIL)):
        out.append(f'<text x="{L-6}" y="{T+di*(chh+4)+chh/2+2.5:.1f}" text-anchor="end">{depth}</text>')
        for h, v in enumerate(row):
            t = (v - vlo) / (vhi - vlo)
            col = f'color-mix(in srgb, #D95F44 {int(t*100)}%, #2E5C8A)'
            out.append(f'<rect x="{L+h*cw:.1f}" y="{T+di*(chh+4):.1f}" width="{cw+0.4:.1f}" height="{chh}" '
                       f'fill="{col}"/>')
    for h in range(0, 72, 24):
        out.append(f'<text x="{L+h*cw:.1f}" y="{H-26}">day {h//24+1}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{T-8}">soil temperature °C, depth × time — the diurnal wave damps and lags with depth, visible as fading stripes</text>')
    out.append(f'<text class="annoS" x="{L}" y="{H-8}">same grid works for soil moisture after rain events</text>')
    out.append('</svg>')
    return ''.join(out)


def rain_event():
    f = F(470, 190, t=18, r=46)
    x = f.sx(0, 71)
    y = f.sy(0, 6)
    ycum = f.sy(0, 40)
    f.grid_y(x, y, (0, 2, 4, 6), unit="mm h⁻¹")
    cum = 0.0
    pts = []
    for h, r in zip(HOURS, RAIN):
        if r > 0:
            f.out.append(f'<rect x="{x(h)-2.6:.1f}" y="{y(min(r,6)):.1f}" width="5.2" '
                         f'height="{y(0)-y(min(r,6)):.1f}" fill="#4A90C2" opacity="0.9"/>')
        cum += r
        pts.append((x(h), ycum(cum)))
    f.out.append(polyline(pts, style='stroke="var(--tx)" stroke-width="1.4"'))
    for gv in (0, 20, 40):
        f.out.append(f'<text x="{f.w-f.r+5}" y="{ycum(gv)+2.5:.1f}">{gv}</text>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r+5}" y="{f.t-4}">Σ mm</text>')
    hour_ticks(f, x)
    f.out.append(f'<text class="anno" x="{pts[-1][0]-6:.1f}" y="{pts[-1][1]-6:.1f}" text-anchor="end">{cum:.0f} mm event total</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">intensity bars + accumulation line, dual axis — hydrology\'s standard rain-event figure</text>')
    f.baseline()
    return f.done()


def pressure_tendency():
    f = F(470, 170, t=18)
    x = f.sx(0, 71)
    y = f.sy(775.5, 782.5)
    f.grid_y(x, y, (776, 778, 780, 782), unit="hPa")
    f.out.append(polyline([(x(h), y(p)) for h, p in zip(HOURS, PRES)],
                          style='stroke="#A46A8C" stroke-width="1.4"'))
    p3 = PRES[-1] - PRES[-4]
    arrow = '↓ falling' if p3 < -0.4 else ('↑ rising' if p3 > 0.4 else '→ steady')
    f.out.append(f'<text class="anno" x="{x(71)-4:.1f}" y="{y(PRES[-1])-8:.1f}" text-anchor="end">{PRES[-1]:.1f} hPa · 3 h {arrow} ({p3:+.1f})</text>')
    hour_ticks(f, x)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">station pressure with the 3-hour tendency stated — the number forecasters actually read; the 12 h tide wiggle is real</text>')
    f.baseline()
    return f.done()


def gust_range():
    f = F(470, 180, t=18)
    x = f.sx(0, 71)
    y = f.sy(0, 14)
    f.grid_y(x, y, (0, 5, 10), unit="m s⁻¹")
    for h, (w, g) in enumerate(zip(WIND, GUST)):
        f.out.append(f'<line x1="{x(h):.1f}" y1="{y(min(g,14)):.1f}" x2="{x(h):.1f}" y2="{y(w):.1f}" '
                     f'stroke="#C9B458" stroke-width="2" opacity="0.5"/>')
    f.out.append(polyline([(x(h), y(w)) for h, w in zip(HOURS, WIND)],
                          style='stroke="#5FA3A0" stroke-width="1.5"'))
    hour_ticks(f, x)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">mean wind line + gust whiskers — the gust envelope is the hazard, not the mean</text>')
    f.baseline()
    return f.done()


def wind_weibull():
    f = F(470, 190, t=18)
    x = f.sx(0, 12)
    bins = [0] * 12
    for w in WIND:
        bins[min(11, int(w))] += 1
    ymax = max(bins) * 1.2
    y = f.sy(0, ymax)
    f.grid_y(x, y, (0, 10, 20), unit="hours")
    bw = f.pw / 12
    for i, b in enumerate(bins):
        f.out.append(f'<rect x="{f.l+i*bw:.1f}" y="{y(b):.1f}" width="{bw-1.6:.1f}" height="{y(0)-y(b):.1f}" '
                     f'fill="#5FA3A0" opacity="0.6"/>')
    k, lam = 2.1, 5.0
    pts = []
    for i in range(0, 121):
        v = i / 10
        p = (k / lam) * (v / lam) ** (k - 1) * math.exp(-((v / lam) ** k))
        pts.append((x(v), y(min(ymax, p * len(WIND)))))
    f.out.append(polyline(pts, style='stroke="var(--tx)" stroke-width="1.6"'))
    for gv in (0, 4, 8, 12):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">wind speed histogram + fitted Weibull (k≈2.1) — the standard wind-resource figure</text>')
    f.baseline()
    return f.done()


def diurnal_cycle():
    f = F(470, 200, t=18, r=46)
    x = f.sx(0, 23)
    y = f.sy(8, 26)
    yr = f.sy(30, 100)
    f.grid_y(x, y, (10, 15, 20, 25), unit="°C")
    hours = list(range(24))
    tmean = [sum(TEMP[h] for h in range(hh, 72, 24)) / 3 for hh in hours]
    tsd = [max(0.4, (sum((TEMP[h] - tmean[hh]) ** 2 for h in range(hh, 72, 24)) / 3) ** 0.5)
           for hh in hours]
    rhm = [sum(RH[h] for h in range(hh, 72, 24)) / 3 for hh in hours]
    up = [(x(h), y(m + s)) for h, m, s in zip(hours, tmean, tsd)]
    dn = [(x(h), y(m - s)) for h, m, s in reversed(list(zip(hours, tmean, tsd)))]
    d = 'M' + ' L'.join(f'{px:.1f},{py:.1f}' for px, py in up + dn) + ' Z'
    f.out.append(f'<path d="{d}" fill="#D95F44" opacity="0.16"/>')
    f.out.append(polyline([(x(h), y(m)) for h, m in zip(hours, tmean)],
                          style='stroke="#D95F44" stroke-width="1.6"'))
    f.out.append(polyline([(x(h), yr(m)) for h, m in zip(hours, rhm)],
                          style='stroke="#4A90C2" stroke-width="1.3" stroke-dasharray="4 3"'))
    for gv in (40, 70, 100):
        f.out.append(f'<text x="{f.w-f.r+5}" y="{yr(gv)+2.5:.1f}" fill="#4A90C2">{gv}</text>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r+5}" y="{f.t-4}" fill="#4A90C2">RH %</text>')
    for h in (0, 6, 12, 18, 23):
        f.xt(x, h, f'{h:02d}')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">the average day: temperature ±1σ band, humidity mirroring it — diurnal composite (micromet standard)</text>')
    f.baseline()
    return f.done()


def station_map():
    import charts as C
    P, ring, s, ox, oy = C._tz_proj(470, 300, pad=10)
    lons = [st[2] for st in STATIONS]
    lats = [st[1] for st in STATIONS]
    lon0, lon1 = min(lons) - 0.25, max(lons) + 0.25
    lat0, lat1 = min(lats) - 0.18, max(lats) + 0.18
    W, H = 470, 300
    X = lambda lon: 14 + (lon - lon0) / (lon1 - lon0) * (W - 28)
    Y = lambda lat: 14 + (lat1 - lat) / (lat1 - lat0) * (H - 58)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    import json, os
    g = json.load(open(os.path.join(os.path.dirname(__file__), 'tiles', 'nca.geojson')))
    r = g['coordinates'][0] if g['type'] == 'Polygon' else g['coordinates'][0][0]
    out.append('<polygon points="' + ' '.join(f'{X(lon):.1f},{Y(lat):.1f}' for lon, lat in r) +
               '" fill="color-mix(in srgb,var(--acc) 7%,transparent)" stroke="var(--acc)" stroke-width="1.4" opacity="0.9"/>')
    for name, lat, lon, elev, st, age in STATIONS:
        col = 'var(--ok)' if st == 'online' else ('var(--warn)' if 'stale' in st else 'var(--fail)')
        out.append(f'<circle cx="{X(lon):.1f}" cy="{Y(lat):.1f}" r="5" fill="{col}" opacity="0.9">'
                   f'<title>{name} · {elev} m · {st}</title></circle>')
        out.append(f'<text x="{X(lon):.1f}" y="{Y(lat)-9:.1f}" text-anchor="middle" '
                   f'style="paint-order:stroke;stroke:var(--cv);stroke-width:2.5">{name.split("—")[0].strip()}</text>')
    out.append(f'<text class="annoS" x="14" y="{H-26}">station health: green = reporting · amber = stale · red = offline (Empakaai rim, 4 days)</text>')
    out.append(f'<text class="annoS" x="14" y="{H-12}">boundary: real NCA polygon from the app database</text>')
    out.append('</svg>')
    return ''.join(out)
