# 📌 Architektonický přehled aplikace: Mapa parcel (Jičínsko)

Tento dokument slouží jako rychlý přehled architektury, datových toků a klíčových technických rozhodnutí pro rychlou orientaci během vývoje a obhajoby projektu.

Strávený čas: 420 minut.

## 🛠 Tech Stack & Zdůvodnění

| Technologie | Role | Proč zrovna tato? | Hlavní Alternativy |
| :--- | :--- | :--- | :--- |
| **Čisté PHP (8.2+)** | Backend API & Parser | Maximální rychlost, nulový overhead, splnění zadání bez zbytečné složitosti frameworků. | Laravel, Symfony (příliš těžkopádné pro jednoúčelové API) |
| **SQLite 3** | Databáze | Jediný soubor, nulová konfigurace, extrémně rychlé čtení. Ideální pro lokální spuštění. | PostgreSQL+PostGIS (složité spuštění), MySQL (nutnost běžícího serveru) |
| **MapLibre GL JS** | Mapový klient | Vykreslování přes **WebGL (GPU)**. Plynule (60 FPS) vykreslí desetitisíce parcel. | Leaflet (při >2000 parcelách v SVG drhne), OpenLayers (příliš robustní/komplexní) |
| **Tailwind CSS (CDN)** | UI / Styling | Rychlý návrh moderního responzivního rozhraní (sidebar, vyhledávání) přímo v HTML bez konfigurace. | Bootstrap (generický vzhled), Vlastní CSS (zdlouhavý vývoj) |

---

## ⚙️ Strategie s daty (Pre-processing Pipeline)

Pro zajištění **absolutní plynulosti** i při zobrazení celého okresu nepoužíváme live dotazy na ČÚZK při každém načtení mapy. Místo toho máme **jednorázový PHP CLI skript (import)**:

1. **Stažení & Čtení:** Skript přečte stažený VFR (GML/XML) soubor od ČÚZK pro obec Jičín (kód 572659).
2. **Převod souřadnic:** Souřadnice polygonů se přepočítají z českého systému **S-JTSK (EPSG:5514)** do standardního GPS systému **WGS84 (EPSG:4326)** pomocí čisté matematické transformace v PHP.
3. **Optimalizace geometrie:** Zaokrouhlení souřadnic na 6 desetinných míst zmenší velikost výsledných souborů o ~40 %.
4. **Export:** 
   * Metadata parcel se uloží do **SQLite** (pro bleskové fulltextové vyhledávání).
   * Geometrie se vyexportují jako **statické minifikované GeoJSONy** rozdělené podle katastrálních území (např. `geojson/659991.json` pro Jičín) do složky `public/geojson/`.

---

## 🗄 Databázové schéma (SQLite)

Relační vztah **1 : N** (Jedno katastrální území obsahuje mnoho parcel).

```sql
-- 1. Tabulka katastrálních území
CREATE TABLE cadastres (
    code INTEGER PRIMARY KEY,          -- Kód katastru (např. 659991 pro Jičín)
    name TEXT NOT NULL,                -- Název katastru
    bbox TEXT NOT NULL                 -- Bounding box pro vycentrování mapy [min_lng, min_lat, max_lng, max_lat]
);

-- 2. Tabulka parcel (pro vyhledávání a metadata)
CREATE TABLE parcels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cadastre_code INTEGER,
    parcel_number TEXT NOT NULL,       -- Číslo parcely (např. 1205/2)
    parcel_type TEXT,                  -- Stavební / Pozemková
    area INTEGER,                      -- Výměra v m²
    geometry_geojson TEXT,             -- GeoJSON reprezentace polygonu (volitelné pro záložní účely)
    FOREIGN KEY(cadastre_code) REFERENCES cadastres(code)
);

-- Indexy pro okamžité vyhledávání
CREATE INDEX idx_parcels_cadastre ON parcels(cadastre_code);
CREATE INDEX idx_parcels_search ON parcels(parcel_number);
```

---

## 🌐 API Endpoints (PHP Backend)

Aplikaci stačí pouze 3 lehké endpointy, které vrací JSON:

1. `GET /api/cadastres.php`
   * **Účel:** Vrací seznam dostupných katastrálních území s jejich názvy, kódy a ohraničením (bbox) pro přepínač v UI.
2. `GET /api/parcels.php?cadastre={code}`
   * **Účel:** Servuje GeoJSON parcel pro konkrétní katastr (buď asynchronně čtením z DB, nebo přímým vrácením statického souboru pro maximální rychlost).
3. `GET /api/search.php?q={query}`
   * **Účel:** Fulltextové vyhledávání parcel podle čísla napříč katastry pro našeptávač.

---

## 🔄 Datový tok (Data Flow)

1. **Načtení mapy:** Frontend načte základní vrstvu mapy a zavolá `/api/cadastres.php` pro vykreslení hranic dostupných katastrálních území.
2. **Přiblížení (Lazy-loading):** Když uživatel přiblíží mapu na konkrétní katastrální území, MapLibre si asynchronně vyžádá GeoJSON parcel přes `/api/parcels.php?cadastre={code}` a okamžitě ho vykreslí na GPU.
3. **Kliknutí na parcelu:** Z vlastností (properties) vybraného polygonu se v bočním panelu (Sidebar) zobrazí informace o parcele a vygeneruje se dynamický odkaz na oficiální **Nahlížení do KN** (využívající kód katastru a číslo parcely).

Pro převod souřadnic jsem se rozhodl použít knihovnu proj4php přes composer. Rozhodl jsem se tak z důvodu ušetření času a eliminaci případných chyb a nepřesností ve vzorcích.
před spuštěním je nutné zavolat
composer install