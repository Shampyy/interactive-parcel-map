<?php
    /**
     * API endpoint volaný z frontendu při každém pohybu mapy.
     * Vrací jen parcely, jejichž bounding box protíná zadaný výřez (bbox) —
     */

    // Komprese odpovědi (gzip)
    ob_start('ob_gzhandler');

    header('Content-Type: application/json');
    header('Cache-Control: Public, max-age=60');

    function respondError(int $httpCode, string $message): never
    {
        http_response_code($httpCode);
        echo json_encode(['error' => $message]);
        exit;
    }

    // --- Validace vstupu ---
    if (!isset($_GET['bbox'])) {
        respondError(400, 'Chybějící parametr bbox');
    }

    $bboxParts = explode(',', $_GET['bbox']);
    if (count($bboxParts) !== 4 || !array_reduce($bboxParts, fn($ok, $v) => $ok && is_numeric($v), true)) {
        respondError(400, 'Neplatný parametr bbox — očekávám "west,south,east,north" jako čísla');
    }
    [$west, $south, $east, $north] = array_map('floatval', $bboxParts);

    $zoom = isset($_GET['zoom']) && is_numeric($_GET['zoom']) ? (int) $_GET['zoom'] : 14;

    // Explicitní allow-list místo přímého vkládání hodnoty
    $geoColumn = ($zoom < 14) ? 'geometry_simple' : 'geometry';

    try {
        $db = new PDO('sqlite:' . __DIR__ . '/../../data/parcely.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("
            SELECT p.id, p.parcel_number, p.area, p.type, p.region, p.region_name,
                   p.municipality, p.municipality_name, p.$geoColumn AS geometry
            FROM parcely_rtree r
            JOIN parcely p ON p.id = r.id
            WHERE r.minX <= :east AND r.maxX >= :west
              AND r.minY <= :north AND r.maxY >= :south
        ");

        $stmt->bindValue(':east', $east);
        $stmt->bindValue(':west', $west);
        $stmt->bindValue(':north', $north);
        $stmt->bindValue(':south', $south);
        $stmt->execute();

        // --- OPTIMALIZACE: Streamování výstupu (šetří paměť serveru) ---
        echo '{"type":"FeatureCollection","features":[';
        $prvni = true;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!$prvni) {
                echo ',';
            }
            $prvni = false;

            // Enkódujeme pouze malý slovník properties
            $properties = json_encode([
                "id"                => (string) $row['id'],
                "parcel_number"     => $row['parcel_number'],
                "area"              => (int) $row['area'],
                "type"              => $row['type'],
                "region"            => $row['region'],
                "region_name"       => $row['region_name'],
                "municipality"      => $row['municipality'],
                "municipality_name" => $row['municipality_name'],
            ]);

            // Geometrie z DB už JE validní JSON string, nemusíme ji parsovat
            $geometryStr = $row['geometry'];

            // Slepíme rovnou textový řetězec a pošleme ho ven
            echo '{"type":"Feature","properties":' . $properties . ',"geometry":{"type":"Polygon","coordinates":' . $geometryStr . '}}';
        }

        echo ']}';

    } catch (PDOException $e) {
        // Nikdy nenecháváme uniknout syrovou PDOException ven — klient by
        // místo JSONu dostal HTML stránku s fatal errorem.
        error_log('parcely.php DB error: ' . $e->getMessage());
        respondError(500, 'Interní chyba při načítání parcel');
    }