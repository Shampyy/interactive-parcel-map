<?php

    /**
     * CLI skript pro převod RUIAN Výměnného formátu (XML) do formátu GeoJSON.
     * Skript využívá XMLReader pro efektivní čtení velkých souborů bez přetečení paměti.
     */

    require_once __DIR__ . '/../src/CoordinateConverter.php';

    $converter = new CoordinateConverter();
    $reader = new XMLReader();

    // Načtení zdrojových dat
    $reader->open(__DIR__ . '/../data/raw/data_jicin.xml');
    $geoJsonFeatures = [];

    // Sekvenční procházení XML uzlů
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Parcela') {

            $xmlText = $reader->readOuterXml();
            $node = new SimpleXMLElement($xmlText);

            // Získání jmenných prostorů pro správné parsování elementů
            $namespaces = $node->getNamespaces(true);
            $pai = $node->children($namespaces['pai']);

            $kmenoveCislo = (string) $pai->KmenoveCislo;
            $vymera = (int) $pai->VymeraParcely;

            // Kontrola, zda má parcela definované hranice
            if (isset($pai->Geometrie->OriginalniHranice)) {
                $gml = $pai->Geometrie->OriginalniHranice->children($namespaces['gml']);
                $posListText = (string) $gml->Polygon->exterior->LinearRing->posList;

                // Odstranění vícenásobných bílých znaků a rozdělení na jednotlivé souřadnice
                $coordArray = preg_split('/\s+/', trim($posListText), -1, PREG_SPLIT_NO_EMPTY);
                $polygonCoords = [];

                // rozdělení na dvojice (Y, X)
                for ($i = 0; $i < count($coordArray) - 1; $i += 2) {
                    $y = (float) $coordArray[$i];
                    $x = (float) $coordArray[$i + 1];

                    // Převod ze systému S-JTSK do WGS84
                    $converterPoint = $converter->convertToGeoJson($y, $x);
                    $polygonCoords[] = $converterPoint;
                }

                // Uložení feature objektu pouze v případě, že se podařilo vyčíst platné souřadnice
                if (count($polygonCoords) > 0) {
                    $geoJsonFeatures[] = [
                        "type" => "Feature",
                        "properties" => [
                            "parcel_number" => $kmenoveCislo,
                            "area" => $vymera
                        ],
                        "geometry" => [
                            "type" => "Polygon",
                            "coordinates" => [$polygonCoords]
                        ]
                    ];
                }
            }
            $reader->next();
        }
    }

    // Sestavení finálního GeoJSON objektu
    $finalGeoJson = [
        "type" => "FeatureCollection",
        "features" => $geoJsonFeatures
    ];

    // Zajištění existence výstupního adresáře a zápis souboru
    $geoJsonDir = __DIR__ . '/../public/geojson';
    if (!file_exists($geoJsonDir)) {
        mkdir($geoJsonDir, 0777, true);
    }

    file_put_contents($geoJsonDir . '/jicin_parcels.json', json_encode($finalGeoJson));
    echo "Generování hotovo! GeoJSON uložen.\n";