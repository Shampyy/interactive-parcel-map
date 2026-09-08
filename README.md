# 📘 Interactive Parcel Map - Dokumentace

Tento dokument slouží jako rychlý přehled architektury, datových toků a klíčových technických rozhodnutí pro rychlou orientaci během vývoje a obhajoby projektu.

### Základní informace a spuštění projektu

 ⚙️ Požadavky pro spuštění (Prerequisites)
* **PHP 8.1** nebo novější (Vyvíjeno a testováno na PHP 8.5, nutné povolit `extension=zip` v `php.ini`)
* **Composer** (pro instalaci závislostí, např. převodníku souřadnic proj4php)
* Moderní webový prohlížeč (Chrome, Firefox, Edge)

 🚀 Instalace a spuštění
* Vzhledem k velikosti dat není zdrojový soubor s katastrálními hranicemi součástí repozitáře. Pro spuštění importu je nutné jej stáhnout:
1. Navštivte stránky Českého úřadu zeměměřického a katastrálního (ČÚZK) – sekci **Výměnný formát RÚIAN (VFR)**.
2. Stáhněte aktuální VFR data pro oblast **Jičín** (případně konkrétní katastrální území).
3. Stažený soubor rozbalte (pokud je v archivu) a uložte jej do kořenové složky projektu na tuto cestu:
   `data/raw/data_jicin.xml`
4. Spuštění samotného scriptu pro vygenerování mapy `php scripts/import.php`

## 🛠 Tech Stack & Zdůvodnění

| Technologie | Role | Proč zrovna tato? | Hlavní Alternativy |
| :--- | :--- | :--- | :--- |
| **Čisté PHP (8.2+)** | Backend / Parser | Maximální rychlost, efektivní správa paměti pomocí `XMLReader` pro zpracování objemných RUIAN dat. Splnění zadání bez zbytečné složitosti frameworků. | Laravel, Symfony (příliš těžkopádné pro jednoúčelový CLI skript) |
| **MapLibre GL JS** | Mapový klient | Vykreslování přes **WebGL (GPU)**. Plynule vykreslí desetitisíce parcel rovnou z GeoJSONu bez pádů prohlížeče. | Leaflet (při >2000 parcelách v SVG drhne), OpenLayers (příliš robustní/komplexní) |
| **Čisté HTML/CSS/JS** | UI / Frontend | Rychlý návrh responzivního rozhraní (vyjížděcí boční panel) bez nutnosti instalovat node_modules nebo kompilovat bundly. | React/Vue + Tailwind (zbytečný overhead pro jednu obrazovku) |

---

## ⚙️ Strategie s daty (Pre-processing Pipeline)

Pro zajištění **absolutní plynulosti** nepoužíváme live dotazy na ČÚZK při každém načtení mapy. Backend funguje jako jednorázový předzpracující krok (tzv. pre-processing) pomocí PHP CLI skriptu:

1. **Stažení & Čtení po uzlech:** Skript postupně čte velký VFR (XML) soubor pomocí `XMLReader`, aby nedošlo k vyčerpání RAM.
2. **Extrakce metadat (Regex):** Jména obcí a katastrálních území (KÚ) se z hlaviček XML tahají pomocí regulárních výrazů (Regex), což elegantně obchází běžné chyby XML parserů při práci se jmennými prostory (namespaces).
3. **Převod souřadnic:** Souřadnice se překládají ze systému **S-JTSK (EPSG:5514)** do standardního GPS formátu **WGS84 (EPSG:4326)** pomocí vlastní PHP třídy `CoordinateConverter`, tedy bez nutnosti instalovat externí knihovny přes Composer.
4. **Řešení překryvů (Z-index parcel):** Vyextrahované parcely jsou před uložením seřazeny od největší výměry po nejmenší (`usort`). Díky tomu se v mapě malé polygony (např. budovy) vykreslí vždy *nad* velkými polygony (např. pole).
5. **Export:** Výstupem je jediný, statický soubor `jicin_parcels.json` (formát FeatureCollection) umístěný do veřejné složky pro frontend.

---

## 🔄 Datový tok a Frontend (Data Flow)

Aplikace na frontendu nepotřebuje žádnou relační databázi ani dynamické API endpointy. Vše funguje nad připraveným statickým souborem:

1. **Načtení mapy:** Prohlížeč načte MapLibre objekt a rovnou stáhne předgenerovaný `jicin_parcels.json` jako primární GeoJSON vrstvu.
2. **Renderování (GPU):** WebGL se postará o okamžité vykreslení podkladové mapy (CartoDB Positron), výplní parcel i jejich černých obrysů.
3. **Inteligentní kliknutí (Interakce):**
   * Při kliknutí do mapy událost vyhodnotí všechny vrstvy pod kurzorem.
   * Pokud se v daném bodě překrývá více parcel, JavaScript pomocí metody `reduce` najde a vybere tu s plošně nejmenší výměrou (nejkonkrétnější cíl).
4. **Zobrazení dat:** Kód otevře (vysune) boční panel přidáním CSS třídy `.panel-open` a naplní ho atributy z vlastností (`properties`) daného GeoJSON polygonu (výměra, název obce, atd.). Typ pozemku se překládá do češtiny lokálním JS slovníkem (číselníkem).
5. **Vizuální zpětná vazba:** Vybrané parcele se pomocí MapLibre filter (`map.setFilter`) na sekundární vrstvě aplikuje výrazná barva pro jasnou orientaci uživatele v mapě.

## 📝 Zápisník a technická rozhodnutí (Developer Log)

Tento zápisník shrnuje myšlenkový pochod, klíčová rozhodnutí a postřehy během vývoje aplikace (odhadovaný čas vývoje: ~11 hodin).

### 1. Klíčová rozhodnutí & Proč jsem se tak rozhodl
* **Předzpracování dat (Pre-processing) místo živých WFS dotazů:**
   * *Proč:* Předzpracování dat přes PHP CLI script se jevilo jako nejjednodušší možnost, navíc WFS služby ČÚZK bývají pomalé a při velkém množství polygonů by se prohlížeč zasekával. Výsledný GeoJSON zaručuje okamžitou odezvu a plynulost v mapě.
* **Volba `XMLReader` v PHP:**
   * *Proč:* Výměnné formáty RÚIAN (VFR) jsou obří XML soubory. Načtení celého souboru do DOM by vyústil v problém s pamětí. `XMLReader` čte data sekvenčně po uzlech, což je paměťově zcela nenáročné.
* **Použití RegEx pro extrakci obcí a katastrů:**
   * *Proč:* Běžné XML parsery se v RÚIAN strukturách kvůli složitým jmenným prostorům (namespaces) u hlaviček obcí a katastrálních území často ztrácejí. Regulární výrazy na `readOuterXml()` poskytly maximálně robustní a rychlé řešení bez chyb.
* **MapLibre GL JS na frontendu:**
   * *Proč:* Klasické knihovny jako Leaflet by při vykreslování tisíců detailních parcelních polygonů přes SVG/DOM měly problém s výkonem a plynulostí. MapLibre využívá WebGL (hardwarovou akceleraci GPU), díky čemuž je vykreslení naprosto plynulé.

### 2. Co mě během vývoje překvapilo
* **Složitost GML geometrií:**
   * Zpočátku jsem počítal pouze s jednoduchými polygony (`LinearRing`). Při testování dat jsem ale narazil na problém s vykreslením složitějších polygonů. Tento problém jsem vyřešil pomocí (`Ring`, `curveMember`, `ArcString`). Musel jsem parser rozšířit, aby tyto segmenty uměl poskládat dohromady, jinak by na mapě celé bloky parcel chyběly.
* **Překrývání polygonů (Z-index):**
   * RÚIAN data obsahují vnořené objekty (např. budova ležící uvnitř pozemku). Bez úpravy se v mapě vykreslovaly v náhodném pořadí. Vyřešil jsem to seřazením polí před exportem (`usort` podle výměry od největší po nejmenší), díky čemuž se menší detaily vykreslí vždy navrch.

### 3. Co bych s více časem řešil jinak
* **Měřítkování na celý okres (Vektorové dlaždice):**
   * Pro zobrazení jednoho města nebo pár katastrů je statický GeoJSON ideální a bleskový. Pokud bych ale měl pokrýt celý okres Jičín , velikost GeoJSON souboru by už přetížila paměť prohlížeče. S více časem bych se tedy zaměřil na úpravu kódu tak, aby bylo možné zobrazit celý okres bez problémů.
* **Automatická aktualizace dat (Cron / Pipeline):**
   * V této fázi aplikace spoléhá na jednorázový manuální import statického XML. S více časem bych proces získávání dat automatizoval. Pravidelně by se kontrolovalo nové VFR (Výměnný formát RÚIAN) soubory nad API ČÚZK, automaticky by se stáhnuly a prohnaly parsovacím skriptem a aktualizoval by se GeoJSON na pozadí bez nutnosti lidského zásahu.