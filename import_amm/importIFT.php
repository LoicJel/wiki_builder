<?php

$GLOBALS['debugMode'] = false;

mb_internal_encoding("UTF-8");

// Set some attributes tables definitions:
$GLOBALS['attributes']['Fonctions'] =    Array('linkTable' => 'usecase_ammproduct',     'attributeTable' => 'usecase');
$GLOBALS['attributes']['Mentions'] =     Array('linkTable' => 'mention_ammproduct',     'attributeTable' => 'mention');
$GLOBALS['attributes']['Formulations'] = Array('linkTable' => 'formulation_ammproduct', 'attributeTable' => 'formulation');
$GLOBALS['attributes']['Dangers'] =      Array('linkTable' => 'danger_ammproduct',      'attributeTable' => 'danger');
$GLOBALS['attributes']['Risques'] =      Array('linkTable' => 'risk_ammproduct',        'attributeTable' => 'risk');

set_time_limit(0);

loadIFT();

echo "\n\ndone.\n";


// référence pour les classe-danger
// http://wiki.scienceamusante.net/index.php?title=Classes_et_cat%C3%A9gories_de_danger_dans_le_r%C3%A8glement_CLP/SGH
// https://fr.wikipedia.org/wiki/Syst%C3%A8me_g%C3%A9n%C3%A9ral_harmonis%C3%A9_de_classification_et_d%27%C3%A9tiquetage_des_produits_chimiques
//
// https://fr.wikipedia.org/wiki/Signalisation_des_substances_dangereuses

function loadIFT()
{
//    download_IFT();

    $sql = "DELETE FROM ift";

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }

    $root = __DIR__;
    $tempFolder = __DIR__ . '/temp/';
    $csvFolder =  $tempFolder . 'json/';

    $files = glob($csvFolder . "*.json");
    sort($files);

    foreach ($files as $filename)
        processIFT_File($filename);

    echo "Terminé !\n";
}


/**
 * Downloads CSV files from the "IFT 2016/2017 - Doses de référence/cible/culture"
 * @see https://plateforme.api-agro.fr/explore/dataset/ift-20162017-doses-de-referencecibleculture/information/
 * @param  boolean $bForce [description]
 * @return [type]          [description]
 */
function download_IFT($bForce = false)
{
    $apiEndPointURL = 'https://plateforme.api-agro.fr/explore/dataset/ift-20162017-doses-de-referencecibleculture/download/?format=csv&timezone=Europe/Berlin&use_labels_for_header=true';

    $root = __DIR__;
    $tempFolder = __DIR__ . '/temp/';
    $csvFolder =  $tempFolder . 'ift/';
    $targetFileName = $csvFolder . 'ift.csv';

    if (!is_dir($tempFolder))
        mkdir($tempFolder);
    if (!is_dir($csvFolder))
        mkdir($csvFolder);

    if (is_file($targetFileName))
    {
        if ($bForce)
            unlink($targetFileName);
        else
            return; // File has already be parsed
    }

    copy($apiEndPointURL, $targetFileName);
}


function processIFT_File($filename)
{
    echo "Processing $filename \n";

    $json = json_decode(file_get_contents ($filename), true);


    foreach ($json as $ift)
    {
        $AMM = $ift['numeroAmm']['idMetier'];
        $bio = ($ift['biocontrole'] == 1) ? 1 : 0;

        $id_culture = $GLOBALS['db']->real_escape_string($ift['culture']['idMetier']);
        $culture = $GLOBALS['db']->real_escape_string($ift['culture']['libelle']);
        $cultureCat = $GLOBALS['db']->real_escape_string($ift['culture']['groupeCultures']['libelle']);
        $cultureCatCode = $GLOBALS['db']->real_escape_string($ift['culture']['groupeCultures']['idMetier']);

        if (!isset($ift['cible']['idMetier']))
            continue; // We ignore IFT with no specific target (although there's a general dose recommendation, but that makes too many rows)

        $id_target = $GLOBALS['db']->real_escape_string($ift['cible']['idMetier']);
        $target = $GLOBALS['db']->real_escape_string($ift['cible']['libelle']);

        $targetCat = $GLOBALS['db']->real_escape_string($ift['segment']['libelle']);
        $targetCatCode = $GLOBALS['db']->real_escape_string($ift['segment']['idMetier']);


        $unit = $GLOBALS['db']->real_escape_string($ift['unite']['libelle']);

        if ($ift['unite']['idMetier'] == 'U0') // SANS DOSE
            $dose = 0;
        else
            $dose = $GLOBALS['db']->real_escape_string($ift['dose']);

        $cultureCatId = addOrupdateCategory('culture_category', $cultureCatCode, $cultureCat);
        $targetCatId = addOrupdateCategory('target_category', $targetCatCode, $targetCat);

        $sql = "INSERT INTO culture (id, name, category_id) VALUES ($id_culture, '$culture', $cultureCatId)
                ON DUPLICATE KEY UPDATE name = '$culture', category_id = $cultureCatId";

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }

        $sql = "INSERT INTO target (id, name, category_id) VALUES ($id_target, '$target', $targetCatId )
                ON DUPLICATE KEY UPDATE name = '$target', category_id = $targetCatId";

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }

        if (!checkIDFromTable($AMM, 'AMMProduct'))
            continue;

        $sql = "INSERT INTO ift (product_id, target_id, culture_id, dose, unit, category)
                VALUES ($AMM, '$id_target', '$id_culture', '$dose', '$unit', '$target')";

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }

        $sql = "UPDATE AMMProduct SET biocontrol =  $bio WHERE id = $AMM";

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }
    }
}

function checkIDFromTable($id, $tableName)
{
    $sql = "SELECT id FROM $tableName WHERE id = $id";

    if ($query = $GLOBALS['db']->query($sql))
    {
        if ($row = $query->fetch_assoc())
            return true;
    }

    return false;
}



function addOrupdateCategory($catTableName, $code, $name)
{
    $sql = "INSERT INTO $catTableName (code, name) VALUES ('$code', '$name')
            ON DUPLICATE KEY UPDATE name = '$name'";

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }

    $sql = "SELECT id FROM $catTableName WHERE code = '$code'";

    if ($query = $GLOBALS['db']->query($sql))
    {
        $row = $query->fetch_assoc();
        $id = $row['id'];

        return $id;
    }

    return false;
}
