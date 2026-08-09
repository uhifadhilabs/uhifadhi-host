"""SVG chart library for the uhifadhi design templates.

One function per visualization idiom, hand-rendered SVG in the app's chart
dialect (.ch CSS classes). Each returns a complete <svg> string. No JS.
"""
import math
import random

import data as D

CAT = ["#D97742", "#4A90C2", "#8FA35F", "#C9B458", "#A46A8C", "#5FA3A0", "#E05B41", "#B0A79A"]
FIRE_RAMP = ["#FFFFB2", "#FED877", "#FDA847", "#FD8D3C", "#F4692E", "#F03B20", "#BD0026"]
ACC = "var(--acc)"


# ───────────────────────── helpers ─────────────────────────
class F:
    """Plot frame: margins, linear scales, grid/axis emitters."""

    def __init__(self, w=470, h=170, l=44, r=10, t=16, b=24):
        self.w, self.h, self.l, self.r, self.t, self.b = w, h, l, r, t, b
        self.pw, self.ph = w - l - r, h - t - b
        self.out = [f'<svg class="ch" viewBox="0 0 {w} {h}" xmlns="http://www.w3.org/2000/svg">']

    def sx(self, lo, hi):
        self._xlo, self._xhi = lo, hi
        return lambda v: self.l + (v - lo) / (hi - lo) * self.pw

    def sy(self, lo, hi):
        self._ylo, self._yhi = lo, hi
        return lambda v: self.t + self.ph - (v - lo) / (hi - lo) * self.ph

    def grid_y(self, x, y, vals, fmt=str, unit=None):
        for v in vals:
            self.out.append(f'<line class="grid" x1="{self.l}" y1="{y(v):.1f}" x2="{self.w-self.r}" y2="{y(v):.1f}"/>')
            self.out.append(f'<text x="{self.l-5}" y="{y(v)+2.5:.1f}" text-anchor="end">{fmt(v)}</text>')
        if unit:
            self.out.append(f'<text class="annoS" x="{self.l-30}" y="{self.t-4}">{unit}</text>')

    def xt(self, x, v, label):
        self.out.append(f'<text x="{x(v):.1f}" y="{self.h-8}" text-anchor="middle">{label}</text>')

    def baseline(self):
        self.out.append(f'<line class="ax" x1="{self.l}" y1="{self.t+self.ph}" x2="{self.w-self.r}" y2="{self.t+self.ph}"/>')

    def done(self):
        self.out.append('</svg>')
        return ''.join(self.out)


def kde(vals, grid, bw):
    n = len(vals)
    return [sum(math.exp(-0.5 * ((g - v) / bw) ** 2) for v in vals) / (n * bw * math.sqrt(2 * math.pi))
            for g in grid]


def quantile(sorted_vals, q):
    n = len(sorted_vals)
    pos = q * (n - 1)
    i = int(pos)
    frac = pos - i
    return sorted_vals[i] if i + 1 >= n else sorted_vals[i] * (1 - frac) + sorted_vals[i + 1] * frac


def norminv(p):
    """Acklam's inverse normal CDF approximation."""
    a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
         1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00]
    b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
         6.680131188771972e+01, -1.328068155288572e+01]
    c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
         -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00]
    d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
         3.754408661907416e+00]
    plow, phigh = 0.02425, 1 - 0.02425
    if p < plow:
        q = math.sqrt(-2 * math.log(p))
        return (((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5]) / ((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1)
    if p > phigh:
        q = math.sqrt(-2 * math.log(1 - p))
        return -(((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5]) / ((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1)
    q = p - 0.5
    r = q * q
    return (((((a[0]*r+a[1])*r+a[2])*r+a[3])*r+a[4])*r+a[5])*q / (((((b[0]*r+b[1])*r+b[2])*r+b[3])*r+b[4])*r+1)


def loess(xs, ys, frac=0.4):
    n = len(xs)
    k = max(3, int(frac * n))
    out = []
    for x0 in xs:
        d = sorted(abs(x - x0) for x in xs)[k - 1] or 1e-9
        ws, wx, wy, wxx, wxy = 0, 0, 0, 0, 0
        for x, y in zip(xs, ys):
            u = min(1, abs(x - x0) / d)
            w = (1 - u ** 3) ** 3
            ws += w; wx += w * x; wy += w * y; wxx += w * x * x; wxy += w * x * y
        den = ws * wxx - wx * wx
        if abs(den) < 1e-12:
            out.append(wy / ws)
        else:
            b1 = (ws * wxy - wx * wy) / den
            b0 = (wy - b1 * wx) / ws
            out.append(b0 + b1 * x0)
    return out


def polyline(pts, cls=None, style=""):
    d = 'M' + ' L'.join(f'{x:.1f},{y:.1f}' for x, y in pts)
    c = f' class="{cls}"' if cls else ''
    return f'<path{c} d="{d}" fill="none" {style}/>'


def spark(series, w=92, h=22, color="#D97742"):
    lo, hi = min(series), max(series)
    rng = (hi - lo) or 1
    pts = [(i / (len(series) - 1) * (w - 4) + 2, h - 3 - (v - lo) / rng * (h - 8))
           for i, v in enumerate(series)]
    d = 'M' + ' L'.join(f'{x:.1f},{y:.1f}' for x, y in pts)
    ex, ey = pts[-1]
    return (f'<svg viewBox="0 0 {w} {h}" width="{w}" height="{h}" style="display:block">'
            f'<path d="{d}" fill="none" stroke="{color}" stroke-width="1.3"/>'
            f'<circle cx="{ex:.1f}" cy="{ey:.1f}" r="1.8" fill="{color}"/></svg>')


# ═══════════════════ OVERVIEW / GEO ═══════════════════
def _tz_ring():
    import json, os
    g = json.load(open(os.path.join(os.path.dirname(__file__), '..', 'tza.geo.json')))
    geom = g['features'][0]['geometry']
    return geom['coordinates'][0] if geom['type'] == 'Polygon' else geom['coordinates'][0][0]


def _merc(lon, lat):
    return lon, -math.log(math.tan(math.pi / 4 + math.radians(lat) / 2)) * 180 / math.pi


def _tz_proj(w, h, pad=14):
    ring = [_merc(lon, lat) for lon, lat in _tz_ring()]
    xs = [p[0] for p in ring]; ys = [p[1] for p in ring]
    x0, x1, y0, y1 = min(xs), max(xs), min(ys), max(ys)
    s = min((w - 2 * pad) / (x1 - x0), (h - 2 * pad) / (y1 - y0))
    ox = (w - s * (x1 - x0)) / 2 - s * x0
    oy = (h - s * (y1 - y0)) / 2 - s * y0

    def P(lon, lat):
        mx, my = _merc(lon, lat)
        return mx * s + ox, my * s + oy
    return P, ring, s, ox, oy


def bubble_map(w=470, h=470):
    P, ring, s, ox, oy = _tz_proj(w, h)
    pts = ' '.join(f'{x*s+ox:.1f},{y*s+oy:.1f}' for x, y in ring)
    out = [f'<svg class="ch" viewBox="0 0 {w} {h}" xmlns="http://www.w3.org/2000/svg">']
    out.append(f'<polygon points="{pts}" fill="color-mix(in srgb,var(--fog) 10%,transparent)" '
               f'stroke="color-mix(in srgb,var(--fog) 45%,transparent)" stroke-width="1.2"/>')
    mx = max(a["mean"] for a in D.AREAS)
    for a in sorted(D.AREAS, key=lambda a: -a["km2"]):
        x, y = P(a["lon"], a["lat"])
        r = 4 + math.sqrt(a["km2"]) / math.sqrt(31000) * 26
        heat = min(1, a["density"] / 12)
        col = FIRE_RAMP[min(6, int(heat * 6.99))]
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.1f}" fill="{col}" opacity="0.62" '
                   f'stroke="{col}" stroke-width="1"><title>{a["name"]}: {a["km2"]:,} km² · '
                   f'{a["mean"]:.0f} ha/yr</title></circle>')
    for sh in ("SER", "NCA", "NYE", "RUA", "KAT", "KIL"):
        a = D.BY_SHORT[sh]
        x, y = P(a["lon"], a["lat"])
        out.append(f'<text x="{x:.0f}" y="{y-3:.0f}" text-anchor="middle" class="anno" '
                   f'style="paint-order:stroke;stroke:var(--cv);stroke-width:2.5">{sh}</text>')
    out.append(f'<text class="annoS" x="12" y="{h-10}">size = protected area · color = loss pressure (ha yr⁻¹ / 100 km²)</text>')
    out.append('</svg>')
    return ''.join(out)


def grid_choropleth(w=470, h=470):
    P, ring, s, ox, oy = _tz_proj(w, h)
    poly = _tz_ring()

    def inside(lon, lat):
        c = False
        n = len(poly)
        for i in range(n):
            x1, y1 = poly[i]; x2, y2 = poly[(i + 1) % n]
            if (y1 > lat) != (y2 > lat) and lon < (x2 - x1) * (lat - y1) / (y2 - y1) + x1:
                c = not c
        return c

    rng = random.Random(11)
    out = [f'<svg class="ch" viewBox="0 0 {w} {h}" xmlns="http://www.w3.org/2000/svg">']
    step = 0.45
    lat = -11.7
    while lat < -0.9:
        lon = 29.3
        while lon < 40.4:
            if inside(lon + step / 2, lat + step / 2):
                west = max(0, 1 - abs(lon - 31.2) / 3.5) * 0.9          # miombo belt
                arc = max(0, 1 - abs(lat + 7.6) / 2.2) * max(0, 1 - abs(lon - 36.6) / 1.8) * 0.8
                coast = max(0, 1 - abs(lon - 39.2) / 1.4) * 0.55
                v = min(1, (west + arc + coast) * (0.7 + 0.6 * rng.random()))
                col = FIRE_RAMP[min(6, int(v * 6.99))]
                x0, y0 = P(lon, lat + step)
                x1, y1 = P(lon + step, lat)
                out.append(f'<rect x="{x0:.1f}" y="{y0:.1f}" width="{x1-x0:.1f}" height="{y1-y0:.1f}" '
                           f'fill="{col}" opacity="0.75"/>')
            lon += step
        lat += step
    pts = ' '.join(f'{x*s+ox:.1f},{y*s+oy:.1f}' for x, y in [_merc(lo, la) for lo, la in poly])
    out.append(f'<polygon points="{pts}" fill="none" stroke="var(--tx)" stroke-width="1.4" opacity="0.7"/>')
    lx = 12
    for i, c in enumerate(FIRE_RAMP):
        out.append(f'<rect x="{lx+i*16}" y="{h-24}" width="16" height="8" fill="{c}"/>')
    out.append(f'<text class="annoS" x="{lx}" y="{h-30}">low</text>')
    out.append(f'<text class="annoS" x="{lx+7*16-18}" y="{h-30}">high</text>')
    out.append(f'<text class="annoS" x="{lx+7*16+10}" y="{h-17}">gridded loss intensity, 0.45° cells</text>')
    out.append('</svg>')
    return ''.join(out)


def ranked_bar():
    rows = sorted(D.AREAS, key=lambda a: -a["total"])[:12]
    f = F(470, 300, l=44, t=8, b=20)
    xmax = max(a["total"] for a in rows) * 1.06
    x = f.sx(0, xmax)
    bh = f.ph / len(rows) * 0.68
    step = f.ph / len(rows)
    for gv in (0, 20000, 40000, 60000):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.out.append(f'<text x="{x(gv):.1f}" y="{f.h-6}" text-anchor="middle">{gv//1000}k</text>')
    for i, a in enumerate(rows):
        y = f.t + i * step + (step - bh) / 2
        col = ACC if a["real"] else "#D97742"
        f.out.append(f'<rect x="{f.l}" y="{y:.1f}" width="{x(a["total"])-f.l:.1f}" height="{bh:.1f}" '
                     f'rx="1.5" fill="{col}" opacity="{1 if a["real"] else .82}"/>')
        f.out.append(f'<text x="{f.l-5}" y="{y+bh/2+2.5:.1f}" text-anchor="end">{a["short"]}</text>')
        f.out.append(f'<text class="annoS" x="{x(a["total"])+4:.1f}" y="{y+bh/2+2.5:.1f}">{a["total"]:,}</text>')
    f.out.append(f'<text class="annoS" x="{f.l}" y="{f.t-0}"></text>')
    return f.done()


def lollipop():
    rows = sorted(D.AREAS, key=lambda a: -a["density"])[:12]
    f = F(470, 300, l=44, t=8, b=20)
    xmax = max(a["density"] for a in rows) * 1.12
    x = f.sx(0, xmax)
    step = f.ph / len(rows)
    for gv in range(0, int(xmax) + 1, 4):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.out.append(f'<text x="{x(gv):.1f}" y="{f.h-6}" text-anchor="middle">{gv}</text>')
    for i, a in enumerate(rows):
        y = f.t + i * step + step / 2
        col = ACC if a["real"] else "#D97742"
        f.out.append(f'<line x1="{f.l}" y1="{y:.1f}" x2="{x(a["density"]):.1f}" y2="{y:.1f}" '
                     f'stroke="{col}" stroke-width="1.4" opacity="0.75"/>')
        f.out.append(f'<circle cx="{x(a["density"]):.1f}" cy="{y:.1f}" r="3.4" fill="{col}"/>')
        f.out.append(f'<text x="{f.l-5}" y="{y+2.5:.1f}" text-anchor="end">{a["short"]}</text>')
    return f.done()


def treemap():
    W, H = 470, 300
    items = sorted(D.AREAS, key=lambda a: -a["km2"])
    total = sum(a["km2"] for a in items)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    x, y, rw, rh = 2.0, 2.0, W - 4.0, H - 4.0
    i = 0
    while i < len(items):
        remaining = sum(a["km2"] for a in items[i:])
        horiz = rw >= rh
        row, rsum = [], 0
        j = i
        while j < len(items) and (len(row) < 1 or rsum / remaining < (0.42 if horiz else 0.5)):
            row.append(items[j]); rsum += items[j]["km2"]; j += 1
        frac = rsum / remaining
        if horiz:
            cw = rw * frac
            cy = y
            for a in row:
                ch = rh * a["km2"] / rsum
                out.append(_tm_cell(x, cy, cw, ch, a))
                cy += ch
            x += cw; rw -= cw
        else:
            chh = rh * frac
            cx = x
            for a in row:
                cw2 = rw * a["km2"] / rsum
                out.append(_tm_cell(cx, y, cw2, chh, a))
                cx += cw2
            y += chh; rh -= chh
        i = j
    out.append('</svg>')
    return ''.join(out)


def _tm_cell(x, y, w, h, a):
    heat = min(1, a["density"] / 12)
    col = FIRE_RAMP[min(6, int(heat * 6.99))]
    lab = (f'<text x="{x+w/2:.1f}" y="{y+h/2+1:.1f}" text-anchor="middle" fill="#141D18" '
           f'font-weight="700" font-size="{min(11, max(6.5, w/8)):.1f}">{a["short"]}</text>'
           if w > 26 and h > 15 else '')
    return (f'<rect x="{x:.1f}" y="{y:.1f}" width="{w-1.6:.1f}" height="{h-1.6:.1f}" rx="2.5" '
            f'fill="{col}" opacity="0.88"><title>{a["name"]} · {a["km2"]:,} km²</title></rect>' + lab)


def bullet(label, value, target, bands, unit, vmax):
    W, H = 470, 56
    L, R = 108, 46
    pw = W - L - R
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    out.append(f'<text x="{L-8}" y="26" text-anchor="end" class="ttl">{label}</text>')
    out.append(f'<text x="{L-8}" y="38" text-anchor="end" class="annoS">{unit}</text>')
    for i, b in enumerate(bands):
        x0 = L + (bands[i - 1] if i else 0) / vmax * pw
        out.append(f'<rect x="{x0:.1f}" y="16" width="{(b-(bands[i-1] if i else 0))/vmax*pw:.1f}" height="18" '
                   f'fill="color-mix(in srgb,var(--fog) {26-i*8}%,transparent)"/>')
    out.append(f'<rect x="{L}" y="21" width="{value/vmax*pw:.1f}" height="8" fill="#D97742" rx="1"/>')
    tx = L + target / vmax * pw
    out.append(f'<line x1="{tx:.1f}" y1="13" x2="{tx:.1f}" y2="37" stroke="var(--tx)" stroke-width="2"/>')
    out.append(f'<text x="{W-R+6}" y="30" class="anno">{value}</text>')
    out.append('</svg>')
    return ''.join(out)


def waffle():
    W, H = 470, 210
    cols, size, gap = 20, 17, 4
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    protected = 38
    for i in range(100):
        r, c = divmod(i, cols)
        col = "#3E7A45" if i < protected else "color-mix(in srgb,var(--fog) 18%,transparent)"
        out.append(f'<rect x="{6+c*(size+gap)}" y="{8+r*(size+gap)}" width="{size}" height="{size}" rx="3" fill="{col}"/>')
    out.append(f'<text class="anno" x="6" y="{H-28}">38 of every 100 km² of Tanzania sit under some protection —</text>')
    out.append(f'<text class="annoS" x="6" y="{H-14}">one square = 1% of the national land area · WDPA Aug 2026</text>')
    out.append('</svg>')
    return ''.join(out)


def donut():
    cats = [("II — National Park", 78940, "#3E7A45"), ("VI — NCA (multi-use)", 8271, "#D97742"),
            ("Game/Forest reserves", 165000, "#C9B458"), ("Marine", 6500, "#4A90C2")]
    total = sum(v for _, v, _ in cats)
    W, H, cx, cy, r0, r1 = 470, 240, 130, 120, 52, 92
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    a0 = -math.pi / 2
    for name, v, col in cats:
        a1 = a0 + v / total * 2 * math.pi
        out.append(_arc(cx, cy, r0, r1, a0, a1, col) + f'<title>{name}</title></path>')
        a0 = a1
    out.append(f'<text x="{cx}" y="{cy-4}" text-anchor="middle" class="ttl" font-size="15">{total//1000}k</text>')
    out.append(f'<text x="{cx}" y="{cy+11}" text-anchor="middle" class="annoS">km² protected</text>')
    ly = 40
    for name, v, col in cats:
        out.append(f'<rect x="256" y="{ly-8}" width="10" height="10" rx="2" fill="{col}"/>')
        out.append(f'<text x="272" y="{ly}">{name} · {round(v/total*100)}%</text>')
        ly += 22
    out.append(f'<text class="annoS" x="256" y="{ly+8}">composition better served by stacked bars —</text>')
    out.append(f'<text class="annoS" x="256" y="{ly+20}">donut kept for the single share-of-whole glance</text>')
    out.append('</svg>')
    return ''.join(out)


def _arc(cx, cy, r0, r1, a0, a1, col):
    gap = 0.012
    a0, a1 = a0 + gap, a1 - gap
    large = 1 if a1 - a0 > math.pi else 0
    x0o, y0o = cx + r1 * math.cos(a0), cy + r1 * math.sin(a0)
    x1o, y1o = cx + r1 * math.cos(a1), cy + r1 * math.sin(a1)
    x1i, y1i = cx + r0 * math.cos(a1), cy + r0 * math.sin(a1)
    x0i, y0i = cx + r0 * math.cos(a0), cy + r0 * math.sin(a0)
    return (f'<path d="M{x0o:.1f} {y0o:.1f} A{r1} {r1} 0 {large} 1 {x1o:.1f} {y1o:.1f} '
            f'L{x1i:.1f} {y1i:.1f} A{r0} {r0} 0 {large} 0 {x0i:.1f} {y0i:.1f} Z" fill="{col}" opacity="0.9">')


# ═══════════════════ AREA DETAIL / TIME ═══════════════════
def annual_chart(width=470):
    f = F(width, 176, t=24)
    ymax = 200.0
    x = f.sx(2000.5, 2023.5)
    y = f.sy(0, ymax)
    f.grid_y(x, y, (0, 50, 100, 150, 200), unit="ha yr⁻¹")
    f.out.append('<defs><linearGradient id="clipg" x1="0" y1="0" x2="0" y2="1">'
                 '<stop offset="0" stop-color="#D97742" stop-opacity="0.25"/>'
                 '<stop offset="0.35" stop-color="#D97742"/></linearGradient></defs>')
    ym = y(sum(D.NCA_LOSS[1:]) / 22)
    f.out.append(f'<line class="ref" x1="{f.l}" y1="{ym:.1f}" x2="{f.w-f.r}" y2="{ym:.1f}"/>')
    bw = f.pw / 23 * 0.72
    for yr, v in zip(D.YEARS, D.NCA_LOSS):
        clipped = v > ymax
        fill = 'url(#clipg)' if clipped else '#D97742'
        top = f.t if clipped else y(v)
        f.out.append(f'<rect x="{x(yr)-bw/2:.1f}" y="{top:.1f}" width="{bw:.1f}" '
                     f'height="{f.t+f.ph-top:.1f}" rx="1.2" fill="{fill}"><title>{yr}: {v} ha</title></rect>')
        if yr % 4 == 1:
            f.xt(x, yr, str(yr)[2:])
    f.out.append(f'<text class="anno" x="{x(2001)+8:.1f}" y="{f.t+9}">⇡ 1,657 ha — 2001 baseline artifact</text>')
    f.out.append(f'<text class="anno" x="{x(2013):.1f}" y="{y(186)-5:.1f}" text-anchor="middle">186</text>')
    f.baseline()
    return f.done()


def cum_chart(width=470):
    f = F(width, 140, t=16)
    x = f.sx(2001, 2023)
    y = f.sy(0, 3400)
    f.grid_y(x, y, (0, 1000, 2000, 3000), fmt=lambda v: f'{v//1000}k' if v else '0')
    cum = [sum(D.NCA_LOSS[:i+1]) for i in range(23)]
    pts = [(x(yr), y(c)) for yr, c in zip(D.YEARS, cum)]
    f.out.append(f'<path class="cum" d="M{f.l},{f.t+f.ph} ' +
                 ' '.join(f'L{px:.1f},{py:.1f}' for px, py in pts) + f' L{f.w-f.r},{f.t+f.ph} Z"/>')
    f.out.append(polyline(pts, 'cumln'))
    for yr in (2001, 2012, 2023):
        f.xt(x, yr, str(yr)[2:])
    ex, ey = pts[-1]
    f.out.append(f'<circle cx="{ex:.1f}" cy="{ey:.1f}" r="2.4" fill="#D97742"/>')
    f.out.append(f'<text class="anno" x="{ex-6:.1f}" y="{ey-6:.1f}" text-anchor="end">3,214 ha since 2001</text>')
    f.baseline()
    return f.done()


def waterfall():
    steps = [("2001 artifact", 1657), ("2002–07", sum(D.NCA_LOSS[1:7])),
             ("2008–13", sum(D.NCA_LOSS[7:13])), ("2014–19", sum(D.NCA_LOSS[13:19])),
             ("2020–23", sum(D.NCA_LOSS[19:]))]
    f = F(470, 190, t=18, b=30)
    x = f.sx(0, len(steps) + 1)
    y = f.sy(0, 3400)
    f.grid_y(x, y, (0, 1000, 2000, 3000), fmt=lambda v: f'{v//1000}k' if v else '0', unit="ha")
    run = 0
    bw = f.pw / (len(steps) + 1) * 0.62
    for i, (lab, v) in enumerate(steps):
        x0 = x(i + 0.5) - bw / 2
        f.out.append(f'<rect x="{x0:.1f}" y="{y(run+v):.1f}" width="{bw:.1f}" height="{y(run)-y(run+v):.1f}" '
                     f'rx="1.5" fill="{"#B0A79A" if i==0 else "#D97742"}" opacity="0.9"/>')
        f.out.append(f'<line x1="{x0+bw:.1f}" y1="{y(run+v):.1f}" x2="{x(i+1.5)-bw/2:.1f}" y2="{y(run+v):.1f}" '
                     f'class="ref"/>')
        f.out.append(f'<text class="annoS" x="{x(i+.5):.1f}" y="{y(run+v)-4:.1f}" text-anchor="middle">+{v:,}</text>')
        f.out.append(f'<text x="{x(i+.5):.1f}" y="{f.h-14}" text-anchor="middle">{lab}</text>')
        run += v
    x0 = x(len(steps) + 0.5) - bw / 2
    f.out.append(f'<rect x="{x0:.1f}" y="{y(run):.1f}" width="{bw:.1f}" height="{y(0)-y(run):.1f}" rx="1.5" fill="{ACC}"/>')
    f.out.append(f'<text class="anno" x="{x(len(steps)+.5):.1f}" y="{y(run)-5:.1f}" text-anchor="middle">3,214</text>')
    f.out.append(f'<text x="{x(len(steps)+.5):.1f}" y="{f.h-14}" text-anchor="middle">total</text>')
    f.baseline()
    return f.done()


def gantt():
    f = F(470, 210, l=118, t=20, b=22)
    x = f.sx(1970, 2026)
    step = f.ph / len(D.DATASETS)
    for gv in (1970, 1980, 1990, 2000, 2010, 2020):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.xt(x, gv, str(gv))
    for i, (name, src, y0, y1, cad, st) in enumerate(D.DATASETS):
        yy = f.t + i * step + step * 0.22
        col = "#4A90C2" if st == "ok" else "color-mix(in srgb,var(--fog) 40%,transparent)"
        w = max(x(y1 + 1) - x(y0), 5)
        f.out.append(f'<rect x="{x(y0):.1f}" y="{yy:.1f}" width="{w:.1f}" height="{step*0.5:.1f}" rx="3" '
                     f'fill="{col}" opacity="0.85"><title>{name} · {y0}–{y1} · {cad}</title></rect>')
        f.out.append(f'<text x="{f.l-6}" y="{yy+step*0.32:.1f}" text-anchor="end">{name.split("(")[0][:20]}</text>')
    now = x(2024)
    f.out.append(f'<line x1="{now:.1f}" y1="{f.t}" x2="{now:.1f}" y2="{f.t+f.ph}" stroke="{ACC}" '
                 f'stroke-width="1.2" stroke-dasharray="3 2"/>')
    f.out.append(f'<text class="annoS" x="{now-4:.1f}" y="{f.t-6}" text-anchor="end" fill="{ACC}">today</text>')
    return f.done()


def horizon():
    rng = random.Random(3)
    months = 144
    vals = []
    for i in range(months):
        season = math.sin((i % 12) / 12 * 2 * math.pi - 1.2) * 25
        vals.append(season + rng.gauss(0, 22))
    W, H = 470, 120
    L, T, bh = 44, 18, 34
    pw = W - L - 10
    bands = 2
    B = 40.0
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    out.append(f'<text class="annoS" x="{L}" y="{T-6}">monthly rainfall anomaly, 2012–2023, mirrored horizon bands (±{int(B)} mm each)</text>')
    for sign, cols in ((1, ["#9CC4E0", "#4A90C2"]), (-1, ["#E0AE9C", "#C05A3A"])):
        for b in range(bands):
            pts = []
            for i, v in enumerate(vals):
                vv = max(0, min(B, sign * v - b * B)) / B
                pts.append((L + i / (months - 1) * pw, T + bh - vv * bh))
            d = (f'M{L},{T+bh} ' + ' '.join(f'L{px:.1f},{py:.1f}' for px, py in pts) +
                 f' L{L+pw},{T+bh} Z')
            out.append(f'<path d="{d}" fill="{cols[b]}" opacity="0.85"/>')
    for i, yr in enumerate(range(2012, 2024, 2)):
        out.append(f'<text x="{L + i*2*12/(months-1)*pw:.1f}" y="{T+bh+14}" >{yr}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{T+bh+30}">12 years in one 34-px strip — the horizon chart is the densest honest time series (matplotlib/d3 idiom)</text>')
    out.append('</svg>')
    return ''.join(out)


def seasonal_subseries():
    rng = random.Random(5)
    P = D.CLIMATE["NCA"][0]
    f = F(470, 176, t=18)
    x = f.sx(0, 12)
    y = f.sy(0, 260)
    f.grid_y(x, y, (0, 100, 200), unit="mm")
    for m in range(12):
        xs0, xs1 = x(m + 0.12), x(m + 0.88)
        series = [max(2, P[m] * (0.55 + 0.9 * rng.random())) for _ in range(10)]
        pts = [(xs0 + i / 9 * (xs1 - xs0), y(v)) for i, v in enumerate(series)]
        f.out.append(polyline(pts, style='stroke="#4A90C2" stroke-width="0.9" opacity="0.75"'))
        mean = sum(series) / len(series)
        f.out.append(f'<line x1="{xs0:.1f}" y1="{y(mean):.1f}" x2="{xs1:.1f}" y2="{y(mean):.1f}" '
                     f'stroke="var(--tx)" stroke-width="1.6"/>')
        f.xt(x, m + 0.5, D.MONTHS[m])
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+10}">each mini-panel: that month across 10 years; bar = month mean (the classic seasonal subseries plot)</text>')
    f.baseline()
    return f.done()


def fire_calendar():
    yrs, data = D.FIRE_YEARS, D.FIRE["NCA"]
    vmax = max(max(r) for r in data)
    W, H = 470, 158
    L, T = 46, 18
    cw, chh, gap = 32, 22, 3
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for j, m in enumerate(D.MONTHS):
        out.append(f'<text x="{L+j*(cw+gap)+cw/2:.1f}" y="{T-4}" text-anchor="middle">{m}</text>')
    for i, (yr, row) in enumerate(zip(yrs, data)):
        out.append(f'<text x="{L-6}" y="{T+i*(chh+gap)+chh/2+2.5:.1f}" text-anchor="end">{yr}</text>')
        for j, v in enumerate(row):
            a = 0.05 + 0.95 * (v / vmax)
            out.append(f'<rect x="{L+j*(cw+gap):.1f}" y="{T+i*(chh+gap):.1f}" width="{cw}" height="{chh}" rx="3" '
                       f'fill="#E05B41" opacity="{a:.2f}"><title>{yr} {D.MONTHS[j]}: {v}</title></rect>')
    out.append(f'<text class="annoS" x="{L}" y="{T+5*(chh+gap)+13:.1f}">dry-season pulse Jul–Oct · peak Aug 2022 ({vmax} detections)</text>')
    out.append('</svg>')
    return ''.join(out)


def step_chart():
    yrs = [2019, 2020, 2021, 2022, 2023, 2024]
    counts = [1, 1, 2, 3, 5, 7]
    f = F(470, 120, t=16)
    x = f.sx(2019, 2024.4)
    y = f.sy(0, 8)
    f.grid_y(x, y, (0, 4, 8), unit="datasets")
    pts = []
    for i, (yr, c) in enumerate(zip(yrs, counts)):
        pts.append((x(yr), y(c)))
        if i + 1 < len(yrs):
            pts.append((x(yrs[i + 1]), y(c)))
    f.out.append(polyline(pts, style=f'stroke="{ACC}" stroke-width="1.6"'))
    for yr in yrs:
        f.xt(x, yr, str(yr)[2:])
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+8}">datasets ingested for this area — step chart: counts change at events, never between them</text>')
    f.baseline()
    return f.done()


# ═══════════════════ COMPARE ═══════════════════
def _logloss(a):
    return [math.log10(max(v, 1)) for v in a["loss"]]


def ridgeline():
    rows = sorted(D.AREAS, key=lambda a: -a["mean"])[:8]
    W, H = 470, 300
    L, R, T = 52, 14, 30
    pw = W - L - R
    rh = 44
    step = (H - T - 24) / len(rows)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    grid = [i / 60 * 4.2 for i in range(61)]
    for i, a in enumerate(rows):
        base = T + (i + 1) * step
        dens = kde(_logloss(a), grid, 0.28)
        mx = max(dens) or 1
        pts = [(L + g / 4.2 * pw, base - d / mx * rh) for g, d in zip(grid, dens)]
        col = ACC if a["real"] else CAT[i % len(CAT)]
        d = (f'M{L},{base:.1f} ' + ' '.join(f'L{x:.1f},{y:.1f}' for x, y in pts) + f' L{L+pw},{base:.1f} Z')
        out.append(f'<path d="{d}" fill="{col}" opacity="0.35"/>')
        out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.2"'))
        out.append(f'<text x="{L-6}" y="{base-3:.1f}" text-anchor="end">{a["short"]}</text>')
    for e in (0, 1, 2, 3, 4):
        out.append(f'<text x="{L+e/4.2*pw:.1f}" y="{H-6}" text-anchor="middle">10{"⁰¹²³⁴"[e]}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{T-16}">annual loss distribution per PA (log ha) — ridgeline/joyplot, overlap makes 8 densities scannable</text>')
    out.append('</svg>')
    return ''.join(out)


def boxplot():
    rows = sorted(D.AREAS, key=lambda a: -a["mean"])[:8]
    f = F(470, 240, t=20, b=26)
    x = f.sx(0, len(rows))
    y = f.sy(0, 4.2)
    f.grid_y(x, y, (0, 1, 2, 3, 4), fmt=lambda v: f'10{"⁰¹²³⁴"[int(v)]}', unit="ha (log)")
    bw = f.pw / len(rows) * 0.44
    for i, a in enumerate(rows):
        vals = sorted(_logloss(a))
        q1, q2, q3 = quantile(vals, .25), quantile(vals, .5), quantile(vals, .75)
        iqr = q3 - q1
        lo = min(v for v in vals if v >= q1 - 1.5 * iqr)
        hi = max(v for v in vals if v <= q3 + 1.5 * iqr)
        cx = x(i + 0.5)
        col = ACC if a["real"] else "#D97742"
        f.out.append(f'<line x1="{cx:.1f}" y1="{y(lo):.1f}" x2="{cx:.1f}" y2="{y(hi):.1f}" '
                     f'stroke="{col}" stroke-width="1"/>')
        f.out.append(f'<rect x="{cx-bw/2:.1f}" y="{y(q3):.1f}" width="{bw:.1f}" height="{y(q1)-y(q3):.1f}" '
                     f'fill="{col}" opacity="0.3" stroke="{col}" rx="2"/>')
        f.out.append(f'<line x1="{cx-bw/2:.1f}" y1="{y(q2):.1f}" x2="{cx+bw/2:.1f}" y2="{y(q2):.1f}" '
                     f'stroke="{col}" stroke-width="1.8"/>')
        for v in vals:
            if v < lo - 1e-9 or v > hi + 1e-9:
                f.out.append(f'<circle cx="{cx:.1f}" cy="{y(v):.1f}" r="1.6" fill="{col}" opacity="0.8"/>')
        f.xt(x, i + 0.5, a["short"])
    return f.done()


def violin_swarm():
    rows = [D.BY_SHORT[s] for s in ("NYE", "RUA", "NCA", "SER", "MAH", "KIL")]
    f = F(470, 240, t=20, b=26)
    x = f.sx(0, len(rows))
    y = f.sy(0, 4.2)
    f.grid_y(x, y, (0, 1, 2, 3, 4), fmt=lambda v: f'10{"⁰¹²³⁴"[int(v)]}', unit="ha (log)")
    grid = [i / 50 * 4.2 for i in range(51)]
    hw = f.pw / len(rows) * 0.34
    for i, a in enumerate(rows):
        vals = _logloss(a)
        dens = kde(vals, grid, 0.3)
        mx = max(dens) or 1
        cx = x(i + 0.5)
        col = ACC if a["real"] else CAT[i]
        right = [(cx + d / mx * hw, y(g)) for g, d in zip(grid, dens)]
        left = [(cx - d / mx * hw, y(g)) for g, d in reversed(list(zip(grid, dens)))]
        d_path = ('M' + ' L'.join(f'{px:.1f},{py:.1f}' for px, py in right + left) + ' Z')
        f.out.append(f'<path d="{d_path}" fill="{col}" opacity="0.25" stroke="{col}" stroke-width="0.8"/>')
        placed = []
        for v in sorted(vals):
            dx = 0.0
            while any(abs(v - pv) < 0.09 and abs(dx - pdx) < 5 for pv, pdx in placed):
                dx = -dx + (4 if dx <= 0 else 0)
            placed.append((v, dx))
            f.out.append(f'<circle cx="{cx+dx:.1f}" cy="{y(v):.1f}" r="1.5" fill="{col}" opacity="0.9"/>')
        f.xt(x, i + 0.5, a["short"])
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">violin = density silhouette · dots = every actual year (swarm) — shape AND raw data at once</text>')
    return f.done()


def stripplot():
    rows = sorted(D.AREAS, key=lambda a: -a["mean"])[:10]
    rng = random.Random(9)
    f = F(470, 220, t=16, b=26)
    x = f.sx(0, len(rows))
    y = f.sy(0, 4.2)
    f.grid_y(x, y, (0, 2, 4), fmt=lambda v: f'10{"⁰¹²³⁴"[int(v)]}', unit="ha (log)")
    for i, a in enumerate(rows):
        cx = x(i + 0.5)
        col = ACC if a["real"] else "#D97742"
        for v in _logloss(a):
            f.out.append(f'<circle cx="{cx+rng.uniform(-7,7):.1f}" cy="{y(v):.1f}" r="1.6" '
                         f'fill="{col}" opacity="0.55"/>')
        f.xt(x, i + 0.5, a["short"])
    return f.done()


def dumbbell():
    rows = sorted(D.AREAS, key=lambda a: -a["forest_pct"])[:10]
    f = F(470, 260, l=48, t=18, b=22)
    x = f.sx(0, 100)
    step = f.ph / len(rows)
    for gv in (0, 25, 50, 75, 100):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.xt(x, gv, str(gv))
    rng = random.Random(4)
    for i, a in enumerate(rows):
        yy = f.t + i * step + step / 2
        f0 = a["forest_pct"]
        f1 = max(0, f0 - a["total"] / a["km2"] / 100 * 100 - rng.uniform(0, 1.5))
        f.out.append(f'<line x1="{x(f1):.1f}" y1="{yy:.1f}" x2="{x(f0):.1f}" y2="{yy:.1f}" '
                     f'stroke="color-mix(in srgb,var(--fog) 55%,transparent)" stroke-width="1.6"/>')
        f.out.append(f'<circle cx="{x(f0):.1f}" cy="{yy:.1f}" r="3.4" fill="#8FA35F"/>')
        f.out.append(f'<circle cx="{x(f1):.1f}" cy="{yy:.1f}" r="3.4" fill="#D97742"/>')
        f.out.append(f'<text x="{f.l-5}" y="{yy+2.5:.1f}" text-anchor="end">{a["short"]}</text>')
    f.out.append(f'<circle cx="{x(78):.1f}" cy="{f.t-6}" r="3" fill="#8FA35F"/>')
    f.out.append(f'<text x="{x(78)+7:.1f}" y="{f.t-3}">2000</text>')
    f.out.append(f'<circle cx="{x(90):.1f}" cy="{f.t-6}" r="3" fill="#D97742"/>')
    f.out.append(f'<text x="{x(90)+7:.1f}" y="{f.t-3}">2023</text>')
    return f.done()


def slope():
    rows = sorted(D.AREAS, key=lambda a: -a["mean"])[:9]
    d1 = sorted(rows, key=lambda a: -sum(a["loss"][:11]) / a["km2"])
    d2 = sorted(rows, key=lambda a: -sum(a["loss"][11:]) / a["km2"])
    W, H = 470, 260
    L, R, T, B = 120, 120, 30, 16
    step = (H - T - B) / (len(rows) - 1)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    out.append(f'<text class="ttl" x="{L-10}" y="{T-12}" text-anchor="end">2001–2011</text>')
    out.append(f'<text class="ttl" x="{W-R+10}" y="{T-12}">2012–2023</text>')
    for a in rows:
        i1, i2 = d1.index(a), d2.index(a)
        y1, y2 = T + i1 * step, T + i2 * step
        col = ACC if a["real"] else ("#E05B41" if i2 < i1 else "color-mix(in srgb,var(--fog) 60%,transparent)")
        out.append(f'<line x1="{L}" y1="{y1:.1f}" x2="{W-R}" y2="{y2:.1f}" stroke="{col}" stroke-width="1.4"/>')
        out.append(f'<text x="{L-8}" y="{y1+3:.1f}" text-anchor="end">{a["short"]}</text>')
        out.append(f'<text x="{W-R+8}" y="{y2+3:.1f}">{a["short"]}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{H-2}">rank by loss density — red = climbing the pressure table (slope chart: change in rank, nothing else)</text>')
    out.append('</svg>')
    return ''.join(out)


def bubble_scatter():
    f = F(470, 260, t=18, b=30)
    x = f.sx(1.5, 4.7)
    y = f.sy(0, 3.7)
    f.grid_y(x, y, (0, 1, 2, 3), fmt=lambda v: f'10{"⁰¹²³"[int(v)]}', unit="mean loss ha (log)")
    for gv in (2, 3, 4):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.xt(x, gv, f'10{"⁰¹²³⁴"[gv]}')
    f.out.append(f'<text class="annoS" x="{f.w-100}" y="{f.h-6}">area km² (log)</text>')
    for a in D.AREAS:
        px, py = x(math.log10(a["km2"])), y(math.log10(max(a["mean"], 1)))
        r = 3 + a["forest_pct"] / 100 * 9
        col = ACC if a["real"] else "#D97742"
        f.out.append(f'<circle cx="{px:.1f}" cy="{py:.1f}" r="{r:.1f}" fill="{col}" opacity="0.55" '
                     f'stroke="{col}"><title>{a["name"]}</title></circle>')
        if a["short"] in ("NYE", "GOM", "NCA", "SER", "KIL"):
            f.out.append(f'<text x="{px:.1f}" y="{py-r-3:.1f}" text-anchor="middle">{a["short"]}</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+8}">bubble = forest share · both axes log — size scaling is why Nyerere dominates loss without being "worse" per km²</text>')
    return f.done()


def splom():
    keys = [("area", lambda a: math.log10(a["km2"])), ("forest", lambda a: a["forest_pct"]),
            ("rain", lambda a: a["rain"]), ("loss", lambda a: math.log10(max(a["mean"], 1)))]
    W = 470
    cell = (W - 60) / 4
    H = int(cell * 4 + 46)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    vals = {k: [f(a) for a in D.AREAS] for k, f in keys}
    rngs = {k: (min(v), max(v)) for k, v in vals.items()}
    for r, (ky, _) in enumerate(keys):
        for c, (kx, _) in enumerate(keys):
            ox, oy = 44 + c * cell, 8 + r * cell
            out.append(f'<rect x="{ox}" y="{oy}" width="{cell-6:.1f}" height="{cell-6:.1f}" fill="none" '
                       f'stroke="color-mix(in srgb,var(--fog) 25%,transparent)"/>')
            lo_x, hi_x = rngs[kx]
            lo_y, hi_y = rngs[ky]
            if r == c:
                hist = [0] * 7
                for v in vals[kx]:
                    hist[min(6, int((v - lo_x) / (hi_x - lo_x + 1e-9) * 7))] += 1
                hm = max(hist)
                bw = (cell - 14) / 7
                for i, hv in enumerate(hist):
                    hh = hv / hm * (cell - 18)
                    out.append(f'<rect x="{ox+4+i*bw:.1f}" y="{oy+cell-10-hh:.1f}" width="{bw-1:.1f}" '
                               f'height="{hh:.1f}" fill="#D97742" opacity="0.75"/>')
            else:
                for a in D.AREAS:
                    xv = (dict(keys)[kx](a) - lo_x) / (hi_x - lo_x + 1e-9)
                    yv = (dict(keys)[ky](a) - lo_y) / (hi_y - lo_y + 1e-9)
                    col = ACC if a["real"] else "#4A90C2"
                    out.append(f'<circle cx="{ox+4+xv*(cell-14):.1f}" cy="{oy+cell-10-yv*(cell-18):.1f}" '
                               f'r="1.8" fill="{col}" opacity="0.8"/>')
            if c == 0:
                out.append(f'<text x="{ox-6}" y="{oy+cell/2:.1f}" text-anchor="end">{ky}</text>')
            if r == 3:
                out.append(f'<text x="{ox+cell/2:.1f}" y="{H-24}" text-anchor="middle">{kx}</text>')
    out.append(f'<text class="annoS" x="44" y="{H-8}">scatter-plot matrix (pairs/SPLOM) — every pairwise relationship at once, histograms on the diagonal</text>')
    out.append('</svg>')
    return ''.join(out)


def parcoords():
    W, H = 470, 240
    L, R, T, B = 30, 14, 26, 30
    axes = D.STAT_VARS
    step = (W - L - R) / (len(axes) - 1)
    cols = {k: [f(a) for a in D.AREAS] for k, _, f in axes}
    rngs = {k: (min(v), max(v)) for k, v in cols.items()}
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for i, (k, unit, _) in enumerate(axes):
        x = L + i * step
        out.append(f'<line class="ax" x1="{x:.1f}" y1="{T}" x2="{x:.1f}" y2="{H-B}"/>')
        out.append(f'<text x="{x:.1f}" y="{T-8}" text-anchor="middle" class="ttl">{k}</text>')
        out.append(f'<text x="{x:.1f}" y="{H-B+14}" text-anchor="middle" class="annoS">{unit}</text>')
    for a in D.AREAS:
        pts = []
        for i, (k, _, f2) in enumerate(axes):
            lo, hi = rngs[k]
            v = (f2(a) - lo) / (hi - lo + 1e-9)
            pts.append((L + i * step, H - B - v * (H - B - T)))
        col = ACC if a["real"] else "color-mix(in srgb,#4A90C2 70%,transparent)"
        wdt = 2 if a["real"] else 1
        out.append(polyline(pts, style=f'stroke="{col}" stroke-width="{wdt}" opacity="{1 if a["real"] else 0.55}"'))
    out.append(f'<text class="annoS" x="{L}" y="{H-4}">parallel coordinates — 16 areas × 5 variables; the jade line is Ngorongoro\'s profile</text>')
    return ''.join(out) + '</svg>'


def radar():
    a = D.BY_SHORT["NCA"]
    axes = D.STAT_VARS
    cols = {k: [f(x) for x in D.AREAS] for k, _, f in axes}
    W, H, cx, cy, R = 470, 260, 235, 128, 88
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    n = len(axes)
    for ring in (0.33, 0.66, 1.0):
        pts = [(cx + R * ring * math.sin(2 * math.pi * i / n), cy - R * ring * math.cos(2 * math.pi * i / n))
               for i in range(n)]
        out.append(f'<polygon points="{" ".join(f"{x:.1f},{y:.1f}" for x, y in pts)}" fill="none" class="grid" stroke-width="0.8" stroke="color-mix(in srgb,var(--fog) 30%,transparent)"/>')
    def poly_for(getval, col, opac, wdt):
        pts = []
        for i, (k, _, f2) in enumerate(axes):
            lo, hi = min(cols[k]), max(cols[k])
            v = (getval(k, f2) - lo) / (hi - lo + 1e-9)
            pts.append((cx + R * v * math.sin(2 * math.pi * i / n), cy - R * v * math.cos(2 * math.pi * i / n)))
        return (f'<polygon points="{" ".join(f"{x:.1f},{y:.1f}" for x, y in pts)}" fill="{col}" '
                f'opacity="{opac}" stroke="{col}" stroke-width="{wdt}"/>')
    out.append(poly_for(lambda k, f2: sum(cols[k]) / len(cols[k]), "#B0A79A", 0.25, 1))
    out.append(poly_for(lambda k, f2: f2(a), ACC, 0.3, 1.6))
    for i, (k, _, _f) in enumerate(axes):
        x = cx + (R + 16) * math.sin(2 * math.pi * i / n)
        y = cy - (R + 16) * math.cos(2 * math.pi * i / n)
        out.append(f'<text x="{x:.1f}" y="{y:.1f}" text-anchor="middle" class="ttl">{k}</text>')
    out.append(f'<text class="annoS" x="14" y="{H-24}">Ngorongoro (jade) vs network mean (grey)</text>')
    out.append(f'<text class="annoS" x="14" y="{H-10}">radar: one entity\'s multivariate shape — use sparingly, parallel coords scale better</text>')
    out.append('</svg>')
    return ''.join(out)


def matrix_heatmap():
    rows = sorted(D.AREAS, key=lambda a: -a["mean"])[:10]
    W = 470
    L, T = 46, 26
    cw = (W - L - 60) / 23
    chh = 20
    H = T + len(rows) * (chh + 2) + 42
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for j, yr in enumerate(D.YEARS):
        if yr % 4 == 1:
            out.append(f'<text x="{L+j*cw+cw/2:.1f}" y="{T-6}" text-anchor="middle">{str(yr)[2:]}</text>')
    for i, a in enumerate(rows):
        out.append(f'<text x="{L-5}" y="{T+i*(chh+2)+chh/2+2.5:.1f}" text-anchor="end">{a["short"]}</text>')
        mx = max(a["loss"]) or 1
        for j, v in enumerate(a["loss"]):
            heat = (v / mx) ** 0.6
            col = FIRE_RAMP[min(6, int(heat * 6.99))]
            out.append(f'<rect x="{L+j*cw:.1f}" y="{T+i*(chh+2):.1f}" width="{cw-1.2:.1f}" height="{chh}" '
                       f'rx="2" fill="{col}" opacity="0.9"><title>{a["short"]} {D.YEARS[j]}: {v} ha</title></rect>')
    out.append(f'<text class="annoS" x="{L}" y="{H-8}">row-normalized heatmap (seaborn heatmap) — each PA\'s own worst years pop regardless of scale</text>')
    out.append('</svg>')
    return ''.join(out)


def cleveland_ci():
    rows = sorted(D.AREAS, key=lambda a: -a["mean"])[:12]
    f = F(470, 290, l=48, t=14, b=24)
    x = f.sx(0, 4.0)
    step = f.ph / len(rows)
    for gv in (0, 1, 2, 3, 4):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.xt(x, gv, f'10{"⁰¹²³⁴"[gv]}')
    for i, a in enumerate(rows):
        vals = _logloss(a)
        m = sum(vals) / len(vals)
        se = (sum((v - m) ** 2 for v in vals) / (len(vals) - 1)) ** 0.5 / math.sqrt(len(vals))
        yy = f.t + i * step + step / 2
        col = ACC if a["real"] else "#D97742"
        f.out.append(f'<line x1="{x(m-1.96*se):.1f}" y1="{yy:.1f}" x2="{x(m+1.96*se):.1f}" y2="{yy:.1f}" '
                     f'stroke="{col}" stroke-width="1.6"/>')
        f.out.append(f'<circle cx="{x(m):.1f}" cy="{yy:.1f}" r="3.2" fill="{col}"/>')
        f.out.append(f'<text x="{f.l-5}" y="{yy+2.5:.1f}" text-anchor="end">{a["short"]}</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+2}">mean log annual loss ± 95% CI — the Cleveland dot plot beats bars for estimates with uncertainty</text>')
    return f.done()


def diverging_bars():
    med = sorted(a["density"] for a in D.AREAS)[len(D.AREAS) // 2]
    rows = sorted(D.AREAS, key=lambda a: a["density"] - med)
    f = F(470, 290, l=48, t=16, b=24)
    dmax = max(abs(a["density"] - med) for a in rows) * 1.1
    x = f.sx(-dmax, dmax)
    step = f.ph / len(rows)
    f.out.append(f'<line class="ax" x1="{x(0):.1f}" y1="{f.t}" x2="{x(0):.1f}" y2="{f.t+f.ph}"/>')
    for i, a in enumerate(rows):
        v = a["density"] - med
        yy = f.t + i * step + step * 0.2
        col = "#C05A3A" if v > 0 else "#4A90C2"
        x0, x1 = sorted((x(0), x(v)))
        f.out.append(f'<rect x="{x0:.1f}" y="{yy:.1f}" width="{x1-x0:.1f}" height="{step*0.6:.1f}" rx="1.5" '
                     f'fill="{col}" opacity="0.85"/>')
        anchor, tx = ('start', x(0) + 4) if v < 0 else ('end', x(0) - 4)
        f.out.append(f'<text x="{tx:.1f}" y="{yy+step*0.42:.1f}" text-anchor="{anchor}">{a["short"]}</text>')
    f.out.append(f'<text class="annoS" x="{f.l}" y="{f.t-4}">loss density vs network median — diverging bars: direction reads before magnitude</text>')
    return f.done()


def stacked_decade():
    rows = sorted(D.AREAS, key=lambda a: -a["total"])[:10]
    f = F(470, 260, t=16, b=26)
    x = f.sx(0, len(rows))
    ymax = max(a["total"] for a in rows) * 1.08
    y = f.sy(0, ymax)
    f.grid_y(x, y, (0, 20000, 40000, 60000), fmt=lambda v: f'{v//1000}k' if v else '0', unit="ha")
    bw = f.pw / len(rows) * 0.6
    for i, a in enumerate(rows):
        d1, d2 = sum(a["loss"][:11]), sum(a["loss"][11:])
        cx = x(i + 0.5)
        f.out.append(f'<rect x="{cx-bw/2:.1f}" y="{y(d1):.1f}" width="{bw:.1f}" height="{y(0)-y(d1):.1f}" '
                     f'fill="#C9B458" opacity="0.9"><title>2001–11: {d1:,} ha</title></rect>')
        f.out.append(f'<rect x="{cx-bw/2:.1f}" y="{y(d1+d2):.1f}" width="{bw:.1f}" height="{y(d1)-y(d1+d2):.1f}" '
                     f'fill="#D97742" opacity="0.9"><title>2012–23: {d2:,} ha</title></rect>')
        f.xt(x, i + 0.5, a["short"])
    f.baseline()
    return f.done()


# ═══════════════════ CLIMATE ═══════════════════
def climograph(short="NCA"):
    P, Tm = D.CLIMATE[short]
    f = F(470, 176, r=42, t=16)
    x = f.sx(0, 12)
    y = f.sy(0, 360)
    yt = f.sy(0, 24)
    f.grid_y(x, y, (0, 100, 200, 300), unit="mm")
    for gv in (0, 10, 20):
        f.out.append(f'<text x="{f.w-f.r+5}" y="{yt(gv)+2.5:.1f}" fill="#D95F44">{gv}</text>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r+5}" y="{f.t-4}" fill="#D95F44">°C</text>')
    bw = f.pw / 12 * 0.62
    for i, p in enumerate(P):
        f.out.append(f'<rect class="precip" x="{x(i+0.5)-bw/2:.1f}" y="{y(p):.1f}" width="{bw:.1f}" '
                     f'height="{y(0)-y(p):.1f}" rx="1.2" opacity="0.85"/>')
        f.xt(x, i + 0.5, D.MONTHS[i])
    pts = [(x(i + 0.5), yt(t)) for i, t in enumerate(Tm)]
    f.out.append(polyline(pts, 'temp'))
    for px, py in pts:
        f.out.append(f'<circle class="tempdot" cx="{px:.1f}" cy="{py:.1f}" r="1.7"/>')
    f.baseline()
    return f.done()


def normals_heatmap():
    shorts = list(D.CLIMATE.keys())
    W = 470
    L, T = 46, 24
    cw = (W - L - 66) / 12
    chh = 24
    H = T + len(shorts) * (chh + 3) + 40
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for j, m in enumerate(D.MONTHS):
        out.append(f'<text x="{L+j*cw+cw/2:.1f}" y="{T-6}" text-anchor="middle">{m}</text>')
    vmax = max(max(P) for P, _ in D.CLIMATE.values())
    for i, sh in enumerate(shorts):
        P, _ = D.CLIMATE[sh]
        out.append(f'<text x="{L-5}" y="{T+i*(chh+3)+chh/2+2.5:.1f}" text-anchor="end">{sh}</text>')
        for j, p in enumerate(P):
            a = 0.06 + 0.94 * (p / vmax) ** 0.7
            out.append(f'<rect x="{L+j*cw:.1f}" y="{T+i*(chh+3):.1f}" width="{cw-1.4:.1f}" height="{chh}" rx="2.5" '
                       f'fill="#4A90C2" opacity="{a:.2f}"><title>{sh} {D.MONTHS[j]}: {p} mm</title></rect>')
    out.append(f'<text class="annoS" x="{L}" y="{H-8}">monthly precip normals — bimodal north (MAM + ND) vs unimodal south/west, visible instantly</text>')
    out.append('</svg>')
    return ''.join(out)


def anomaly():
    f = F(470, 160, t=16, b=26)
    x = f.sx(0, len(D.ANOM_YEARS))
    y = f.sy(-30, 30)
    for gv in (-30, -15, 0, 15, 30):
        cls = 'ax' if gv == 0 else 'grid'
        f.out.append(f'<line class="{cls}" x1="{f.l}" y1="{y(gv):.1f}" x2="{f.w-f.r}" y2="{y(gv):.1f}"/>')
        f.out.append(f'<text x="{f.l-5}" y="{y(gv)+2.5:.1f}" text-anchor="end">{gv:+d}</text>')
    f.out.append(f'<text class="annoS" x="{f.l-30}" y="{f.t-4}">%</text>')
    bw = f.pw / len(D.ANOM_YEARS) * 0.6
    for i, (yr, v) in enumerate(zip(D.ANOM_YEARS, D.ANOMALY)):
        col = "#4A90C2" if v > 0 else "#C05A3A"
        y0, y1 = sorted((y(0), y(v)))
        f.out.append(f'<rect x="{x(i+0.5)-bw/2:.1f}" y="{y0:.1f}" width="{bw:.1f}" height="{y1-y0:.1f}" rx="1.2" fill="{col}"/>')
        f.xt(x, i + 0.5, str(yr)[2:])
    f.out.append(f'<text class="anno" x="{x(10.5):.1f}" y="{y(-21)+12:.1f}" text-anchor="middle">−21% drought</text>')
    return f.done()


def rain_rose():
    P, _ = D.CLIMATE["NCA"]
    W, H, cx, cy = 470, 260, 235, 132
    Rm = 96
    vmax = max(P)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for ring in (0.33, 0.66, 1.0):
        out.append(f'<circle cx="{cx}" cy="{cy}" r="{Rm*ring:.1f}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 28%,transparent)" stroke-width="0.8"/>')
    for i, p in enumerate(P):
        a0 = 2 * math.pi * i / 12 - math.pi / 2 + 0.03
        a1 = 2 * math.pi * (i + 1) / 12 - math.pi / 2 - 0.03
        r = 6 + p / vmax * (Rm - 6)
        x0, y0 = cx + r * math.cos(a0), cy + r * math.sin(a0)
        x1, y1 = cx + r * math.cos(a1), cy + r * math.sin(a1)
        out.append(f'<path d="M{cx} {cy} L{x0:.1f} {y0:.1f} A{r:.1f} {r:.1f} 0 0 1 {x1:.1f} {y1:.1f} Z" '
                   f'fill="#4A90C2" opacity="0.8"><title>{D.MONTHS[i]}: {p} mm</title></path>')
        lx = cx + (Rm + 13) * math.cos((a0 + a1) / 2)
        ly = cy + (Rm + 13) * math.sin((a0 + a1) / 2)
        out.append(f'<text x="{lx:.1f}" y="{ly+2.5:.1f}" text-anchor="middle">{D.MONTHS[i]}</text>')
    out.append(f'<text class="annoS" x="14" y="{H-24}">polar/rose chart (wind-rose family):</text>')
    out.append(f'<text class="annoS" x="14" y="{H-10}">the year is a cycle — seasonality reads as shape</text>')
    out.append('</svg>')
    return ''.join(out)


def density2d():
    pts = [(t, p) for sh in D.CLIMATE for p, t in zip(*[D.CLIMATE[sh][0], D.CLIMATE[sh][1]])]
    f = F(470, 250, t=16, b=30)
    x = f.sx(7, 29)
    y = f.sy(0, 360)
    f.grid_y(x, y, (0, 100, 200, 300), unit="mm")
    for gv in (10, 15, 20, 25):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-70}" y="{f.h-6}">mean °C</text>')
    cells = 26
    grid_v = [[0.0] * cells for _ in range(cells)]
    for t, p in pts:
        for gi in range(cells):
            for gj in range(cells):
                gt = 7 + gi / (cells - 1) * 22
                gp = gj / (cells - 1) * 360
                grid_v[gi][gj] += math.exp(-(((gt - t) / 1.8) ** 2 + ((gp - p) / 34) ** 2) / 2)
    mx = max(max(r) for r in grid_v)
    for gi in range(cells):
        for gj in range(cells):
            v = grid_v[gi][gj] / mx
            if v > 0.06:
                px0 = x(7 + gi / (cells - 1) * 22)
                py0 = y(gj / (cells - 1) * 360)
                f.out.append(f'<rect x="{px0-4.5:.1f}" y="{py0-4.5:.1f}" width="9" height="9" '
                             f'fill="#4A90C2" opacity="{v*0.75:.2f}"/>')
    for t, p in pts:
        f.out.append(f'<circle cx="{x(t):.1f}" cy="{y(p):.1f}" r="1.6" fill="var(--tx)" opacity="0.65"/>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+8}">2-D density (KDE raster) of monthly climate states across 6 PAs — two climate regimes emerge as blobs</text>')
    return f.done()


def jointplot():
    rng = random.Random(13)
    pts = []
    for a in D.AREAS:
        for v in a["loss"][1:]:
            pts.append((a["rain"] * (0.9 + 0.2 * rng.random()), math.log10(max(v, 1))))
    W, H = 470, 300
    mL, mT, mS = 46, 54, 44
    pw, ph = W - mL - 14, H - mT - 30
    f_out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    xlo, xhi, ylo, yhi = 400, 2400, 0, 4
    X = lambda v: mL + (v - xlo) / (xhi - xlo) * pw
    Y = lambda v: mT + ph - (v - ylo) / (yhi - ylo) * ph
    for gv in (500, 1000, 1500, 2000):
        f_out.append(f'<line class="grid" x1="{X(gv):.1f}" y1="{mT}" x2="{X(gv):.1f}" y2="{mT+ph}"/>')
        f_out.append(f'<text x="{X(gv):.1f}" y="{H-16}" text-anchor="middle">{gv}</text>')
    for gv in (0, 2, 4):
        f_out.append(f'<line class="grid" x1="{mL}" y1="{Y(gv):.1f}" x2="{mL+pw}" y2="{Y(gv):.1f}"/>')
        f_out.append(f'<text x="{mL-5}" y="{Y(gv)+2.5:.1f}" text-anchor="end">10{"⁰¹²³⁴"[gv]}</text>')
    for px, py in pts:
        f_out.append(f'<circle cx="{X(px):.1f}" cy="{Y(py):.1f}" r="1.5" fill="#4A90C2" opacity="0.35"/>')
    hx = [0] * 18
    for px, _ in pts:
        hx[min(17, int((px - xlo) / (xhi - xlo) * 18))] += 1
    mh = max(hx)
    bw = pw / 18
    for i, hv in enumerate(hx):
        hh = hv / mh * (mS - 10)
        f_out.append(f'<rect x="{mL+i*bw:.1f}" y="{mT-6-hh:.1f}" width="{bw-1:.1f}" height="{hh:.1f}" '
                     f'fill="#4A90C2" opacity="0.6"/>')
    f_out.append(f'<text class="annoS" x="{mL}" y="14">joint plot (seaborn jointplot): scatter + marginal histogram — relationship and distributions in one figure</text>')
    f_out.append(f'<text class="annoS" x="{mL+pw-104}" y="{H-4}">annual rainfall mm</text>')
    f_out.append('</svg>')
    return ''.join(f_out)


def facets_temp():
    shorts = list(D.CLIMATE.keys())
    W = 470
    cw2, chh = (W - 56) / 3, 84
    H = int(2 * chh + 58)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for idx, sh in enumerate(shorts):
        r, c = divmod(idx, 3)
        ox, oy = 44 + c * cw2, 16 + r * (chh + 14)
        _, Tm = D.CLIMATE[sh]
        out.append(f'<rect x="{ox}" y="{oy}" width="{cw2-10:.1f}" height="{chh-16}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 25%,transparent)"/>')
        pts = [(ox + 3 + i / 11 * (cw2 - 16), oy + chh - 18 - (t - 8) / 20 * (chh - 22))
               for i, t in enumerate(Tm)]
        out.append(polyline(pts, style='stroke="#D95F44" stroke-width="1.3"'))
        out.append(f'<text x="{ox+4:.1f}" y="{oy+11}" class="ttl">{sh}</text>')
        if c == 0:
            out.append(f'<text x="{ox-6}" y="{oy+12}" text-anchor="end" class="annoS">28°</text>')
            out.append(f'<text x="{ox-6}" y="{oy+chh-18}" text-anchor="end" class="annoS">8°</text>')
    out.append(f'<text class="annoS" x="44" y="{H-8}">small multiples / facets (ggplot facet_wrap) — same axes everywhere, so only the data differs</text>')
    out.append('</svg>')
    return ''.join(out)


def streamgraph():
    f = F(470, 210, t=18, b=24)
    x = f.sx(2012, 2023)
    totals = [sum(D.STREAM[sh][i] for sh in D.STREAM_PAS) for i in range(len(D.STREAM_YEARS))]
    ymax = max(totals)
    y = f.sy(-ymax / 2 * 1.1, ymax / 2 * 1.1)
    base = [-t / 2 for t in totals]
    for si, sh in enumerate(D.STREAM_PAS):
        top = [b + v for b, v in zip(base, D.STREAM[sh])]
        up = [(x(yr), y(t)) for yr, t in zip(D.STREAM_YEARS, top)]
        dn = [(x(yr), y(b)) for yr, b in reversed(list(zip(D.STREAM_YEARS, base)))]
        d = 'M' + ' L'.join(f'{px:.1f},{py:.1f}' for px, py in up + dn) + ' Z'
        f.out.append(f'<path d="{d}" fill="{CAT[si]}" opacity="0.8"><title>{sh}</title></path>')
        mid_i = 4 if sh != "NYE" else 6
        f.out.append(f'<text x="{x(D.STREAM_YEARS[mid_i]):.1f}" '
                     f'y="{y((base[mid_i]+top[mid_i])/2)+2:.1f}" text-anchor="middle" fill="#141D18" '
                     f'font-weight="700">{sh}</text>')
        base = top
    for yr in (2012, 2016, 2020, 2023):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l}" y="{f.t-6}">fire detections by PA — streamgraph: totals AND shares flow together; the 2016/2021 fire years bulge</text>')
    return f.done()


def proj_ribbons():
    f = F(470, 220, t=18, b=26)
    x = f.sx(2015, 2080)
    y = f.sy(0, 6)
    f.grid_y(x, y, (0, 2, 4, 6), fmt=lambda v: f'+{v}°', unit="vs 1981–2010")
    for name, (mid, half, col) in D.PROJ.items():
        up = [(x(yr), y(v + half)) for yr, v in zip(D.PROJ_YEARS, mid)]
        dn = [(x(yr), y(max(0, v - half))) for yr, v in reversed(list(zip(D.PROJ_YEARS, mid)))]
        d = 'M' + ' L'.join(f'{px:.1f},{py:.1f}' for px, py in up + dn) + ' Z'
        f.out.append(f'<path d="{d}" fill="{col}" opacity="0.18"/>')
        f.out.append(polyline([(x(yr), y(v)) for yr, v in zip(D.PROJ_YEARS, mid)],
                              style=f'stroke="{col}" stroke-width="1.6"'))
        f.out.append(f'<text x="{x(2080)-2:.1f}" y="{y(mid[-1])-4:.1f}" text-anchor="end" fill="{col}" '
                     f'font-weight="700">{name}</text>')
    for yr in (2020, 2040, 2060, 2080):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">projection ribbons: the band IS the message — uncertainty drawn, never dropped (CMIP6-style)</text>')
    f.baseline()
    return f.done()


# ═══════════════════ LAND COVER ═══════════════════
def sankey():
    W, H = 470, 320
    L, R, T, B = 96, 96, 26, 30
    ph = H - T - B
    froms, tos = {}, {}
    for a, b, v in D.SANKEY:
        froms[a] = froms.get(a, 0) + v
        tos[b] = tos.get(b, 0) + v
    total = sum(v for _, _, v in D.SANKEY)
    colmap = dict((n, c) for n, c in D.LC_CLASSES)
    def stack(d):
        ys, run = {}, T
        for n in [n for n, _ in D.LC_CLASSES if n in d]:
            h = d[n] / total * ph
            ys[n] = (run, run + h)
            run += h + 4
        return ys
    fy, ty = stack(froms), stack(tos)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    foff = {n: fy[n][0] for n in fy}
    toff = {n: ty[n][0] for n in ty}
    for a, b, v in D.SANKEY:
        h = v / total * ph
        y0, y1 = foff[a], toff[b]
        foff[a] += h; toff[b] += h
        c = colmap[a]
        x0, x1 = L + 12, W - R - 12
        mx = (x0 + x1) / 2
        d = (f'M{x0} {y0:.1f} C{mx} {y0:.1f} {mx} {y1:.1f} {x1} {y1:.1f} '
             f'L{x1} {y1+h:.1f} C{mx} {y1+h:.1f} {mx} {y0+h:.1f} {x0} {y0+h:.1f} Z')
        op = 0.55 if a == b else 0.8
        out.append(f'<path d="{d}" fill="{c}" opacity="{op if a==b else 0.85}" '
                   f'{"" if a==b else f"stroke={chr(34)}var(--cv){chr(34)} stroke-width={chr(34)}0.5{chr(34)}"}>'
                   f'<title>{a} → {b}: {v} km²</title></path>')
    for n, (y0, y1) in fy.items():
        out.append(f'<rect x="{L+4}" y="{y0:.1f}" width="8" height="{y1-y0:.1f}" fill="{colmap[n]}"/>')
        out.append(f'<text x="{L-2}" y="{(y0+y1)/2+2.5:.1f}" text-anchor="end">{n}</text>')
    for n, (y0, y1) in ty.items():
        out.append(f'<rect x="{W-R-12}" y="{y0:.1f}" width="8" height="{y1-y0:.1f}" fill="{colmap[n]}"/>')
        out.append(f'<text x="{W-R+2}" y="{(y0+y1)/2+2.5:.1f}">{n}</text>')
    out.append(f'<text class="ttl" x="{L+12}" y="{T-10}">2000</text>')
    out.append(f'<text class="ttl" x="{W-R-40}" y="{T-10}">2020</text>')
    out.append(f'<text class="annoS" x="{L-50}" y="{H-8}">land-cover transitions, NCA (plotly sankey) — crossing ribbons ARE the change; forest→cropland is the one to watch</text>')
    out.append('</svg>')
    return ''.join(out)


def sunburst():
    tree = [("Savanna", "#C9B458", [("Grassland", 61), ("Shrubland", 17)]),
            ("Woodland", "#3E7A45", [("Forest", 11)]),
            ("Human", "#C98A4B", [("Cropland", 5), ("Built-up", 1)]),
            ("Other", "#7E939B", [("Bare", 3), ("Water", 1), ("Wetland", 1)])]
    W, H, cx, cy = 470, 280, 150, 142
    r0, r1, r2 = 34, 72, 112
    total = sum(v for _, _, kids in tree for _, v in kids)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    a0 = -math.pi / 2
    ly = 44
    for name, col, kids in tree:
        seg = sum(v for _, v in kids)
        a1 = a0 + seg / total * 2 * math.pi
        out.append(_arc(cx, cy, r0, r1, a0, a1, col) + f'<title>{name} {seg}%</title></path>')
        ka = a0
        for kn, kv in kids:
            ka1 = ka + kv / total * 2 * math.pi
            out.append(_arc(cx, cy, r1 + 2, r2, ka, ka1, col).replace('opacity="0.9"', 'opacity="0.55"')
                       + f'<title>{kn} {kv}%</title></path>')
            ka = ka1
        out.append(f'<rect x="292" y="{ly-8}" width="10" height="10" rx="2" fill="{col}"/>')
        out.append(f'<text x="308" y="{ly}">{name} · {seg}%</text>')
        for kn, kv in kids:
            ly += 16
            out.append(f'<text x="308" y="{ly}" class="annoS">— {kn} {kv}%</text>')
        ly += 24
    out.append(f'<text x="{cx}" y="{cy+3}" text-anchor="middle" class="ttl">NCA</text>')
    out.append(f'<text class="annoS" x="14" y="{H-10}">sunburst: hierarchy as nested rings — biome → class (plotly sunburst / matplotlib nested pie)</text>')
    out.append('</svg>')
    return ''.join(out)


def stacked_area():
    keys = ["Forest", "Shrubland", "Grassland", "Cropland", "Other"]
    cols = {"Forest": "#3E7A45", "Shrubland": "#8FA35F", "Grassland": "#C9B458",
            "Cropland": "#C98A4B", "Other": "#B0A79A"}
    f = F(470, 210, t=18, b=26)
    x = f.sx(2000, 2020)
    y = f.sy(0, 100)
    f.grid_y(x, y, (0, 25, 50, 75, 100), unit="%")
    base = [0.0] * len(D.LC_TREND_YEARS)
    for k in keys:
        top = [b + v for b, v in zip(base, D.LC_TREND[k])]
        up = [(x(yr), y(t)) for yr, t in zip(D.LC_TREND_YEARS, top)]
        dn = [(x(yr), y(b)) for yr, b in reversed(list(zip(D.LC_TREND_YEARS, base)))]
        d = 'M' + ' L'.join(f'{px:.1f},{py:.1f}' for px, py in up + dn) + ' Z'
        f.out.append(f'<path d="{d}" fill="{cols[k]}" opacity="0.85"><title>{k}</title></path>')
        base = top
    for yr in D.LC_TREND_YEARS:
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="anno" x="{x(2010):.1f}" y="{y(97)+10:.1f}" text-anchor="middle" fill="#141D18" font-weight="700">cropland creep: 3.6% → 5.8%</text>')
    f.baseline()
    return f.done()


def pct_stacked_bars():
    shorts = list(D.LANDCOVER.keys())
    f = F(470, 250, l=48, t=16, b=40)
    x = f.sx(0, 100)
    step = f.ph / len(shorts)
    for gv in (0, 25, 50, 75, 100):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.xt(x, gv, f'{gv}%')
    for i, sh in enumerate(shorts):
        yy = f.t + i * step + step * 0.16
        run = 0
        for (cname, col), v in zip(D.LC_CLASSES, D.LANDCOVER[sh]):
            if v <= 0:
                continue
            f.out.append(f'<rect x="{x(run):.1f}" y="{yy:.1f}" width="{x(run+v)-x(run):.1f}" '
                         f'height="{step*0.66:.1f}" fill="{col}" opacity="0.9"><title>{sh} {cname}: {v}%</title></rect>')
            run += v
        f.out.append(f'<text x="{f.l-5}" y="{yy+step*0.44:.1f}" text-anchor="end">{sh}</text>')
    lx = f.l
    for cname, col in D.LC_CLASSES[:6]:
        f.out.append(f'<rect x="{lx}" y="{f.h-22}" width="8" height="8" rx="2" fill="{col}"/>')
        f.out.append(f'<text x="{lx+12}" y="{f.h-15}">{cname}</text>')
        lx += 12 + 5.4 * len(cname) + 12
    return f.done()


# ═══════════════════ STATISTICS ═══════════════════
def regplot():
    xs = [a["forest_pct"] for a in D.AREAS]
    ys = [math.log10(max(a["mean"], 1)) for a in D.AREAS]
    n = len(xs)
    mx, my = sum(xs) / n, sum(ys) / n
    sxx = sum((x - mx) ** 2 for x in xs)
    b1 = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sxx
    b0 = my - b1 * mx
    resid = [y - (b0 + b1 * x) for x, y in zip(xs, ys)]
    s2 = sum(r * r for r in resid) / (n - 2)
    f = F(470, 250, t=18, b=30)
    X = f.sx(0, 95)
    Y = f.sy(0, 4)
    f.grid_y(X, Y, (0, 1, 2, 3, 4), fmt=lambda v: f'10{"⁰¹²³⁴"[int(v)]}', unit="mean loss ha")
    for gv in (0, 25, 50, 75):
        f.xt(X, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-80}" y="{f.h-6}">forest %</text>')
    band_up, band_dn = [], []
    for gx in range(0, 96, 5):
        se = math.sqrt(s2 * (1 / n + (gx - mx) ** 2 / sxx))
        yv = b0 + b1 * gx
        band_up.append((X(gx), Y(min(4, yv + 1.96 * se))))
        band_dn.append((X(gx), Y(max(0, yv - 1.96 * se))))
    d = ('M' + ' L'.join(f'{px:.1f},{py:.1f}' for px, py in band_up + band_dn[::-1]) + ' Z')
    f.out.append(f'<path d="{d}" fill="#4A90C2" opacity="0.16"/>')
    f.out.append(polyline([(X(0), Y(b0)), (X(95), Y(b0 + b1 * 95))],
                          style='stroke="#4A90C2" stroke-width="1.6"'))
    for a, x0, y0 in zip(D.AREAS, xs, ys):
        col = ACC if a["real"] else "var(--tx)"
        f.out.append(f'<circle cx="{X(x0):.1f}" cy="{Y(y0):.1f}" r="2.6" fill="{col}" opacity="0.85">'
                     f'<title>{a["name"]}</title></circle>')
    r = D.pearson(xs, ys)
    f.out.append(f'<text class="anno" x="{f.l+6}" y="{f.t+10}">OLS + 95% CI (seaborn regplot) · r = {r:+.2f} — forest share alone barely predicts loss rate</text>')
    return f.done()


def loess_plot():
    xs = [float(y) for y in D.YEARS[1:]]
    ys = [float(v) for v in D.NCA_LOSS[1:]]
    sm = loess(xs, ys, 0.45)
    f = F(470, 200, t=18)
    x = f.sx(2002, 2023)
    y = f.sy(0, 200)
    f.grid_y(x, y, (0, 100, 200), unit="ha")
    for x0, y0 in zip(xs, ys):
        f.out.append(f'<circle cx="{x(x0):.1f}" cy="{y(min(y0,200)):.1f}" r="2.2" fill="#D97742" opacity="0.75"/>')
    f.out.append(polyline([(x(x0), y(max(0, min(s, 200)))) for x0, s in zip(xs, sm)],
                          style=f'stroke="{ACC}" stroke-width="2"'))
    for yr in (2002, 2009, 2016, 2023):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">LOESS smoother over the real NCA series (2001 excluded) — local regression shows the drift a straight line would fake</text>')
    f.baseline()
    return f.done()


def corr_heat(order=None, title=None):
    names, cols, corr = D.stat_matrix()
    idx = order if order else list(range(len(names)))
    W = 470
    L, T = 60, 46
    cs = 52
    H = T + cs * len(idx) + 34
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for i, ii in enumerate(idx):
        out.append(f'<text x="{L+i*cs+cs/2:.1f}" y="{T-8}" text-anchor="middle" class="ttl">{names[ii]}</text>')
        out.append(f'<text x="{L-6}" y="{T+i*cs+cs/2+3:.1f}" text-anchor="end" class="ttl">{names[ii]}</text>')
        for j, jj in enumerate(idx):
            v = corr[ii][jj]
            col = "#C05A3A" if v > 0 else "#4A90C2"
            op = abs(v) * 0.9 + 0.06
            out.append(f'<rect x="{L+j*cs:.1f}" y="{T+i*cs:.1f}" width="{cs-3}" height="{cs-3}" rx="4" '
                       f'fill="{col}" opacity="{op:.2f}"/>')
            tcol = "#141D18" if abs(v) > 0.55 else "var(--tx)"
            out.append(f'<text x="{L+j*cs+(cs-3)/2:.1f}" y="{T+i*cs+cs/2+2:.1f}" text-anchor="middle" '
                       f'fill="{tcol}" font-weight="600">{v:+.2f}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{H-8}">{title or "correlation matrix with values in-cell (seaborn heatmap annot=True)"}</text>')
    out.append('</svg>')
    return ''.join(out)


def clustermap():
    names, cols, corr = D.stat_matrix()
    n = len(names)
    dist = [[1 - abs(corr[i][j]) for j in range(n)] for i in range(n)]
    clusters = [[i] for i in range(n)]
    merges = []
    while len(clusters) > 1:
        best = (1e9, 0, 1)
        for i in range(len(clusters)):
            for j in range(i + 1, len(clusters)):
                d = min(dist[a][b] for a in clusters[i] for b in clusters[j])
                if d < best[0]:
                    best = (d, i, j)
        d, i, j = best
        merges.append((clusters[i][:], clusters[j][:], d))
        clusters[i] = clusters[i] + clusters[j]
        del clusters[j]
    order = clusters[0]
    heat = corr_heat(order, "clustermap: rows/cols reordered by single-linkage clustering — correlated variables become visible blocks")
    leaf_x = {v: 60 + order.index(v) * 52 + 24.5 for v in range(n)}
    dend = ['<svg class="ch" viewBox="0 0 470 60" xmlns="http://www.w3.org/2000/svg">']
    heights = {}
    y_base = 56
    for gi, (ca, cb, d) in enumerate(merges):
        xa = sum(leaf_x[v] for v in ca) / len(ca)
        xb = sum(leaf_x[v] for v in cb) / len(cb)
        ya = heights.get(tuple(sorted(ca)), y_base)
        yb = heights.get(tuple(sorted(cb)), y_base)
        yn = y_base - 12 - gi * 10
        dend.append(f'<path d="M{xa:.1f} {ya} V{yn} H{xb:.1f} V{yb}" fill="none" '
                    f'stroke="var(--fog)" stroke-width="1.1"/>')
        heights[tuple(sorted(ca + cb))] = yn
        for v in ca + cb:
            leaf_x[v] = leaf_x[v]
    dend.append('</svg>')
    return ''.join(dend) + heat


def pca_biplot():
    names, cols, _ = D.stat_matrix()
    mat = []
    for i in range(len(D.AREAS)):
        row = []
        for k in names:
            v = cols[k]
            m = sum(v) / len(v)
            s = (sum((x - m) ** 2 for x in v) / len(v)) ** 0.5 or 1
            row.append((v[i] - m) / s)
        mat.append(row)
    n, p = len(mat), len(names)
    cov = [[sum(mat[r][i] * mat[r][j] for r in range(n)) / n for j in range(p)] for i in range(p)]

    def power_iter(A, iters=300):
        v = [1.0] * p
        for _ in range(iters):
            w = [sum(A[i][j] * v[j] for j in range(p)) for i in range(p)]
            norm = math.sqrt(sum(x * x for x in w)) or 1
            v = [x / norm for x in w]
        lam = sum(v[i] * sum(A[i][j] * v[j] for j in range(p)) for i in range(p))
        return v, lam
    v1, l1 = power_iter(cov)
    cov2 = [[cov[i][j] - l1 * v1[i] * v1[j] for j in range(p)] for i in range(p)]
    v2, l2 = power_iter(cov2)
    tot = sum(cov[i][i] for i in range(p))
    scores = [(sum(r[i] * v1[i] for i in range(p)), sum(r[i] * v2[i] for i in range(p))) for r in mat]
    f = F(470, 280, t=20, b=30)
    smax = max(max(abs(s[0]), abs(s[1])) for s in scores) * 1.2
    X = f.sx(-smax, smax)
    Y = f.sy(-smax, smax)
    f.out.append(f'<line class="grid" x1="{X(0):.1f}" y1="{f.t}" x2="{X(0):.1f}" y2="{f.t+f.ph}"/>')
    f.out.append(f'<line class="grid" x1="{f.l}" y1="{Y(0):.1f}" x2="{f.w-f.r}" y2="{Y(0):.1f}"/>')
    for (sx_, sy_), a in zip(scores, D.AREAS):
        col = ACC if a["real"] else "#4A90C2"
        f.out.append(f'<circle cx="{X(sx_):.1f}" cy="{Y(sy_):.1f}" r="2.8" fill="{col}" opacity="0.85">'
                     f'<title>{a["name"]}</title></circle>')
        if a["short"] in ("NYE", "GOM", "NCA", "KIL", "SER", "MKO"):
            f.out.append(f'<text x="{X(sx_):.1f}" y="{Y(sy_)-5:.1f}" text-anchor="middle">{a["short"]}</text>')
    for i, k in enumerate(names):
        ax_, ay_ = v1[i] * smax * 0.8, v2[i] * smax * 0.8
        f.out.append(f'<line x1="{X(0):.1f}" y1="{Y(0):.1f}" x2="{X(ax_):.1f}" y2="{Y(ay_):.1f}" '
                     f'stroke="#D97742" stroke-width="1.2" opacity="0.8"/>')
        f.out.append(f'<text x="{X(ax_*1.12):.1f}" y="{Y(ay_*1.12):.1f}" text-anchor="middle" '
                     f'fill="#D97742" font-weight="700">{k}</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">PCA biplot — PC1 {l1/tot*100:.0f}% + PC2 {l2/tot*100:.0f}% of variance; arrows = variable loadings, dots = the 16 PAs</text>')
    return f.done()


def ecdf():
    vals = sorted(math.log10(max(v, 1)) for v in D.NCA_LOSS[1:])
    n = len(vals)
    f = F(470, 190, t=18)
    x = f.sx(0, 3)
    y = f.sy(0, 1)
    f.grid_y(x, y, (0, 0.5, 1), fmt=lambda v: f'{v:.1f}', unit="F(x)")
    pts = []
    for i, v in enumerate(vals):
        pts.append((x(v), y(i / n)))
        pts.append((x(v), y((i + 1) / n)))
    f.out.append(polyline(pts, style='stroke="#4A90C2" stroke-width="1.6"'))
    med = quantile(vals, 0.5)
    f.out.append(f'<line class="ref" x1="{x(med):.1f}" y1="{y(0):.1f}" x2="{x(med):.1f}" y2="{y(0.5):.1f}"/>')
    f.out.append(f'<line class="ref" x1="{f.l}" y1="{y(0.5):.1f}" x2="{x(med):.1f}" y2="{y(0.5):.1f}"/>')
    f.out.append(f'<text class="anno" x="{x(med)+5:.1f}" y="{y(0.5)+3:.1f}">median = {10**med:.0f} ha</text>')
    for gv in (0, 1, 2, 3):
        f.xt(x, gv, f'10{"⁰¹²³"[gv]}')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">ECDF — every point visible, no bin choices to argue about (seaborn ecdfplot); read any percentile directly</text>')
    f.baseline()
    return f.done()


def hist_kde():
    vals = [math.log10(max(v, 1)) for a in D.AREAS for v in a["loss"]]
    f = F(470, 200, t=18)
    x = f.sx(0, 4.2)
    bins = [0] * 21
    for v in vals:
        bins[min(20, int(v / 4.2 * 21))] += 1
    ymax = max(bins) * 1.15
    y = f.sy(0, ymax)
    f.grid_y(x, y, (0, 20, 40), unit="years")
    bw = f.pw / 21
    for i, b in enumerate(bins):
        f.out.append(f'<rect x="{f.l+i*bw:.1f}" y="{y(b):.1f}" width="{bw-1.2:.1f}" height="{y(0)-y(b):.1f}" '
                     f'fill="#D97742" opacity="0.55"/>')
    grid = [i / 80 * 4.2 for i in range(81)]
    dens = kde(vals, grid, 0.22)
    scale = len(vals) * 4.2 / 21
    f.out.append(polyline([(x(g), y(min(ymax, d * scale))) for g, d in zip(grid, dens)],
                          style='stroke="var(--tx)" stroke-width="1.6"'))
    for gv in (0, 1, 2, 3, 4):
        f.xt(x, gv, f'10{"⁰¹²³⁴"[gv]}')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">histogram + KDE overlay of all 368 PA-years (log ha) — bins for honesty, curve for shape</text>')
    f.baseline()
    return f.done()


def hexbin():
    rng = random.Random(21)
    pts = []
    for a in D.AREAS:
        for _ in range(max(3, int(a["mean"] ** 0.5))):
            pts.append((a["elev"] * (0.85 + 0.3 * rng.random()),
                        math.log10(max(1, rng.lognormvariate(math.log(max(a["mean"], 2) / 8 + 0.5), 0.8)))))
    f = F(470, 250, t=18, b=30)
    x = f.sx(0, 3400)
    y = f.sy(0, 2.6)
    s = 13.0
    counts = {}
    for px, py in pts:
        gx, gy = x(px), y(py)
        q = round(gx / (s * 1.5))
        r = round((gy - (q % 2) * s * 0.87) / (s * 1.73))
        counts[(q, r)] = counts.get((q, r), 0) + 1
    mx = max(counts.values())
    for (q, r), c in counts.items():
        cx = q * s * 1.5
        cy = r * s * 1.73 + (q % 2) * s * 0.87
        if cx < f.l or cx > f.w - f.r or cy < f.t or cy > f.t + f.ph:
            continue
        hexpts = ' '.join(f'{cx+s*0.92*math.cos(math.pi/6+i*math.pi/3):.1f},'
                          f'{cy+s*0.92*math.sin(math.pi/6+i*math.pi/3):.1f}' for i in range(6))
        heat = (c / mx) ** 0.65
        col = FIRE_RAMP[min(6, int(heat * 6.99))]
        f.out.append(f'<polygon points="{hexpts}" fill="{col}" opacity="0.85"><title>{c} patches</title></polygon>')
    for gv in (0, 1000, 2000, 3000):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-90}" y="{f.h-6}">elevation m</text>')
    f.out.append(f'<text class="annoS" x="{f.l-30}" y="{f.t-4}">patch ha (log)</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+8}">hexbin (matplotlib hexbin): thousands of loss patches without overplotting — lowland big-patch cluster is obvious</text>')
    return f.done()


def qq():
    vals = sorted(math.log(max(v, 1)) for v in D.NCA_LOSS[1:])
    n = len(vals)
    m = sum(vals) / n
    sd = (sum((v - m) ** 2 for v in vals) / (n - 1)) ** 0.5
    f = F(470, 250, t=18, b=30)
    lo = min(vals) - 0.5
    hi = max(vals) + 0.5
    x = f.sx(lo, hi)
    y = f.sy(lo, hi)
    f.out.append(polyline([(x(lo), y(lo)), (x(hi), y(hi))], style='stroke="var(--fog)" stroke-width="1" stroke-dasharray="4 3"'))
    for i, v in enumerate(vals):
        q = norminv((i + 0.5) / n) * sd + m
        f.out.append(f'<circle cx="{x(q):.1f}" cy="{y(v):.1f}" r="2.4" fill="#4A90C2" opacity="0.85"/>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+8}">Q–Q plot vs fitted lognormal — points hug the line, so "loss is lognormal" survives; the tails wobble first</text>')
    f.out.append(f'<text class="annoS" x="{f.w-130}" y="{f.h-6}">theoretical quantiles</text>')
    f.out.append(f'<text class="annoS" x="{f.l-32}" y="{f.t-4}">sample</text>')
    return f.done()


def connected_scatter():
    rng = random.Random(17)
    yrs = list(range(2012, 2024))
    losses = D.NCA_LOSS[11:]
    anoms = D.ANOMALY
    f = F(470, 250, t=18, b=30)
    x = f.sx(-28, 28)
    y = f.sy(0, 200)
    f.grid_y(x, y, (0, 100, 200), unit="loss ha")
    f.out.append(f'<line class="ax" x1="{x(0):.1f}" y1="{f.t}" x2="{x(0):.1f}" y2="{f.t+f.ph}"/>')
    for gv in (-20, 0, 20):
        f.xt(x, gv, f'{gv:+d}%')
    f.out.append(f'<text class="annoS" x="{f.w-120}" y="{f.h-6}">rainfall anomaly</text>')
    pts = [(x(a), y(min(200, l))) for a, l in zip(anoms, losses)]
    f.out.append(polyline(pts, style='stroke="color-mix(in srgb,var(--fog) 55%,transparent)" stroke-width="1"'))
    for (px, py), yr in zip(pts, yrs):
        f.out.append(f'<circle cx="{px:.1f}" cy="{py:.1f}" r="2.6" fill="#D97742"/>')
        if yr in (2013, 2016, 2017, 2022, 2023):
            f.out.append(f'<text x="{px+4:.1f}" y="{py-4:.1f}">{yr}</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t+8}">connected scatter: loss vs rainfall anomaly, joined in time order — dry years drift left AND up (fire link)</text>')
    return f.done()
