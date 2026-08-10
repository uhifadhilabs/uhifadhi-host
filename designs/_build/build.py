#!/usr/bin/env python3
"""Assembles the uhifadhi design-template app.

IA: national level (Overview · Areas · Compare · Runs · Gallery) + one SUB-APP
per park (hub + Forest / Fires / Climate / Stations / Land cover / Statistics),
because each protected area carries its own full analytical surface.
"""
import base64
import json
import math
import os

import charts as C
import data as D
import eco as E
import extra as X
import fires as FI
import weather as WX

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.normpath(os.path.join(HERE, '..'))
TILES = os.path.join(HERE, 'tiles')

NAV = [("index.html", "Overview"), ("areas.html", "Areas"), ("alerts.html", "Alerts"),
       ("compare.html", "Compare"), ("ingestion.html", "Runs"), ("gallery.html", "Gallery")]


def _svg(paths):
    return ('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>')


# Lucide glyphs for the collapsible sidebar (icon rail when collapsed).
SIDE_ICON = {
    "Overview": _svg('<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/>'
                     '<rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>'),
    "Areas": _svg('<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894'
                  'l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618'
                  'a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/><path d="M15 5.764v15"/><path d="M9 3.236v15"/>'),
    "Alerts": _svg('<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673'
                   'C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>'),
    "Compare": _svg('<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>'),
    "Runs": _svg('<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0'
                 'l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>'),
    "Gallery": _svg('<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/>'
                    '<rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>'),
}
IC_THEME = _svg('<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41'
                'M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>')
IC_PLUS = _svg('<path d="M5 12h14M12 5v14"/>')
IC_COLLAPSE = _svg('<path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/>')
# maximize / expand — clearer than the thin ⤢ glyph
IC_EXPAND = ('<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
             'stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/>'
             '<path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>')
IC_BELL = _svg('<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673'
               'C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>')
IC_GEAR = _svg('<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73'
               'l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38'
               'a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18'
               'a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08'
               'a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08'
               'a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>')

FEED = [
    ("S3", "fail", "Nyerere", "GLAD cluster · 41 px in 3 days, new road spur visible", "deforestation", "2 h"),
    ("S3", "fail", "Ruaha", "fire OUTSIDE burn plan, 14 detections", "fire", "5 h"),
    ("S3", "fail", "L. Manyara", "lake level +2.1 σ — flood watch", "hydrology", "1 d"),
    ("S2", "warn", "Ngorongoro", "Karatu-edge lights +34% q/q", "encroachment", "1 d"),
    ("S2", "warn", "Ngorongoro", "Empakaai station silent 4 days", "station", "4 d"),
    ("S1", "idle", "Serengeti", "block B07 burning — matches plan", "fire", "3 h"),
]


def feed_html(n=6, compact=False):
    rows = []
    for sev, cls, park, msg, stream, age in FEED[:n]:
        rows.append(
            f'<div class="rln" style="align-items:flex-start"><span class="chip {cls}" '
            f'style="margin-top:1px">{sev}</span><span style="flex:1"><b class="disp">{park}</b> '
            f'<span class="fog">— {msg}</span><br><span class="mono d" style="font-size:9px">{stream} · {age} ago'
            f'</span></span><a href="alerts.html" class="acc mono" style="font-size:9px;text-decoration:none">open →</a></div>')
    return ''.join(rows)

NCA_SUB = [("area-ngorongoro.html", "Overview"), ("ngoro-forest.html", "Forest loss"),
           ("ngoro-climate.html", "Climate"), ("ngoro-drought.html", "Drought"),
           ("ngoro-veg.html", "Vegetation"), ("ngoro-stations.html", "Stations"),
           ("ngoro-landcover.html", "Land cover"), ("ngoro-anthro.html", "Anthropogenic"),
           ("ngoro-tourism.html", "Tourism"), ("ngoro-livestock.html", "Livestock"),
           ("ngoro-stats.html", "Statistics")]
SER_SUB = [("area-serengeti.html", "Overview"), ("ser-fires.html", "Fire management"),
           ("ser-bio.html", "Biodiversity"), ("ser-air.html", "Air & smoke")]

CSS = r"""
:root{
  --cv:#0C1310; --p1:#152019; --p2:#111A14;
  --ln:rgba(216,236,224,.09); --ln2:rgba(216,236,224,.17);
  --tx:#EAF2EC; --fog:#87988D; --dim:#5A6960; --raised:#1C2921;
  --acc:#3ED9A8; --accT:#0C1310; --accGlow:rgba(62,217,168,.18);
  --ok:#63C97F; --warn:#DBA33F; --fail:#E05B41;
  --glass:rgba(12,19,15,.78); --shadow:0 8px 26px rgba(0,0,0,.34);
}
html.light{
  --cv:#F3F2EB; --p1:#FFFFFF; --p2:#FBFAF6;
  --ln:rgba(23,38,30,.11); --ln2:rgba(23,38,30,.20);
  --tx:#1B2620; --fog:#68776D; --dim:#98A49B; --raised:#ECEBE2;
  --acc:#0F8A68; --accT:#FFFFFF; --accGlow:rgba(15,138,104,.13);
  --ok:#1E7A46; --warn:#9A6B14; --fail:#BA4227;
  --glass:rgba(255,255,255,.85); --shadow:0 6px 22px rgba(23,38,30,.10);
}
*{box-sizing:border-box}
body{margin:0;background:var(--cv);color:var(--tx);
     font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;
     -webkit-font-smoothing:antialiased;position:relative}
body::before{content:"";position:fixed;inset:0;pointer-events:none;opacity:.5;z-index:0;
    background:linear-gradient(color-mix(in srgb,var(--fog) 8%,transparent) 1px,transparent 1px),
        linear-gradient(90deg,color-mix(in srgb,var(--fog) 8%,transparent) 1px,transparent 1px);
    background-size:72px 72px}
.mono{font-family:"JetBrains Mono",ui-monospace,Menlo,monospace}
.disp{font-family:"Archivo","Avenir Next",system-ui,sans-serif;letter-spacing:-.02em}

.bar{position:sticky;top:0;z-index:20;height:52px;display:flex;align-items:center;gap:20px;
     padding:0 22px;border-bottom:1px solid var(--ln);
     background:color-mix(in srgb,var(--cv) 88%,transparent);backdrop-filter:blur(10px)}
.bar .logo{display:flex;align-items:center;gap:10px;font-family:"Archivo",system-ui,sans-serif;
     font-weight:700;font-size:15px;color:var(--tx);text-decoration:none}
.bar .logo i{width:24px;height:24px;border-radius:7px;font-style:normal;display:grid;place-items:center;
     background:var(--acc);color:var(--accT);font-size:12px;font-weight:800;
     clip-path:polygon(0 0,100% 0,100% 72%,72% 100%,0 100%)}
.bar nav{display:flex;gap:2px;overflow-x:auto}
.bar nav a{color:var(--fog);font-size:12.5px;padding:6px 13px;border-radius:8px;text-decoration:none;
     position:relative;white-space:nowrap}
.bar nav a:hover{color:var(--tx)}
.bar nav a.on{color:var(--tx);font-weight:700}
.bar nav a.on::after{content:"";position:absolute;left:13px;right:13px;bottom:-3px;height:2px;
     background:var(--acc);border-radius:2px}
.bar .right{margin-left:auto;display:flex;gap:14px;align-items:center;font-size:11px;color:var(--fog)}
.live{display:inline-flex;align-items:center;gap:6px;color:var(--acc);font-weight:600}
.live i{width:7px;height:7px;border-radius:50%;background:var(--acc);font-style:normal;
     animation:pulse 2s ease-in-out infinite;box-shadow:0 0 8px var(--accGlow)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
@media (prefers-reduced-motion: reduce){.live i{animation:none}}
.cta{background:var(--acc);color:var(--accT);border-radius:9px;padding:7px 15px;font-weight:800;
     font-size:12px;text-decoration:none}
.tgl{border:1px solid var(--ln2);background:none;color:var(--fog);border-radius:8px;
     padding:5px 11px;font-size:11px;cursor:pointer;font-family:inherit}

.subnav{display:flex;gap:6px;margin:0 0 26px;flex-wrap:wrap}
.subnav a{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:10px;font-weight:600;
     letter-spacing:.1em;text-transform:uppercase;color:var(--fog);text-decoration:none;
     border:1px solid var(--ln2);border-radius:99px;padding:6px 15px}
.subnav a:hover{color:var(--tx);border-color:var(--fog)}
.subnav a.on{color:var(--accT);background:var(--acc);border-color:var(--acc)}
.subnav span.off{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:10px;
     letter-spacing:.1em;text-transform:uppercase;color:var(--dim);border:1px dashed var(--ln2);
     border-radius:99px;padding:6px 15px}

.page{position:relative;z-index:1;max-width:1280px;margin:0 auto;padding:26px 22px 90px}
.crumb{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:10px;letter-spacing:.14em;
     text-transform:uppercase;color:var(--fog);margin:2px 0 6px}
.crumb a{color:var(--acc);text-decoration:none}
h1.pg{font-family:"Archivo",system-ui,sans-serif;font-size:27px;font-weight:700;letter-spacing:-.02em;
     margin:0 0 4px}
.pgsub{color:var(--fog);font-size:13.5px;margin:0 0 22px;max-width:96ch;line-height:1.6}
.pgsub b{color:var(--tx)}
h2.zone{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:10.5px;font-weight:600;
     letter-spacing:.22em;text-transform:uppercase;color:var(--fog);margin:38px 0 22px;
     display:flex;align-items:center;gap:12px}
h2.zone::after{content:"";flex:1;height:1px;background:var(--ln2)}

.grid{display:grid;gap:20px;margin-bottom:20px}
.g2{grid-template-columns:1fr 1fr}.g3{grid-template-columns:1fr 1fr 1fr}
.g4{grid-template-columns:repeat(4,1fr)}.g32{grid-template-columns:1.5fr 1fr}
@media (max-width:980px){.g2,.g3,.g32{grid-template-columns:1fr}.g4{grid-template-columns:1fr 1fr}}

.c{background:linear-gradient(180deg,var(--p1),var(--p2));border:1px solid var(--ln);
   border-radius:14px;padding:24px 16px 14px;position:relative;box-shadow:var(--shadow)}
.c .tab{position:absolute;top:-9px;left:13px;background:var(--cv);border:1px solid var(--ln2);
   border-radius:7px;padding:3px 10px;font-family:"JetBrains Mono",ui-monospace,monospace;
   font-size:8.5px;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:var(--fog);
   display:flex;gap:8px;align-items:center;white-space:nowrap;max-width:92%;overflow:hidden;z-index:2}
.c .tab .idx{color:var(--acc);letter-spacing:.05em}
.c .tab .src{letter-spacing:.03em;color:var(--dim);text-transform:none}
.c .lib{position:absolute;top:-9px;right:12px;background:var(--cv);border:1px dashed var(--ln2);
   border-radius:7px;padding:3px 9px;font-family:"JetBrains Mono",ui-monospace,monospace;
   font-size:8px;color:var(--dim);z-index:2}
.stamp{position:absolute;right:12px;bottom:6px;font-family:"JetBrains Mono",ui-monospace,monospace;
   font-size:7.5px;color:color-mix(in srgb,var(--fog) 62%,transparent);letter-spacing:.06em}
.use{font-size:11.5px;color:var(--fog);margin:10px 2px 4px;line-height:1.5}
.use b{color:var(--tx)}

.kpi b{font-family:"Archivo",system-ui,sans-serif;font-size:30px;font-weight:700;letter-spacing:-.035em;
   display:block;line-height:1;font-variant-numeric:tabular-nums}
.kpi b em{font-style:normal;font-size:12px;color:var(--fog);font-weight:500;margin-left:4px}
.kpi .sub{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:8.5px;color:var(--dim);
   margin-top:8px;display:flex;align-items:center;gap:9px}
.kpi.hot b{color:var(--acc)}
.sb{display:inline-flex;height:4px;width:64px;border:1px solid color-mix(in srgb,var(--tx) 42%,transparent);
   border-radius:1px;overflow:hidden}
.sb i{flex:1}.sb i:nth-child(odd){background:color-mix(in srgb,var(--tx) 55%,transparent)}

.chip{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:9px;font-weight:700;
   border-radius:6px;padding:2.5px 9px;border:1px solid;white-space:nowrap}
.chip.ok{color:var(--ok);border-color:color-mix(in srgb,var(--ok) 40%,transparent)}
.chip.warn{color:var(--warn);border-color:color-mix(in srgb,var(--warn) 40%,transparent)}
.chip.fail{color:var(--fail);border-color:color-mix(in srgb,var(--fail) 40%,transparent)}
.chip.idle{color:var(--dim);border:1px dashed color-mix(in srgb,var(--fog) 45%,transparent);font-weight:600}
.chip.acc{color:var(--acc);border-color:color-mix(in srgb,var(--acc) 45%,transparent)}

table.tbl{width:100%;border-collapse:collapse;font-size:12.5px}
table.tbl th{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:8.5px;letter-spacing:.16em;
   text-transform:uppercase;color:var(--fog);text-align:left;padding:8px 10px;
   border-bottom:1px solid var(--ln2);font-weight:600}
table.tbl td{padding:9px 10px;border-bottom:1px dashed color-mix(in srgb,var(--fog) 18%,transparent);
   font-variant-numeric:tabular-nums}
table.tbl tr:hover td{background:color-mix(in srgb,var(--acc) 4%,transparent)}
table.tbl a{color:var(--acc);text-decoration:none;font-weight:600}
table.tbl .num{text-align:right;font-family:"JetBrains Mono",ui-monospace,monospace;font-size:11px}

.rln{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:12px;
   padding:8px 2px;border-bottom:1px dashed color-mix(in srgb,var(--fog) 18%,transparent)}
.rln:last-child{border:0}
.g{color:var(--ok)}.w{color:var(--warn)}.r{color:var(--fail)}.d{color:var(--dim)}.fog{color:var(--fog)}
.acc{color:var(--acc)}
.fld{background:var(--raised);border:1px solid var(--ln2);border-radius:10px;padding:10px 13px;
   color:var(--tx);font-size:13px;width:100%;font-family:inherit}
.fld::placeholder{color:var(--dim)}
.prog{height:7px;background:var(--raised);border-radius:4px;overflow:hidden}
.prog i{display:block;height:100%;border-radius:4px;background:var(--acc)}
.thumb{width:76px;height:56px;border-radius:9px;flex-shrink:0;border:1px solid var(--ln2);
   background-size:256px 256px;background-repeat:no-repeat;box-shadow:var(--shadow)}

.mod{display:flex;flex-direction:column;gap:6px;text-decoration:none;color:var(--tx)}
.mod .mt{font-family:"Archivo",system-ui,sans-serif;font-weight:700;font-size:15px}
.mod .md{color:var(--fog);font-size:12px;line-height:1.5}
.mod .chip{align-self:flex-start;margin-top:4px}

.ch{width:100%;height:auto;display:block}
.ch text{font-family:"JetBrains Mono",ui-monospace,Menlo,monospace;font-size:7.5px;fill:var(--fog)}
.ch text.ttl{font-size:8px;fill:var(--tx);font-weight:600}
.ch text.anno{fill:var(--tx);font-size:7.5px}
.ch text.annoS{fill:var(--fog);font-size:6.9px}
.ch line.grid{stroke:color-mix(in srgb,var(--fog) 22%,transparent);stroke-width:.6}
.ch line.ax{stroke:color-mix(in srgb,var(--fog) 55%,transparent);stroke-width:.8}
.ch line.ref{stroke:var(--tx);stroke-width:.8;stroke-dasharray:3 2;opacity:.55}
.ch .cum{fill:rgba(217,119,66,.16)}
.ch .cumln{fill:none;stroke:#D97742;stroke-width:1.4}
.ch .precip{fill:#4A90C2}
.ch .temp{fill:none;stroke:#D95F44;stroke-width:1.4}
.ch .tempdot{fill:#D95F44}
.legend{display:flex;gap:14px;flex-wrap:wrap;font-family:"JetBrains Mono",ui-monospace,monospace;
   font-size:8.5px;color:var(--fog);margin-top:8px;align-items:center}
.legend i{width:9px;height:9px;border-radius:2px;display:inline-block;margin-right:5px;vertical-align:-1px}
.glass{background:var(--glass);backdrop-filter:blur(10px);border:1px solid var(--ln2);border-radius:10px}
.footer{margin-top:44px;border-top:1px solid var(--ln);padding-top:18px;color:var(--dim);
   font-size:11.5px;max-width:96ch;line-height:1.6}
.gal-item{display:flex;gap:12px;align-items:baseline;padding:8px 2px;
   border-bottom:1px dashed color-mix(in srgb,var(--fog) 16%,transparent);font-size:12.5px}
.gal-item a{color:var(--acc);text-decoration:none;font-weight:600;min-width:190px}
.gal-item .l{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:9px;color:var(--dim);min-width:150px}
.gal-item .u{color:var(--fog);font-size:11.5px}
"""

JS = """
<script>
(function(){
  const d=document.documentElement;
  if(localStorage.getItem('uhifadhi-theme')==='light')d.classList.add('light');
  window.toggleTheme=function(){
    const l=d.classList.toggle('light');
    localStorage.setItem('uhifadhi-theme',l?'light':'dark');
  };
  window.uhiNav=function(){
    const s=document.getElementById('side');
    const rail=s.classList.toggle('rail');
    localStorage.setItem('uhifadhi-nav',rail?'rail':'full');
  };
  if(localStorage.getItem('uhifadhi-nav')==='rail'){
    const s=document.getElementById('side'); if(s)s.classList.add('rail');
  }
})();
</script>
"""

PL = [0]


def plate(title, body, src=None, lib=None, use=None, pid=None, style="", stamp=None):
    PL[0] += 1
    tab = (f'<span class="tab"><span class="idx">PL·{PL[0]:02d}</span>{title}'
           + (f'<span class="src">· {src}</span>' if src else '') + '</span>')
    libel = f'<span class="lib">{lib}</span>' if lib else ''
    useel = f'<div class="use">{use}</div>' if use else ''
    stampel = f'<span class="stamp">{stamp}</span>' if stamp else ''
    anchor = f' id="{pid}"' if pid else ''
    return f'<div class="c"{anchor} style="{style}">{tab}{libel}{body}{useel}{stampel}</div>'


def subnav(items, active, planned=()):
    links = ''.join(f'<a href="{f}"{" class=on" if f == active else ""}>{t}</a>' for f, t in items)
    off = ''.join(f'<span class="off">{t}</span>' for t in planned)
    return f'<div class="subnav">{links}{off}</div>'


def page(fname, title, active, crumb, sub, body, sub_nav="", action=""):
    items = ''.join(
        f'<a class="nav-item{" on" if f == active else ""}" href="{f}" title="{t}">{SIDE_ICON[t]}<span>{t}</span></a>'
        for f, t in NAV)
    head = (f'<div class="pghead"><div><h1 class="pg">{title}</h1><p class="pgsub">{sub}</p></div>'
            f'{f"<div class=pgact>{action}</div>" if action else ""}</div>')
    html = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title} — Uhifadhi</title>
<link rel="stylesheet" href="uhifadhi.css">
<link rel="stylesheet" href="nav.css">
</head><body>
<div class="shell">
<aside class="side" id="side">
  <div class="side-top">
    <a class="brand" href="index.html"><i>U</i><b>Uhifadhi</b></a>
    <button class="collapse-btn" onclick="uhiNav()" title="Collapse sidebar" aria-label="Collapse sidebar">{IC_COLLAPSE}</button>
  </div>
  <nav class="nav">{items}</nav>
  <div class="side-foot">
    <a class="row" href="#" title="Settings">{IC_GEAR}<span>Settings</span></a>
  </div>
</aside>
<main class="main">
<header class="topbar">
  <span class="tb-right">
    <span class="live"><i></i>worker</span>
    <button class="tb-icon" title="Alerts">{IC_BELL}<span class="badge">6</span></button>
    <button class="tb-icon" onclick="toggleTheme()" title="Toggle theme">{IC_THEME}</button>
    <span class="user"><span class="avatar">NK</span><span class="uinfo"><b>N. Kileo</b><em>NCAA · operator</em></span></span>
  </span>
</header>
<div class="page">
<div class="crumb">{crumb}</div>
{head}
{sub_nav}
{body}
<div class="footer">Design template — static HTML generated from the case-study dataset (Ngorongoro forest
loss is <b>real</b> app data; Serengeti/other series are plausibility-tuned placeholders). Regenerate:
<span class="mono">python3 designs/_build/build.py</span>.</div>
</div>
</main>
</div>
{JS}</body></html>"""
    open(os.path.join(OUT, fname), 'w').write(html)
    print(f'{fname:26s} {len(html):>9,} bytes')


def nca_map():
    """The area map in the alerts-map idiom: a fixed landscape frame, a working
    satellite⇄street toggle (uhiBase), and an expand affordance — no controls
    obscuring the map (loss-year filtering lives in a strip beside the plate)."""
    # Composed from z9 tiles (3×3 = 768² canvas) so the ~square NCA boundary fits
    # inside a landscape 768×512 frame (same size as the alerts map): a lower zoom
    # trades ground detail for "the whole area is always visible if a boundary exists".
    X0, Y0, N = 305, 259, 3
    CANVAS = N * 256
    # landscape crop centred on the boundary (its y-centre ≈ 346 in the 768² canvas)
    VBX, VBY, VBW, VBH = 0, 90, 768, 512

    def b64(p, m):
        with open(p, 'rb') as fh:
            return f'data:{m};base64,' + base64.b64encode(fh.read()).decode()

    def px(lon, lat):
        mx = (lon + 180) / 360 * 512
        my = (1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * 512
        return ((mx - X0) * 256, (my - Y0) * 256)

    sat_imgs, osm_imgs, loss = [], [], []
    for xi in range(N):
        for yi in range(N):
            x, y = X0 + xi, Y0 + yi
            sp = os.path.join(TILES, f'z9sat_{x}_{y}.jpg')
            op = os.path.join(TILES, f'z9osm_{x}_{y}.png')
            lp = os.path.join(TILES, f'z9loss_{x}_{y}.png')
            tag = f'x="{xi*256}" y="{yi*256}" width="256" height="256"'
            if os.path.exists(sp):
                sat_imgs.append(f'<image {tag} href="{b64(sp,"image/jpeg")}"/>')
            if os.path.exists(op):
                osm_imgs.append(f'<image {tag} href="{b64(op,"image/png")}"/>')
            if os.path.exists(lp) and os.path.getsize(lp) > 0:
                loss.append(f'<image {tag} href="{b64(lp,"image/png")}" opacity="0.9"/>')
    g = json.load(open(os.path.join(TILES, 'nca.geojson')))
    ring = g['coordinates'][0] if g['type'] == 'Polygon' else g['coordinates'][0][0]
    pts = [px(lon, lat) for lon, lat in ring]
    poly = ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)
    path_d = 'M' + ' L'.join(f'{x:.1f} {y:.1f}' for x, y in pts) + ' Z'
    # vignette outside the boundary — focuses the eye, and works on both basemaps
    dim = f'<path d="M0 0 H{CANVAS} V{CANVAS} H0 Z {path_d}" fill="#060a08" opacity="0.42" fill-rule="evenodd"/>'
    # two toggleable basemap layers (satellite default + real z10 street tiles)
    sat_g = f'<g id="nca-sat" visibility="visible">{"".join(sat_imgs)}{"".join(loss)}</g>'
    osm_g = f'<g id="nca-osm" visibility="hidden">{"".join(osm_imgs)}</g>'
    labels = [(35.585, -3.172, 'Ngorongoro Crater'), (35.843, -2.920, 'Empakaai'),
              (35.700, -3.070, 'Northern Highland Forest')]
    lab = []
    for lon, lat, t in labels:
        x, y = px(lon, lat)
        lab.append(f'<text x="{x:.0f}" y="{y:.0f}" font-size="15" fill="#F5F7F3" stroke="#0A0F0C" '
                   f'stroke-width="3" paint-order="stroke" font-family="JetBrains Mono,ui-monospace,monospace" '
                   f'text-anchor="middle" opacity="0.9">{t}</text>')
    mpp = 156543.03 * math.cos(math.radians(-3.1)) / 512  # z9 metres-per-pixel
    w20 = 20000 / mpp
    sx, sy = VBX + 22, VBY + VBH - 22
    scale = (f'<g><rect x="{sx:.0f}" y="{sy:.0f}" width="{w20:.0f}" height="6" fill="none" stroke="#F5F7F3" stroke-width="1.5"/>'
             f'<rect x="{sx:.0f}" y="{sy:.0f}" width="{w20/4:.0f}" height="6" fill="#F5F7F3"/>'
             f'<rect x="{sx+w20/2:.0f}" y="{sy:.0f}" width="{w20/4:.0f}" height="6" fill="#F5F7F3"/>'
             f'<text x="{sx+w20+10:.0f}" y="{sy+6:.0f}" font-size="14" fill="#F5F7F3" stroke="#0A0F0C" stroke-width="3" '
             f'paint-order="stroke" font-family="JetBrains Mono,ui-monospace,monospace">20 km</text></g>')
    lx, ly = VBX + VBW - 250, VBY + VBH - 40
    legend = (f'<g><rect x="{lx:.0f}" y="{ly:.0f}" width="240" height="32" rx="8" fill="rgba(8,13,10,.75)"/>'
              f'<rect x="{lx+14:.0f}" y="{ly+10:.0f}" width="12" height="12" rx="2" fill="#DD64B0"/>'
              f'<text x="{lx+34:.0f}" y="{ly+20:.0f}" font-size="14" fill="#F5F7F3" font-family="JetBrains Mono,ui-monospace,monospace">'
              'tree cover loss 2001–2024</text></g>')
    svg = (f'<svg viewBox="{VBX} {VBY} {VBW} {VBH}" style="width:100%;height:auto;display:block;border-radius:9px" '
           'xmlns="http://www.w3.org/2000/svg">' + sat_g + osm_g + dim +
           f'<polygon points="{poly}" fill="none" stroke="#49E6B4" stroke-width="3" opacity="0.95"/>' +
           ''.join(lab) + scale + legend + '</svg>')
    btn = ('font-family:JetBrains Mono,ui-monospace,monospace;font-size:10px;font-weight:700;'
           'letter-spacing:.08em;padding:6px 13px;border:0;cursor:pointer')
    zbtn = (btn + ';background:rgba(8,13,10,.72);color:#F5F7F3;padding:4px 12px;font-size:14px')
    controls = (
        # zoom — top-left, the conventional navigation corner
        f'<div style="position:absolute;top:10px;left:10px;display:flex;flex-direction:column;'
        f'border-radius:8px;overflow:hidden;border:1px solid rgba(245,247,243,.25)">'
        f'<button title="zoom in" style="{zbtn}">+</button>'
        f'<button title="zoom out" style="{zbtn};border-top:1px solid rgba(245,247,243,.2)">−</button></div>'
        # layer toggle + expand — top-right, the view corner
        f'<div style="position:absolute;top:10px;right:10px;display:flex;border-radius:8px;overflow:hidden;'
        f'border:1px solid rgba(245,247,243,.25)">'
        f'<button id="nca-b-sat" style="{btn};background:var(--acc);color:var(--accT)" '
        f'onclick="uhiBase(\'nca\',\'sat\')">SATELLITE</button>'
        f'<button id="nca-b-osm" style="{btn};background:rgba(8,13,10,.72);color:#F5F7F3" '
        f'onclick="uhiBase(\'nca\',\'osm\')">MAP</button></div>'
        f'<button title="expand" style="{btn};position:absolute;top:10px;right:152px;border-radius:8px;padding:5px 8px;'
        f'display:grid;place-items:center;background:rgba(8,13,10,.72);color:#F5F7F3;'
        f'border:1px solid rgba(245,247,243,.25)">{IC_EXPAND}</button>')
    script = ("""<script>
function uhiBase(p, which){
  document.getElementById(p+'-sat').setAttribute('visibility', which==='sat'?'visible':'hidden');
  document.getElementById(p+'-osm').setAttribute('visibility', which==='osm'?'visible':'hidden');
  const bs=document.getElementById(p+'-b-sat'), bo=document.getElementById(p+'-b-osm');
  const on='var(--acc)', off='rgba(8,13,10,.72)';
  bs.style.background=which==='sat'?on:off; bs.style.color=which==='sat'?'var(--accT)':'#F5F7F3';
  bo.style.background=which==='osm'?on:off; bo.style.color=which==='osm'?'var(--accT)':'#F5F7F3';
}
</script>""")
    return f'<div style="position:relative">{svg}{controls}</div>{script}'


def kpi(title, value, unit, sub, hot=False):
    return plate(title, f'<b class="disp">{value}<em>{unit}</em></b>'
                        f'<span class="sub">{sub}</span>'
                 ).replace('class="c"', f'class="c kpi{" hot" if hot else ""}"')


def module_card(href, title, desc, status="live"):
    chip = {"live": '<span class="chip ok">live</span>',
            "demo": '<span class="chip warn">template</span>',
            "off": '<span class="chip idle">planned</span>'}[status]
    inner = f'<span class="mt">{title}</span><span class="md">{desc}</span>{chip}'
    if href:
        return f'<a class="c mod" href="{href}" style="padding:20px 16px 16px">{inner}</a>'
    return f'<div class="c mod" style="padding:20px 16px 16px;opacity:.65">{inner}</div>'


# ═══════════════════════ NATIONAL LEVEL ═══════════════════════
def build_index():
    PL[0] = 0
    body = f"""
<div class="grid g4">
{kpi("Areas monitored", "16", "", "Tanzania PA network")}
{kpi("Protected", "97,431", "km²", "in monitored set")}
{kpi("Forest lost 2001–23", "1.2", "M ha", "network estimate", hot=True)}
{kpi("Worst pressure", "NYE", "", "Nyerere · miombo charcoal belt")}
</div>
<div class="grid g32">
{plate("Where it's happening", X.sat_alerts_map(), src="Esri imagery · live alert streams", lib="leaflet in-app",
       use="<b>The feed's spatial twin</b> — open alerts on the real basemap, radius = severity; small white dots are the monitored areas for orientation. Rankings and pressure live in the charts below — this map answers the one question they can't: <b>where</b>. Auto-fits the tenant's areas; no country outline hard-coded.", pid="natl-map")}
<div style="display:flex;flex-direction:column;gap:20px">
{plate("Needs attention now", feed_html(5), src="alert streams", pid="index-feed",
       use='The five most severe open alerts — full triage in <a href="alerts.html" style="color:var(--acc)">Alerts</a>.')}
{plate("Top movers, 3-yr vs prior", ''.join(
    f'<div class="rln"><b class="disp">{a["name"].split(" National")[0].split(" Conservation")[0]}</b>'
    f'{C.spark(a["loss"][1:])}<span class="chip {"fail" if (sum(a["loss"][-3:])/3)/max(1,sum(a["loss"][-6:-3])/3)>1.15 else "ok"}">'
    f'{(sum(a["loss"][-3:])/3)/max(1,sum(a["loss"][-6:-3])/3):+.0%}</span></div>'
    for a in sorted(D.AREAS, key=lambda a: -(sum(a["loss"][-3:])/3)/max(1, sum(a["loss"][-6:-3])/3))[:5]),
    src="forest_loss_year", pid="movers",
    use="Recent-trend ranking — acceleration, not size, decides who gets looked at this week.")}
</div>
</div>
<div class="grid g2">
{plate("Total loss by PA", C.ranked_bar(), src="Hansen 2001–23", lib="seaborn barplot (sorted)",
       use="<b>Ranked horizontal bars</b> — sorted, labeled at the end of each bar. Jade = Ngorongoro (live data).", pid="ranked-bar")}
{plate("Loss density league", C.lollipop(), src="ha yr⁻¹ / 100 km²", lib="ggplot geom_lollipop",
       use="<b>Lollipop</b> — same ranking logic, less ink: normalize by area and the league table reshuffles completely.", pid="lollipop")}
</div>
<div class="grid g2">
{plate("The network as area", C.treemap(), src="WDPA", lib="plotly treemap",
       use="<b>Treemap</b> — every PA sized by km², heat-colored by loss density. Nyerere dwarfs everything; Gombe is a pixel with a story.", pid="treemap")}
<div style="display:flex;flex-direction:column;gap:20px">
{plate("30×30 progress", C.bullet("Land protected", 38, 30, [17, 30, 50], "% of Tanzania", 60)
        + C.bullet("PAs with loss data", 16, 16, [8, 12, 16], "of 16 in set", 16)
        + C.bullet("Climate tracks", 6, 16, [4, 10, 16], "of 16 in set", 16),
       src="WDPA + app", lib="bullet (Few)",
       use="<b>Bullet charts</b> — value vs target vs qualitative bands; the anti-gauge: honest and compact.", pid="bullet")}
{plate("One square = 1%", C.waffle(), src="WDPA", lib="waffle / pywaffle",
       use="<b>Waffle</b> — part-of-whole for humans; percentages become countable squares.", pid="waffle")}
</div>
</div>
<div class="grid g2">
{plate("Protection by governance", C.donut(), src="WDPA categories", lib="plotly pie/donut",
       use="<b>Donut</b> — used once, for the single share-of-whole glance; composition across many entities always goes to stacked bars instead.", pid="donut")}
{plate("Fire seasons, five parks", C.streamgraph(), src="NASA FIRMS", lib="d3/plotly streamgraph",
       use="<b>Streamgraph</b> — totals and shares in one flowing figure; fire years 2016/2021 bulge the whole river.", pid="streamgraph")}
</div>"""
    page("index.html", "Tanzania protected areas", "index.html",
         '<a href="index.html">uhifadhi</a> / overview',
         "The national command view. Sixteen protected areas; each one opens into its own sub-app "
         "(forest, fires, climate, stations…). Every idiom here is the standard one from the scientific "
         "plotting canon.", body)


def park_thumb(a):
    """Real satellite thumbnail: CSS-crops the z6 tile around the park centroid."""
    mx = (a["lon"] + 180) / 360 * 64
    my = (1 - math.asinh(math.tan(math.radians(a["lat"]))) / math.pi) / 2 * 64
    tx, ty = int(mx), int(my)
    px, py = (mx - tx) * 256, (my - ty) * 256
    return (f'<div class="thumb" style="background-image:url(assets/tz_{tx}_{ty}.jpg);'
            f'background-position:{-(px-38):.0f}px {-(py-28):.0f}px"></div>')


def build_areas():
    PL[0] = 30
    rows = []
    for a in sorted(D.AREAS, key=lambda a: -a["total"]):
        href = ('area-ngorongoro.html' if a["short"] == "NCA"
                else 'area-serengeti.html' if a["short"] == "SER" else '#')
        link = f'<a href="{href}">{a["name"]}</a>'
        chip = ('<span class="chip ok">live · 6 modules</span>' if a["short"] == "NCA"
                else '<span class="chip warn">fire module</span>' if a["short"] == "SER"
                else '<span class="chip idle">queued</span>')
        al = {"NYE": ('2 open', 'fail'), "RUA": ('1 open', 'fail'), "NCA": ('2 open', 'warn'),
              "SER": ('1 open', 'idle'), "MAN": ('1 open', 'fail'), "KAT": ('1 open', 'warn')}.get(a["short"])
        alert_cell = (f'<a href="alerts.html"><span class="chip {al[1]}">{al[0]}</span></a>' if al
                      else '<span class="mono d" style="font-size:10px">—</span>')
        d3 = (sum(a["loss"][-3:]) / 3) / max(1, sum(a["loss"][-6:-3]) / 3)
        delta = f'<span class="chip {"fail" if d3 > 1.15 else ("ok" if d3 < 0.9 else "idle")}">{d3:+.0%}</span>'
        name_cell = (f'<div style="display:flex;gap:12px;align-items:center">{park_thumb(a)}'
                     f'<div><div style="font-size:13.5px;font-weight:600">{link}</div>'
                     f'<div class="mono" style="font-size:9px;color:var(--dim);margin-top:2px">'
                     f'IUCN {a["iucn"]} · est. {a["established"]} · {abs(a["lat"]):.1f}°S {a["lon"]:.1f}°E</div></div></div>')
        rows.append(f'<tr style="cursor:pointer" onclick="if(!event.target.closest(\'a\'))location.href=\'{href}\'">'
                    f'<td>{name_cell}</td><td class="num">{a["km2"]:,}</td>'
                    f'<td class="num">{a["forest_pct"]}%</td><td class="num">{a["total"]:,}</td>'
                    f'<td class="num">{a["mean"]:.0f}</td><td>{C.spark(a["loss"][1:])}</td>'
                    f'<td>{delta}</td><td>{alert_cell}</td><td>{chip}</td>'
                    f'<td style="text-align:right"><a class="open-btn" href="{href}">Open →</a></td></tr>')
    tbl = ('<table class="tbl"><tr><th>Protected area — click to open its sub-app</th>'
           '<th style="text-align:right">km²</th><th style="text-align:right">forest</th>'
           '<th style="text-align:right">loss 01–23 ha</th><th style="text-align:right">ha/yr</th>'
           '<th>trend 02–23</th><th>3-yr Δ</th><th>alerts</th><th>modules</th><th></th></tr>' + ''.join(rows) + '</table>')
    controls = (
        '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">'
        '<input class="fld" placeholder="Search areas… (⌘K)" style="max-width:260px">'
        '<span class="subnav" style="margin:0">'
        '<a class="on" href="#">All · 16</a><a href="#">Live data · 1</a><a href="#">Fire module · 1</a>'
        '<a href="#">With alerts · 6</a><a href="#">Queued · 14</a></span>'
        '<span style="margin-left:auto" class="mono" title="sort">'
        '<span class="chip acc">sort: total loss ↓</span></span></div>')
    body = f"""
{controls}
<div class="grid">
{plate("All areas", tbl, src="area_of_interest ⋈ forest_loss_year ⋈ alerts", lib="rich sparkline table",
       use="<b>The register as a working list</b> — real satellite thumbnail, identity metadata, full time series, recent-trend delta and open alerts per row; filters above narrow by module and alert state. Geography lives on the Overview map and inside each sub-app — here, density wins.", pid="spark-table")}
</div>"""
    page("areas.html", "Areas", "areas.html",
         '<a href="index.html">uhifadhi</a> / areas',
         "The register: every monitored area, each opening into its own analytical sub-app.", body,
         action=f'<a class="cta" href="new-area.html">{IC_PLUS}New area</a>')


# ═══════════════════════ NGORONGORO SUB-APP ═══════════════════════
NCA_CRUMB = '<a href="index.html">uhifadhi</a> / <a href="areas.html">areas</a> / ngorongoro'


def build_nca_hub():
    PL[0] = 9
    body = f"""
<div class="grid g4">
{kpi("Area", "8,271", "km²", "ST_Area geography")}
{kpi("Forest lost", "3,214", "ha", "2001–2023 · real", hot=True)}
{kpi("Worst year", "2013", "", "186 ha · rank #2")}
{kpi("Stations", "4/5", "", "Empakaai rim offline")}
</div>
<div class="grid g32">
{plate("Boundary + tree cover loss", nca_map(), src="Esri imagery · Hansen/UMD via GFW", lib="leaflet in-app",
       use="<b>The real thing</b>: actual satellite mosaic, actual GFW loss tiles (pink), the WDPA boundary from the database. Loss hugs the Northern Highland Forest edge — exactly what the 2013 bar says.",
       pid="nca-map", stamp="EPSG:3857 · z10 · aoi #2")}
<div style="display:flex;flex-direction:column;gap:20px">
{plate("Annual loss", C.annual_chart(), src="forest_loss_year · real", lib="matplotlib bar + annotate",
       use="Full analysis in <a href='ngoro-forest.html' style='color:var(--acc)'>Forest loss</a>.", pid="annual")}
{plate("Fire detections", C.fire_calendar(), src="NASA FIRMS / VIIRS", lib="calendar heatmap", pid="fire-cal",
       use="The dry-season pulse; prescribed-burn analytics live in the Serengeti demo.")}
</div>
</div>
<h2 class="zone">Flux — what the ecosystem is doing</h2>
<div class="grid g4">
{module_card("ngoro-forest.html", "Forest loss", "The real Hansen series: accounting, decomposition, trend.", "live")}
{module_card("ngoro-climate.html", "Climate", "WorldClim normals, CHIRPS anomalies, CMIP futures.", "demo")}
{module_card("ngoro-drought.html", "Drought", "SPEI monitor, drought-class extent, soil-moisture percentiles.", "demo")}
{module_card("ngoro-veg.html", "Vegetation", "NDVI envelopes, Hovmöller, phenology shift.", "demo")}
</div>
<h2 class="zone">Pressure — what people are doing</h2>
<div class="grid g4">
{module_card("ngoro-anthro.html", "Anthropogenic", "Boundary-buffer encroachment: built-up rings, distance decay, edge pressure.", "demo")}
{module_card("ngoro-tourism.html", "Tourism", "Camps &amp; lodges monitor: expansion, concentration, wildlife displacement.", "demo")}
{module_card("ngoro-livestock.html", "Livestock", "Herd census trends, grazing pressure map, stocking vs capacity.", "demo")}
{module_card(None, "Roads &amp; access", "OSM/GRIP road density, isochrones, fragmentation — planned.", "off")}
</div>
<h2 class="zone">Pulse &amp; synthesis</h2>
<div class="grid g4">
{module_card("ngoro-stations.html", "Weather stations", "Meteograms, wind roses, soil profiles, warming stripes.", "demo")}
{module_card("ngoro-landcover.html", "Land cover", "WorldCover composition, sankey transitions, cropland creep.", "demo")}
{module_card("ngoro-stats.html", "Statistics", "Fits, uncertainty, diagnostics, PCA — the inferential layer.", "demo")}
{module_card(None, "Water &amp; hydrology", "JRC surface water, lake levels, hydrographs — planned.", "off")}
</div>"""
    page("area-ngorongoro.html", "Ngorongoro Conservation Area", "areas.html", NCA_CRUMB,
         "The park hub: identity, the live map, and one door per analytical module, grouped by the "
         "taxonomy — flux (what the ecosystem does), pressure (what people do), pulse (live instruments).",
         body, subnav(NCA_SUB, "area-ngorongoro.html", planned=("Fires", "Water", "Roads")))


def build_nca_forest():
    PL[0] = 14
    body = f"""
<div class="grid g2">
{plate("Annual loss", C.annual_chart(), src="forest_loss_year · real", lib="matplotlib bar + annotate",
       use="<b>Annotated bars</b> — the 2001 baseline artifact is labeled and gradient-clipped, never silently squashed.", pid="annual")}
{plate("Cumulative loss", C.cum_chart(), src="real", lib="matplotlib fill_between", pid="cumulative",
       use="<b>Cumulative curve</b> — the running total; flattening = good news, and it is flattening.")}
</div>
<div class="grid g2">
{plate("Where the 3,214 ha came from", C.waterfall(), src="real, grouped", lib="plotly waterfall",
       use="<b>Waterfall</b> — additive decomposition: the 2001 artifact (grey) vs genuine periods.", pid="waterfall")}
{plate("Loss trend, artifact excluded", C.loess_plot(), src="real 2002–23", lib="seaborn regplot(lowess)",
       use="<b>LOESS smoother</b> — follows the mid-2000s hump and the post-2013 decline a straight line would deny.", pid="loess")}
</div>
<div class="grid g2">
{plate("Dataset coverage", C.gantt(), src="dataset_run", lib="plotly timeline", pid="gantt",
       use="<b>Gantt</b> — what data exists for this park, over what span.")}
{plate("Shelf growth", C.step_chart(), src="dataset_run", lib="step chart", pid="shelf",
       use="<b>Step chart</b> — dataset count changes at ingestion events, never between them.")}
</div>"""
    page("ngoro-forest.html", "Ngorongoro — Forest loss", "areas.html", NCA_CRUMB + ' / forest',
         "The forest-loss module, computed from the 23 real rows in forest_loss_year.",
         body, subnav(NCA_SUB, "ngoro-forest.html"))


def build_nca_climate():
    PL[0] = 70
    body = f"""
<h2 class="zone">Normals — what a year looks like</h2>
<div class="grid g2">
{plate("Crater highlands climograph", C.climograph(), src="WorldClim 1970–2000", lib="Walter–Lieth climograph",
       use="<b>Climograph</b> — precipitation bars + temperature line, dual axes: the single most recognized figure in ecology.", pid="climograph")}
{plate("Six parks, twelve months", C.normals_heatmap(), src="WorldClim", lib="seaborn heatmap",
       use="<b>Normals heatmap</b> — the bimodal north / unimodal south split across the network in one matrix.", pid="normals-heat")}
</div>
<div class="grid g2">
{plate("The year as a circle", C.rain_rose(), src="WorldClim, NCA", lib="plotly barpolar",
       use="<b>Polar rose</b> — seasonality is cyclic, so show the cycle.", pid="rose")}
{plate("Temperature, faceted", C.facets_temp(), src="WorldClim", lib="ggplot facet_wrap",
       use="<b>Small multiples</b> — six identical panels, only data differs; scales to 60 parks.", pid="facets")}
</div>
<h2 class="zone">Variability &amp; anomaly</h2>
<div class="grid g2">
{plate("Rainfall vs normal", C.anomaly(), src="CHIRPS", lib="anomaly bars (NOAA style)",
       use="<b>Diverging anomaly bars</b> — zero is the 30-year normal; the droughts read as a red fence.", pid="anomaly")}
{plate("Two climate regimes", C.density2d(), src="WorldClim monthly states", lib="kdeplot / density_contour",
       use="<b>2-D density</b> — monthly (T, P) states: the bimodal cloud IS the two regimes.", pid="density2d")}
</div>
<div class="grid g2">
{plate("Rain ↔ loss, jointly", C.jointplot(), src="CHIRPS × Hansen", lib="seaborn jointplot",
       use="<b>Joint plot</b> — scatter plus marginals; relationship and distributions together.", pid="jointplot")}
{plate("Dry years, hot years, lost years", C.connected_scatter(), src="NCA, 2012–23", lib="connected scatterplot",
       use="<b>Connected scatter</b> — two variables walked through time; loops and drifts are the story.", pid="connected")}
</div>
<div class="grid g2">
{plate("Twelve years of rainfall in one strip", C.horizon(), src="CHIRPS monthly", lib="horizon chart (d3)",
       use="<b>Horizon chart</b> — a decade of monthly anomalies at sparkline height without lying about magnitude.", pid="horizon")}
{plate("The rainfall year, dissected", C.seasonal_subseries(), src="CHIRPS", lib="seasonal subseries",
       use="<b>Seasonal subseries</b> — each month across ten years with its mean bar.", pid="subseries")}
</div>
<h2 class="zone">Futures</h2>
<div class="grid g2">
{plate("Warming scenarios to 2080", C.proj_ribbons(), src="CMIP6 ensembles", lib="fan/ribbon chart",
       use="<b>Scenario ribbons</b> — central line + uncertainty band per SSP; the band is the message.", pid="ribbons")}
{plate("Fire pressure river", C.streamgraph(), src="NASA FIRMS", lib="streamgraph", pid="stream2",
       use="<b>Streamgraph</b> — the hazard side of climate, flowing over a decade.")}
</div>"""
    page("ngoro-climate.html", "Ngorongoro — Climate", "areas.html", NCA_CRUMB + ' / climate',
         "WorldClim normals, CHIRPS variability and CMIP futures for the crater highlands — each dataset "
         "drawn with the figure its literature already speaks.",
         body, subnav(NCA_SUB, "ngoro-climate.html"))


def build_nca_stations():
    PL[0] = 130
    body = f"""
<div class="grid g4">
{kpi("Air temp", "17.8", "°C", "NCA HQ · 14:20 EAT")}
{kpi("Wind", "5.4", "m s⁻¹", "gusting 9.1 · SE")}
{kpi("Pressure", "778.9", "hPa", "3 h falling −0.6", hot=True)}
{kpi("Rain today", "6.2", "mm", "event total 31 mm")}
</div>
<div class="grid">
{plate("72-hour meteogram — NCA HQ (2,286 m)", WX.meteogram(), src="station telemetry", lib="meteogram (MetPy/DWD)",
       use="<b>The meteogram</b> — every station's signature figure: stacked elements, one shared time axis. Temperature/dew-point converge before the rain band arrives; pressure carries the 12-hour atmospheric tide.", pid="meteogram")}
</div>
<div class="grid g2">
{plate("Wind climate, 72 h", WX.wind_rose(), src="anemometer 10 m", lib="wind rose (windrose lib)",
       use="<b>The true wind rose</b> — 16 sectors × 3 speed bins, stacked petals: direction, frequency and strength in one polar figure.", pid="windrose")}
{plate("Station network", WX.station_map(), src="5 stations · real NCA boundary", lib="status map",
       use="<b>Health map</b> — reporting/stale/offline at a glance; Empakaai rim has been silent four days and its plates grey out downstream.", pid="stationmap")}
</div>
<div class="grid">
{plate("44 years at this station", WX.warming_stripes(), src="homogenized series", lib="warming stripes (Hawkins)",
       use="<b>Warming stripes</b> — the most famous climate graphic alive: no axes, no numbers, undeniable direction. Every station page should end its temperature story with this.", pid="stripes")}
</div>
<div class="grid">
{plate("A year of daily ranges", WX.temp_ribbon(), src="daily min/max", lib="NYT weather band",
       use="<b>Min–max whiskers on the climatological band</b> — every day compared to its own normal; red whiskers = days breaking it.", pid="tempribbon")}
</div>
<div class="grid g2">
{plate("Rain event, dissected", WX.rain_event(), src="tipping bucket", lib="hyetograph + accumulation",
       use="<b>Intensity + accumulation, dual axis</b> — hydrology's standard rain-event figure.", pid="rainevent")}
{plate("Heat entering the ground", WX.soil_profile(), src="soil array 5–100 cm", lib="depth × time heatmap",
       use="<b>Soil profile heatmap</b> — the diurnal wave damps and lags with depth; same grid serves soil moisture after rain.", pid="soilprofile")}
</div>
<div class="grid g2">
{plate("Pressure + tendency", WX.pressure_tendency(), src="barometer", lib="barogram",
       use="<b>Barogram</b> — with the 3-hour tendency stated as a number, the way forecasters read it.", pid="pressure")}
{plate("Wind + gust envelope", WX.gust_range(), src="anemometer", lib="gust range plot",
       use="<b>Mean line + gust whiskers</b> — the envelope is the hazard, not the mean.", pid="gust")}
</div>
<div class="grid g2">
{plate("Wind resource", WX.wind_weibull(), src="72 h speeds", lib="Weibull fit (wind industry)",
       use="<b>Histogram + fitted Weibull</b> — the standard wind-characterization figure.", pid="weibull")}
{plate("The average day", WX.diurnal_cycle(), src="hourly composite", lib="diurnal composite",
       use="<b>Diurnal composite</b> — hour-of-day mean ±1σ, humidity mirroring temperature; micromet's daily fingerprint.", pid="diurnal")}
</div>"""
    page("ngoro-stations.html", "Ngorongoro — Weather stations", "areas.html", NCA_CRUMB + ' / stations',
         "In-park observations: the meteorological canon applied to five telemetered stations. "
         "This is data WorldClim can never give you — the park's own instruments, live.",
         body, subnav(NCA_SUB, "ngoro-stations.html"))


def build_nca_landcover():
    PL[0] = 85
    body = f"""
<div class="grid g2">
{plate("Where land went, 2000 → 2020", C.sankey(), src="ESA WorldCover, NCA", lib="plotly sankey",
       use="<b>Sankey</b> — transitions as ribbons; the thin crossing forest→cropland ribbon is the alarm.", pid="sankey")}
{plate("Cover hierarchy", C.sunburst(), src="ESA WorldCover", lib="plotly sunburst",
       use="<b>Sunburst</b> — biome → class as nested rings.", pid="sunburst")}
</div>
<div class="grid g2">
{plate("Composition drift", C.stacked_area(), src="WorldCover epochs", lib="matplotlib stackplot",
       use="<b>Stacked area</b> — the cropland wedge growing at grassland's expense is the plot.", pid="stacked-area")}
{plate("Eight parks, one bar each", C.pct_stacked_bars(), src="ESA WorldCover", lib="100% stacked bars",
       use="<b>100% stacked bars</b> — composition compared across areas; replaces eight pies.", pid="pct-stacked")}
</div>"""
    page("ngoro-landcover.html", "Ngorongoro — Land cover", "areas.html", NCA_CRUMB + ' / land cover',
         "ESA WorldCover-style composition and change: flows, hierarchy and shares each in the idiom "
         "that does that one job best.",
         body, subnav(NCA_SUB, "ngoro-landcover.html"))


def build_nca_stats():
    PL[0] = 92
    body = f"""
<h2 class="zone">Fits &amp; uncertainty</h2>
<div class="grid g2">
{plate("Does forest share predict loss?", C.regplot(), src="PA table", lib="seaborn regplot",
       use="<b>OLS + 95% CI band</b> — a wide band is a finding, not a failure.", pid="regplot")}
{plate("Local trend, no line imposed", C.loess_plot(), src="NCA real", lib="lowess", pid="loess2",
       use="<b>LOESS</b> — for when you refuse to assume linearity.")}
</div>
<h2 class="zone">Distribution diagnostics</h2>
<div class="grid g2">
{plate("All 368 PA-years", C.hist_kde(), src="network", lib="seaborn histplot(kde=True)",
       use="<b>Histogram + KDE</b> — counts for honesty, curve for shape; log-x because loss is multiplicative.", pid="histkde")}
{plate("Read any percentile", C.ecdf(), src="NCA real", lib="seaborn ecdfplot",
       use="<b>ECDF</b> — no bins, no bandwidth; the distribution plot statisticians trust most.", pid="ecdf")}
</div>
<div class="grid g2">
{plate("Is loss lognormal?", C.qq(), src="NCA real", lib="statsmodels qqplot",
       use="<b>Q–Q plot</b> — quantiles vs fitted lognormal; hugging the diagonal = the model survives.", pid="qq")}
{plate("Ten thousand patches, no smear", C.hexbin(), src="patch-level synth", lib="matplotlib hexbin",
       use="<b>Hexbin</b> — density binning where scatter would overplot.", pid="hexbin")}
</div>
<h2 class="zone">Structure</h2>
<div class="grid g2">
{plate("What moves together", C.corr_heat(), src="PA table", lib="seaborn heatmap(annot)",
       use="<b>Correlation matrix</b> — values in-cell so nobody squints at a colorbar.", pid="corr")}
{plate("…and reordered by kinship", C.clustermap(), src="PA table", lib="seaborn clustermap",
       use="<b>Clustermap</b> — rows/cols reordered by clustering, dendrogram on top: blocks emerge.", pid="clustermap")}
</div>
<div class="grid g2">
{plate("Sixteen parks in two axes", C.pca_biplot(), src="PA table standardized", lib="PCA biplot",
       use="<b>PCA biplot</b> — scores + loadings: wet-forest mountains separate from dry savannas along PC1.", pid="pca")}
{plate("Estimates, ranked, with error", C.cleveland_ci(), src="mean ± CI", lib="pointrange", pid="cleveland2",
       use="<b>Point-range</b> — the summary page always ends with uncertainty made visible.")}
</div>"""
    page("ngoro-stats.html", "Ngorongoro — Statistics", "areas.html", NCA_CRUMB + ' / statistics',
         "The inferential layer: fits with confidence bands, distribution diagnostics, structure-finding.",
         body, subnav(NCA_SUB, "ngoro-stats.html"))


def build_nca_anthro():
    PL[0] = 150
    body = f"""
<div class="grid g2">
{plate("Built-up growth by buffer ring", E.buffer_rings(), src="GHSL epochs", lib="multi-line trend",
       use="<b>Buffer-ring analysis</b> — the module's signature: settlement growth in 0–5 / 5–10 / 10–25 km rings OUTSIDE the boundary. The inner ring growing fastest means pressure is converging on the fence, not just growing generally.", pid="rings")}
{plate("The cliff at the boundary", E.distance_decay(), src="WorldPop 2000 vs 2020", lib="distance-decay curves",
       use="<b>Distance decay</b> — population density vs distance to boundary, two epochs. The curve rising AND steepening is encroachment in one figure.", pid="decay")}
</div>
<div class="grid g2">
{plate("Edge pressure, segment by segment", E.edge_pressure_map(), src="GHSL + cropland + VIIRS lights, real boundary",
       lib="segmented perimeter map",
       use="<b>The boundary as the chart</b> — each segment of the real WDPA perimeter colored by a composite pressure index. Karatu glows; the Serengeti side sleeps. Rangers instantly know where to look.", pid="edgemap")}
{plate("What feeds the index",
       '<div class="rln"><span>GHSL built-up surface</span><span class="mono d">1975–2030 · 5-yearly</span><span class="chip ok">open</span></div>'
       '<div class="rln"><span>WorldPop density</span><span class="mono d">2000–2023 · annual</span><span class="chip ok">open</span></div>'
       '<div class="rln"><span>VIIRS nighttime lights</span><span class="mono d">2012–now · monthly</span><span class="chip ok">open</span></div>'
       '<div class="rln"><span>GFW cropland expansion</span><span class="mono d">2000–2023</span><span class="chip ok">open</span></div>'
       '<div class="rln"><span>OSM/GRIP roads</span><span class="mono d">feeds Roads module</span><span class="chip idle">planned</span></div>',
       src="sources", pid="anthro-src",
       use="Every layer open and global — this module needs zero fieldwork to light up for all sixteen parks.")}
</div>
<h2 class="zone">The proxies, watched individually</h2>
<div class="grid g2">
{plate("Gate towns at night", X.nightlights_facets(), src="VIIRS annual composites", lib="small multiples",
       use="<b>Nightlights facets</b> — Karatu's radiance quadrupled in eleven years; Loliondo barely moved. Lights are the fastest honest proxy for edge growth, and they update monthly.", pid="lights")}
{plate("Agriculture walking to the fence", X.cropland_rings(), src="GFW cropland layers", lib="stacked area by ring",
       use="<b>Cropland by ring, stacked</b> — the wedge nearest the boundary grows fastest. Pair with the built-up rings above: houses follow fields.", pid="cropland")}
</div>
<div class="grid g2">
{plate("What rangers actually log", X.incursion_calendar(), src="SMART patrol records", lib="calendar heatmap",
       use="<b>Incident calendar</b> — the ground-truth companion to every satellite layer: dry-season pulse plus a rising baseline. When satellite and patrol disagree, THAT is the alert.", pid="incursions")}
{plate("From proxy to alert",
       '<div class="rln"><span>lights Δ ≥ 25% q/q (5 km ring)</span><span class="chip warn">→ S2 encroachment</span></div>'
       '<div class="rln"><span>new building cluster ≥ 10 (Open Buildings)</span><span class="chip warn">→ S2 encroachment</span></div>'
       '<div class="rln"><span>cropland Δ ≥ 5 km² / yr (0–5 ring)</span><span class="chip fail">→ S3 review</span></div>'
       '<div class="rln"><span>patrol incidents &gt; p90 month</span><span class="chip fail">→ S3 + field team</span></div>',
       src="alert rules", pid="anthro-rules",
       use='This module exists to feed <a href="alerts.html" style="color:var(--acc)">Alerts</a> — thresholds stated here, audited there.')}
</div>"""
    page("ngoro-anthro.html", "Ngorongoro — Anthropogenic", "areas.html", NCA_CRUMB + ' / anthropogenic',
         "Encroachment as edge analytics: the unit of analysis is the boundary buffer, watched from space.",
         body, subnav(NCA_SUB, "ngoro-anthro.html"))


def build_nca_tourism():
    PL[0] = 158
    body = f"""
<div class="grid g2">
{plate("Camps &amp; lodges inventory", E.lodge_map(), src="OSM history + Google Open Buildings, real boundary",
       lib="proportional symbol map",
       use="<b>The inventory</b> — every camp/lodge sized by beds, colored by establishment year on the real NCA polygon. OSM history dates them; Open Buildings epochs measure their physical growth without anyone reporting it.", pid="lodgemap")}
{plate("Who carries the load", E.beds_lorenz(), src="bed capacity", lib="Lorenz curve",
       use="<b>Lorenz curve</b> — tourism concentration: the crater rim carries half the beds on 2% of the land. Gini ≈ 0.58 turns a siting debate into a number.", pid="lorenz")}
</div>
<h2 class="zone">Ecological effect</h2>
<div class="grid g2">
{plate("Does a lodge change the land?", E.baci_panel(), src="MODIS NDVI, matched sites", lib="BACI design",
       use="<b>BACI panel</b> (Before-After-Control-Impact — ecology's gold standard): NDVI around a 2019 lodge vs five matched controls. The post-construction divergence, not the level, is the effect.", pid="baci")}
{plate("Do animals avoid the lights?", E.wildlife_lodge_decay(), src="tracking + camera traps", lib="distance-decay per species",
       use="<b>Species-wise distance decay</b> — shy grazers dip below 1 near lodges, waste-attracted hyenas spike above it. Both directions are management findings.", pid="wl-decay")}
</div>
<h2 class="zone">Load &amp; resources</h2>
<div class="grid g2">
{plate("Visitor pressure through the year", X.visitors_envelope(), src="gate entries", lib="climatology envelope",
       use="<b>Seasonality envelope</b> — the migration-season and holiday peaks against fourteen years of record; 2024 running above the band is a capacity question, not a triumph.", pid="visitors")}
{plate("Thirty years of capacity", X.beds_growth(), src="licensing records", lib="step + line, dual axis",
       use="<b>Step chart</b> (capacity changes at openings, never between) — beds ×7 since 1995 while the land stayed the same size.", pid="beds")}
</div>
<div class="grid g2">
{plate("The water bill", X.water_demand(), src="borehole metering", lib="grouped bars vs limit",
       use="<b>Abstraction vs sustainable yield</b> — 2024 demand is closing on what the rim springs can give. The hardest number in this module, and the one that caps growth.", pid="water")}
{plate("From monitor to alert",
       '<div class="rln"><span>new footprint ≥ 500 m² unlicensed</span><span class="chip fail">→ S3 review</span></div>'
       '<div class="rln"><span>abstraction &gt; 85% of yield</span><span class="chip warn">→ S2 water</span></div>'
       '<div class="rln"><span>BACI divergence &gt; 2σ</span><span class="chip warn">→ S2 ecology</span></div>'
       '<div class="rln"><span>entries &gt; envelope 3 months running</span><span class="chip idle">→ S1 note</span></div>',
       src="alert rules", pid="tourism-rules",
       use='Tourism feeds <a href="alerts.html" style="color:var(--acc)">Alerts</a> like every module — growth is fine, unwatched growth is not.')}
</div>"""
    page("ngoro-tourism.html", "Ngorongoro — Tourism", "areas.html", NCA_CRUMB + ' / tourism',
         "The camps & lodges monitor: how tourism infrastructure expands, where it concentrates, and "
         "whether it bends vegetation and animal behaviour around it.",
         body, subnav(NCA_SUB, "ngoro-tourism.html"))


def build_nca_veg():
    PL[0] = 166
    body = f"""
<div class="grid">
{plate("This year against twenty", E.ndvi_envelope(), src="MODIS NDVI 16-day", lib="climatology envelope",
       use="<b>Envelope plot</b> — the current season against the 2003–23 min–max band and median: 'below the envelope since March' is a sentence a warden can act on.", pid="ndvi-env")}
</div>
<div class="grid">
{plate("Space × time in one plane", E.hovmoller(), src="MODIS NDVI by latitude band", lib="Hovmöller diagram",
       use="<b>Hovmöller</b> — the classic atmospheric-science idiom: latitude × time, color = NDVI. Seasonal green waves run as diagonal stripes; the 2021–22 drought cuts a vertical brown scar.", pid="hovmoller")}
</div>
<div class="grid g2">
{plate("Spring is moving", E.greenup_shift(), src="MODIS phenology", lib="trend scatter",
       use="<b>Phenology shift</b> — green-up date per year with OLS trend: climate change made visible as a calendar drift.", pid="greenup")}
{plate("Twelve years of anomalies", E.ndvi_anomaly_grid(), src="MODIS NDVI", lib="anomaly matrix",
       use="<b>Anomaly heat grid</b> — month × year, green above normal / brown below: droughts and exceptional wet seasons pop without a single axis label being read.", pid="ndvi-grid")}
</div>"""
    page("ngoro-veg.html", "Ngorongoro — Vegetation", "areas.html", NCA_CRUMB + ' / vegetation',
         "Greening or browning? MODIS productivity and phenology, in the idioms vegetation labs publish with.",
         body, subnav(NCA_SUB, "ngoro-veg.html"))


def build_nca_drought():
    PL[0] = 174
    body = f"""
<div class="grid">
{plate("Twenty-four years of wet and dry", E.spei_bars(), src="SPEI-6 from CHIRPS+ET", lib="drought index bars",
       use="<b>SPEI monitor</b> — the standard drought index as signed monthly bars; persistence, not single months, is what kills. 2016–17 and 2021–22 dominate the record.", pid="spei")}
</div>
<div class="grid g2">
{plate("How much of the park is dry?", E.drought_area_stack(), src="gridded SPEI classes", lib="USDM stacked area",
       use="<b>Drought-class extent</b> (US Drought Monitor idiom) — severity AND spatial extent stacked in one field; the D3 sliver appearing is the alarm.", pid="dstack")}
{plate("Is the soil unusual?", E.soil_moisture_pct(), src="ESA CCI soil moisture", lib="percentile-of-record",
       use="<b>Percentile framing</b> — not 'how much water' but 'how unusual for this date'; the dive into the watch band is the alert threshold made visual.", pid="soilpct")}
</div>
<h2 class="zone">Why this module matters — the cascade</h2>
<div class="grid">
{plate("Drought propagating through the system", X.cascade(), src="SPEI × NDVI × FIRMS × census", lib="aligned small multiples",
       use="<b>The cascade panel</b> — four modules on one shared clock: drought (SPEI) → browning (NDVI) → burning (fires) → overstocking. The shaded 2016–17 and 2021–22 bands propagate down the chain with a lag; this figure is the argument for cross-module architecture in one image.", pid="cascade")}
</div>"""
    page("ngoro-drought.html", "Ngorongoro — Drought monitor", "areas.html", NCA_CRUMB + ' / drought',
         "The stress dial: SPEI, drought-class extent and soil-moisture percentiles — early warning before "
         "the fire and forage modules feel it.",
         body, subnav(NCA_SUB, "ngoro-drought.html"))


def build_nca_livestock():
    PL[0] = 182
    body = f"""
<div class="grid g2">
{plate("Sixty years of herds", E.herd_trend(), src="NCAA census + FAO GLW", lib="annotated trend lines",
       use="<b>Census series with event annotations</b> — the shoat curve quietly overtaking cattle is a drought-adaptation signature; policy events drawn on the axis where they happened.", pid="herds")}
{plate("Where the pressure sits", E.grazing_map(), src="FAO GLW gridded, real boundary", lib="masked choropleth",
       use="<b>Gridded density inside the real polygon</b> — and the crater's grazing-free hole shows policy as geography.", pid="grazemap")}
</div>
<div class="grid g2">
{plate("Demand vs what the land grows", E.stocking_balance(), src="census ÷ MODIS NPP", lib="ratio-to-capacity line",
       use="<b>Stocking ratio</b> — herd demand over forage supply with the 1.0 line drawn; crossing it is overstocking, and droughts reset it the hard way.", pid="stocking")}
{plate("The coexistence question", E.livestock_wildlife(), src="shared transects", lib="regression scatter",
       use="<b>Competition regression</b> — wildlife use declining ~0.6 per unit livestock use: the multi-use mandate's central trade-off, quantified.", pid="lw")}
</div>"""
    page("ngoro-livestock.html", "Ngorongoro — Livestock & grazing", "areas.html", NCA_CRUMB + ' / livestock',
         "NCA's defining module: a multi-use landscape where pastoralism is legal, historic and measured — "
         "not a violation but a variable.",
         body, subnav(NCA_SUB, "ngoro-livestock.html"))


# ═══════════════════════ SERENGETI SUB-APP ═══════════════════════
SER_CRUMB = '<a href="index.html">uhifadhi</a> / <a href="areas.html">areas</a> / serengeti'


def build_ser_hub():
    PL[0] = 40
    body = f"""
<div class="grid g4">
{kpi("Area", "14,763", "km²", "WDPA · IUCN II")}
{kpi("Burned 2023", "3,280", "km²", "85% prescribed", hot=True)}
{kpi("Active fires", "26", "", "VIIRS · last 24 h")}
{kpi("Program", "72", "%", "of 2024 plan burned")}
</div>
<div class="grid">
{plate("Burn operations map — real imagery", FI.ser_map(), src="Esri World Imagery z9 · VIIRS · burn blocks",
       lib="leaflet in-app",
       use="<b>Real Serengeti satellite mosaic</b> with the burn-block plan and last-24 h VIIRS detections. Block color = seasons since last burn — the fuel-load map every fire officer keeps in their head, drawn.",
       pid="ser-map", stamp="EPSG:3857 · z9 · blocks illustrative")}
</div>
<h2 class="zone">Modules</h2>
<div class="grid g4">
{module_card("ser-fires.html", "Fire management", "The prescribed-burn program: accounting, progress, severity, return intervals.", "demo")}
{module_card("ser-bio.html", "Biodiversity &amp; movement", "The migration loop, occurrence hexmaps, activity clocks, range overlap.", "demo")}
{module_card("ser-air.html", "Air &amp; smoke", "Burn-season aerosols, smoke-days vs area burned, plume-bearing rose.", "demo")}
{module_card(None, "Forest · Climate · Stations …", "Light up as ingestion tracks land — one skeleton, sixteen parks.", "off")}
</div>"""
    page("area-serengeti.html", "Serengeti National Park", "areas.html", SER_CRUMB,
         "Second park hub — same sub-app skeleton as Ngorongoro, different modules lit first: fire, "
         "the migration, and the airshed the burns feed.",
         body, subnav(SER_SUB, "area-serengeti.html", planned=("Forest", "Climate", "Stations")))


def build_ser_fires():
    PL[0] = 44
    body = f"""
<div class="grid">
{plate("Same scene, before and after the program", FI.before_after(), src="Esri imagery, June vs September",
       lib="split-pane comparison",
       use="<b>Before/after pair</b> — the same real scene; September carries the burn-scar mosaic. In-app this is a slider over two real Sentinel-2 composites.", pid="beforeafter")}
</div>
<h2 class="zone">Program accounting</h2>
<div class="grid g2">
{plate("Prescribed vs wildfire", FI.burned_stacked(), src="MODIS burned area MCD64A1", lib="stacked bars",
       use="<b>Stacked bars</b> — the program thesis in one figure: years with more early prescribed burning (straw) show less late wildfire (ember).", pid="burnstack")}
{plate("Season progress", FI.burn_progress(), src="ops log", lib="progress curves",
       use="<b>Progress curves</b> — cumulative % of plan by week, against target: operations tracking, not just science.", pid="burnprogress")}
</div>
<div class="grid g2">
{plate("Fire-return distribution", FI.time_since_hist(), src="burn blocks", lib="histogram",
       use="<b>Return-interval histogram</b> — the 3-year rotation shows as mass at 0–2 seasons; the 6y+ tail is accumulating fuel.", pid="firereturn")}
{plate("The fire year as shape", FI.fire_rose(), src="VIIRS 2012–23", lib="plotly barpolar",
       use="<b>Fire rose</b> — Jun–Sep program fan plus the late-dry wildfire tail; one glance separates them.", pid="firerose")}
</div>
<h2 class="zone">Severity &amp; behaviour</h2>
<div class="grid g2">
{plate("Burn severity by block", FI.severity_stacked(), src="Landsat dNBR", lib="100% stacked",
       use="<b>dNBR severity classes</b> — patchy low-severity mosaics are the management GOAL; a block going deep red gets a review.", pid="severity")}
{plate("How hot do they burn?", FI.frp_ecdf(), src="VIIRS FRP", lib="two-sample ECDF",
       use="<b>Two-sample ECDF</b> — prescribed burns run cool; the wildfire curve's hot tail IS the argument for the program. The cleanest two-distribution comparison in statistics.", pid="frp")}
</div>
<div class="grid g2">
{plate("Fuel follows rain", FI.rain_burn_scatter(), src="CHIRPS × MCD64A1", lib="regression scatter",
       use="<b>Regression scatter</b> — wet years grow grass, grass carries fire: the relationship that lets the program scale itself by last season's rainfall.", pid="rainburn")}
{plate("Detections calendar", C.fire_calendar(), src="VIIRS", lib="calendar heatmap", pid="ser-firecal",
       use="<b>Calendar heatmap</b> — the same idiom as Ngorongoro's, so parks compare instantly.")}
</div>"""
    page("ser-fires.html", "Serengeti — Fire management", "areas.html", SER_CRUMB + ' / fires',
         "Controlled early-season burning for grass regeneration, as an analytical module: real imagery, "
         "program accounting, severity, return intervals — science and operations on one page.",
         body, subnav(SER_SUB, "ser-fires.html"))


def build_ser_bio():
    PL[0] = 190
    body = f"""
<div class="grid">
{plate("The great migration, on the real map", E.migration_map(), src="Movebank collars · Esri imagery",
       lib="flow map",
       use="<b>Flow map on real imagery</b> — the ~1.3 M-head clockwise loop with month stations: calving in the south, the western corridor, the Mara crossings. In-app this is an animated month scrubber over Leaflet.",
       pid="migration", stamp="z9 mosaic · loop schematic from collar literature")}
</div>
<div class="grid g2">
{plate("Where the records are", E.occurrence_hex(), src="GBIF", lib="hexbin map",
       use="<b>Occurrence hexmap</b> — records AND effort at once; blank hexes are unsurveyed, not empty, and that caveat belongs on the figure.", pid="occhex")}
{plate("Who is awake when", E.activity_clock(), src="camera traps", lib="polar activity clock",
       use="<b>Activity clock</b> — 24 h as a circle, one curve per species: crepuscular lion, midday zebra, strictly nocturnal leopard. Temporal niche partitioning in one figure.", pid="clock")}
</div>
<div class="grid g2">
{plate("Is the inventory complete?", E.accumulation_curve(), src="survey records", lib="rarefaction ± CI",
       use="<b>Species-accumulation / rarefaction</b> — flattening curve = inventory nearly complete; still-rising = keep surveying. The figure that disciplines biodiversity claims.", pid="accum")}
{plate("Who shares ground with whom", E.range_overlap(), src="IUCN ranges ∩ tracking", lib="overlap matrix",
       use="<b>Range-overlap matrix</b> — the grazer guild moves as one; elephant walks alone. Feeds corridor planning between parks.", pid="overlap")}
</div>"""
    page("ser-bio.html", "Serengeti — Biodiversity & movement", "areas.html", SER_CRUMB + ' / biodiversity',
         "Where the animals are, when they move, and how complete our knowledge is — GBIF, collars and "
         "camera traps in their canonical figures.",
         body, subnav(SER_SUB, "ser-bio.html"))


def build_ser_air():
    PL[0] = 198
    body = f"""
<div class="grid g2">
{plate("The burn-season hump", E.aod_envelope(), src="MODIS/S5P AOD", lib="climatology envelope",
       use="<b>Aerosol envelope</b> — this year's optical depth against the 20-year band; a dirty burn season shows as the ember line riding the top of the hump.", pid="aod")}
{plate("The airshed cost of the program", E.smoke_vs_burn(), src="AOD × MCD64A1", lib="regression scatter",
       use="<b>Smoke-days vs area burned</b> — the trade-off the EIA asks about, as a fitted line instead of an argument.", pid="smokeburn")}
</div>
<div class="grid g2">
{plate("Where the smoke goes", E.plume_rose(), src="S5P plumes × ERA5 winds", lib="bearing rose",
       use="<b>Plume-bearing rose</b> — smoke tracks WSW→ENE with the trades, so the Mara side is downwind: schedule burns on NE-wind days. Operations advice straight from a polar chart.", pid="plume")}
{plate("Ties into", '<div class="rln"><span>Fire management module</span>'
       '<a href="ser-fires.html" class="acc" style="text-decoration:none;font-weight:700">ser-fires →</a></div>'
       '<div class="rln"><span>Weather stations (wind obs)</span>'
       '<a href="ngoro-stations.html" class="acc" style="text-decoration:none;font-weight:700">stations →</a></div>'
       '<div class="rln"><span>Drought monitor (fuel state)</span>'
       '<a href="ngoro-drought.html" class="acc" style="text-decoration:none;font-weight:700">drought →</a></div>',
       src="cross-module", pid="air-links",
       use="Air quality is a derivative module — it exists to close the loop between fire, weather and health.")}
</div>
<h2 class="zone">Composition &amp; health</h2>
<div class="grid g2">
{plate("Three gases, one wave", X.gas_facets(), src="Sentinel-5P columns", lib="small multiples",
       use="<b>Trace-gas facets</b> — NO₂, CO and aerosol as multiples of their wet-season floor: all ride the burn-season wave, CO hardest. Same axes, so only the chemistry differs.", pid="gases")}
{plate("The health framing", X.exceed_strip(), src="vs WHO PM₂.₅ guideline", lib="exceedance calendar",
       use="<b>Guideline-exceedance calendar</b> — burn season costs downwind villages 8–12 guideline days a month. The number that turns an emissions chart into a public-health conversation.", pid="exceed")}
</div>"""
    page("ser-air.html", "Serengeti — Air & smoke", "areas.html", SER_CRUMB + ' / air',
         "Sentinel-5P and MODIS aerosols during burn season: the atmospheric half of the fire program.",
         body, subnav(SER_SUB, "ser-air.html"))


# ═══════════════════════ OTHER NATIONAL PAGES ═══════════════════════
def build_compare():
    PL[0] = 50
    body = f"""
<h2 class="zone">The national field</h2>
<div class="grid g32">
{plate("Gridded loss intensity", X.sat_grid_choropleth(), src="Hansen 0.45° cells · Esri imagery", lib="leaflet heat layer",
       use="<b>Gridded choropleth on real ground</b> — geolocated data belongs on real imagery, never a blank outline: the miombo belt and Eastern Arc glow exactly where the forests actually are, and the empty cells are visibly steppe.", pid="grid-choropleth")}
{plate("Reading it", '<div class="use" style="margin-top:0">The comparisons below rank <b>parks</b>; this map shows the <b>field</b> they sit in. '
       'A park can rank badly simply because it straddles a hot cell — normalize before you blame management. '
       'That distinction is why the map lives here, next to the rankings it disciplines, and not on the overview.</div>', pid="field-note")}
</div>
<h2 class="zone">Distributions — how loss behaves, not just averages</h2>
<div class="grid g2">
{plate("Loss distributions, stacked", C.ridgeline(), src="all PAs", lib="seaborn ridgeline/joyplot",
       use="<b>Ridgeline</b> — eight densities share one axis; Nyerere's whole distribution sits an order of magnitude right of Kilimanjaro's.", pid="ridgeline")}
{plate("Five-number summaries", C.boxplot(), src="all PAs", lib="seaborn boxplot",
       use="<b>Box plot</b> — quartiles, whiskers, outliers: the ANOVA-culture standard.", pid="box")}
</div>
<div class="grid g2">
{plate("Shape + every raw year", C.violin_swarm(), src="6 PAs", lib="seaborn violinplot + swarmplot",
       use="<b>Violin + swarm</b> — the density silhouette with the actual 23 points on top. Never hide n when n is small.", pid="violin")}
{plate("Just the raw points", C.stripplot(), src="10 PAs", lib="seaborn stripplot",
       use="<b>Strip/jitter</b> — the minimal honest categorical scatter; use before summarizing anything.", pid="strip")}
</div>
<h2 class="zone">Change &amp; rank</h2>
<div class="grid g2">
{plate("Forest cover, then vs now", C.dumbbell(), src="Hansen-derived", lib="dumbbell",
       use="<b>Dumbbell</b> — two epochs joined by a bar: magnitude of change AND level per PA.", pid="dumbbell")}
{plate("Pressure league, two decades", C.slope(), src="loss density rank", lib="slopegraph (Tufte)",
       use="<b>Slope chart</b> — rank flow between periods; the red climbers are tomorrow's problems.", pid="slope")}
</div>
<div class="grid g2">
{plate("Decade split", C.stacked_decade(), src="Hansen", lib="stacked bar",
       use="<b>Stacked bars</b> — is each PA's loss historic or current?", pid="stacked-decade")}
{plate("Distance from the median", C.diverging_bars(), src="loss density", lib="diverging bar",
       use="<b>Diverging bars</b> — sign first, size second; the network median as the zero line.", pid="diverging")}
</div>
<h2 class="zone">Multivariate structure</h2>
<div class="grid g2">
{plate("Size vs loss vs forest", C.bubble_scatter(), src="PA table", lib="plotly scatter (bubble)",
       use="<b>Bubble scatter, log–log</b> — three variables, no 3-D.", pid="bubble")}
{plate("Every pair at once", C.splom(), src="PA table", lib="seaborn pairplot / SPLOM",
       use="<b>Scatter-plot matrix</b> — all pairwise relationships plus marginals; the first figure of any EDA.", pid="splom")}
</div>
<div class="grid g2">
{plate("Sixteen profiles, five axes", C.parcoords(), src="PA table", lib="plotly parcoords",
       use="<b>Parallel coordinates</b> — each PA a line; crossing bundles = negative correlation. Jade = Ngorongoro.", pid="parcoords")}
{plate("One profile vs the mean", C.radar(), src="PA table", lib="plotly radar",
       use="<b>Radar</b> — one entity's shape against a reference; kept to exactly this use.", pid="radar")}
</div>
<div class="grid g2">
{plate("The full history grid", C.matrix_heatmap(), src="PA × year", lib="seaborn heatmap",
       use="<b>Matrix heatmap</b> — 10 PAs × 23 years, row-normalized.", pid="matrix-heat")}
{plate("Estimates with uncertainty", C.cleveland_ci(), src="mean ± 95% CI", lib="geom_pointrange",
       use="<b>Cleveland dots + CI</b> — when the error bar matters, dots beat bars.", pid="cleveland")}
</div>"""
    page("compare.html", "Compare areas", "compare.html",
         '<a href="index.html">uhifadhi</a> / compare',
         "The cross-sectional lab: sixteen protected areas held against each other — distributions first, "
         "then change, then multivariate structure.", body)


def build_alerts():
    PL[0] = 210
    rules = (
        '<div class="rln"><span><b class="disp">deforestation</b> <span class="mono d">GLAD/RADD ≥ 10 px cluster / 7 d</span></span>'
        '<a href="ngoro-forest.html" class="acc mono" style="font-size:9px;text-decoration:none">forest →</a></div>'
        '<div class="rln"><span><b class="disp">fire</b> <span class="mono d">VIIRS detection outside plan ∨ outside season</span></span>'
        '<a href="ser-fires.html" class="acc mono" style="font-size:9px;text-decoration:none">fire →</a></div>'
        '<div class="rln"><span><b class="disp">hydrology</b> <span class="mono d">lake level |z| ≥ 2 vs day-of-year record</span></span>'
        '<span class="chip idle">water module planned</span></div>'
        '<div class="rln"><span><b class="disp">encroachment</b> <span class="mono d">lights/built-up Δ ≥ 25% q/q in 5 km ring</span></span>'
        '<a href="ngoro-anthro.html" class="acc mono" style="font-size:9px;text-decoration:none">anthro →</a></div>'
        '<div class="rln"><span><b class="disp">station</b> <span class="mono d">no telemetry &gt; 24 h</span></span>'
        '<a href="ngoro-stations.html" class="acc mono" style="font-size:9px;text-decoration:none">stations →</a></div>'
        '<div class="rln"><span><b class="disp">movement</b> <span class="mono d">collared herd leaves corridor envelope</span></span>'
        '<a href="ser-bio.html" class="acc mono" style="font-size:9px;text-decoration:none">biodiversity →</a></div>')
    body = f"""
<div class="grid g4">
{kpi("Open alerts", "9", "", "3 urgent · S3")}
{kpi("Confirm rate", "52", "%", "of field-checked", hot=True)}
{kpi("Median verify", "14", "h", "vs 48 h SLA")}
{kpi("Noisiest stream", "31", "% FP", "nightlights — tune it")}
</div>
<div class="grid g32">
{plate("Active alerts", X.alerts_map_toggle("al"), src="all streams, live", lib="leaflet + layer control",
       use="<b>The alert map, with a working basemap toggle</b> — satellite for ground truth, street map for names and roads when directing a field team; the ⤢ expands to full screen in-app. Radius = severity; every dot deep-links to its alert.", pid="alerts-map")}
{plate("The inbox", feed_html(6), src="triage queue", pid="alerts-feed",
       use="Severity-first, newest within severity. Every alert deep-links into the module that raised it — alerts are the front door to the whole app.")}
</div>
<div class="grid">
{plate("A year of alerts", X.alert_volume(), src="52 weeks, by stream", lib="stacked area",
       use="<b>Volume by stream</b> — the network's heartbeat: the fire-season swell, the wet-season deforestation pulse. A flat line here would mean the sensors are broken, not that the parks are safe.", pid="alert-volume")}
</div>
<h2 class="zone">Operations — is the alerting system itself healthy?</h2>
<div class="grid g2">
{plate("When alerts arrive", X.punchcard(), src="90 days", lib="GitHub punchcard",
       use="<b>Punchcard</b> — mid-morning satellite-pass processing dominates; thin weekends reveal how many alerts are human-raised. Staffing follows this figure.", pid="punchcard")}
{plate("Load by stream × severity", X.sev_matrix(), src="90 days", lib="annotated matrix",
       use="<b>Severity matrix</b> — S3s are rare by design; severity inflation is the death of alert systems, and this grid is the audit.", pid="sevmatrix")}
</div>
<div class="grid g2">
{plate("What happens to an alert", X.triage_sankey(), src="lifecycle log", lib="sankey",
       use="<b>Triage sankey</b> — raised → verified → outcome. 52% confirm; the 14% that EXPIRE unreviewed is the management number on this page.", pid="triage")}
{plate("How fast we verify", X.latency_ecdf(), src="verification log", lib="ECDF vs SLA",
       use="<b>Latency ECDFs vs the 48 h SLA</b> — fire is fast, encroachment waits too long: a staffing argument in one figure.", pid="latency")}
</div>
<div class="grid g2">
{plate("Which streams cry wolf", X.fp_rates(), src="field verification", lib="pointrange",
       use="<b>False-positive rates ± CI</b> — the feedback loop that tunes thresholds. Nightlights at 31% needs its quarter-on-quarter cutoff raised.", pid="fprates")}
{plate("The rulebook", rules, src="alert definitions", pid="rules",
       use="Every trigger is a stated, versioned rule linking back to its module — no black-box alerts. This card IS the contract between modules and the inbox.")}
</div>"""
    page("alerts.html", "Alerts", "alerts.html",
         '<a href="index.html">uhifadhi</a> / alerts',
         "The cross-cutting module: every other module's job is ultimately to raise a flag here, early "
         "and honestly. Half this page watches the parks; the other half watches the alerting system itself.",
         body)


def build_ingestion():
    PL[0] = 110
    cards = []
    for rid, area, src, st, note, prog, result in D.RUNS:
        chip = {"running": '<span class="chip acc">running</span>',
                "awaiting_input": '<span class="chip warn">awaiting input</span>',
                "succeeded": '<span class="chip ok">succeeded</span>',
                "failed": '<span class="chip fail">failed</span>'}[st]
        extra = ''
        if st == 'running':
            extra = (f'<div class="prog" style="margin-top:8px"><i style="width:{prog}%"></i></div>'
                     f'<div class="mono d" style="font-size:9px;margin-top:4px">{note} · {prog}%</div>')
        elif st == 'awaiting_input':
            extra = (f'<div style="margin-top:8px;font-size:12px" class="fog">{note}</div>'
                     f'<div style="display:flex;gap:8px;margin-top:8px">'
                     f'<span class="cta" style="font-size:10.5px;padding:5px 11px">Use station A</span>'
                     f'<span class="chip idle" style="padding:5px 11px">Compare series…</span></div>')
        elif st == 'failed':
            extra = f'<div class="mono r" style="font-size:10px;margin-top:6px">{note} — {result}</div>'
        else:
            extra = f'<div class="mono d" style="font-size:10px;margin-top:6px">{result}</div>'
        cards.append(f'<div class="rln" style="flex-direction:column;align-items:stretch;padding:10px 2px">'
                     f'<div style="display:flex;justify-content:space-between;align-items:center">'
                     f'<span><span class="mono d">#{rid}</span> &nbsp;<b class="disp">{area}</b> '
                     f'<span class="mono fog">· {src}</span></span>{chip}</div>{extra}</div>')
    log_p = plate("Live — run #7",
        '<div class="mono" style="font-size:10px;color:var(--fog);line-height:2">'
        '14:02:11 <span style="color:var(--tx)">gdalwarp clip</span> <span class="g">✓</span> nodata 255<br>'
        '14:03:40 <span style="color:var(--tx)">gdal raster polygonize</span> <span class="acc">▮▮▮▮▮▯▯▯ 62%</span><br>'
        '<span class="d">next → ORM staging load → ST_Union dissolve → loss years</span></div>',
        src="mercure stream", pid="live-log")
    worker_p = plate("Worker",
        '<div class="rln"><span>messenger · async</span><span class="live" style="font-size:10px"><i></i>online</span></div>'
        '<div class="rln"><span>queue depth</span><span class="mono d">1 message</span></div>'
        '<div class="rln"><span>memory cap</span><span class="mono d">1G</span></div>', pid="worker")
    body = f"""
<div class="grid g32">
{plate("Dataset runs — all areas", ''.join(cards), src="dataset_run", pid="runs",
       use="Runs are jobs you watch: progress for the long ones, <b>questions instead of failures</b> for the ambiguous ones, provenance for the finished ones.")}
<div style="display:flex;flex-direction:column;gap:20px">{log_p}{worker_p}</div>
</div>
<div class="grid g2">
{plate("Coverage produced so far", C.gantt(), src="dataset_run", lib="plotly timeline", pid="coverage")}
{plate("Shelf growth", C.step_chart(), src="dataset_run", lib="step chart", pid="shelf")}
</div>"""
    page("ingestion.html", "Ingestion console", "ingestion.html",
         '<a href="index.html">uhifadhi</a> / runs',
         "The ops room. Async jobs with live Mercure status; ambiguity surfaces as an "
         "awaiting-input question, never a stack trace.", body)


def build_newarea():
    PL[0] = 120
    body = f"""
<div class="grid g32">
{plate("Boundary upload",
       '<input class="fld" placeholder="Area name — e.g. Katavi National Park" style="margin-bottom:12px">'
       '<div style="border:1.5px dashed color-mix(in srgb,var(--acc) 45%,transparent);border-radius:12px;'
       'padding:44px 20px;text-align:center;color:var(--fog);font-size:13px;line-height:1.8;'
       'background:color-mix(in srgb,var(--acc) 4%,transparent)">Drop your boundary here<br>'
       '<span class="mono" style="font-size:10px;color:var(--dim)">GeoJSON · zipped Shapefile · GeoPackage · '
       'KML/KMZ · zipped FGDB — WDPA nested archives are scanned automatically</span></div>'
       '<div style="display:flex;margin-top:14px"><span class="cta" style="margin-left:auto">Import boundary</span></div>',
       src="BoundaryImportService", pid="upload")}
{plate("Import pipeline — live",
       '<div class="rln"><span class="g mono">✓</span><span>archive scanned — 3 nested zips found</span></div>'
       '<div class="rln"><span class="g mono">✓</span><span>polygon layer picked</span></div>'
       '<div class="rln"><span class="live" style="font-size:10px"><i></i></span><span>reprojecting → EPSG:4326</span></div>'
       '<div class="rln"><span class="d mono">○</span><span class="d">persist via ORM</span></div>'
       '<div class="rln"><span class="d mono">○</span><span class="d">module activation offer</span></div>'
       '<div class="mono" style="margin-top:10px;font-size:9.5px;color:var(--dim);border-top:1px dashed '
       'color-mix(in srgb,var(--fog) 25%,transparent);padding-top:10px">ambiguous archives pause and ask — '
       'see <a href="ingestion.html" style="color:var(--acc)">Runs</a></div>',
       src="mercure", pid="pipeline")}
</div>"""
    page("new-area.html", "New area", "new-area.html",
         '<a href="index.html">uhifadhi</a> / new area',
         "Upload as a watched job: the form is small, the pipeline is the page. A finished import "
         "births a new park sub-app.", body)


def build_gallery():
    FAMS = [
        ("Geospatial & imagery", [
            ("Alert map on satellite basemap", "leaflet", "index.html#natl-map", "what-where duty view, any country"),
            ("Gridded choropleth", "plotly choropleth", "compare.html#grid-choropleth", "intensity independent of boundaries"),
            ("Satellite + data overlay", "leaflet", "area-ngorongoro.html#nca-map", "ground truth under the numbers (real tiles)"),
            ("Burn-operations map", "leaflet + plan layer", "area-serengeti.html#ser-map", "management state on real imagery"),
            ("Before/after imagery pair", "split-pane", "ser-fires.html#beforeafter", "change as evidence"),
            ("Station health map", "status map", "ngoro-stations.html#stationmap", "network liveness at a glance"),
        ]),
        ("Ranking & magnitude", [
            ("Ranked horizontal bars", "seaborn barplot", "index.html#ranked-bar", "ordered comparison"),
            ("Lollipop", "ggplot geom_lollipop", "index.html#lollipop", "ranking with less ink"),
            ("Cleveland dots + CI", "geom_pointrange", "compare.html#cleveland", "estimates with uncertainty"),
            ("Diverging bars", "matplotlib barh", "compare.html#diverging", "signed distance from reference"),
            ("Bullet chart", "Few's bullet", "index.html#bullet", "KPI vs target — the honest gauge"),
            ("Waterfall", "plotly waterfall", "ngoro-forest.html#waterfall", "additive decomposition"),
        ]),
        ("Part-of-whole & flow", [
            ("Treemap", "plotly treemap", "index.html#treemap", "hierarchical area"),
            ("Sunburst", "plotly sunburst", "ngoro-landcover.html#sunburst", "hierarchy as rings"),
            ("Donut", "plotly pie", "index.html#donut", "single share-of-whole glance"),
            ("Waffle", "pywaffle", "index.html#waffle", "countable percentages"),
            ("100% stacked bars", "matplotlib", "ngoro-landcover.html#pct-stacked", "composition across entities"),
            ("Stacked area", "stackplot", "ngoro-landcover.html#stacked-area", "composition over time"),
            ("Sankey", "plotly sankey", "ngoro-landcover.html#sankey", "flows and transitions"),
        ]),
        ("Time series", [
            ("Annotated bars", "matplotlib annotate", "ngoro-forest.html#annual", "yearly amounts + outlier honesty"),
            ("Cumulative curve", "fill_between", "ngoro-forest.html#cumulative", "running totals"),
            ("Step chart", "matplotlib step", "ingestion.html#shelf", "counts that change at events"),
            ("Streamgraph", "d3/plotly", "index.html#streamgraph", "flowing stacked series"),
            ("Horizon chart", "d3 horizon", "ngoro-climate.html#horizon", "long series at sparkline height"),
            ("Seasonal subseries", "statsmodels month_plot", "ngoro-climate.html#subseries", "cycle vs stability"),
            ("Calendar heatmap", "calmap", "area-ngorongoro.html#fire-cal", "events on the calendar grid"),
            ("Sparkline table", "Tufte", "areas.html#spark-table", "a series per table row"),
            ("Gantt / timeline", "plotly timeline", "ngoro-forest.html#gantt", "coverage and spans"),
            ("Scenario ribbons", "fan chart", "ngoro-climate.html#ribbons", "projections with uncertainty"),
            ("Connected scatter", "NYT-style", "ngoro-climate.html#connected", "two variables through time"),
            ("Anomaly bars", "NOAA style", "ngoro-climate.html#anomaly", "departure from a normal"),
            ("Progress curves", "ops chart", "ser-fires.html#burnprogress", "plan vs actual by week"),
        ]),
        ("Meteorology (station canon)", [
            ("Meteogram", "MetPy/DWD", "ngoro-stations.html#meteogram", "the station's signature stacked figure"),
            ("Wind rose", "windrose", "ngoro-stations.html#windrose", "direction × frequency × speed"),
            ("Warming stripes", "Hawkins", "ngoro-stations.html#stripes", "44 years, zero axes, undeniable"),
            ("Min–max band ribbon", "NYT weather", "ngoro-stations.html#tempribbon", "daily range vs its own normal"),
            ("Hyetograph + accumulation", "hydrology std", "ngoro-stations.html#rainevent", "rain event, dual axis"),
            ("Soil depth × time heatmap", "micromet", "ngoro-stations.html#soilprofile", "the damped diurnal wave"),
            ("Barogram + tendency", "synoptic", "ngoro-stations.html#pressure", "pressure the way forecasters read it"),
            ("Gust envelope", "range plot", "ngoro-stations.html#gust", "the envelope is the hazard"),
            ("Weibull wind fit", "wind industry", "ngoro-stations.html#weibull", "speed distribution + model"),
            ("Diurnal composite", "micromet", "ngoro-stations.html#diurnal", "the average day ±1σ"),
        ]),
        ("Fire management", [
            ("Prescribed vs wildfire stack", "MCD64A1", "ser-fires.html#burnstack", "the program thesis"),
            ("Fire-return histogram", "fire ecology", "ser-fires.html#firereturn", "rotation vs fuel load"),
            ("dNBR severity stack", "Landsat dNBR", "ser-fires.html#severity", "patchiness as the goal"),
            ("Two-sample FRP ECDF", "statistics", "ser-fires.html#frp", "cool program vs hot wildfire"),
            ("Fire rose", "barpolar", "ser-fires.html#firerose", "the season as shape"),
            ("Fuel–rain regression", "OLS", "ser-fires.html#rainburn", "scale the program by rainfall"),
        ]),
        ("Alerts & operations", [
            ("Alert status map", "duty-officer screen", "alerts.html#alerts-map", "everything open, at its place"),
            ("Volume by stream", "stacked area", "alerts.html#alert-volume", "the network's heartbeat"),
            ("Arrival punchcard", "GitHub idiom", "alerts.html#punchcard", "when alerts land → staffing"),
            ("Triage sankey", "lifecycle flow", "alerts.html#triage", "what happens to an alert"),
            ("Latency ECDF vs SLA", "ops statistics", "alerts.html#latency", "how fast we verify"),
            ("False-positive pointrange", "verification loop", "alerts.html#fprates", "which streams cry wolf"),
            ("Stream × severity matrix", "audit grid", "alerts.html#sevmatrix", "severity inflation check"),
            ("Cascade panel", "aligned multiples", "ngoro-drought.html#cascade", "one event through four modules"),
        ]),
        ("Anthropogenic & tourism", [
            ("Nightlights facets", "VIIRS", "ngoro-anthro.html#lights", "edge growth, monthly cadence"),
            ("Cropland-by-ring stack", "GFW", "ngoro-anthro.html#cropland", "agriculture walking to the fence"),
            ("Incident calendar", "SMART patrols", "ngoro-anthro.html#incursions", "ground truth vs satellite"),
            ("Visitor seasonality envelope", "gate entries", "ngoro-tourism.html#visitors", "capacity vs record"),
            ("Capacity step chart", "licensing", "ngoro-tourism.html#beds", "beds ×7, land ×1"),
            ("Abstraction vs yield", "borehole metering", "ngoro-tourism.html#water", "the number that caps growth"),
            ("Buffer-ring trends", "GHSL analysis", "ngoro-anthro.html#rings", "encroachment converging on the fence"),
            ("Distance-decay (population)", "WorldPop", "ngoro-anthro.html#decay", "the cliff at the boundary"),
            ("Segmented edge-pressure map", "composite index", "ngoro-anthro.html#edgemap", "the boundary as the chart"),
            ("Proportional-symbol inventory", "OSM + Open Buildings", "ngoro-tourism.html#lodgemap", "lodges sized, dated, mapped"),
            ("Lorenz curve", "econometrics", "ngoro-tourism.html#lorenz", "tourism concentration as a Gini"),
            ("BACI panel", "ecology gold standard", "ngoro-tourism.html#baci", "impact = post-intervention divergence"),
            ("Species distance-decay", "movement ecology", "ngoro-tourism.html#wl-decay", "avoidance and attraction, per species"),
        ]),
        ("Vegetation & drought", [
            ("Climatology envelope", "GIMMS/MODIS", "ngoro-veg.html#ndvi-env", "this year vs twenty"),
            ("Hovmöller diagram", "atmospheric science", "ngoro-veg.html#hovmoller", "space × time in one plane"),
            ("Phenology-shift trend", "MODIS phenology", "ngoro-veg.html#greenup", "spring moving on the calendar"),
            ("Anomaly heat grid", "month × year", "ngoro-veg.html#ndvi-grid", "droughts pop without axes"),
            ("SPEI index bars", "drought canon", "ngoro-drought.html#spei", "persistence is what kills"),
            ("Drought-class extent stack", "US Drought Monitor", "ngoro-drought.html#dstack", "severity AND extent"),
            ("Percentile-of-record line", "ESA CCI", "ngoro-drought.html#soilpct", "how unusual, not how much"),
        ]),
        ("Biodiversity & movement", [
            ("Migration flow map", "Movebank + imagery", "ser-bio.html#migration", "the loop with month stations"),
            ("Occurrence hexmap", "GBIF", "ser-bio.html#occhex", "records and effort together"),
            ("Activity clock", "camera traps", "ser-bio.html#clock", "temporal niches on a 24 h dial"),
            ("Rarefaction ± CI", "community ecology", "ser-bio.html#accum", "is the inventory complete?"),
            ("Range-overlap matrix", "IUCN ∩ tracking", "ser-bio.html#overlap", "who shares ground with whom"),
        ]),
        ("Air, smoke & livestock", [
            ("Aerosol envelope", "S5P/MODIS AOD", "ser-air.html#aod", "the burn-season hump"),
            ("Smoke-vs-burn regression", "AOD × MCD64A1", "ser-air.html#smokeburn", "the airshed trade-off"),
            ("Plume-bearing rose", "S5P × ERA5", "ser-air.html#plume", "where the smoke goes"),
            ("Trace-gas facets", "Sentinel-5P", "ser-air.html#gases", "three gases, one wave"),
            ("Exceedance calendar", "WHO guideline", "ser-air.html#exceed", "emissions → public health"),
            ("Annotated census series", "NCAA + FAO GLW", "ngoro-livestock.html#herds", "policy events on the axis"),
            ("Masked grazing choropleth", "FAO GLW", "ngoro-livestock.html#grazemap", "policy visible as geography"),
            ("Stocking-ratio line", "census ÷ NPP", "ngoro-livestock.html#stocking", "demand vs carrying capacity"),
            ("Competition regression", "shared transects", "ngoro-livestock.html#lw", "coexistence quantified"),
        ]),
        ("Distributions", [
            ("Histogram + KDE", "seaborn histplot", "ngoro-stats.html#histkde", "counts + shape"),
            ("ECDF", "seaborn ecdfplot", "ngoro-stats.html#ecdf", "bin-free distribution"),
            ("Box plot", "seaborn boxplot", "compare.html#box", "five-number summaries"),
            ("Violin + swarm", "violin+swarm", "compare.html#violin", "shape plus every raw point"),
            ("Strip / jitter", "seaborn stripplot", "compare.html#strip", "minimal categorical scatter"),
            ("Ridgeline", "joyplot", "compare.html#ridgeline", "many densities, one axis"),
            ("Q–Q plot", "statsmodels", "ngoro-stats.html#qq", "assumption check"),
        ]),
        ("Relationships & multivariate", [
            ("Bubble scatter (log–log)", "plotly scatter", "compare.html#bubble", "three variables, no 3-D"),
            ("Scatter-plot matrix", "seaborn pairplot", "compare.html#splom", "all pairs at once"),
            ("Parallel coordinates", "plotly parcoords", "compare.html#parcoords", "entities × variables"),
            ("Radar", "plotly polar", "compare.html#radar", "one profile vs reference"),
            ("Joint plot", "seaborn jointplot", "ngoro-climate.html#jointplot", "scatter + marginals"),
            ("2-D density / contour", "kdeplot", "ngoro-climate.html#density2d", "where the mass lives"),
            ("Hexbin", "matplotlib hexbin", "ngoro-stats.html#hexbin", "big-n scatter without smear"),
            ("Regression + CI band", "seaborn regplot", "ngoro-stats.html#regplot", "fit with uncertainty"),
            ("LOESS smoother", "lowess", "ngoro-stats.html#loess2", "trend without a line assumed"),
            ("Correlation matrix", "seaborn heatmap", "ngoro-stats.html#corr", "what moves together"),
            ("Clustermap + dendrogram", "seaborn clustermap", "ngoro-stats.html#clustermap", "structure by reordering"),
            ("PCA biplot", "sklearn + mpl", "ngoro-stats.html#pca", "dimensionality reduced, loadings shown"),
            ("Matrix heatmap", "seaborn heatmap", "compare.html#matrix-heat", "entity × time in one field"),
            ("Slope chart", "Tufte slopegraph", "compare.html#slope", "rank flow between epochs"),
            ("Dumbbell", "cleveland pairs", "compare.html#dumbbell", "then vs now per entity"),
        ]),
        ("Cyclic", [
            ("Climograph", "Walter–Lieth", "ngoro-climate.html#climograph", "the ecology classic"),
            ("Polar rose", "plotly barpolar", "ngoro-climate.html#rose", "seasonality as shape"),
            ("Facets / small multiples", "facet_wrap", "ngoro-climate.html#facets", "same axes, many panels"),
            ("Normals heatmap", "seaborn heatmap", "ngoro-climate.html#normals-heat", "climate matrix across sites"),
        ]),
    ]
    secs = []
    n = 0
    for fam, items in FAMS:
        rows = ''.join(f'<div class="gal-item"><a href="{href}">{name}</a>'
                       f'<span class="l">{lib}</span><span class="u">{use}</span></div>'
                       for name, lib, href, use in items)
        n += len(items)
        secs.append(f'<h2 class="zone">{fam} · {len(items)}</h2><div class="c" style="padding:18px 16px 12px">{rows}</div>')
    body = ''.join(secs) + """
<h2 class="zone">deliberately left out</h2>
<div class="c" style="padding:18px 16px 14px"><div class="use" style="margin-top:0">
<b>Gauges</b> (bullet does it honestly) · <b>3-D surfaces/pies</b> (perspective distorts every comparison) ·
<b>word clouds</b> (no quantitative mapping) · <b>chord diagrams</b> (sankey covers the flow case legibly) ·
<b>funnel charts</b> (no conservation funnel exists) · <b>candlestick/OHLC</b> (finance-specific).
Exclusion is part of the grammar: if an idiom can't answer "what would a scientist read off this in
5 seconds", it doesn't ship.</div></div>"""
    page("gallery.html", f"Idiom gallery — {n} visual aids", "gallery.html",
         '<a href="index.html">uhifadhi</a> / gallery',
         "The catalog behind every page: each idiom, its library of origin, the job it does, and a link "
         "to it in context. This is the design system's chart contract.", body)


if __name__ == '__main__':
    import shutil
    for stale in ('climate.html', 'landcover.html', 'statistics.html'):
        p = os.path.join(OUT, stale)
        if os.path.exists(p):
            os.remove(p)
    assets = os.path.join(OUT, 'assets')
    os.makedirs(assets, exist_ok=True)
    for t in os.listdir(TILES):
        if t.startswith('tz_'):
            shutil.copy(os.path.join(TILES, t), assets)
    open(os.path.join(OUT, 'uhifadhi.css'), 'w').write(CSS)
    build_index()
    build_areas()
    build_nca_hub()
    build_nca_forest()
    build_nca_climate()
    build_nca_stations()
    build_nca_landcover()
    build_nca_anthro()
    build_nca_tourism()
    build_nca_veg()
    build_nca_drought()
    build_nca_livestock()
    build_nca_stats()
    build_ser_hub()
    build_ser_fires()
    build_ser_bio()
    build_ser_air()
    build_alerts()
    build_compare()
    build_ingestion()
    build_newarea()
    build_gallery()
    print('done →', OUT)
