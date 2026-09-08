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
            $nsCom = $namespaces['com'] ?? 'urn:cz:isvs:ruian:schemas:CommonTypy:v1';
            $pai = $node->children($namespaces['pai']);

            $kmenoveCislo = (string) $pai->KmenoveCislo;
            $poddeleniCisla = (string) $pai->PoddeleniCisla;
            $vymera = (int) $pai->VymeraParcely;

            if(isset($pai->KatastralniUzemi)){
                $com = $pai->KatastralniUzemi->children($nsCom);
                $KatastralniUzemiKod = (string) $com->Kod;
            }else{
                $KatastralniUzemiKod = '';
            }

            if(isset($pai->DruhPozemku)){
                $comDruh = $pai->DruhPozemku->children($nsCom);
                $DruhPozemkuKod = (string) $comDruh->Kod;
            }else{
                $DruhPozemkuKod = '';
            }

            if (!empty($poddeleniCisla)) {
                $formatovaneCislo = $kmenoveCislo . '/' . $poddeleniCisla;
            }else{
                $formatovaneCislo = $kmenoveCislo;
            }


            // Kontrola, zda má parcela definované hranice
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

                // rozdělení na dvojice (Y, X)
                for ($i = 0; $i < count($coordArray) - 1; $i += 2) {
                    $y = (float) $coordArray[$i];
                    $x = (float) $coordArray[$i + 1];
                    $polygonCoords[] = $converter->convertToGeoJson($y, $x);
                }

                // Uložení feature objektu pouze v případě, že se podařilo vyčíst platné souřadnice
                if (count($polygonCoords) > 0) {
                    $geoJsonFeatures[] = [
                        "type" => "Feature",
                        "properties" => [
                            "parcel_number" => $formatovaneCislo,
                            "area" => $vymera,
                            "type" => $DruhPozemkuKod,
                            "region" => $KatastralniUzemiKod,
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

    // Zajištění existence výstupního adresáře a zápis souboru
    $geoJsonDir = __DIR__ . '/../public/geojson';
    if (!file_exists($geoJsonDir)) {
        mkdir($geoJsonDir, 0777, true);
    }

    file_put_contents($geoJsonDir . '/jicin_parcels.json', json_encode($finalGeoJson));
    echo "Generování hotovo! GeoJSON uložen.\n";