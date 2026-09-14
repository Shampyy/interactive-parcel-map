<?php

    /**
     * CLI skript pro převod RUIAN Výměnného formátu (XML) do formátu SQLite databáze.
     * Skript využívá XMLReader pro efektivní čtení velkých souborů bez přetečení paměti —
     * na rozdíl od DOM parserů nikdy nedrží celý XML soubor v RAM najednou.
     *
     * Výstupem je data/parcely.sqlite: tabulka `parcely` (atributy + geometrie)
     * a virtuální tabulka `parcely_rtree` (prostorový index pro rychlé dotazy podle bbox).
     */

    require_once __DIR__ . '/../src/CoordinateConverter.php';

    $converter = new CoordinateConverter();
    $reader = new XMLReader();

    // --- Validace a otevření vstupního XML souboru ---
    $sourcePath = __DIR__ . '/../data/raw/data_jicin.xml';
    if (!file_exists($sourcePath)) {
        fwrite(STDERR, "Zdrojový soubor nenalezen: $sourcePath\n");
        exit(1);
    }
    if (!$reader->open($sourcePath)) {
        fwrite(STDERR, "Nepodařilo se otevřít soubor: $sourcePath\n");
        exit(1);
    }

    // --- Nastavení SQLite databáze ---
    $dbPath = __DIR__ . '/../data/parcely.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Hlavní tabulka s atributy parcely a geometrií uloženou jako JSON text
    $db->exec('CREATE TABLE IF NOT EXISTS parcely (
    id INTEGER PRIMARY KEY, parcel_number TEXT, area INTEGER,
    type TEXT, region TEXT, region_name TEXT, municipality TEXT, municipality_name TEXT, geometry TEXT
    )');

    // R-Tree tabulka pro prostorové indexování (rychlé hledání parcel protínající daný BBOX)
    $db->exec('CREATE VIRTUAL TABLE IF NOT EXISTS parcely_rtree USING rtree(
    id, minX, maxX, minY, maxY
    )');

    // Připravené SQL dotazy
    $insert = $db->prepare('INSERT OR REPLACE INTO parcely VALUES (?,?,?,?,?,?,?,?,?)');
    $insertRtree = $db->prepare('INSERT OR REPLACE INTO parcely_rtree VALUES (?,?,?,?,?)');

    // Datové slovníky pro uchování globálních informací o obci a katastru,
    $katastry = [];
    $katastrToObec = [];
    $currentObecKod = '';
    $currentObecNazev = '';

    // Pomocná funkce pro vytažení a konverzi bodů z jakékoliv hranice
    $parseBoundary = function($boundaryNode) use ($converter) {
        $posListText = '';
        if (isset($boundaryNode->LinearRing)) {
            $posListText = (string) $boundaryNode->LinearRing->posList;
        } elseif (isset($boundaryNode->Ring)) {
            $segments = [];
            foreach ($boundaryNode->Ring->curveMember as $curveMember) {
                if (isset($curveMember->LineString)) {
                    $segments[] = (string) $curveMember->LineString->posList;
                } elseif (isset($curveMember->Curve->segments->ArcString)) {
                    $segments[] = (string) $curveMember->Curve->segments->ArcString->posList;
                }
            }
            $posListText = implode(' ', $segments);
        }

        $coordArray = preg_split('/\s+/', trim($posListText), -1, PREG_SPLIT_NO_EMPTY);
        $coords = [];
        for ($i = 0; $i < count($coordArray) - 1; $i += 2) {
            $coords[] = $converter->convertToGeoJson((float)$coordArray[$i], (float)$coordArray[$i + 1]);
        }
        return $coords;
    };

    $db->beginTransaction();

    // --- Sekvenční procházení XML uzlů ---
    while ($reader->read()) {

        // Záchyt globálních informací o obci pomocí regulárních výrazů.
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Obec') {
            $xml = $reader->readOuterXml();
            preg_match('/<[^>]+:Kod>(\d+)<\/[^>]+:Kod>/', $xml, $kodM);
            preg_match('/<[^>]+:Nazev>([^<]+)<\/[^>]+:Nazev>/', $xml, $nazM);
            if (!empty($kodM[1]) && !empty($nazM[1])) {
                $currentObecKod = $kodM[1];
                $currentObecNazev = $nazM[1];
            }
            continue;
        }

        // Záchyt katastrálních území a jejich uložení do dočasných slovníků.
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'KatastralniUzemi') {
            $xml = $reader->readOuterXml();
            preg_match('/<[^>]+:Kod>(\d+)<\/[^>]+:Kod>/', $xml, $kodM);
            preg_match('/<[^>]+:Nazev>([^<]+)<\/[^>]+:Nazev>/', $xml, $nazM);
            if (!empty($kodM[1]) && !empty($nazM[1])) {
                $katastry[$kodM[1]] = $nazM[1];
                $katastrToObec[$kodM[1]] = $currentObecKod;
            }
            continue;
        }

        // --- Hlavní zpracování jednotlivých parcel ---
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Parcela') {

            // Načtení celého uzlu Parcela jako samostatného XML fragmentu a jeho naparsování přes SimpleXML
            $xmlText = $reader->readOuterXml();
            $node = new SimpleXMLElement($xmlText);

            // Extrakce jmenných prostorů pro konkrétní uzel parcely
            $namespaces = $node->getNamespaces(true);
            $pai = $node->children($namespaces['pai']);

            $paiId =  (int) $pai->Id;

            // Základní atributy parcely
            $kmenoveCislo = (string) $pai->KmenoveCislo;
            $pododdeleniCisla = (string) $pai->PododdeleniCisla;
            $DruhPozemkuKod = (string) $pai->DruhPozemkuKod;
            $vymera = (int) $pai->VymeraParcely;

            // Získání kódu katastrálního území přes XPath
            $KatastralniUzemiKod = '';
            if (isset($pai->KatastralniUzemi)) {
                $kodNodes = $pai->KatastralniUzemi->xpath('.//*[local-name()="Kod"]');
                if (!empty($kodNodes)) {
                    $KatastralniUzemiKod = (string) $kodNodes[0];
                }
            }

            $KatastralniUzemiNazev = $katastry[$KatastralniUzemiKod] ?? '';
            $ObecNazev = $currentObecNazev;

            // Zformátování čísla parcely
            if (!empty($pododdeleniCisla)) {
                $formatovaneCislo = $kmenoveCislo . '/' . $pododdeleniCisla;
            } else {
                $formatovaneCislo = $kmenoveCislo;
            }

            // Kontrola, zda má parcela definované hranice
            if (isset($pai->Geometrie->OriginalniHranice)) {
                $gml = $pai->Geometrie->OriginalniHranice->children($namespaces['gml']);

                // Zde budou všechny obrysy
                $polygonCoords = [];

                // Zpracování vnější hranice
                $exteriorCoords = $parseBoundary($gml->Polygon->exterior);
                if (!empty($exteriorCoords)) {
                    $polygonCoords[] = $exteriorCoords;
                }

                // Zpracování vnitřní hranice
                if (isset($gml->Polygon->interior)) {
                    foreach ($gml->Polygon->interior as $interiorNode) {
                        $interiorCoords = $parseBoundary($interiorNode);
                        if (!empty($interiorCoords)) {
                            $polygonCoords[] = $interiorCoords;
                        }
                    }
                }

                // Pokud máme platný vnější obrys, uložíme do DB
                if (count($polygonCoords) > 0 && count($polygonCoords[0]) > 0) {
                    $ObecKod = $katastrToObec[$KatastralniUzemiKod] ?? '';

                    // Bounding box parcely počítáme pouze z vnějšího obvodu (index 0)!
                    $lon = array_column($polygonCoords[0], 0);
                    $lat = array_column($polygonCoords[0], 1);

                    $insert->execute([
                        $paiId,
                        $formatovaneCislo,
                        $vymera,
                        $DruhPozemkuKod,
                        $KatastralniUzemiKod,
                        $KatastralniUzemiNazev,
                        $ObecKod,
                        $ObecNazev,
                        json_encode($polygonCoords)
                    ]);

                    $insertRtree->execute([
                        $paiId,
                        min($lon), max($lon),
                        min($lat), max($lat)
                    ]);
                }
            }
        }
    }
    $db->commit();

    $reader->close();
    echo "Generování hotovo! Data uložena do SQLite databáze data/parcely.sqlite\n";