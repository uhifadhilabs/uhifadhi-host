"""Case-study dataset: Tanzania protected areas.

Ngorongoro's loss series is REAL (uhifadhi DB, forest_loss_year aoi_id=2).
Everything else is deterministic, plausibility-tuned synthetic data so every
chart idiom has honest-looking material until the ingestion tracks land.
"""
import math
import random

YEARS = list(range(2001, 2024))

# name, short, area km2, lat, lon, forest %, mean elev m, annual rain mm,
# established, IUCN cat, mean annual loss ha (target), trend %/yr, spikes
PAS = [
    ("Ngorongoro Conservation Area", "NCA",   8271, -3.20, 35.49, 11, 2200,  900, 1959, "VI",   71,  -1.0, {}),
    ("Serengeti National Park",      "SER",  14763, -2.33, 34.83,  3, 1500,  900, 1951, "II",  120,   0.5, {2012: 1.8}),
    ("Nyerere National Park",        "NYE",  30893, -9.00, 37.40, 45,  300,  900, 2019, "II", 2600,   4.0, {2016: 1.6, 2021: 1.5}),
    ("Ruaha National Park",          "RUA",  20226, -7.50, 34.60, 25,  900,  550, 1964, "II",  800,   3.0, {2016: 1.7}),
    ("Katavi National Park",         "KAT",   4471, -6.70, 31.10, 50,  900,  950, 1974, "II",  300,   2.0, {2012: 1.5, 2021: 1.4}),
    ("Mikumi National Park",         "MIK",   3230, -7.40, 37.00, 20,  550,  800, 1964, "II",  240,   1.5, {}),
    ("Tarangire National Park",      "TAR",   2850, -3.83, 36.00,  8, 1100,  650, 1970, "II",   90,   1.0, {2017: 1.5}),
    ("Mkomazi National Park",        "MKO",   3245, -4.00, 38.07,  5,  800,  550, 2008, "II",   30,   0.0, {}),
    ("Kilimanjaro National Park",    "KIL",   1688, -3.07, 37.35, 60, 3000, 2200, 1973, "II",   45,  -2.0, {2012: 1.6}),
    ("Udzungwa Mountains NP",        "UDZ",   1990, -7.80, 36.70, 80, 1500, 2000, 1992, "II",   60,  -1.5, {}),
    ("Mahale Mountains NP",          "MAH",   1613, -6.10, 29.80, 75, 1800, 1800, 1985, "II",  110,   1.0, {2016: 1.5}),
    ("Gombe National Park",          "GOM",     56, -4.67, 29.63, 85, 1500, 1600, 1968, "II",    4,  -4.0, {}),
    ("Arusha National Park",         "ARU",    322, -3.25, 36.83, 55, 1800, 1200, 1960, "II",   12,   0.0, {}),
    ("Lake Manyara National Park",   "MAN",    325, -3.50, 35.80, 35, 1000,  750, 1960, "II",   25,   1.0, {2017: 1.6}),
    ("Saadani National Park",        "SAA",   1062, -6.00, 38.62, 40,   50, 1000, 2005, "II",  180,   2.5, {}),
    ("Kitulo National Park",         "KIT",    413, -9.10, 33.90, 30, 2600, 1600, 2005, "II",    8,   0.0, {}),
]

NCA_LOSS = [1657, 124, 109, 53, 92, 109, 117, 128, 37, 185, 43, 33, 186, 63,
            16, 18, 114, 23, 34, 27, 7, 32, 7]           # REAL, sums to 3214


def loss_series(short, mean, trend, spikes):
    if short == "NCA":
        return list(NCA_LOSS)
    rng = random.Random(sum(ord(c) * 31 ** i for i, c in enumerate(short)))
    out = []
    for i, yr in enumerate(YEARS):
        base = mean * (1 + trend / 100) ** (i - 11)
        noise = rng.lognormvariate(0, 0.35)
        v = base * noise * spikes.get(yr, 1.0)
        out.append(round(v))
    return out


AREAS = []
for name, short, km2, lat, lon, fpct, elev, rain, est, iucn, mean, trend, spikes in PAS:
    s = loss_series(short, mean, trend, spikes)
    AREAS.append(dict(
        name=name, short=short, km2=km2, lat=lat, lon=lon, forest_pct=fpct,
        elev=elev, rain=rain, established=est, iucn=iucn,
        loss=s, total=sum(s), mean=sum(s) / len(s),
        density=sum(s) / len(s) / km2 * 100,   # ha yr-1 per 100 km2
        real=(short == "NCA"),
    ))

BY_SHORT = {a["short"]: a for a in AREAS}

# ---------- climate normals (monthly precip mm / mean temp C) ----------
# bimodal north (long rains MAM, short rains ND), unimodal south/west (Nov-Apr)
CLIMATE = {
    "NCA": ([ 98, 108, 148, 196,  92, 26, 13, 15, 24, 46,  92, 112],
            [14.2, 14.4, 14.6, 14.7, 13.9, 12.7, 12.1, 12.6, 13.5, 14.6, 14.6, 14.3]),
    "SER": ([ 88, 100, 142, 168,  84, 22, 10, 14, 28, 52,  98, 104],
            [21.8, 21.9, 21.7, 21.2, 20.6, 19.8, 19.4, 20.2, 21.3, 22.2, 21.9, 21.7]),
    "KIL": ([ 62,  78, 168, 342, 218, 42, 20, 22, 30, 58, 142,  90],
            [ 9.8, 10.1, 10.4, 10.6, 10.0,  8.9,  8.4,  8.8,  9.6, 10.5, 10.6, 10.2]),
    "RUA": ([118, 112,  96,  48,   8,  1,  0,  0,  2, 12,  48, 105],
            [24.6, 24.4, 24.2, 23.8, 22.6, 20.8, 20.4, 21.8, 24.0, 25.6, 25.8, 25.0]),
    "MAH": ([182, 168, 196, 178,  58,  4,  1,  2, 14, 76, 168, 186],
            [22.4, 22.4, 22.2, 22.0, 21.4, 20.2, 19.9, 21.0, 22.4, 23.0, 22.6, 22.3]),
    "NYE": ([148, 132, 158, 120,  36,  4,  2,  2,  8, 28,  82, 132],
            [26.8, 26.9, 26.5, 25.9, 24.4, 22.6, 22.2, 23.2, 25.1, 26.9, 27.3, 27.0]),
}
MONTHS = list("JFMAMJJASOND")

# CHIRPS-style rainfall anomaly % vs 1991-2020 normal, NCA
ANOM_YEARS = list(range(2012, 2024))
ANOMALY = [-8, 5, 9, 12, -18, -9, 6, 22, 4, -14, -21, 9]

# VIIRS-style fire detections, month x year (rows 2019-2023), per selected PA
FIRE = {
    "NCA": [[2, 1, 0, 1, 3, 8, 22, 38, 31, 14, 4, 2],
            [1, 0, 1, 0, 2, 6, 18, 29, 26, 10, 3, 1],
            [2, 1, 0, 1, 4, 9, 25, 41, 33, 12, 5, 2],
            [3, 2, 1, 2, 5, 12, 31, 52, 44, 19, 6, 3],
            [1, 1, 0, 1, 2, 5, 15, 24, 21, 8, 2, 1]],
}
FIRE_YEARS = [2019, 2020, 2021, 2022, 2023]
# annual fire detections by PA (streamgraph), 2012-2023
STREAM_PAS = ["NYE", "RUA", "KAT", "SER", "NCA"]
STREAM = {
    "NYE": [420, 480, 460, 520, 780, 560, 540, 600, 580, 820, 700, 610],
    "RUA": [310, 340, 320, 360, 560, 380, 350, 390, 370, 540, 470, 400],
    "KAT": [180, 210, 190, 200, 260, 210, 220, 230, 210, 300, 260, 230],
    "SER": [140, 150, 170, 160, 180, 150, 160, 170, 150, 190, 175, 160],
    "NCA": [ 60,  70,  65,  70,  95,  75,  70,  80,  75, 110,  90,  78],
}
STREAM_YEARS = list(range(2012, 2024))

# ESA WorldCover-style composition (% of PA)
LC_CLASSES = [("Grassland", "#C9B458"), ("Shrubland", "#8FA35F"), ("Forest", "#3E7A45"),
              ("Cropland", "#C98A4B"), ("Bare/sparse", "#B0A79A"), ("Water", "#4A90C2"),
              ("Wetland", "#5FA3A0"), ("Built-up", "#A46A8C")]
LANDCOVER = {   # percentages per class, same order
    "NCA": [61, 17, 11, 5, 4, 1, 1, 0],
    "SER": [72, 18,  3, 1, 4, 1, 1, 0],
    "NYE": [18, 28, 45, 3, 2, 2, 2, 0],
    "RUA": [34, 34, 25, 2, 3, 1, 1, 0],
    "KIL": [12, 14, 60, 2, 10, 1, 1, 0],
    "UDZ": [ 6, 10, 80, 2, 1, 0, 1, 0],
    "GOM": [ 4,  9, 85, 1, 0, 1, 0, 0],
    "SAA": [28, 22, 40, 4, 2, 2, 2, 0],
}
# land-cover transition 2000 -> 2020, NCA, km2 (sankey)
SANKEY = [  # (from, to, km2)
    ("Forest", "Forest", 842), ("Forest", "Shrubland", 38), ("Forest", "Grassland", 22),
    ("Forest", "Cropland", 8),
    ("Shrubland", "Shrubland", 1290), ("Shrubland", "Grassland", 96), ("Shrubland", "Cropland", 20),
    ("Grassland", "Grassland", 4880), ("Grassland", "Shrubland", 74), ("Grassland", "Cropland", 62),
    ("Grassland", "Bare/sparse", 40),
    ("Cropland", "Cropland", 318), ("Cropland", "Grassland", 12),
    ("Bare/sparse", "Bare/sparse", 296), ("Water", "Water", 92), ("Wetland", "Wetland", 81),
]
# composition over time (stacked area), NCA % forest/shrub/grass/crop
LC_TREND_YEARS = [2000, 2005, 2010, 2015, 2020]
LC_TREND = {
    "Forest":    [11.9, 11.6, 11.4, 11.2, 11.0],
    "Shrubland": [17.6, 17.4, 17.2, 17.1, 17.0],
    "Grassland": [61.9, 61.8, 61.6, 61.4, 61.2],
    "Cropland":  [ 3.6, 4.2, 4.8, 5.3, 5.8],
    "Other":     [ 5.0, 5.0, 5.0, 5.0, 5.0],
}

# CMIP6-style temperature projection for the crater highlands, deg C anomaly
PROJ_YEARS = list(range(2015, 2081, 5))
PROJ = {  # scenario: (mid, lo, hi)
    "SSP1-2.6": ([0.4, 0.5, 0.7, 0.8, 0.9, 1.0, 1.0, 1.1, 1.1, 1.1, 1.1, 1.1, 1.1, 1.1],
                 0.25, "#4A90C2"),
    "SSP2-4.5": ([0.4, 0.6, 0.8, 1.0, 1.2, 1.4, 1.6, 1.7, 1.9, 2.0, 2.1, 2.2, 2.3, 2.4],
                 0.35, "#DBA33F"),
    "SSP5-8.5": ([0.4, 0.6, 0.9, 1.2, 1.6, 2.0, 2.4, 2.8, 3.2, 3.6, 4.0, 4.4, 4.8, 5.2],
                 0.55, "#E05B41"),
}

# dataset shelf for the area page (gantt + sparkline table)
DATASETS = [
    ("Hansen tree cover loss", "UMD/GFW", 2001, 2023, "annual", "ok"),
    ("VIIRS active fires", "NASA FIRMS", 2012, 2023, "daily", "ok"),
    ("CHIRPS rainfall", "UCSB", 1981, 2023, "monthly", "ok"),
    ("WorldClim normals", "WorldClim", 1970, 2000, "static", "ok"),
    ("ESA WorldCover", "ESA", 2020, 2021, "static", "ok"),
    ("Biodiversity Intactness", "NHM/WRI", 2015, 2015, "static", "todo"),
    ("SRTM elevation", "NASA", 2000, 2000, "static", "todo"),
]

# ingestion runs (console page)
RUNS = [
    (7, "Gombe National Park", "hansen", "running", "polygonize", 62, "—"),
    (6, "Serengeti National Park", "chirps", "awaiting_input", "3 station series conflict — pick primary", None, "—"),
    (5, "Ngorongoro Conservation Area", "viirs", "succeeded", None, 100, "12,404 detections"),
    (4, "Ngorongoro Conservation Area", "worldclim", "succeeded", None, 100, "24 rasters clipped"),
    (3, "Ngorongoro Conservation Area", "hansen", "succeeded", None, 100, "23 loss years · 3,214 ha"),
    (2, "Ngorongoro Conservation Area", "hansen", "succeeded", None, 100, "validation run"),
    (1, "Ngorongoro Conservation Area", "hansen", "failed", "tile timeout", None, "retried as #2"),
]


def pearson(xs, ys):
    n = len(xs)
    mx, my = sum(xs) / n, sum(ys) / n
    sx = math.sqrt(sum((x - mx) ** 2 for x in xs))
    sy = math.sqrt(sum((y - my) ** 2 for y in ys))
    if sx == 0 or sy == 0:
        return 0.0
    return sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / (sx * sy)


STAT_VARS = [("area", "log km²", lambda a: math.log10(a["km2"])),
             ("forest", "%", lambda a: a["forest_pct"]),
             ("rain", "mm", lambda a: a["rain"]),
             ("elev", "m", lambda a: a["elev"]),
             ("loss", "log ha", lambda a: math.log10(max(a["mean"], 1)))]


def stat_matrix():
    cols = {k: [f(a) for a in AREAS] for k, _, f in STAT_VARS}
    names = [k for k, _, _ in STAT_VARS]
    corr = [[pearson(cols[a], cols[b]) for b in names] for a in names]
    return names, cols, corr
