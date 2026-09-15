#!/bin/sh

# Vytvoření složky pro data, pokud neexistuje
mkdir -p data/raw

# Pokud cílový XML soubor ještě neexistuje, stáhneme ho
if [ ! -f "data/raw/data_jicin.xml" ]; then
    echo "Stahuji RÚIAN data pro Jičín z ČÚZK..."
    curl -o data/raw/jicin.zip https://vdp.cuzk.gov.cz/vymenny_format/soucasna/20260831_OB_572659_UKSH.xml.zip

    echo "Rozbaluji a přejmenovávám na jicin_parcels.xml..."
    unzip -p data/raw/jicin.zip > data/raw/jicin_parcels.xml

    # Úklid staženého ZIPu
    rm data/raw/jicin.zip
else
    echo "Data už jsou stažena, přeskakuji stahování."
fi

echo "Spouštím import do databáze..."
php scripts/import.php

echo "Startuji server na portu 8000..."
php -S 0.0.0.0:8000 -t public