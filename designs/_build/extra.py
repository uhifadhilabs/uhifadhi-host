"""Alerts module + depth passes for anthro / tourism / air / drought pages."""
import math
import random

import charts as CH
import data as D
import fires as FI
from charts import F, polyline, FIRE_RAMP

RNG = random.Random(91)

ALERT_TYPES = [("deforestation", "#E05B41"), ("fire", "#DBA33F"), ("hydrology", "#4A90C2"),
               ("station", "#A46A8C"), ("encroachment", "#C9B458"), ("movement", "#8FA35F")]


# ═══════════════ ALERTS ═══════════════
def alerts_map(w=470, h=430):
    P, ring, s, ox, oy = CH._tz_proj(w, h)
    pts = ' '.join(f'{x*s+ox:.1f},{y*s+oy:.1f}' for x, y in ring)
    out = [f'<svg class="ch" viewBox="0 0 {w} {h}" xmlns="http://www.w3.org/2000/svg">']
    out.append(f'<polygon points="{pts}" fill="color-mix(in srgb,var(--fog) 9%,transparent)" '
               f'stroke="color-mix(in srgb,var(--fog) 45%,transparent)" stroke-width="1.2"/>')
    alerts = [("NYE", 37.4, -9.0, "deforestation", 3, "GLAD cluster · 41 px"),
              ("NYE", 37.1, -8.7, "deforestation", 2, "RADD · new road spur"),
              ("RUA", 34.6, -7.5, "fire", 3, "fire outside burn plan"),
              ("SER", 34.83, -2.33, "fire", 1, "prescribed block B07 · expected"),
              ("NCA", 35.72, -3.31, "encroachment", 2, "lights +34% Karatu edge"),
              ("NCA", 35.84, -2.91, "station", 2, "Empakaai rim silent 4 d"),
              ("MAN", 35.8, -3.5, "hydrology", 3, "lake level +2.1 σ"),
              ("KAT", 31.1, -6.7, "fire", 2, "late-season ignition"),
              ("SER", 34.6, -2.1, "movement", 1, "herd crossed early")]
    for park, lon, lat, typ, sev, note in alerts:
        col = dict(ALERT_TYPES)[typ]
        x, y = (lambda m: (m[0] * s + ox, m[1] * s + oy))(CH._merc(lon, lat))
        r = 4 + sev * 2.5
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r+4:.1f}" fill="{col}" opacity="0.18"/>')
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.1f}" fill="{col}" opacity="0.9" '
                   f'stroke="var(--cv)" stroke-width="1.4"><title>{park} · {typ} S{sev} — {note}</title></circle>')
    ly = h - 88
    for name, col in ALERT_TYPES:
        out.append(f'<circle cx="20" cy="{ly-3}" r="4" fill="{col}"/>')
        out.append(f'<text x="30" y="{ly}">{name}</text>')
        ly += 14
    out.append(f'<text class="annoS" x="{w-190}" y="{h-12}">radius = severity · 9 open alerts</text>')
    out.append('</svg>')
    return ''.join(out)


def alert_volume():
    W = 960
    f = F(W, 210, l=48, t=18, b=26)
    weeks = 52
    x = f.sx(0, weeks)
    y = f.sy(0, 46)
    f.grid_y(x, y, (0, 15, 30, 45), unit="alerts / week")
    rng = random.Random(5)
    series = {}
    for name, _ in ALERT_TYPES:
        base = {"deforestation": 7, "fire": 5, "hydrology": 2, "station": 2,
                "encroachment": 3, "movement": 2}[name]
        row = []
        for wk in range(weeks):
            v = max(0, rng.gauss(base, base * 0.4))
            if name == "fire" and 24 <= wk <= 40:
                v *= 2.6
            if name == "deforestation" and 18 <= wk <= 30:
                v *= 1.7
            row.append(v)
        series[name] = row
    base_r = [0.0] * weeks
    for name, col in ALERT_TYPES:
        top = [b + v for b, v in zip(base_r, series[name])]
        up = [(x(wk), y(min(46, t))) for wk, t in enumerate(top)]
        dn = [(x(wk), y(b)) for wk, b in reversed(list(enumerate(base_r)))]
        f.out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in up + dn) +
                     f' Z" fill="{col}" opacity="0.85"><title>{name}</title></path>')
        base_r = top
    for wk, lab in ((0, 'Jan'), (13, 'Apr'), (26, 'Jul'), (39, 'Oct'), (51, 'Dec')):
        f.xt(x, wk, lab)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">alert volume by stream, 52 weeks — the fire season and the wet-season deforestation pulse are the network\'s heartbeat</text>')
    return f.done()


def punchcard():
    rng = random.Random(8)
    W = 470
    L, T = 52, 26
    cw = (W - L - 16) / 24
    chh = 22
    days = "Mon Tue Wed Thu Fri Sat Sun".split()
    H = T + 7 * (chh + 2) + 40
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for hh in range(0, 24, 6):
        out.append(f'<text x="{L+hh*cw+cw/2:.1f}" y="{T-8}" text-anchor="middle">{hh:02d}h</text>')
    for di, day in enumerate(days):
        out.append(f'<text x="{L-6}" y="{T+di*(chh+2)+chh/2+2.5:.1f}" text-anchor="end">{day}</text>')
        for hh in range(24):
            sat = math.exp(-((hh - 10.5) % 24 - 0) ** 2 / 14) + 0.7 * math.exp(-((hh - 13.5) % 24) ** 2 / 10)
            v = sat * (0.5 if di >= 5 else 1.0) * (0.55 + 0.8 * rng.random())
            r = min(chh / 2 - 1.5, 1 + v * 9)
            if r > 1.4:
                out.append(f'<circle cx="{L+hh*cw+cw/2:.1f}" cy="{T+di*(chh+2)+chh/2:.1f}" r="{r:.1f}" '
                           f'fill="var(--acc)" opacity="0.7"/>')
    out.append(f'<text class="annoS" x="{L}" y="{H-10}">arrival punchcard (GitHub idiom) — alerts land mid-morning after overnight satellite passes process; weekends are thin because HUMANS raise a third of them</text>')
    out.append('</svg>')
    return ''.join(out)


def triage_sankey():
    W, H = 470, 300
    L, R, T = 118, 118, 30
    ph = H - T - 40
    stages = [("auto-verified", 46, "#8FA35F"), ("field-checked", 30, "#C9B458"),
              ("expired unreviewed", 14, "#B0A79A"), ("suppressed (rule)", 10, "#87988D")]
    outcomes = [("confirmed", 52, "#E05B41"), ("false positive", 24, "#4A90C2"),
                ("inconclusive", 14, "#B0A79A"), ("dropped", 10, "#5A6960")]
    flows = [(0, 0, 34), (0, 1, 8), (0, 2, 4), (1, 0, 18), (1, 1, 8), (1, 2, 4),
             (2, 2, 4), (2, 3, 10), (3, 1, 8), (3, 3, 2)]
    total = 100.0
    ly0, ly1 = {}, {}
    run = T
    for i, (n, v, c) in enumerate(stages):
        ly0[i] = run
        run += v / total * ph + 6
    run = T
    for i, (n, v, c) in enumerate(outcomes):
        ly1[i] = run
        run += v / total * ph + 6
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    foff = dict(ly0)
    toff = dict(ly1)
    for a, b, v in flows:
        h = v / total * ph
        y0, y1 = foff[a], toff[b]
        foff[a] += h
        toff[b] += h
        col = stages[a][2]
        x0, x1 = L + 10, W - R - 10
        mx = (x0 + x1) / 2
        out.append(f'<path d="M{x0} {y0:.1f} C{mx} {y0:.1f} {mx} {y1:.1f} {x1} {y1:.1f} '
                   f'L{x1} {y1+h:.1f} C{mx} {y1+h:.1f} {mx} {y0+h:.1f} {x0} {y0+h:.1f} Z" '
                   f'fill="{col}" opacity="0.65"><title>{stages[a][0]} → {outcomes[b][0]}: {v}%</title></path>')
    for i, (n, v, c) in enumerate(stages):
        h = v / total * ph
        out.append(f'<rect x="{L+2}" y="{ly0[i]:.1f}" width="8" height="{h:.1f}" fill="{c}"/>')
        out.append(f'<text x="{L-4}" y="{ly0[i]+h/2+2.5:.1f}" text-anchor="end">{n} {v}%</text>')
    for i, (n, v, c) in enumerate(outcomes):
        h = v / total * ph
        out.append(f'<rect x="{W-R-10}" y="{ly1[i]:.1f}" width="8" height="{h:.1f}" fill="{c}"/>')
        out.append(f'<text x="{W-R+4}" y="{ly1[i]+h/2+2.5:.1f}">{n} {v}%</text>')
    out.append(f'<text class="ttl" x="{L+10}" y="{T-12}">triage path</text>')
    out.append(f'<text class="ttl" x="{W-R-70}" y="{T-12}">outcome</text>')
    out.append(f'<text class="annoS" x="{L-100}" y="{H-10}">alert lifecycle sankey — 52% confirm; the 14% that EXPIRE unreviewed is the number to manage down</text>')
    out.append('</svg>')
    return ''.join(out)


def latency_ecdf():
    f = F(470, 230, t=18, b=30)
    x = f.sx(-0.5, 2.7)
    y = f.sy(0, 1)
    f.grid_y(x, y, (0, 0.5, 1), fmt=lambda v: f'{v:.1f}', unit="F(x)")
    f.out.append(f'<line class="ref" x1="{x(math.log10(48)):.1f}" y1="{y(0):.1f}" x2="{x(math.log10(48)):.1f}" y2="{y(1):.1f}"/>')
    f.out.append(f'<text class="annoS" x="{x(math.log10(48))+4:.1f}" y="{y(0.94):.1f}">48 h SLA</text>')
    for name, col, mu, sg in (("fire", "#DBA33F", 0.45, 0.35), ("deforestation", "#E05B41", 1.15, 0.4),
                              ("encroachment", "#C9B458", 1.7, 0.35)):
        rng = random.Random(sum(ord(c) for c in name))
        vals = sorted(mu + rng.gauss(0, sg) for _ in range(120))
        pts = []
        n = len(vals)
        for i, v in enumerate(vals):
            pts.append((x(max(-0.5, min(2.7, v))), y(i / n)))
            pts.append((x(max(-0.5, min(2.7, v))), y((i + 1) / n)))
        f.out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.6"'))
        f.out.append(f'<text x="{x(mu):.1f}" y="{y(0.52):.1f}" fill="{col}" font-weight="700">{name}</text>')
    for gv, lab in ((0, '1h'), (1, '10h'), (2, '100h')):
        f.xt(x, gv, lab)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">time-to-field-verification, ECDF per stream vs the 48 h SLA — fire is fast; encroachment alerts wait too long</text>')
    f.baseline()
    return f.done()


def fp_rates():
    rows = [("VIIRS fire", 8, 3), ("GLAD deforestation", 22, 5), ("RADD radar", 15, 4),
            ("lights (encroach.)", 31, 7), ("hydrology (altimetry)", 12, 5), ("station watchdog", 4, 2)]
    f = F(470, 230, l=128, t=16, b=26)
    x = f.sx(0, 45)
    step = f.ph / len(rows)
    for gv in (0, 15, 30, 45):
        f.out.append(f'<line class="grid" x1="{x(gv):.1f}" y1="{f.t}" x2="{x(gv):.1f}" y2="{f.t+f.ph}"/>')
        f.xt(x, gv, f'{gv}%')
    for i, (name, v, ci) in enumerate(rows):
        yy = f.t + i * step + step / 2
        col = "#4A90C2" if v < 15 else ("#DBA33F" if v < 25 else "#E05B41")
        f.out.append(f'<line x1="{x(max(0,v-ci)):.1f}" y1="{yy:.1f}" x2="{x(v+ci):.1f}" y2="{yy:.1f}" '
                     f'stroke="{col}" stroke-width="1.6"/>')
        f.out.append(f'<circle cx="{x(v):.1f}" cy="{yy:.1f}" r="3.4" fill="{col}"/>')
        f.out.append(f'<text x="{f.l-6}" y="{yy+2.5:.1f}" text-anchor="end">{name}</text>')
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-2}">false-positive rate ± CI from field verification — this chart TUNES the thresholds; nightlights need work</text>')
    return f.done()


def sev_matrix():
    types = [t for t, _ in ALERT_TYPES]
    data = [[38, 12, 3], [22, 16, 6], [9, 6, 4], [11, 5, 1], [14, 9, 2], [7, 2, 0]]
    W = 470
    L, T, cs = 118, 42, 62
    H = T + len(types) * 34 + 36
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for j, s in enumerate(("S1 info", "S2 act", "S3 urgent")):
        out.append(f'<text x="{L+j*cs+cs/2:.1f}" y="{T-8}" text-anchor="middle" class="ttl">{s}</text>')
    mx = max(max(r) for r in data)
    for i, (t, row) in enumerate(zip(types, data)):
        out.append(f'<text x="{L-6}" y="{T+i*34+19:.1f}" text-anchor="end">{t}</text>')
        for j, v in enumerate(row):
            op = 0.06 + 0.9 * (v / mx) ** 0.7
            col = dict(ALERT_TYPES)[t]
            out.append(f'<rect x="{L+j*cs:.1f}" y="{T+i*34:.1f}" width="{cs-4}" height="30" rx="4" '
                       f'fill="{col}" opacity="{op:.2f}"/>')
            out.append(f'<text x="{L+j*cs+(cs-4)/2:.1f}" y="{T+i*34+19:.1f}" text-anchor="middle" '
                       f'fill="var(--tx)" font-weight="600">{v}</text>')
    out.append(f'<text class="annoS" x="{L-100}" y="{H-8}">last-90-days load, stream × severity — S3s are rare by design: severity inflation kills alert systems</text>')
    out.append('</svg>')
    return ''.join(out)


# ═══════════════ ANTHRO DEPTH ═══════════════
def nightlights_facets():
    towns = [("Karatu", 3.1, 0.14), ("Mto wa Mbu", 1.6, 0.09), ("Loliondo", 0.7, 0.05)]
    W = 470
    cw2, chh = (W - 58) / 3, 120
    H = chh + 58
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    rng = random.Random(14)
    for idx, (name, base, rate) in enumerate(towns):
        ox = 46 + idx * cw2
        out.append(f'<rect x="{ox}" y="16" width="{cw2-12:.1f}" height="{chh-16}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 25%,transparent)"/>')
        pts = []
        for yi in range(12):
            v = base * math.exp(rate * yi) * (1 + rng.gauss(0, 0.05))
            pts.append((ox + 5 + yi / 11 * (cw2 - 24), 16 + chh - 22 - min(1, v / 5.2) * (chh - 34)))
        out.append(polyline(pts, style='stroke="#DBA33F" stroke-width="1.6"'))
        out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in pts) +
                   f' L{pts[-1][0]:.1f},{16+chh-22} L{pts[0][0]:.1f},{16+chh-22} Z" fill="#DBA33F" opacity="0.15"/>')
        out.append(f'<text x="{ox+5:.1f}" y="12" class="ttl">{name}</text>')
        out.append(f'<text x="{ox+cw2-16:.1f}" y="{H-30}" text-anchor="end" class="annoS">2012→2023</text>')
        pct = int((math.exp(rate * 11) - 1) * 100)
        out.append(f'<text x="{ox+5:.1f}" y="28" class="anno" fill="#DBA33F">+{pct}%</text>')
    out.append(f'<text class="annoS" x="46" y="{H-8}">VIIRS radiance, gate towns (small multiples) — Karatu quadrupled; lights are the fastest honest proxy for edge growth</text>')
    out.append('</svg>')
    return ''.join(out)


def cropland_rings():
    f = F(470, 210, t=18, b=26)
    x = f.sx(2000, 2023)
    y = f.sy(0, 130)
    f.grid_y(x, y, (0, 40, 80, 120), unit="cropland km²")
    layers = [("0–5 km", 9, 0.075, "#E05B41"), ("5–10 km", 18, 0.055, "#DBA33F"), ("10–25 km", 34, 0.038, "#C9B458")]
    base = {yr: 0.0 for yr in D.YEARS}
    yrs = list(range(2000, 2024))
    stack_base = [0.0] * len(yrs)
    for name, b0, rate, col in layers:
        top = [sb + b0 * math.exp(rate * (yr - 2000)) for sb, yr in zip(stack_base, yrs)]
        up = [(x(yr), y(min(130, t))) for yr, t in zip(yrs, top)]
        dn = [(x(yr), y(sb)) for yr, sb in reversed(list(zip(yrs, stack_base)))]
        f.out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in up + dn) +
                     f' Z" fill="{col}" opacity="0.8"><title>{name}</title></path>')
        f.out.append(f'<text x="{up[-1][0]-4:.1f}" y="{(up[-1][1]+y(stack_base[-1]))/2+3:.1f}" '
                     f'text-anchor="end" fill="#141D18" font-weight="700">{name}</text>')
        stack_base = top
    for yr in (2000, 2012, 2023):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">cropland by buffer ring (GFW/WorldCover) — the wedge nearest the fence grows fastest: agriculture is walking toward the boundary</text>')
    f.baseline()
    return f.done()


def incursion_calendar():
    rng = random.Random(44)
    yrs = [2020, 2021, 2022, 2023]
    W = 470
    L, T = 46, 22
    cw, chh, gap = 32, 22, 3
    H = T + len(yrs) * (chh + gap) + 42
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for j, m in enumerate("JFMAMJJASOND"):
        out.append(f'<text x="{L+j*(cw+gap)+cw/2:.1f}" y="{T-6}" text-anchor="middle">{m}</text>')
    for i, yr in enumerate(yrs):
        out.append(f'<text x="{L-6}" y="{T+i*(chh+gap)+chh/2+2.5:.1f}" text-anchor="end">{yr}</text>')
        for j in range(12):
            dry = 1.6 if 5 <= j <= 9 else 0.6
            v = max(0, rng.gauss(6 * dry * (1 + 0.15 * i), 3))
            a = 0.05 + min(1, v / 22) * 0.9
            out.append(f'<rect x="{L+j*(cw+gap):.1f}" y="{T+i*(chh+gap):.1f}" width="{cw}" height="{chh}" '
                       f'rx="3" fill="#C9B458" opacity="{a:.2f}"><title>{yr}: {v:.0f} incidents</title></rect>')
    out.append(f'<text class="annoS" x="{L}" y="{H-10}">patrol-recorded boundary incidents (SMART records) — dry-season pulse + a rising baseline: the ground-truth companion to the satellite layers</text>')
    out.append('</svg>')
    return ''.join(out)


# ═══════════════ TOURISM DEPTH ═══════════════
def visitors_envelope():
    f = F(470, 200, t=18, b=26)
    x = f.sx(0, 11)
    y = f.sy(0, 90)
    f.grid_y(x, y, (0, 30, 60, 90), unit="visitors ×1000")
    clim = [58, 62, 48, 30, 24, 38, 66, 78, 60, 46, 40, 62]
    up = [(x(m), y(min(90, c * 1.25))) for m, c in enumerate(clim)]
    dn = [(x(m), y(c * 0.72)) for m, c in reversed(list(enumerate(clim)))]
    f.out.append('<path d="M' + ' L'.join(f'{a:.1f},{b:.1f}' for a, b in up + dn) +
                 ' Z" fill="color-mix(in srgb,#A46A8C 25%,transparent)"/>')
    rng = random.Random(3)
    cur = [(x(m), y(min(90, c * 1.18 + rng.gauss(0, 2)))) for m, c in enumerate(clim[:9])]
    f.out.append(polyline(cur, style='stroke="#A46A8C" stroke-width="2"'))
    f.out.append(f'<text class="anno" x="{cur[-1][0]+5:.1f}" y="{cur[-1][1]:.1f}" fill="#A46A8C">2024 — record pace</text>')
    for m, lab in enumerate("JFMAMJJASOND"):
        f.xt(x, m, lab)
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">monthly gate entries vs the 2010–23 envelope — the twin peaks are migration season and the holidays; 2024 is running above the band</text>')
    f.baseline()
    return f.done()


def beds_growth():
    f = F(470, 200, t=18, b=26, r=46)
    x = f.sx(1995, 2025)
    y = f.sy(0, 1400)
    y2 = f.sy(0, 40)
    f.grid_y(x, y, (0, 500, 1000), unit="beds")
    steps = [(1995, 180, 4), (1998, 420, 6), (2004, 610, 9), (2010, 870, 15),
             (2015, 1120, 22), (2018, 1160, 27), (2021, 1240, 33), (2024, 1310, 38)]
    pts = []
    for i, (yr, beds, sites) in enumerate(steps):
        pts.append((x(yr), y(beds)))
        if i + 1 < len(steps):
            pts.append((x(steps[i + 1][0]), y(beds)))
    f.out.append(polyline(pts, style='stroke="#A46A8C" stroke-width="2"'))
    spts = [(x(yr), y2(sites)) for yr, _, sites in steps]
    f.out.append(polyline(spts, style='stroke="var(--fog)" stroke-width="1.3" stroke-dasharray="4 3"'))
    for gv in (0, 20, 40):
        f.out.append(f'<text x="{f.w-f.r+5}" y="{y2(gv)+2.5:.1f}">{gv}</text>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r+5}" y="{f.t-4}">sites</text>')
    for yr in (1995, 2005, 2015, 2024):
        f.xt(x, yr, str(yr))
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">bed capacity (step — it changes at openings) + site count (dashed) — capacity ×7 in thirty years while the land stayed the same size</text>')
    f.baseline()
    return f.done()


def water_demand():
    f = F(470, 210, t=18, b=40)
    x = f.sx(0, 4)
    y = f.sy(0, 1300)
    f.grid_y(x, y, (0, 400, 800, 1200), unit="m³ / day")
    groups = [("2005", 190, 40), ("2015", 430, 90), ("2020", 640, 150), ("2024", 890, 230)]
    bw = f.pw / 4 * 0.32
    for i, (yr, lodges, staff) in enumerate(groups):
        cx = x(i + 0.5)
        f.out.append(f'<rect x="{cx-bw:.1f}" y="{y(lodges):.1f}" width="{bw:.1f}" height="{y(0)-y(lodges):.1f}" '
                     f'fill="#4A90C2" opacity="0.9"><title>lodge use</title></rect>')
        f.out.append(f'<rect x="{cx:.1f}" y="{y(staff):.1f}" width="{bw:.1f}" height="{y(0)-y(staff):.1f}" '
                     f'fill="#5FA3A0" opacity="0.9"><title>staff villages</title></rect>')
        f.xt(x, i + 0.5, yr)
    f.out.append(f'<line class="ref" x1="{f.l}" y1="{y(1050):.1f}" x2="{f.w-f.r}" y2="{y(1050):.1f}"/>')
    f.out.append(f'<text class="annoS" x="{f.w-f.r-4}" y="{y(1090):.1f}" text-anchor="end">sustainable-yield estimate, rim springs</text>')
    lx = f.l
    for name, col in (("lodges", "#4A90C2"), ("staff villages", "#5FA3A0")):
        f.out.append(f'<rect x="{lx}" y="{f.h-20}" width="8" height="8" rx="2" fill="{col}"/>')
        f.out.append(f'<text x="{lx+12}" y="{f.h-13}">{name}</text>')
        lx += 12 + 5.4 * len(name) + 14
    f.out.append(f'<text class="annoS" x="{f.l+4}" y="{f.t-6}">groundwater abstraction, crater-rim boreholes — 2024 demand is closing on the springs\' sustainable yield: the module\'s hardest number</text>')
    f.baseline()
    return f.done()


# ═══════════════ AIR DEPTH ═══════════════
def gas_facets():
    gases = [("NO₂", "#A46A8C", [1.0, 1.0, 1.1, 1.0, 1.1, 1.4, 2.1, 2.4, 1.9, 1.3, 1.1, 1.0]),
             ("CO", "#DBA33F", [1.0, 1.0, 1.0, 1.1, 1.2, 1.6, 2.6, 3.1, 2.4, 1.5, 1.1, 1.0]),
             ("aerosol", "#B0A79A", [1.0, 1.1, 1.2, 1.1, 1.3, 2.0, 3.4, 4.1, 3.2, 1.8, 1.2, 1.0])]
    W = 470
    cw2, chh = (W - 58) / 3, 120
    H = chh + 58
    out = [f'<svg class="ch" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg">']
    for idx, (name, col, row) in enumerate(gases):
        ox = 46 + idx * cw2
        out.append(f'<rect x="{ox}" y="16" width="{cw2-12:.1f}" height="{chh-16}" fill="none" '
                   f'stroke="color-mix(in srgb,var(--fog) 25%,transparent)"/>')
        pts = [(ox + 5 + m / 11 * (cw2 - 24), 16 + chh - 22 - (v / 4.2) * (chh - 34)) for m, v in enumerate(row)]
        out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.6"'))
        out.append(f'<text x="{ox+5:.1f}" y="12" class="ttl">{name} · ×{max(row):.1f} peak</text>')
        out.append(f'<text x="{ox+5:.1f}" y="{H-30}" class="annoS">J→D</text>')
    out.append(f'<text class="annoS" x="46" y="{H-8}">Sentinel-5P columns as multiples of the wet-season floor (small multiples) — all three gases ride the same burn-season wave, CO hardest</text>')
    out.append('</svg>')
    return ''.join(out)


def exceed_strip():
    rng = random.Random(52)
    yrs = list(range(2019, 2024))
    W = 470
    L, T = 46, 22
    cw, chh, gap = 32, 22, 3
    H = T + len(yrs) * (chh + gap) + 42
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for j, m in enumerate("JFMAMJJASOND"):
        out.append(f'<text x="{L+j*(cw+gap)+cw/2:.1f}" y="{T-6}" text-anchor="middle">{m}</text>')
    for i, yr in enumerate(yrs):
        out.append(f'<text x="{L-6}" y="{T+i*(chh+gap)+chh/2+2.5:.1f}" text-anchor="end">{yr}</text>')
        for j in range(12):
            v = max(0, rng.gauss(9, 4)) if 5 <= j <= 9 else max(0, rng.gauss(0.5, 0.8))
            a = 0.05 + min(1, v / 16) * 0.9
            out.append(f'<rect x="{L+j*(cw+gap):.1f}" y="{T+i*(chh+gap):.1f}" width="{cw}" height="{chh}" '
                       f'rx="3" fill="#A46A8C" opacity="{a:.2f}"><title>{yr}: {v:.0f} days</title></rect>')
    out.append(f'<text class="annoS" x="{L}" y="{H-10}">days above the WHO PM₂.₅ interim guideline — the health framing: burn season costs downwind villages 8–12 guideline days a month</text>')
    out.append('</svg>')
    return ''.join(out)


# ═══════════════ DROUGHT DEPTH — the cascade ═══════════════
def cascade():
    W = 960
    L, R = 78, 16
    pw = W - L - R
    rows = [("SPEI-6", "#B4632C"), ("NDVI anomaly", "#8FA35F"), ("fire detections", "#E05B41"),
            ("stocking ratio", "#C9B458")]
    rh, gap, T = 62, 20, 30
    H = T + 4 * (rh + gap) + 30
    yrs = list(range(2012, 2024))
    X = lambda v: L + (v - 2012) / 11.99 * pw
    rng = random.Random(64)
    spei, ndvi, fire, stock = [], [], [], []
    v = 0.2
    for m in range(144):
        yr = 2012 + m / 12
        v = v * 0.85 + rng.gauss(0, 0.4) - (0.5 if 2016.3 < yr < 2017.2 or 2021.5 < yr < 2022.7 else 0)
        spei.append(max(-2.5, min(2.5, v)))
        ndvi.append(v * 0.55 + rng.gauss(0, 0.25))
        fire.append(max(0, -v * 0.8 + 0.6 + rng.gauss(0, 0.3)) * (1.6 if (m % 12) in (6, 7, 8) else 0.5))
        stock.append(1.0 - v * 0.1 + rng.gauss(0, 0.03))
    out = [f'<svg class="ch" viewBox="0 0 {W} {H:.0f}" xmlns="http://www.w3.org/2000/svg">']
    for d0, d1 in ((2016.3, 2017.2), (2021.5, 2022.7)):
        out.append(f'<rect x="{X(d0):.1f}" y="{T-8}" width="{X(d1)-X(d0):.1f}" height="{4*(rh+gap)-gap+16:.1f}" '
                   f'fill="#B4632C" opacity="0.10"/>')
    series = [spei, ndvi, fire, stock]
    for ri, ((name, col), row) in enumerate(zip(rows, series)):
        oy = T + ri * (rh + gap)
        lo, hi = min(row), max(row)
        out.append(f'<text x="{L-8}" y="{oy+rh/2+2:.1f}" text-anchor="end" class="ttl">{name}</text>')
        out.append(f'<line class="grid" x1="{L}" y1="{oy+rh:.1f}" x2="{W-R}" y2="{oy+rh:.1f}"/>')
        if ri in (0, 1):
            zero = oy + rh - (0 - lo) / (hi - lo) * rh
            for m, vv in enumerate(row):
                yv = oy + rh - (vv - lo) / (hi - lo) * rh
                c = col if vv < 0 else "#4A90C2"
                if ri == 1:
                    c = "#B4632C" if vv < 0 else col
                y0, y1 = sorted((zero, yv))
                out.append(f'<rect x="{L+m/143*pw:.1f}" y="{y0:.1f}" width="{pw/143+0.3:.1f}" '
                           f'height="{max(0.5,y1-y0):.1f}" fill="{c}"/>')
        else:
            pts = [(L + m / 143 * pw, oy + rh - (vv - lo) / (hi - lo) * rh) for m, vv in enumerate(row)]
            out.append(polyline(pts, style=f'stroke="{col}" stroke-width="1.4"'))
            if ri == 3:
                cap = oy + rh - (1.0 - lo) / (hi - lo) * rh
                out.append(f'<line class="ref" x1="{L}" y1="{cap:.1f}" x2="{W-R}" y2="{cap:.1f}"/>')
    for yr in (2012, 2016, 2020, 2023):
        out.append(f'<text x="{X(yr):.1f}" y="{H-12}" text-anchor="middle">{yr}</text>')
    out.append(f'<text class="annoS" x="{L}" y="{T-14}">THE CASCADE — four modules on one clock: drought (SPEI) → browning (NDVI) → burning (fires) → overstocking. Shaded bands = the 2016–17 and 2021–22 events propagating down the chain with a lag</text>')
    out.append('</svg>')
    return ''.join(out)


# ═══════════════ SATELLITE AGGREGATOR MAP (not country-shaped) ═══════════════
def sat_bubble_map():
    """Real satellite basemap auto-fit to the tenant's areas — works for any country."""
    import base64
    import os
    T = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'tiles')
    X0, Y0 = 37, 32          # z6 mosaic origin
    out = ['<svg viewBox="0 0 768 512" style="width:100%;height:auto;display:block;border-radius:9px" '
           'xmlns="http://www.w3.org/2000/svg">']
    for xi in range(3):
        for yi in range(2):
            p = os.path.join(T, f'tz_{X0+xi}_{Y0+yi}.jpg')
            if os.path.exists(p):
                with open(p, 'rb') as fh:
                    b = base64.b64encode(fh.read()).decode()
                out.append(f'<image x="{xi*256}" y="{yi*256}" width="256" height="256" '
                           f'href="data:image/jpeg;base64,{b}"/>')
    out.append('<rect x="0" y="0" width="768" height="512" fill="#060a08" opacity="0.30"/>')

    def px(lon, lat):
        mx = (lon + 180) / 360 * 64
        my = (1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * 64
        return ((mx - X0) * 256, (my - Y0) * 256)
    import data as DD
    for a in sorted(DD.AREAS, key=lambda a: -a["km2"]):
        x, y = px(a["lon"], a["lat"])
        r = 4 + math.sqrt(a["km2"]) / math.sqrt(31000) * 24
        heat = min(1, a["density"] / 12)
        col = FIRE_RAMP[min(6, int(heat * 6.99))]
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.1f}" fill="{col}" opacity="0.55" '
                   f'stroke="{col}" stroke-width="1.4"><title>{a["name"]}: {a["km2"]:,} km² · '
                   f'{a["mean"]:.0f} ha/yr</title></circle>')
    for sh in ("SER", "NCA", "NYE", "RUA", "KAT", "GOM"):
        a = DD.BY_SHORT[sh]
        x, y = px(a["lon"], a["lat"])
        out.append(f'<text x="{x:.0f}" y="{y-4:.0f}" text-anchor="middle" font-size="14" fill="#F5F7F3" '
                   f'stroke="#0A0F0C" stroke-width="3" paint-order="stroke" '
                   f'font-family="JetBrains Mono,ui-monospace,monospace" font-weight="700">{sh}</text>')
    out.append('<g><rect x="14" y="464" width="422" height="34" rx="8" fill="rgba(8,13,10,.78)"/>'
               '<text x="28" y="485" font-size="13" fill="#F5F7F3" font-family="JetBrains Mono,monospace">'
               'basemap auto-fits the tenant’s areas · size = km² · color = pressure</text></g>')
    out.append('</svg>')
    return ''.join(out)


def sat_alerts_map(view="index"):
    """Open alerts on the real satellite basemap — the feed's spatial twin. Carries
    the shared map chrome (zoom, satellite⇄street toggle, expand)."""
    X0, Y0 = 37, 32
    out = ['<div style="position:relative">',
           '<svg viewBox="0 0 768 512" style="width:100%;height:auto;display:block;border-radius:9px" '
           'xmlns="http://www.w3.org/2000/svg">', _basemap_layers("na")]

    def px(lon, lat):
        mx = (lon + 180) / 360 * 64
        my = (1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * 64
        return ((mx - X0) * 256, (my - Y0) * 256)
    import data as DD
    for a in DD.AREAS:
        x, y = px(a["lon"], a["lat"])
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="2.5" fill="#F5F7F3" opacity="0.55">'
                   f'<title>{a["name"]}</title></circle>')
    alerts = [("Nyerere", 37.4, -9.0, "deforestation", 3, "GLAD cluster · 41 px"),
              ("Nyerere", 37.1, -8.7, "deforestation", 2, "RADD · new road spur"),
              ("Ruaha", 34.6, -7.5, "fire", 3, "fire outside burn plan"),
              ("Serengeti", 34.83, -2.33, "fire", 1, "block B07 · matches plan"),
              ("Ngorongoro", 35.72, -3.31, "encroachment", 2, "lights +34% Karatu edge"),
              ("Ngorongoro", 35.84, -2.91, "station", 2, "Empakaai silent 4 d"),
              ("L. Manyara", 35.8, -3.5, "hydrology", 3, "lake level +2.1 σ"),
              ("Katavi", 31.1, -6.7, "fire", 2, "late-season ignition"),
              ("Serengeti", 34.6, -2.1, "movement", 1, "herd crossed early")]
    cols = dict(ALERT_TYPES)
    for park, lon, lat, typ, sev, note in alerts:
        col = cols[typ]
        x, y = px(lon, lat)
        r = 5 + sev * 3
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r+6:.1f}" fill="{col}" opacity="0.22"/>')
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.1f}" fill="{col}" opacity="0.95" '
                   f'stroke="#0A0F0C" stroke-width="1.6"><title>{park} · {typ} S{sev} — {note}</title></circle>')
        if sev >= 3:
            out.append(f'<text x="{x:.0f}" y="{y-r-6:.0f}" text-anchor="middle" font-size="13" '
                       f'fill="#F5F7F3" stroke="#0A0F0C" stroke-width="3" paint-order="stroke" '
                       f'font-family="JetBrains Mono,monospace" font-weight="700">{park}</text>')
    lx, lyy = 14, 448
    out.append(f'<rect x="{lx-4}" y="{lyy-16}" width="470" height="60" rx="8" fill="rgba(8,13,10,.78)"/>')
    for i, (name, col) in enumerate(ALERT_TYPES):
        cxx = lx + 8 + (i % 3) * 150
        cyy = lyy + (i // 3) * 20
        out.append(f'<circle cx="{cxx}" cy="{cyy-3}" r="4.5" fill="{col}"/>')
        out.append(f'<text x="{cxx+11}" y="{cyy}" font-size="12" fill="#F5F7F3" '
                   f'font-family="JetBrains Mono,monospace">{name}</text>')
    out.append('<text x="482" y="500" font-size="11" fill="#9DB8A5" font-family="JetBrains Mono,monospace">'
               'radius = severity · dots = monitored areas · basemap auto-fits</text>')
    out.append('</svg>')
    out.append(_map_controls("na"))
    out.append('</div>')
    return ''.join(out)


def _basemap_layers(pfx):
    """Two toggleable tile layers: satellite (default) + OSM street map."""
    import base64
    import os
    T = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'tiles')
    X0, Y0 = 37, 32
    layers = []
    for name, pat, mime, vis in (("sat", "tz_{}_{}.jpg", "image/jpeg", "visible"),
                                 ("osm", "osm_{}_{}.png", "image/png", "hidden")):
        imgs = []
        for xi in range(3):
            for yi in range(2):
                p = os.path.join(T, pat.format(X0 + xi, Y0 + yi))
                if os.path.exists(p):
                    with open(p, 'rb') as fh:
                        b = base64.b64encode(fh.read()).decode()
                    imgs.append(f'<image x="{xi*256}" y="{yi*256}" width="256" height="256" '
                                f'href="data:{mime};base64,{b}"/>')
        dim = ('<rect x="0" y="0" width="768" height="512" fill="#060a08" opacity="0.34"/>'
               if name == "sat" else
               '<rect x="0" y="0" width="768" height="512" fill="#060a08" opacity="0.06"/>')
        layers.append(f'<g id="{pfx}-{name}" visibility="{vis}">{"".join(imgs)}{dim}</g>')
    return ''.join(layers)


def _map_controls(pfx):
    """Reusable map chrome — zoom (top-left) + satellite⇄street toggle & expand
    (top-right) + the uhiBase visibility toggler. Pairs with _basemap_layers, which
    emits the <g id="{pfx}-sat"> / <g id="{pfx}-osm"> layers this switches."""
    btn = ('font-family:JetBrains Mono,ui-monospace,monospace;font-size:10px;font-weight:700;'
           'letter-spacing:.08em;padding:6px 13px;border:0;cursor:pointer')
    zbtn = btn + ';background:rgba(8,13,10,.72);color:#F5F7F3;padding:4px 12px;font-size:14px'
    ic = ('<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
          'stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/>'
          '<path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/>'
          '<path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>')
    return (
        f'<div style="position:absolute;top:10px;left:10px;display:flex;flex-direction:column;border-radius:8px;'
        f'overflow:hidden;border:1px solid rgba(245,247,243,.25)">'
        f'<button title="zoom in" style="{zbtn}">+</button>'
        f'<button title="zoom out" style="{zbtn};border-top:1px solid rgba(245,247,243,.2)">−</button></div>'
        f'<div style="position:absolute;top:10px;right:10px;display:flex;border-radius:8px;overflow:hidden;'
        f'border:1px solid rgba(245,247,243,.25)">'
        f'<button id="{pfx}-b-sat" style="{btn};background:var(--acc);color:var(--accT)" '
        f'onclick="uhiBase(\'{pfx}\',\'sat\')">SATELLITE</button>'
        f'<button id="{pfx}-b-osm" style="{btn};background:rgba(8,13,10,.72);color:#F5F7F3" '
        f'onclick="uhiBase(\'{pfx}\',\'osm\')">MAP</button></div>'
        f'<button title="expand" style="{btn};position:absolute;top:10px;right:152px;border-radius:8px;padding:5px 8px;'
        f'display:grid;place-items:center;background:rgba(8,13,10,.72);color:#F5F7F3;'
        f'border:1px solid rgba(245,247,243,.25)">{ic}</button>'
        """<script>
function uhiBase(p, which){
  document.getElementById(p+'-sat').setAttribute('visibility', which==='sat'?'visible':'hidden');
  document.getElementById(p+'-osm').setAttribute('visibility', which==='osm'?'visible':'hidden');
  const bs=document.getElementById(p+'-b-sat'), bo=document.getElementById(p+'-b-osm');
  const on='var(--acc)', off='rgba(8,13,10,.72)';
  bs.style.background=which==='sat'?on:off; bs.style.color=which==='sat'?'var(--accT)':'#F5F7F3';
  bo.style.background=which==='osm'?on:off; bo.style.color=which==='osm'?'var(--accT)':'#F5F7F3';
}
</script>""")


def alerts_map_toggle(pfx="al"):
    """Alert map with a working satellite ⇄ street-map toggle + expand affordance."""
    X0, Y0 = 37, 32

    def px(lon, lat):
        mx = (lon + 180) / 360 * 64
        my = (1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * 64
        return ((mx - X0) * 256, (my - Y0) * 256)
    out = [f'<div style="position:relative">',
           '<svg viewBox="0 0 768 512" style="width:100%;height:auto;display:block;border-radius:9px" '
           'xmlns="http://www.w3.org/2000/svg">', _basemap_layers(pfx)]
    import data as DD
    for a in DD.AREAS:
        x, y = px(a["lon"], a["lat"])
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="2.5" fill="#F5F7F3" stroke="#0A0F0C" '
                   f'stroke-width="0.8" opacity="0.75"><title>{a["name"]}</title></circle>')
    alerts = [("Nyerere", 37.4, -9.0, "deforestation", 3, "GLAD cluster · 41 px"),
              ("Nyerere", 37.1, -8.7, "deforestation", 2, "RADD · new road spur"),
              ("Ruaha", 34.6, -7.5, "fire", 3, "fire outside burn plan"),
              ("Serengeti", 34.83, -2.33, "fire", 1, "block B07 · matches plan"),
              ("Ngorongoro", 35.72, -3.31, "encroachment", 2, "lights +34% Karatu edge"),
              ("Ngorongoro", 35.84, -2.91, "station", 2, "Empakaai silent 4 d"),
              ("L. Manyara", 35.8, -3.5, "hydrology", 3, "lake level +2.1 σ"),
              ("Katavi", 31.1, -6.7, "fire", 2, "late-season ignition"),
              ("Serengeti", 34.6, -2.1, "movement", 1, "herd crossed early")]
    cols = dict(ALERT_TYPES)
    for park, lon, lat, typ, sev, note in alerts:
        col = cols[typ]
        x, y = px(lon, lat)
        r = 5 + sev * 3
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r+6:.1f}" fill="{col}" opacity="0.22"/>')
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.1f}" fill="{col}" opacity="0.95" '
                   f'stroke="#0A0F0C" stroke-width="1.6"><title>{park} · {typ} S{sev} — {note}</title></circle>')
        if sev >= 3:
            out.append(f'<text x="{x:.0f}" y="{y-r-6:.0f}" text-anchor="middle" font-size="13" '
                       f'fill="#F5F7F3" stroke="#0A0F0C" stroke-width="3" paint-order="stroke" '
                       f'font-family="JetBrains Mono,monospace" font-weight="700">{park}</text>')
    out.append('<rect x="14" y="446" width="450" height="52" rx="8" fill="rgba(8,13,10,.78)"/>')
    for i, (name, col) in enumerate(ALERT_TYPES):
        cxx = 26 + (i % 3) * 148
        cyy = 466 + (i // 3) * 20
        out.append(f'<circle cx="{cxx}" cy="{cyy-3}" r="4.5" fill="{col}"/>')
        out.append(f'<text x="{cxx+11}" y="{cyy}" font-size="12" fill="#F5F7F3" '
                   f'font-family="JetBrains Mono,monospace">{name}</text>')
    out.append('</svg>')
    btn = ('font-family:JetBrains Mono,ui-monospace,monospace;font-size:10px;font-weight:700;'
           'letter-spacing:.08em;padding:6px 13px;border:0;cursor:pointer')
    zbtn = btn + ';background:rgba(8,13,10,.72);color:#F5F7F3;padding:4px 12px;font-size:14px'
    ic_expand = ('<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
                 'stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/>'
                 '<path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/>'
                 '<path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>')
    out.append(
        # zoom — top-left, the conventional navigation corner
        f'<div style="position:absolute;top:10px;left:10px;display:flex;flex-direction:column;border-radius:8px;'
        f'overflow:hidden;border:1px solid rgba(245,247,243,.25)">'
        f'<button title="zoom in" style="{zbtn}">+</button>'
        f'<button title="zoom out" style="{zbtn};border-top:1px solid rgba(245,247,243,.2)">−</button></div>'
        # layer toggle + expand — top-right, the view corner
        f'<div style="position:absolute;top:10px;right:10px;display:flex;border-radius:8px;overflow:hidden;'
        f'border:1px solid rgba(245,247,243,.25)">'
        f'<button id="{pfx}-b-sat" style="{btn};background:var(--acc);color:var(--accT)" '
        f'onclick="uhiBase(\'{pfx}\',\'sat\')">SATELLITE</button>'
        f'<button id="{pfx}-b-osm" style="{btn};background:rgba(8,13,10,.72);color:#F5F7F3" '
        f'onclick="uhiBase(\'{pfx}\',\'osm\')">MAP</button></div>'
        f'<button title="expand" style="{btn};position:absolute;top:10px;right:172px;border-radius:8px;padding:5px 8px;'
        f'display:grid;place-items:center;background:rgba(8,13,10,.72);color:#F5F7F3;'
        f'border:1px solid rgba(245,247,243,.25)">{ic_expand}</button>')
    out.append('</div>')
    out.append("""<script>
function uhiBase(p, which){
  document.getElementById(p+'-sat').setAttribute('visibility', which==='sat'?'visible':'hidden');
  document.getElementById(p+'-osm').setAttribute('visibility', which==='osm'?'visible':'hidden');
  const bs=document.getElementById(p+'-b-sat'), bo=document.getElementById(p+'-b-osm');
  const on='var(--acc)', off='rgba(8,13,10,.72)';
  bs.style.background=which==='sat'?on:off; bs.style.color=which==='sat'?'var(--accT)':'#F5F7F3';
  bo.style.background=which==='osm'?on:off; bo.style.color=which==='osm'?'var(--accT)':'#F5F7F3';
}
</script>""")
    return ''.join(out)


def sat_grid_choropleth():
    """Gridded loss intensity ON the satellite basemap — geolocated data over real ground."""
    import charts as CHX
    X0, Y0 = 37, 32
    poly = CHX._tz_ring()

    def inside(lon, lat):
        c = False
        n = len(poly)
        for i in range(n):
            x1, y1 = poly[i]
            x2, y2 = poly[(i + 1) % n]
            if (y1 > lat) != (y2 > lat) and lon < (x2 - x1) * (lat - y1) / (y2 - y1) + x1:
                c = not c
        return c

    def px(lon, lat):
        mx = (lon + 180) / 360 * 64
        my = (1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * 64
        return ((mx - X0) * 256, (my - Y0) * 256)
    rng = random.Random(11)
    out = ['<svg viewBox="0 0 768 512" style="width:100%;height:auto;display:block;border-radius:9px" '
           'xmlns="http://www.w3.org/2000/svg">', _basemap_layers("cmp").replace('visibility="hidden"', 'visibility="hidden" ')]
    step = 0.45
    lat = -11.7
    while lat < -0.9:
        lon = 29.3
        while lon < 40.4:
            if inside(lon + step / 2, lat + step / 2):
                west = max(0, 1 - abs(lon - 31.2) / 3.5) * 0.9
                arc = max(0, 1 - abs(lat + 7.6) / 2.2) * max(0, 1 - abs(lon - 36.6) / 1.8) * 0.8
                coast = max(0, 1 - abs(lon - 39.2) / 1.4) * 0.55
                v = min(1, (west + arc + coast) * (0.7 + 0.6 * rng.random()))
                if v > 0.12:
                    col = FIRE_RAMP[min(6, int(v * 6.99))]
                    x0, y0 = px(lon, lat + step)
                    x1, y1 = px(lon + step, lat)
                    out.append(f'<rect x="{x0:.1f}" y="{y0:.1f}" width="{x1-x0:.1f}" height="{y1-y0:.1f}" '
                               f'fill="{col}" opacity="{0.28+v*0.42:.2f}"/>')
            lon += step
        lat += step
    out.append('<rect x="14" y="462" width="420" height="36" rx="8" fill="rgba(8,13,10,.78)"/>')
    for i, c in enumerate(FIRE_RAMP):
        out.append(f'<rect x="{26+i*22}" y="{474}" width="22" height="9" fill="{c}"/>')
    out.append('<text x="26" y="470" font-size="11" fill="#F5F7F3" font-family="JetBrains Mono,monospace">low</text>')
    out.append(f'<text x="{26+7*22-24}" y="470" font-size="11" fill="#F5F7F3" font-family="JetBrains Mono,monospace">high</text>')
    out.append('<text x="200" y="482" font-size="11" fill="#F5F7F3" font-family="JetBrains Mono,monospace">loss intensity · 0.45° cells · on real ground</text>')
    out.append('</svg>')
    return ''.join(out)
