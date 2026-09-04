<?php

    // Načtení Composer autoloaderu pro přístup ke knihovnám třetích stran
    require_once __DIR__ . '/../vendor/autoload.php';

    use proj4php\Proj4php;
    use proj4php\Proj;
    use proj4php\Point;

    /**
     * Třída pro transformaci geodetických souřadnic z českého standardu (S-JTSK)
     * do mezinárodního GPS standardu (WGS84) využívaného v GeoJSON a webových mapách.
     */
    class CoordinateConverter
    {
        private Proj4php $proj4;
        private Proj $projSJTSK;
        private Proj $projWGS84;


        //Konstruktor inicializuje transformační knihovnu a definuje mapové projekce.
        public function __construct()
        {
            $this->proj4 = new Proj4php();

            // Přidání exaktní definice pro český S-JTSK (EPSG:5514 / Křovákovo zobrazení).
            // Tento PROJ string obsahuje parametry elipsoidu (Bessel) a transformační klíč k WGS84.
            $this->proj4->addDef(
                "EPSG:5514",
                "+proj=krovak +lat_0=49.5 +lon_0=24.83333333333333 +alpha=30.28813972222222 +k=0.9999 +x_0=0 +y_0=0 +ellps=bessel +towgs84=570.8,85.7,462.8,4.998,1.587,5.261,3.56 +units=m +no_defs"
            );

            // Vytvoření instancí obou projekcí pro pozdější převod
            $this->projSJTSK = new Proj('EPSG:5514', $this->proj4);
            $this->projWGS84 = new Proj('EPSG:4326', $this->proj4);
        }


        //Převede souřadnice S-JTSK na GPS souřadnice formátované pro GeoJSON.
        public function convertToGeoJson(float $y, float $x): array
        {
            // Vytvoření bodu ve zdrojovém souřadnicovém systému (Křovák)
            $pointSJTSK = new Point($y, $x, $this->projSJTSK);

            // Matematická transformace bodu do cílového systému (GPS)
            $pointWGS84 = $this->proj4->transform($this->projWGS84, $pointSJTSK);

            // Vracíme formát požadovaný GeoJSON specifikací: [zeměpisná délka, zeměpisná šířka]
            return [
                round($pointWGS84->x, 6),
                round($pointWGS84->y, 6)
            ];
        }
    }