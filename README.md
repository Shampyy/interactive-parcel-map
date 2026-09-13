#  Interactive Parcel Map - Dokumentace

Tento dokument slouží jako rychlý přehled architektury, datových toků a klíčových technických rozhodnutí pro rychlou orientaci během vývoje a obhajoby projektu.

## Základní informace a spuštění projektu

### Požadavky pro spuštění (Prerequisites)
* **PHP 8.1** nebo novější (Vyvíjeno a testováno na PHP 8.5)
* PHP rozšíření: `pdo_sqlite`, `simplexml`, `xmlreader` (běžně součástí standardní instalace PHP)
* **Composer** — používáme ho výhradně pro knihovnu `proj4php` (převod souřadnic S-JTSK → WGS84). Zbytek aplikace (import, API, XML parsování) je čisté PHP bez frameworku.
* Moderní webový prohlížeč (Chrome, Firefox, Edge)


### Instalace a spuštění

**Stažení zdrojových dat:**
Vzhledem k velikosti dat není zdrojový XML soubor s katastrálními hranicemi součástí repozitáře. Pro spuštění importu je nutné jej stáhnout:
1. Otevřete Výdejní portál dat ČÚZK: **https://services.cuzk.cz/atom-index/RUIAN-S-K-U/5514/**
2. Najděte soubor pro oblast **Jičín**.
3. Stáhněte .zip soubor.
4. extrahujte ho do `data/raw` jako `data_jicin.xml`

**Vygenerování databáze parcel a spuštění:**
1. instalace composeru `composer install`
2. zadání příkazu `php scripts/import.php` pro vygenerování databáze
2. spuštění localhostu `php -S localhost:8000 -t public`
3. Aplikace pak běží na **http://localhost:8000**

---

##  Tech Stack & Zdůvodnění

| Technologie | Role | Proč zrovna tato? | Hlavní Alternativy |
| :--- | :--- | :--- | :--- |
| **Čisté PHP (8.1+)** | Backend / Parser / API | Maximální rychlost, efektivní správa paměti pomocí `XMLReader` pro zpracování objemných RUIAN dat. Splnění zadání bez zbytečné složitosti frameworků. | Laravel, Symfony (příliš těžkopádné pro tento rozsah) |
| **SQLite + R-Tree** | Prostorové úložiště | Jeden soubor, žádná instalace databázového serveru, a přesto skutečný 2D prostorový index (virtuální `rtree` tabulka) pro rychlé dotazy podle výřezu mapy. | PostGIS (silnější, ale vyžaduje samostatný DB server — zbytečné pro tento rozsah dat a časový rámec) |
| **proj4php (Composer)** | Převod souřadnic | Standardní, ověřený PROJ string pro české Krovákovo zobrazení (S-JTSK, EPSG:5514) → WGS84. Psát si vlastní matematiku projekce od nuly by bylo zbytečné riziko chyb bez přínosu. | Vlastní implementace Krovákovy projekce (zvažováno, zamítnuto kvůli časové náročnosti a riziku nepřesnosti) |
| **MapLibre GL JS** | Mapový klient | Vykreslování přes **WebGL (GPU)**. Plynule vykreslí tisíce parcel i s průběžně se měnícím obsahem zdroje dat. | Leaflet (při větším počtu parcel v SVG drhne), OpenLayers (příliš robustní/komplexní pro tento rozsah) |
| **Čisté HTML/CSS/JS** | UI / Frontend | Rychlý návrh responzivního rozhraní (vyjížděcí boční panel) bez nutnosti instalovat node_modules nebo kompilovat bundly. | React/Vue + Tailwind (zbytečný overhead pro jednu obrazovku) |

---

## ️ Architektura a datový tok

Aplikace má dvě fáze: **jednorázový import** (offline příprava dat) a **běžící API** (obsluhuje mapu za provozu).

### Fáze 1 — Import (`scripts/import.php`)

- **`XMLReader`** čte VFR soubor postupně po uzlech, aby nedošlo k vyčerpání RAM u velkého XML.
- **Kódy a názvy obcí/katastrů** se extrahují regulárními výrazy nad `readOuterXml()`
- **Obec ke katastru** se přiřazuje přes vyhledávací tabulku `$katastrToObec[$kodKatastru] = $kodObce`
- **Geometrie** se skládá jak z jednoduchého `LinearRing`, tak ze složené hranice `Ring`/`curveMember` (`LineString` i zakřivené `ArcString`) — část parcel (podél řek, silničních oblouků) používá druhý tvar.
- **Souřadnice** se převádí z S-JTSK (EPSG:5514) do WGS84 pomocí `CoordinateConverter` (interně `proj4php`).
- Výsledek se ukládá do **SQLite** — tabulka `parcely` (atributy + geometrie jako JSON) a virtuální `rtree` tabulka (bounding box) pro rychlé prostorové vyhledávání.

### Fáze 2 — Běžící API (`public/api/parcely.php`)
- Frontend při pohybu mapy pošle aktuální výřez (bbox, s rezervním okrajem) na API.
- API ověří vstup (chybný `bbox` → HTTP 400, ne pád) a přes `JOIN` na `rtree` vrátí jen parcely protínající daný výřez — typicky stovky, ne desítky tisíc.
- Odpověď je komprimovaná (`gzip`) s `Cache-Control` hlavičkou.
- Frontend nová data slučuje s klientskou cache a průběžně maže to, co je už mimo oblast zájmu.
- `minzoom` na vrstvách zajišťuje, že se při velkém oddálení parcely vůbec nestahují ani nevykreslují — mizí tak i problém se slévajícími se obrysy do černé plochy.

### Frontend — interakce s parcelou

- Klik vyhodnotí featury pod kurzorem; pokud se překrývá víc parcel (typicky budova uvnitř pozemku — stavební a pozemková parcela mají nezávislé číslování a jejich hranice se legitimně překrývají), `reduce` vybere tu s nejmenší výměrou.
- Zvýraznění (`parcels-selected`) filtruje podle unikátního RUIAN `id`
- Panel zobrazí výměru, druh pozemku (přes JS číselník) a názvy katastru/obce.

##  Zápisník a technická rozhodnutí (Developer Log)
Tento zápisník shrnuje myšlenkový pochod, klíčová rozhodnutí a postřehy během vývoje aplikace (odhadovaný čas vývoje: **12h**).

### 1. Klíčová rozhodnutí & Proč jsem se tak rozhodl

* **SQLite + R-Tree**
    * *Proč:* Místo stažení celého datasetu jako statického souboru, což by na rozsah úlohy bylo možné, jsem se rozhodl použít databázy a api, které tahá jen část parcel, které jsou relevantní a né všechny najednou, což by při větším rozsahu způsobilo lag a špatný výkon.
* **Předzpracování dat (import) místo živých WFS dotazů:**
    * *Proč:* WFS služby ČÚZK bývají pomalé při větším množství polygonů. Jednorázový import do lokální databáze zaručuje rychlé a předvídatelné odezvy API bez závislosti na dostupnosti a rychlosti externí služby při běžném používání mapy.
* **XMLReader místo DOM:**
    * *Proč:* VFR soubory jsou objemné XML. Načtení celého souboru do DOM by hrozilo vyčerpáním paměti; `XMLReader` čte sekvenčně po uzlech.
* **MapLibre GL JS na frontendu:**
    * *Proč:* Vykreslování přes WebGL (GPU) zvládá plynule i řádově tisíce polygonů a průběžně měnící se zdroj dat, na rozdíl od SVG/DOM přístupů (Leaflet).

### 2. Co mě během vývoje překvapilo

* **Složitost GML geometrií:**
    * Zpočátku jsem počítal jen s jednoduchými polygony (`LinearRing`). Část parcel ale používá složenou hranici (`Ring` + `curveMember` s `LineString`/`ArcString`) — bez podpory tohoto tvaru by na mapě chyběly celé skupiny parcel (v mém datasetu šlo o stovky záznamů). Parser jsem musel rozšířit, aby uměl oba tvary poskládat do jednoho souvislého seznamu bodů.
* **Dvojí nezávislé číslování parcel v katastru:**
    * Stavební a pozemková parcela mohou mít v jednom katastrálním území shodné číslo a jejich plochy se mohou legitimně překrývat (budova stojící na pozemku). Zpočátku jsem to považoval za chybu v datech — ve skutečnosti jde o běžnou vlastnost českého katastru, na kterou bylo potřeba zareagovat ve způsobu, jak parcely identifikuji (přes unikátní RUIAN `id`, ne přes číslo parcely) a jak vybírám, kterou z překrývajících se parcel po kliknutí zvýraznit.
* **Výkon GeoJSON zdroje v MapLibre při častých aktualizacích:**
    * Podle výřezu jsem při profilování v Chrome DevTools zjistil, že `GeoJSONSource.setData()` při každém volání přepočítává **celou** předanou kolekci znovu od nuly, ne jen nově přidaná data. Při častém volání (plynulé tažení myší) a rostoucí klientské cache to citelně zatěžovalo hlavní i worker vlákno prohlížeče. Řešením bylo omezit frekvenci volání (throttle/debounce) a udržovat klientskou cache menší agresivnějším mazáním dat mimo aktuální oblast zájmu. Sice vykreslování parcel není okamžité a může chvilku trvat, ale alespoň je zajištěn lepší výkon.

### 3. Co bych s více časem řešil jinak

* **Vektorové dlaždice (vector tiles) místo dotazovaného GeoJSONu:**
    * Současné řešení (SQLite + R-Tree + API podle výřezu) plně řeší požadavek na plynulost pro zvolený rozsah katastrů. Pro pokrytí celého okresu (nebo kraje) by ale bylo čistší řešení postavit na vektorových dlaždicích (např. přes `tippecanoe` nebo `ST_AsMVT` v PostGIS) — každá dlaždice by nesla jen data pro svůj konkrétní výřez a úroveň přiblížení, včetně automatické geometrické simplifikace, a `setData()` s přepočtem celé kolekce by odpadl úplně.
    * S tím souvisí i **simplifikace geometrie podle zoomu** — v současné verzi se na nízkém zoomu jednoduše nevykreslují žádné parcely (`minzoom`), místo aby se zobrazovaly zjednodušené obrysy. Vektorové dlaždice by tohle řešily automaticky.
* **Automatická aktualizace dat (Cron / Pipeline):**
    * Aplikace nyní spoléhá na jednorázový manuální import statického XML. S více časem bych proces automatizoval — pravidelnou kontrolu nových VFR souborů nad API ČÚZK, jejich stažení a přehnání importním skriptem na pozadí.