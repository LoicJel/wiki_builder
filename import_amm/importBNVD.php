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

load_bnvd();

echo "\n\ndone.\n";



/**
 * Find all CSV files in the BNVD folder and import them
 * @return [type] [description]
 */
function load_bnvd()
{
    download_bnv_d();

    $root = __DIR__;
    $tempFolder = __DIR__ . '/temp/';
    $csvFolder =  $tempFolder . 'csv/';
    $files = glob($csvFolder . "*.csv");

    foreach ($files as $filename)
        processBNVD_File($filename);

    echo "Terminé !\n";
}


/**
 * Downloads CSV files from the "Données de vente de pesticides par département"
 * @see https://www.data.gouv.fr/fr/datasets/donnees-de-vente-de-pesticides-par-departement/
 * @param  boolean $bForce [description]
 * @return [type]          [description]
 */
function download_bnv_d($bForce = false)
{
    $apiEndPointURL = 'https://www.data.gouv.fr/fr/datasets/r/1b581fe6-bd99-4197-bc1a-af56b8a75a13';

    $zipFileName = getZipFileName($apiEndPointURL);

    $root = __DIR__;
    $tempFolder = __DIR__ . '/temp/';
    $csvFolder =  $tempFolder . 'csv/';
    $zipFileName = $tempFolder . $zipFileName;

    if (!is_dir($tempFolder))
        mkdir($tempFolder);
    if (!is_dir($csvFolder))
        mkdir($csvFolder);

    if (is_file($zipFileName))
    {
        if ($bForce)
            unlink($zipFileName);
        else
            return; // File has already be parsed
    }

    copy($apiEndPointURL, $zipFileName);

    // first remove all the xml files
    $files = glob($csvFolder . "*.csv");
    foreach ($files as $file)
        unlink($file);

    $zip = new ZipArchive;
    if ($zip->open($zipFileName) === TRUE)
    {
        $zip->extractTo($csvFolder);
        $zip->close();
    }
    else
    {
        echo 'failed extracting zip';
    }
}


function processBNVD_File($filename)
{
    $row = 1;
    $handle = fopen($filename, "r");

    if (!$handle)
        return;

    $AMMSubstances = array();

    // Parse the file a first time to know how many substances we have per product
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE)
    {
        // Début;Fin;Département;AMM;Exemple de nom de produit;Quantité produit;Conditionnement;Substance;N° CAS;Quantité (Kg);Distributeurs concernés (niveau AMM)
        // 2008-01-01;2008-12-31;AUBE;2000001;DESHERBANT ALLEES PJT BASF HJ;0.8;L;glyphosate;1071-83-6;0.288;1

        if ($data[1] == 'Fin')
            continue; // header row

        $AMM = $data[3];
        $substance = $data[7] . $data[8]; // Substance  N° CAS

        $AMMSubstances[$AMM][$substance] = $substance;
    }


    // Now properly parse the file:
    rewind($handle);

    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE)
    {
        // Début;Fin;Département;AMM;Exemple de nom de produit;Quantité produit;Conditionnement;Substance;N° CAS;Quantité (Kg);Distributeurs concernés (niveau AMM)
        // 2008-01-01;2008-12-31;AUBE;2000001;DESHERBANT ALLEES PJT BASF HJ;0.8;L;glyphosate;1071-83-6;0.288;1

        if ($data[1] == 'Fin')
            continue; // header row

        $year = substr($data[0], 0, 4);
        $depNo = matchDepartement($data[2]);
        $AMM = $data[3];

        $CAS = $GLOBALS['db']->real_escape_string($data[8]);
        $substanceQty = $GLOBALS['db']->real_escape_string($data[9]);
        $productQty = $data[5] / count($AMMSubstances[$AMM]); // the quantity is repeated as many times as there are substances
        $unit = $GLOBALS['db']->real_escape_string($data[6]);

        // Now update the substance
        $sql = "SELECT substance_quantity.substance_id
                FROM substance_quantity
                INNER JOIN substance ON substance.id = substance_quantity.substance_id
                WHERE substance_quantity.product_id = $AMM
                AND substance.cas = '$CAS'";

        if ($query = $GLOBALS['db']->query($sql))
        {
            if ($row = $query->fetch_assoc())
            {
                $substance_id = $row['substance_id'];

                $sql = "INSERT INTO yearly_substance_usage (year, department, substance_id, quantity) VALUES ('$year', '$depNo', '$substance_id', '$substanceQty')
                        ON DUPLICATE KEY UPDATE quantity = quantity + '$substanceQty'";

                if (!$GLOBALS['db']->query($sql))
                {
                    printf("SQL Error: %s\n", $GLOBALS['db']->error);
                    echo  $sql;
                    exit();
                }
            }
        }


        // Now update the product quantity. First make sure that the product actually exists:
        $sql = "SELECT ammproduct.id
                FROM ammproduct
                WHERE ammproduct.id = $AMM";

        if ($query = $GLOBALS['db']->query($sql))
        {
            if ($row = $query->fetch_assoc())
            {
                $sql = "INSERT INTO yearly_ammusage (year, department, product_id, quantity, unit) VALUES ('$year', '$depNo', '$AMM', '$productQty', '$unit')
                        ON DUPLICATE KEY UPDATE quantity = quantity + '$productQty'";

                if (!$GLOBALS['db']->query($sql))
                {
                    printf("SQL Error: %s\n", $GLOBALS['db']->error);
                    echo  $sql;
                    exit();
                }
            }
        }
    }

    fclose($handle);
}


/**
 * Find all CSV files in the BNVD folder and import them
 * @return [type] [description]
 */
function bindCASandSubstances()
{
    download_bnv_d();

    $root = __DIR__;
    $tempFolder = __DIR__ . '/temp/';
    $csvFolder =  $tempFolder . 'csv/';
    $files = glob($csvFolder . "*.csv");

    foreach ($files as $filename)
        processFileForCasBinding($filename);

    echo "Terminé !\n";
}

function processFileForCasBinding($filename)
{
    echo "importing CAS bindings for $filename \n";

    $row = 1;
    $handle = fopen($filename, "r");

    if (!$handle)
        return;

    $AMMSubstances = array();

    // Parse the file a first time to know what are the substances we have for each product
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE)
    {
        // Début;Fin;Département;AMM;Exemple de nom de produit;Quantité produit;Conditionnement;Substance;N° CAS;Quantité (Kg);Distributeurs concernés (niveau AMM)
        // 2008-01-01;2008-12-31;AUBE;2000001;DESHERBANT ALLEES PJT BASF HJ;0.8;L;glyphosate;1071-83-6;0.288;1

        if ($data[1] == 'Fin')
            continue; // header row

        $AMM = $data[3];
        $Qte = $data[9];

        $substance = $data[7] . $data[8]; // Substance  N° CAS

        if (!isset($AMMSubstances[$AMM][$substance]))
            $AMMSubstances[$AMM][$substance] = array('qte' => $Qte, 'CAS' => $data[8], 'name' => $data[7]);
        else
        {
            $AMMSubstances[$AMM][$substance]['qte'] += $Qte;
        }
    }

    fclose($handle);

    foreach ($AMMSubstances as $AMM => $BNVDsubstances)
    {
        $sql = "SELECT substance_quantity.product_id, substance_quantity.substance_id,
                       substance_quantity.quantity, substance_quantity.unit,
                       substance.name, substance.variant, substance.cas, substance.bnvd_name
                FROM substance_quantity
                INNER JOIN substance ON substance.id = substance_quantity.substance_id
                WHERE substance_quantity.product_id = $AMM
                ORDER BY substance_quantity.quantity ASC, substance.variant DESC";

        $substances = array();

        if ($query = $GLOBALS['db']->query($sql))
        {
            while ($row = $query->fetch_assoc())
                $substances[$row['substance_id']] = $row;
        }

        if (count($substances) == 0)
            continue; // AMM not in the DB

        if (count($substances) == count($BNVDsubstances))
        {
            if (count($BNVDsubstances) == 1)
            {
                $a = reset($substances);
                $b = reset($BNVDsubstances);

                updateSubstanceWithCAS($a, $b, false);
            }
            else
            {
                // So now we have two sets of substances to match. Let's sort them by quantity and name:
                usort($substances, 'cmp_dbsubstances');
                usort($BNVDsubstances, 'cmp_bnvdsubstances');

                foreach ($substances as $k => $substance)
                    updateSubstanceWithCAS($substance, $BNVDsubstances[$k], true);
            }
        }
        else // count mismatch
        {
            foreach ($substances as $a)
            {
                foreach ($BNVDsubstances as $b)
                {
                    if (updateSubstanceWithCAS($a, $b, true))
                        echo "Matched uneven count $AMM : " . $a['name'] . " (".$a['variant'].") --> " . $b['name'] . "\n";
                }
            }

            continue;
        }
    }


}

function updateSubstanceWithCAS($substance, $cas, $bcheckIfSimilarName)
{
    if (!empty($substance['cas']))
      return false; // Already set, nothing to do

    $AMM = $substance['product_id'];

    if ($bcheckIfSimilarName)
    {
        $percent1 = 0;
        similar_text(strtoupper($substance['variant']), strtoupper($cas['name']), $percent1);
        $percent2 = 0;
        similar_text(strtoupper($substance['name']), strtoupper($cas['name']), $percent2);

        $exceptions = array();

        $exceptions['135590-91-9'][] = 'Mefenpyr';
        $exceptions['144550-36-7'][] = 'Mesosulfuron';
        $exceptions['163515-14-8'][] = 'Dimethenamid-p';
        $exceptions['16484-77-8'][] = 'Mcpp';
        $exceptions['16484-77-8'][] = 'Mecoprop';
        $exceptions['16484-77-8'][] = 'Mecoprop-P';
        $exceptions['7085-19-0'][] = 'Mcpp';
        $exceptions['7085-19-0'][] = 'Mecoprop';
        $exceptions['24307-26-4'][] = 'Mepiquat';
        $exceptions['334-48-5'][] = 'Fatty acids';
        $exceptions['124-07-2'][] = 'Fatty acids';
        $exceptions['112-72-1'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['16725-53-4'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['16974-11-1'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['20711-10-8'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['28079-04-1'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['33956-49-9'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['37338-40-2'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['38363-29-0'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['38421-90-8'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['40642-40-8'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['54364-62-4'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['56578-18-8'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['629-70-9'][] = 'Straight Chain Lepidopteran Pheromones';
        $exceptions['51-03-6'][] = 'Piperonyl butoxide';
        $exceptions['64-17-5'][] = 'Cire d\'abeille';
        $exceptions['64-17-5'][] = 'Ethanol';
        $exceptions['7446-19-7'][] = 'Zinc';
        $exceptions['8001-79-4'][] = 'Huile de riccin ethoxylee';
        $exceptions['8002-09-3'][] = 'Plant oils / Pinus oil';
        $exceptions['8006-64-2'][] = 'essence de térébenthine';
        $exceptions['8050-09-7'][] = 'Resines';
        $exceptions['86-87-3'][] = '1-Naphtylacetic acid';
        $exceptions['9006-42-2'][] = 'Metiram';
        $exceptions['94-74-6'][] = 'MCPA';
        $exceptions['61789-60-4'][] = 'Poix extrait de résine de pin';
        $exceptions['65996-93-2'][] = 'Poix extrait de résine de pin';
        $exceptions['8050-18-8'][] = 'Poix extrait de résine de pin';
        $exceptions['65996-93-2'][] = 'colophane traitée au maléate';
        $exceptions['61789-60-4'][] = 'colophane traitée au maléate';
        $exceptions['8050-18-8'][] = 'colophane traitée au maléate';
        $exceptions['8011-48-1'][] = 'Goudron de pin';

        $bMatchOnException = false;
        if (isset($exceptions[$cas['CAS']]))
        {
            foreach ($exceptions[$cas['CAS']] as $exception)
            {
                $percent3 = 0;
                similar_text(strtoupper($substance['name']), strtoupper($exception), $percent3);
                if ($percent3 > 80)
                {
                    $bMatchOnException = true;
                    break;
                }
            }
        }

        if ($percent1 > 80 || $percent2 > 80 || $bMatchOnException)
        {
            // There is a name match, we can continue
        }
        else
        {
            echo "Possible mismatch $AMM\t" . $substance['name'] . "\t".$substance['variant']."\t" . $cas['name'] . "\t". $cas['CAS']."\n";
            return false; // don't touch this.
        }
    }

    $sql = "UPDATE substance
            SET cas = '".$GLOBALS['db']->real_escape_string($cas['CAS'])."',
                bnvd_name = '".$GLOBALS['db']->real_escape_string($cas['name'])."'
            WHERE id = " . $substance['substance_id'];

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }

    return true;
}

function cmp_dbsubstances($a, $b)
{
    if ($a['quantity'] != $b['quantity'])
        return $a['quantity'] < $b['quantity'];

    return $a['variant'] < $b['variant'];
}

function cmp_bnvdsubstances($a, $b)
{
    if ($a['qte'] != $b['qte'])
        return $a['qte'] < $b['qte'];

    return $a['name'] < $b['name'];
}

function matchDepartement($depName)
{
    $depNo = '';

    switch ($depName)
    {
        case 'AIN' : $depNo = '1'; break;
        case 'AISNE' : $depNo = '2'; break;
        case 'ALLIER' : $depNo = '3'; break;
        case 'ALPES-DE-HAUTE-PROVENCE' : $depNo = '4'; break;
        case 'ALPES-MARITIMES' : $depNo = '6'; break;
        case 'ARDECHE' : $depNo = '7'; break;
        case 'ARDENNES' : $depNo = '8'; break;
        case 'ARIEGE' : $depNo = '9'; break;
        case 'AUBE' : $depNo = '10'; break;
        case 'AUDE' : $depNo = '11'; break;
        case 'AVEYRON' : $depNo = '12'; break;
        case 'BAS-RHIN' : $depNo = '67'; break;
        case 'BOUCHES-DU-RHONE' : $depNo = '13'; break;
        case 'CALVADOS' : $depNo = '14'; break;
        case 'CANTAL' : $depNo = '15'; break;
        case 'CHARENTE' : $depNo = '16'; break;
        case 'CHARENTE-MARITIME' : $depNo = '17'; break;
        case 'CHER' : $depNo = '18'; break;
        case 'CORREZE' : $depNo = '19'; break;
        case 'CORSE-DU-SUD' : $depNo = '2a'; break;
        case 'COTE-D\'OR' : $depNo = '21'; break;
        case 'COTES-D\'ARMOR' : $depNo = '22'; break;
        case 'CREUSE' : $depNo = '23'; break;
        case 'DEUX-SEVRES' : $depNo = '79'; break;
        case 'DORDOGNE' : $depNo = '24'; break;
        case 'DOUBS' : $depNo = '25'; break;
        case 'DROME' : $depNo = '26'; break;
        case 'ESSONNE' : $depNo = '91'; break;
        case 'EURE' : $depNo = '27'; break;
        case 'EURE-ET-LOIR' : $depNo = '28'; break;
        case 'FINISTERE' : $depNo = '29'; break;
        case 'GARD' : $depNo = '30'; break;
        case 'GERS' : $depNo = '32'; break;
        case 'GIRONDE' : $depNo = '33'; break;
        case 'GUADELOUPE' : $depNo = '971'; break;
        case 'GUYANE' : $depNo = '973'; break;
        case 'HAUTE-CORSE' : $depNo = '2b'; break;
        case 'HAUTE-GARONNE' : $depNo = '31'; break;
        case 'HAUTE-LOIRE' : $depNo = '43'; break;
        case 'HAUTE-MARNE' : $depNo = '52'; break;
        case 'HAUTES-ALPES' : $depNo = '5'; break;
        case 'HAUTE-SAONE' : $depNo = '70'; break;
        case 'HAUTE-SAVOIE' : $depNo = '74'; break;
        case 'HAUTES-PYRENEES' : $depNo = '65'; break;
        case 'HAUTE-VIENNE' : $depNo = '87'; break;
        case 'HAUT-RHIN' : $depNo = '68'; break;
        case 'HAUTS-DE-SEINE' : $depNo = '92'; break;
        case 'HERAULT' : $depNo = '34'; break;
        case 'ILLE-ET-VILAINE' : $depNo = '35'; break;
        case 'INDRE' : $depNo = '36'; break;
        case 'INDRE-ET-LOIRE' : $depNo = '37'; break;
        case 'ISERE' : $depNo = '38'; break;
        case 'JURA' : $depNo = '39'; break;
        case 'LA REUNION' : $depNo = '974'; break;
        case 'LANDES' : $depNo = '40'; break;
        case 'LOIRE' : $depNo = '42'; break;
        case 'LOIRE-ATLANTIQUE' : $depNo = '44'; break;
        case 'LOIRET' : $depNo = '45'; break;
        case 'LOIR-ET-CHER' : $depNo = '41'; break;
        case 'LOT' : $depNo = '46'; break;
        case 'LOT-ET-GARONNE' : $depNo = '47'; break;
        case 'LOZERE' : $depNo = '48'; break;
        case 'MAINE-ET-LOIRE' : $depNo = '49'; break;
        case 'MANCHE' : $depNo = '50'; break;
        case 'MARNE' : $depNo = '51'; break;
        case 'MARTINIQUE' : $depNo = '972'; break;
        case 'MAYENNE' : $depNo = '53'; break;
        case 'MEURTHE-ET-MOSELLE' : $depNo = '54'; break;
        case 'MEUSE' : $depNo = '55'; break;
        case 'MORBIHAN' : $depNo = '56'; break;
        case 'MOSELLE' : $depNo = '57'; break;
        case 'NIEVRE' : $depNo = '58'; break;
        case 'NORD' : $depNo = '59'; break;
        case 'OISE' : $depNo = '60'; break;
        case 'ORNE' : $depNo = '61'; break;
        case 'PARIS' : $depNo = '75'; break;
        case 'PAS-DE-CALAIS' : $depNo = '62'; break;
        case 'PUY-DE-DOME' : $depNo = '63'; break;
        case 'PYRENEES-ATLANTIQUES' : $depNo = '64'; break;
        case 'PYRENEES-ORIENTALES' : $depNo = '66'; break;
        case 'RHONE' : $depNo = '69'; break;
        case 'SAONE-ET-LOIRE' : $depNo = '71'; break;
        case 'SARTHE' : $depNo = '72'; break;
        case 'SAVOIE' : $depNo = '73'; break;
        case 'SEINE-ET-MARNE' : $depNo = '77'; break;
        case 'SEINE-MARITIME' : $depNo = '76'; break;
        case 'SEINE-SAINT-DENIS' : $depNo = '93'; break;
        case 'SOMME' : $depNo = '80'; break;
        case 'TARN' : $depNo = '81'; break;
        case 'TARN-ET-GARONNE' : $depNo = '82'; break;
        case 'TERRITOIRE DE BELFORT' : $depNo = '90'; break;
        case 'VAL-DE-MARNE' : $depNo = '94'; break;
        case 'VAL-D\'OISE' : $depNo = '95'; break;
        case 'VAR' : $depNo = '83'; break;
        case 'VAUCLUSE' : $depNo = '84'; break;
        case 'VENDEE' : $depNo = '85'; break;
        case 'VIENNE' : $depNo = '86'; break;
        case 'VOSGES' : $depNo = '88'; break;
        case 'YONNE' : $depNo = '89'; break;
        case 'YVELINES' : $depNo = '78'; break;
        case 'MAYOTTE' : $depNo = '976'; break;

        default:
            echo "Erreur - nom de département inconnu";
    }

    return $depNo;
}
