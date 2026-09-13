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
    id TEXT PRIMARY KEY, parcel_number TEXT, area INTEGER,
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

            $paiId = (string) $pai->Id;

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

            // Kontrola, zda má parcela definované hranice, a extrakce souřadnicových bodů
            if (isset($pai->Geometrie->OriginalniHranice)) {
                $gml = $pai->Geometrie->OriginalniHranice->children($namespaces['gml']);
                $exterior = $gml->Polygon->exterior;

                $posListText = '';

                if (isset($exterior->LinearRing)) {

                    $posListText = (string) $exterior->LinearRing->posList;

                } elseif (isset($exterior->Ring)) {
                    $segments = [];
                    foreach ($exterior->Ring->curveMember as $curveMember) {
                        if (isset($curveMember->LineString)) {
                            $segments[] = (string) $curveMember->LineString->posList;
                        } elseif (isset($curveMember->Curve->segments->ArcString)) {
                            $segments[] = (string) $curveMember->Curve->segments->ArcString->posList;
                        }
                    }
                    $posListText = implode(' ', $segments);
                }

                // Rozdělení textového seznamu souřadnic do pole čísel
                $coordArray = preg_split('/\s+/', trim($posListText), -1, PREG_SPLIT_NO_EMPTY);
                $polygonCoords = [];

                // Převod dvojic Y, X ze systému S-JTSK do WGS84 (lon/lat)
                for ($i = 0; $i < count($coordArray) - 1; $i += 2) {
                    $y = (float) $coordArray[$i];
                    $x = (float) $coordArray[$i + 1];
                    $polygonCoords[] = $converter->convertToGeoJson($y, $x);
                }

                // Pokud máme platné body, uložíme parcelu do databáze
                if (count($polygonCoords) > 0) {

                    $ObecKod = $katastrToObec[$KatastralniUzemiKod] ?? '';

                    // Bounding box parcely — potřebný pro R-Tree prostorový index
                    $lon = array_column($polygonCoords, 0);
                    $lat = array_column($polygonCoords, 1);

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