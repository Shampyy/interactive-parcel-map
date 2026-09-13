<?php
    /**
     * API endpoint volaný z frontendu při každém pohybu mapy.
     * Vrací jen parcely, jejichž bounding box protíná zadaný výřez (bbox) —
     */

    // Komprese odpovědi (gzip)
    ob_start('ob_gzhandler');

    header('Content-Type: application/json');
    header('Cache-Control: Public, max-age=60');

    $db = new PDO('sqlite:' . __DIR__ . '/../../data/parcely.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Validace vstupu
    if (!isset($_GET['bbox']) || count($bbox = explode(',', $_GET['bbox'])) !== 4) {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatný nebo chybějící parametr bbox']);
        exit;
    }

    // Dotaz přes JOIN na R-Tree tabulku
    $stmt = $db->prepare('
    SELECT p.id, p.parcel_number, p.area, p.type, p.region, p.region_name, p.municipality, p.municipality_name, p.geometry
    FROM parcely p
    JOIN parcely_rtree r ON p.id = r.id
    WHERE r.minX <= ? AND r.maxX >= ? AND r.minY <= ? AND r.maxY >= ?
    ');

    $stmt->execute([$bbox[2], $bbox[0], $bbox[3], $bbox[1]]);

    $features = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        // Geometrie je v DB uložená jako JSON text
        $souradnice = json_decode($row['geometry']);

        $feature = [
            "type" => "Feature",
            "properties" => [
                "id"                => (string) $row['id'],
                "parcel_number"     => $row['parcel_number'],
                "area"              => (int) $row['area'],
                "type"              => $row['type'],
                "region"            => $row['region'],
                "region_name"       => $row['region_name'],
                "municipality"      => $row['municipality'],
                "municipality_name" => $row['municipality_name'],
            ],
            "geometry" => [
                "type" => "Polygon",
                "coordinates" => [$souradnice]
            ]
        ];

        $features[] = $feature;
    }

    echo json_encode([
        "type" => "FeatureCollection",
        "features" => $features
    ]);