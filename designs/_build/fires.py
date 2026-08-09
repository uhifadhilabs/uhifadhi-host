"""Fire-management module: Serengeti prescribed-burn program idioms."""
import base64
import math
import os
import random

from charts import F, polyline, FIRE_RAMP

RNG = random.Random(31)
TILES = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'tiles')
BURN_YEARS = list(range(2012, 2024))
# km2 burned: (prescribed, wildfire) — early dry-season program vs late wild fires
BURNED = {2012: (2100, 1350), 2013: (2350, 980), 2014: (2600, 720), 2015: (2450, 810),
          2016: (2200, 1650), 2017: (2500, 940), 2018: (2700, 620), 2019: (2750, 560),
          2020: (1900, 1150), 2021: (2300, 1500), 2022: (2650, 700), 2023: (2800, 480)}

# compartments: (col,row) grid position, years since last burn
COMPS = []
for r in range(5):
    for c in range(6):
        ys = RNG.choice([0, 0, 1, 1, 1, 2, 2, 3, 4, 6])
        COMPS.append((c, r, ys))

SEV = [("Unburned island", "#8FA35F"), ("Low (dNBR<.1)", "#C9B458"),
       ("Moderate", "#FD8D3C"), ("High (dNBR>.44)", "#BD0026")]
BLOCKS = ["B02", "B05", "B08", "B11", "B14", "B17", "B21", "B23"]
SEV_MIX = {b: [RNG.uniform(8, 18), RNG.uniform(34, 50), RNG.uniform(24, 38),
               RNG.uniform(3, 22 if b in ("B11", "B21") else 9)] for b in BLOCKS}


def _b64(p):
    with open(p, 'rb') as fh:
        return 'data:image/jpeg;base64,' + base64.b64encode(fh.read()).decode()


def _mosaic(w=768, fltr=None, idp=""):
    out = []
    for xi, x in enumerate((304, 305, 306)):
        for yi, y in enumerate((258, 259, 260)):
            p = os.path.join(TILES, f'ser_{x}_{y}.jpg')
            if os.path.exists(p):
                f = f' filter="url(#{idp})"' if fltr else ''
                out.append(f'<image x="{xi*256}" y="{yi*256}" width="256" height="256" href="{_b64(p)}"{f}/>')
    return ''.join(out)


def ser_map():
    """Real Serengeti mosaic + burn-block plan + active detections."""
    out = ['<svg viewBox="0 0 768 768" style="width:100%;height:auto;display:block;border-radius:9px" '
           'xmlns="http://www.w3.org/2000/svg">']
    out.append(_mosaic())
    out.append('<rect x="0" y="0" width="768" height="768" fill="#060a08" opacity="0.18"/>')
    gx, gy, cw, chh = 150, 170, 74, 74
    ramp = ["#E05B41", "#DBA33F", "#C9B458", "#8FA35F", "#5E8B57", "#3E6B47"]
    for c, r, ys in COMPS:
        col = ramp[min(5, ys)]
        x, y = gx + c * (cw + 5), gy + r * (chh + 5)
        op = 0.5 if ys == 0 else 0.34
        out.append(f'<rect x="{x}" y="{y}" width="{cw}" height="{chh}" rx="4" fill="{col}" opacity="{op}" '
                   f'stroke="{col}" stroke-width="1.6"><title>block {r*6+c+1:02d} · burned {ys} season(s) ago</title></rect>')
        if ys == 0:
            out.append(f'<text x="{x+cw/2}" y="{y+chh/2+4}" text-anchor="middle" font-size="13" fill="#fff" '
                       f'style="paint-order:stroke;stroke:#0A0F0C;stroke-width:3" '
                       f'font-family="JetBrains Mono,monospace">burning</text>')
    for _ in range(26):
        c, r, ys = RNG.choice([k for k in COMPS if k[2] == 0])
        px = gx + c * (cw + 5) + RNG.uniform(6, cw - 6)
        py = gy + r * (chh + 5) + RNG.uniform(6, chh - 6)
        out.append(f'<circle cx="{px:.0f}" cy="{py:.0f}" r="3.2" fill="#FF5A2A" stroke="#FFD23C" stroke-width="1.2"/>')
    mpp = 156543.03 * math.cos(math.radians(-2.4)) / 512
    w40 = 40000 / mpp
    out.append(f'<g><rect x="30" y="732" width="{w40:.0f}" height="6" fill="none" stroke="#F5F7F3" stroke-width="1.4"/>'
               f'<rect x="30" y="732" width="{w40/4:.0f}" height="6" fill="#F5F7F3"/>'
               f'<rect x="{30+w40/2:.0f}" y="732" width="{w40/4:.0f}" height="6" fill="#F5F7F3"/>'
               f'<text x="{38+w40:.0f}" y="739" font-size="14" fill="#F5F7F3" stroke="#0A0F0C" stroke-width="3" '
               f'paint-order="stroke" font-family="JetBrains Mono,monospace">40 km</text></g>')
    out.append('<g><rect x="470" y="700" width="272" height="52" rx="8" fill="rgba(8,13,10,.78)"/>'
               '<circle cx="486" cy="716" r="4" fill="#FF5A2A" stroke="#FFD23C"/>'
               '<text x="498" y="720" font-size="12" fill="#F5F7F3" font-family="JetBrains Mono,monospace">VIIRS detection (24 h)</text>'
               '<rect x="480" y="730" width="11" height="11" rx="2" fill="#E05B41" opacity="0.7"/>'
               '<text x="498" y="740" font-size="12" fill="#F5F7F3" font-family="JetBrains Mono,monospace">block colour = seasons since burn</text></g>')
    out.append('</svg>')
    return ''.join(out)


def before_after():
    """Same real imagery twice: pre-burn vs post-burn with scars — the split-pane idiom."""
    out = ['<svg viewBox="0 0 968 500" style="width:100%;height:auto;display:block" '
           'xmlns="http://www.w3.org/2000/svg">',
           '<defs><filter id="postburn"><feColorMatrix type="matrix" '
           'values="0.9 0.05 0 0 -0.02  0.06 0.82 0 0 -0.03  0.03 0.03 0.72 0 -0.02  0 0 0 1 0"/></filter>'
           '<clipPath id="cl-a"><rect x="0" y="0" width="470" height="470" rx="9"/></clipPath>'
           '<clipPath id="cl-b"><rect x="0" y="0" width="470" height="470" rx="9"/></clipPath></defs>']
    out.append('<g clip-path="url(#cl-a)"><g transform="scale(0.612)">' + _mosaic() + '</g></g>')
    out.append('<g transform="translate(498,0)"><g clip-path="url(#cl-b)">'
               '<g transform="scale(0.612)" filter="url(#postburn)">' + _mosaic() + '</g>')
    for _ in range(14):
        cx, cy = RNG.uniform(90, 400), RNG.uniform(110, 400)
        pts = []
        n = RNG.randint(6, 9)
        for i in range(n):
            a = i / n * 2 * math.pi
            r = RNG.uniform(14, 44)
            pts.append(f'{cx+r*math.cos(a):.0f},{cy+r*0.7*math.sin(a):.0f}')
        out.append(f'<polygon points="{" ".join(pts)}" fill="#1A140F" opacity="{RNG.uniform(0.45,0.7):.2f}"/>')
    out.append('</g>')
    for lab, x in (("June — pre-burn", 16), ("September — burn scars", 514)):
        out.append(f'<text x="{x}" y="30" font-size="16" fill="#F5F7F3" stroke="#0A0F0C" stroke-width="3.5" '
                   f'paint-order="stroke" font-family="JetBrains Mono,monospace">{lab}</text>')
    out.append('</svg>')
    return ''.join(out)


def burned_stacked():
    f = F(470, 220, t=18, b=26)
    x = f.sx(0, len(BURN_YEARS))
    y = f.sy(0, 4400)
    f.grid_y(x, y, (0, 1000, 2000, 3000, 4000), fmt=lambda v: f'{v//1000}k' if v else '0', unit="km²")
    bw = f.pw / len(BURN_YEARS) * 0.62
    for i, yr in enumerate(BURN_YEARS):
        p, w = BURNED[yr]
        cx = x(i + 0.5)
        f.out.append(f'<rect x="{cx-bw/2:.1f}" y="{y(p):.1f}" width="{bw:.1f}" height="{y(0)-y(p):.1f}" '
                     f'fill="#C9B458" opacity="0.92"><title>{yr} prescribed: {p} km²</title></rect>')
        f.out.append(f'<rect x="{cx-bw/2:.1f}" y="{y(p+w):.1f}" width="{bw:.1f}" height="{y(p)-y(p+w):.1f}" '
                     f'fill="#E05B41" opacity="0.92"><title>{yr} wildfire: {w} km²</title></rect>')
        if yr % 2 == 0:
            f.xt(x, i + 0.5, str(yr)[2:])
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">prescribed (straw) vs wildfire (ember): more early-season burning → less late wildfire, visibly</text>')
    f.baseline()
    return f.done()


def burn_progress():
    f = F(470, 230, t=18, b=30)
    x = f.sx(0, 20)
    y = f.sy(0, 100)
    f.grid_y(x, y, (0, 25, 50, 75, 100), unit="% of plan")
    f.out.append(f'<line class="ref" x1="{f.l}" y1="{y(90):.1f}" x2="{f.w-f.r}" y2="{y(90):.1f}"/>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r-2}" y="{y(90)-4:.1f}" text-anchor="end">target: 90% before late-dry season</text>')
    for yr, col, wid in ((2021, "color-mix(in srgb,var(--fog) 45%,transparent)", 1),
                         (2022, "color-mix(in srgb,var(--fog) 65%,transparent)", 1),
                         (2023, "var(--acc)", 2)):
        rng2 = random.Random(yr)
        cum, pts = 0.0, []
        for wk in range(21):
            rate = max(0, rng2.gauss(7, 3.5)) if 2 <= wk <= 16 else max(0, rng2.gauss(1.5, 1))
            cum = min(100, cum + rate)
            pts.append((x(wk), y(cum)))
        f.out.append(polyline(pts, style=f'stroke="{col}" stroke-width="{wid}"'))
        f.out.append(f'<text x="{pts[-1][0]-4:.1f}" y="{pts[-1][1]-5:.1f}" text-anchor="end" '
                     f'fill="{col}" font-weight="700">{yr}</text>')
    for wk in (0, 5, 10, 15, 20):
        f.xt(x, wk, f'wk {wk}')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">burn-program progress curves, week of season — operations tracking as a bump-free race chart</text>')
    f.baseline()
    return f.done()


def time_since_hist():
    counts = [0] * 7
    for _, _, ys in COMPS:
        counts[min(6, ys)] += 1
    f = F(470, 190, t=18, b=30)
    x = f.sx(0, 7)
    y = f.sy(0, max(counts) * 1.25)
    f.grid_y(x, y, (0, 5, 10), unit="blocks")
    ramp = ["#E05B41", "#DBA33F", "#C9B458", "#8FA35F", "#5E8B57", "#3E6B47", "#2E5138"]
    bw = f.pw / 7 * 0.68
    for i, c in enumerate(counts):
        f.out.append(f'<rect x="{x(i+0.5)-bw/2:.1f}" y="{y(c):.1f}" width="{bw:.1f}" height="{y(0)-y(c):.1f}" '
                     f'rx="2" fill="{ramp[i]}" opacity="0.92"/>')
        f.xt(x, i + 0.5, "now" if i == 0 else f'{i}y')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">fire-return distribution across 30 blocks — the 3-year rotation shows as mass at 0–2; the 6y+ tail is fuel load</text>')
    f.baseline()
    return f.done()


def severity_stacked():
    f = F(470, 230, l=52, t=18, b=38)
    x = f.sx(0, 100)
    step = f.ph / len(BLOCKS)
    for gv in (0, 25, 50, 75, 100):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.xt(x, gv, f'{gv}%')
    for i, b in enumerate(BLOCKS):
        mix = SEV_MIX[b]
        tot = sum(mix)
        yy = f.t + i * step + step * 0.18
        run = 0.0
        for (name, col), v in zip(SEV, mix):
            pct = v / tot * 100
            f.out.append(f'<rect x="{x(run):.1f}" y="{yy:.1f}" width="{x(run+pct)-x(run):.1f}" '
                         f'height="{step*0.62:.1f}" fill="{col}" opacity="0.92"><title>{b} {name}: {pct:.0f}%</title></rect>')
            run += pct
        f.out.append(f'<text x="{f.l-5}" y="{yy+step*0.42:.1f}" text-anchor="end">{b}</text>')
    lx = f.l
    for name, col in SEV:
        f.out.append(f'<rect x="{lx}" y="{f.h-20}" width="8" height="8" rx="2" fill="{col}"/>')
        f.out.append(f'<text x="{lx+12}" y="{f.h-13}">{name}</text>')
        lx += 12 + 5.1 * len(name) + 12
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">dNBR burn severity per block (Landsat pre/post) — patchy low-severity mosaics are the management GOAL</text>')
    return f.done()


def frp_ecdf():
    rng2 = random.Random(6)
    presc = sorted(min(300, rng2.lognormvariate(2.1, 0.7)) for _ in range(160))
    wild = sorted(min(300, rng2.lognormvariate(3.1, 0.9)) for _ in range(120))
    f = F(470, 210, t=18, b=30)
    x = f.sx(0, 2.6)
    y = f.sy(0, 1)
    f.grid_y(x, y, (0, 0.5, 1), fmt=lambda v: f'{v:.1f}', unit="F(x)")
    for vals, col, lab in ((presc, "#C9B458", "prescribed"), (wild, "#E05B41", "wildfire")):
        pts = []
        n = len(vals)
        for i, v in enumerate(vals):
            lx = math.log10(max(v, 1))
            pts.append((x(lx), y(i / n)))
            pts.append((x(lx), y((i + 1) / n)))
        f.out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.5"'))
        f.out.append(f'<text x="{x(math.log10(vals[len(vals)//2])):.1f}" y="{y(0.52):.1f}" '
                     f'fill="{col}" font-weight="700">{lab}</text>')
    for gv in (0, 1, 2):
        f.xt(x, gv, f'10{"⁰¹²"[gv]}')
    f.out.append(f'<text class="annoS" x="{f.w-90}" y="{f.h-6}">FRP MW (log)</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">fire radiative power, two ECDFs — prescribed burns run cool; the wildfire curve\'s hot tail is the argument for the program</text>')
    f.baseline()
    return f.done()


def fire_rose():
    month_det = [4, 6, 18, 8, 30, 190, 420, 520, 300, 90, 12, 5]
    W, H, cx, cy, Rm = 470, 280, 168, 142, 100
    vmax = max(month_det)
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for ring in (0.33, 0.66, 1.0):
        out.append(f'<circle cx="{cx}" cy="{cy}" r="{Rm*ring:.1f}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 28%,transparent)" stroke-width="0.8"/>')
    M = "JFMAMJJASOND"
    for i, v in enumerate(month_det):
        a0 = 2 * math.pi * i / 12 - math.pi / 2 + 0.03
        a1 = 2 * math.pi * (i + 1) / 12 - math.pi / 2 - 0.03
        r = 6 + (v / vmax) ** 0.6 * (Rm - 6)
        heat = (v / vmax) ** 0.5
        col = FIRE_RAMP[min(6, int(heat * 6.99))]
        x0, y0 = cx + r * math.cos(a0), cy + r * math.sin(a0)
        x1, y1 = cx + r * math.cos(a1), cy + r * math.sin(a1)
        out.append(f'<path d="M{cx} {cy} L{x0:.1f} {y0:.1f} A{r:.1f} {r:.1f} 0 0 1 {x1:.1f} {y1:.1f} Z" '
                   f'fill="{col}" opacity="0.88"><title>{M[i]}: {v}</title></path>')
        lx = cx + (Rm + 13) * math.cos((a0 + a1) / 2)
        ly = cy + (Rm + 13) * math.sin((a0 + a1) / 2)
        out.append(f'<text x="{lx:.1f}" y="{ly+2.5:.1f}" text-anchor="middle">{M[i]}</text>')
    out.append(f'<text class="annoS" x="330" y="70">the season as shape:</text>')
    out.append(f'<text class="annoS" x="330" y="84">Jun–Sep fan = program +</text>')
    out.append(f'<text class="annoS" x="330" y="98">late-dry wildfire tail</text>')
    out.append('</svg>')
    return ''.join(out)


def rain_burn_scatter():
    rng2 = random.Random(15)
    f = F(470, 230, t=18, b=30)
    x = f.sx(500, 1100)
    y = f.sy(0, 2000)
    f.grid_y(x, y, (0, 500, 1000, 1500, 2000), fmt=lambda v: f'{v//1000}k' if v >= 1000 else str(v), unit="wildfire km²")
    pts = []
    for yr in BURN_YEARS:
        rain = 800 + rng2.gauss(0, 130)
        wild = BURNED[yr][1] + rng2.gauss(0, 60)
        pts.append((rain, wild, yr))
    xs = [p[0] for p in pts]
    ys = [p[1] for p in pts]
    n = len(xs)
    mx, my = sum(xs) / n, sum(ys) / n
    b1 = sum((a - mx) * (b - my) for a, b, _ in pts) / sum((a - mx) ** 2 for a in xs)
    b0 = my - b1 * mx
    f.out.append(polyline([(x(560), y(max(0, b0 + b1 * 560))), (x(1060), y(max(0, b0 + b1 * 1060)))],
                          style='stroke="#4A90C2" stroke-width="1.4" stroke-dasharray="5 3"'))
    for rain, wild, yr in pts:
        f.out.append(f'<circle cx="{x(rain):.1f}" cy="{y(wild):.1f}" r="3" fill="#E05B41" opacity="0.85">'
                     f'<title>{yr}</title></circle>')
        if yr in (2016, 2021, 2023):
            f.out.append(f'<text x="{x(rain)+5:.1f}" y="{y(wild)-4:.1f}">{yr}</text>')
    for gv in (600, 800, 1000):
        f.xt(x, gv, str(gv))
    f.out.append(f'<text class="annoS" x="{f.w-140}" y="{f.h-6}">prior wet-season rain mm</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">wet years grow grass, grass carries fire — the fuel-load regression that justifies scaling the program by rainfall</text>')
    return f.done()
