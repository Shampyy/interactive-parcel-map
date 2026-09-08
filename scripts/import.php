<?php

    /**
     * CLI skript pro převod RUIAN Výměnného formátu (XML) do formátu GeoJSON.
     * Skript využívá XMLReader pro efektivní čtení velkých souborů bez přetečení paměti.
     */

    require_once __DIR__ . '/../src/CoordinateConverter.php';

    $converter = new CoordinateConverter();
    $reader = new XMLReader();

    // Validace a otevření vstupního XML souboru
    $sourcePath = __DIR__ . '/../data/raw/data_jicin.xml';
    if (!file_exists($sourcePath)) {
        fwrite(STDERR, "Zdrojový soubor nenalezen: $sourcePath\n");
        exit(1);
    }
    if (!$reader->open($sourcePath)) {
        fwrite(STDERR, "Nepodařilo se otevřít soubor: $sourcePath\n");
        exit(1);
    }

    $geoJsonFeatures = [];
    $seenIds = [];

    // Datové slovníky pro uchování globálních informací o obci a katastru
    $katastry = [];
    $currentObecKod = '';
    $currentObecNazev = '';

    // Sekvenční procházení XML uzlů
    while ($reader->read()) {

        // Záchyt globálních informací o obci pomocí Regulárních výrazů
        // Regex používáme pro bezpečné vyčtení dat bez ohledu na XML namespaces
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

        // Záchyt Katastrálních území a jejich uložení do dočasného slovníku
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'KatastralniUzemi') {
            $xml = $reader->readOuterXml();
            preg_match('/<[^>]+:Kod>(\d+)<\/[^>]+:Kod>/', $xml, $kodM);
            preg_match('/<[^>]+:Nazev>([^<]+)<\/[^>]+:Nazev>/', $xml, $nazM);
            if (!empty($kodM[1]) && !empty($nazM[1])) {
                $katastry[$kodM[1]] = $nazM[1];
            }
            continue;
        }

        // Hlavní zpracování jednotlivých parcel
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Parcela') {

            $xmlText = $reader->readOuterXml();
            $node = new SimpleXMLElement($xmlText);

            // Extrakce jmenných prostorů pro konkrétní uzel parcely
            $namespaces = $node->getNamespaces(true);
            $pai = $node->children($namespaces['pai']);

            $paiId = (string) $pai->Id;
            $seenIds[] = $paiId;

            // Základní atributy parcely
            $kmenoveCislo = (string) $pai->KmenoveCislo;
            $pododdeleniCisla = (string) $pai->PododdeleniCisla;
            $DruhPozemkuKod = (string) $pai->DruhPozemkuKod;
            $vymera = (int) $pai->VymeraParcely;
            $DruhCislovaniKod = (string) $pai->DruhCislovaniKod;

            // Získání kódu Katastrálního území přes XPath
            $KatastralniUzemiKod = '';
            if (isset($pai->KatastralniUzemi)) {
                $kodNodes = $pai->KatastralniUzemi->xpath('.//*[local-name()="Kod"]');
                if (!empty($kodNodes)) {
                    $KatastralniUzemiKod = (string) $kodNodes[0];
                }
            }

            $KatastralniUzemiNazev = $katastry[$KatastralniUzemiKod] ?? '';
            $ObecKod = $currentObecKod;
            $ObecNazev = $currentObecNazev;

            // Zformátování čísla parcely
            if (!empty($pododdeleniCisla)) {
                $formatovaneCislo = $kmenoveCislo . '/' . $pododdeleniCisla;
            }else{
                $formatovaneCislo = $kmenoveCislo;
            }

            // Kontrola, zda má parcela definované hranice a extrakce bodů
            if (isset($pai->Geometrie->OriginalniHranice)) {
                $gml = $pai->Geometrie->OriginalniHranice->children($namespaces['gml']);
                $exterior = $gml->Polygon->exterior;

                $posListText = '';

                if (isset($exterior->LinearRing)) {
                    // Jednoduchý polygon (rovné hrany)
                    $posListText = (string) $exterior->LinearRing->posList;
                } elseif (isset($exterior->Ring)) {
                    // Složený polygon (křivky a zaoblení)
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

                // Rozdělení textového seznamu souřadnic do pole
                $coordArray = preg_split('/\s+/', trim($posListText), -1, PREG_SPLIT_NO_EMPTY);
                $polygonCoords = [];

                // Převod dvojic Y, X ze systému S-JTSK do WGS84 (lat/lon)
                for ($i = 0; $i < count($coordArray) - 1; $i += 2) {
                    $y = (float) $coordArray[$i];
                    $x = (float) $coordArray[$i + 1];
                    $polygonCoords[] = $converter->convertToGeoJson($y, $x);
                }

                // Pokud máme platné body, uložíme feature do GeoJSON pole
                if (count($polygonCoords) > 0) {
                    $geoJsonFeatures[] = [
                        "type" => "Feature",
                        "properties" => [
                            "id" => $paiId,
                            "parcel_number" => $formatovaneCislo,
                            "area" => $vymera,
                            "type" => $DruhPozemkuKod,
                            "region" => $KatastralniUzemiKod,
                            "region_name" => $KatastralniUzemiNazev,
                            "municipality" => $ObecKod,
                            "municipality_name" => $ObecNazev,
                        ],
                        "geometry" => [
                            "type" => "Polygon",
                            "coordinates" => [$polygonCoords]
                        ]
                    ];
                }
            }
        }
    }

    $reader->close();

    // Seřazení podle výměry (od největší po nejmenší).
    usort($geoJsonFeatures, function ($a, $b) {
        return $b['properties']['area'] <=> $a['properties']['area'];
    });

    // Sestavení finálního GeoJSON objektu
    $finalGeoJson = [
        "type" => "FeatureCollection",
        "features" => $geoJsonFeatures
    ];

    // Zajištění existence výstupního adresáře
    $geoJsonDir = __DIR__ . '/../public/geojson';
    if (!file_exists($geoJsonDir)) {
        mkdir($geoJsonDir, 0777, true);
    }

    // Ochranná kontrola na nesmyslné S-JTSK souřadnice (mimo oblast Jičína)
    $jicinBounds = ['lonMin' => 15.20, 'lonMax' => 15.50, 'latMin' => 50.35, 'latMax' => 50.55];
    $suspect = 0;

    foreach ($geoJsonFeatures as $feature) {
        foreach ($feature['geometry']['coordinates'][0] as [$lon, $lat]) {
            if ($lon < $jicinBounds['lonMin'] || $lon > $jicinBounds['lonMax']
                || $lat < $jicinBounds['latMin'] || $lat > $jicinBounds['latMax']) {
                $suspect++;
                break;
            }
        }
    }

    // Uložení výsledného GeoJSONu
    file_put_contents($geoJsonDir . '/jicin_parcels.json', json_encode($finalGeoJson));
    echo "Generování hotovo! GeoJSON uložen.\n";