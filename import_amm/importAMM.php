<?php

/*

{{PPP
|name=REVUS TOP
|AMM=2130244
|Provider=SYNGENTA FRANCE SAS
|Status=Produit de référence
|UsageType=Professionnel
|AMMSince=10/07/2014
|Function=Fongicide
|Formulation=Suspension concentrée
|CompositionString=250 g/l [[Difenoconazole]] + 250 g/l [[Mandipropamid]]
|Usage=Mildiou....
}}

[[Category:Difenoconazole]][[Category:Mandipropamid]]


FONGICIDES
PROTECTION DES CULTURES
N° AMM :
Composition: 250 g/ldifénoconazole250 g/lmandipropamid
Famille chimique:
TriazolesCarboxylic Acid Amide
Formulation: SC Suspension concentrée
Mode d'action: PréventifDiffusantEffet Antisporulant : Bonne action anti-sporulante sur mildiouPénétrantTranslaminaireFoliaire

*/


$GLOBALS['debugMode'] = false;

mb_internal_encoding("UTF-8");

// Set some attributes tables definitions:
$GLOBALS['attributes']['Fonctions'] =    Array('linkTable' => 'usecase_ammproduct',     'attributeTable' => 'usecase');
$GLOBALS['attributes']['Mentions'] =     Array('linkTable' => 'mention_ammproduct',     'attributeTable' => 'mention');
$GLOBALS['attributes']['Formulations'] = Array('linkTable' => 'formulation_ammproduct', 'attributeTable' => 'formulation');
$GLOBALS['attributes']['Dangers'] =      Array('linkTable' => 'danger_ammproduct',      'attributeTable' => 'danger');
$GLOBALS['attributes']['Risques'] =      Array('linkTable' => 'risk_ammproduct',        'attributeTable' => 'risk');

set_time_limit(0);

include_once(__DIR__ . '/../includes/wikibuilder.php');

libxml_use_internal_errors(true);

$filename = __DIR__ . "/../out/wiki_AMM.xml";

if (file_exists($filename))
    unlink($filename);

$GLOBALS['wikiBuilder'] = new wikiImportFile($filename);


importAMMDecisions();

$GLOBALS['wikiBuilder']->close();

echo "\n\ndone.\n";


function importAMMDecisions()
{
    downloadAMMSourceData(false);
    loadAMMxml();
}

/**
 * Downloads a zip file with the XML containing all the AMM products authorised
 * @see https://www.data.gouv.fr/fr/datasets/donnees-ouvertes-du-catalogue-e-phy-des-produits-phytopharmaceutiques-matieres-fertilisantes-et-supports-de-culture-adjuvants-produits-mixtes-et-melanges/
 *
 *
 */
function downloadAMMSourceData($bForce = false)
{
    $apiEndPointURL = 'https://www.data.gouv.fr/fr/datasets/r/cdbc887b-265e-4338-9509-5e9958df1a48';

    $zipFileName = getZipFileName($apiEndPointURL);

    $tempFolder = __DIR__ . '/../temp/';
    $xmlFolder =  $tempFolder . 'amm_xml/';
    $zipFileName = $tempFolder . $zipFileName;

    if (!is_dir($tempFolder))
        mkdir($tempFolder);
    if (!is_dir($xmlFolder))
        mkdir($xmlFolder);

    if (is_file($zipFileName))
    {
        if ($bForce)
            unlink($zipFileName);
        else
            return; // File has already be downloaded and unziped
    }

    copy($apiEndPointURL, $zipFileName);

    // first remove all the xml files
    $files = glob($xmlFolder . "*.xml");
    foreach ($files as $file)
        unlink($file);

    $zip = new ZipArchive;
    if ($zip->open($zipFileName) === TRUE)
    {
        $zip->extractTo($xmlFolder);
        $zip->close();
    }
    else
    {
        throw new Exception("failed extracting zip");
    }
}

/**
 * Follow the redirection of a given URL in order to know the filename that is pointed by it.
 * @param  string $sourceURL The URL (of an API, eg: https://www.data.gouv.fr/fr/datasets/r/cdbc887b-265e-4338-9509-5e9958df1a48)
 * @return string            the basename of the final pointed URL (eg: decisionamm-intrant-format-xml-20181017.zip)
 */
function getZipFileName($sourceURL)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sourceURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, TRUE); // We'll parse redirect url from header.

    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE); // We want to just get redirect url but not to follow it.

    $html = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if($status_code=302 or $status_code=301)
    {
        $url = "";

        preg_match_all('/^Location:(.*)$/mi', $html, $matches);
        $url = !empty($matches[1]) ? trim($matches[1][0]) : '';

    }

    curl_close($ch);

    return basename($url);
}

function loadAMMxml()
{
    $xmlFolder =  __DIR__ . '/../temp/amm_xml/';
	$files = glob($xmlFolder . "*.xml");

    foreach ($files as $filename)
        process_xml($filename);
}

/**
 * Process one idml file
 *
 * @param type $filename
 */
function process_xml($filename)
{
    echo "Processing $filename\n";

    if (is_dir($filename))
    {
        echo "$filename is a folder, skipping\n";
        return;
    }

    $intrants = simplexml_load_file($filename);

    $productCount = 0;

    foreach ($intrants->intrants->children() as $typeProduitNode)
    {
        foreach ($typeProduitNode->children() as $productNode)
        {
            $numeroAMM = (string)$productNode->{'numero-AMM'};
            $nomProduit = (string)$productNode->{'nom-produit'};

            $etatProduit = (string)$productNode->{'etat-produit'};

            if ($etatProduit == 'RETIRE')
                continue; // we don't cover retired products

            switch($etatProduit)
            {
                case 'AUTORISE':             $etatProduit = 'A'; break;
                case 'AUTRE_CAS':            $etatProduit = 'O'; break;
                case 'INSCRIPTION_EN_COURS': $etatProduit = 'C'; break;
                case 'INSCRITE':             $etatProduit = 'I'; break;
                case 'NON_INSCRITE':         $etatProduit = 'N'; break;
                case 'RETIRE':               $etatProduit = 'R'; break;
                default:
                    echo "Erreur ! etat produit non anticipé !! $etatProduit ($numeroAMM)\n"; exit();
            }

            $typeProduit = (string)$productNode->{'type-produit'};
            if ($typeProduit == 'SUBSTANCE')
            {
                addSubstance($productNode);
                continue; // Specific management for substances
            }

            echo "$numeroAMM $nomProduit\n";

            if ((int)$numeroAMM == 0)
                continue;

            switch($typeProduit)
            {
                case 'SUBSTANCE':     $typeProduitCat = 'Substance'; break;
                case 'ADJUVANT':      $typeProduitCat = 'Adjuvant'; break;
                case 'MELANGE':       $typeProduitCat = 'Mélange'; break;
                case 'MFSC':          $typeProduitCat = 'MFSC (Matières Fertilisantes et des Supports de Culture)'; break;
                case 'PPP':           $typeProduitCat = 'PPP (Produits Phytopharmaceutiques)'; break;
                case 'PRODUIT-MIXTE': $typeProduitCat = 'Produit-Mixte'; break;
                default:
                    echo "Erreur ! Type de produit non anticipé !! $typeProduit ($numeroAMM)\n"; exit();
            }

            $nomProduit = mb_convert_case($nomProduit, MB_CASE_TITLE);

            $page = $GLOBALS['wikiBuilder']->addPage($nomProduit);
            $page->addCategory($typeProduitCat);

            $pageContent = '{{'. "$typeProduit|Name=$nomProduit|AMM=$numeroAMM" . '}}';
            $page->addContent($pageContent);

            $typeCommercial = (string)$productNode->{'type-commercial'};
            $typeCommercialRefId = (string)$productNode->{'type-commercial'}['ref-id'];

            switch($typeCommercialRefId)
            {
                case '': // Dans le cas où le type commercial n'est pas indiqué, on considère que c'est un produit de référence.
                case '20100401000000000001': $typeCommercial = 'R'; break; // Produit de référence
                case '20100401000000000002': $typeCommercial = 'S'; break; // Second nom commercial
                case '20100401000000000003': $typeCommercial = 'D'; break; // Deuxième gamme
                case '20100401000000000004': $typeCommercial = 'P'; break; // Produit de revente
                case '20100401000000000006': $typeCommercial = 'G'; break; // Générique

                default:
                    echo "Erreur ! Type commercial non anticipé !! $typeCommercial - $typeCommercialRefId ($numeroAMM)\n"; exit();
            }

            $gammeUsage = (string)$productNode->{'gamme-usage'};
            $gammeUsageRefId = (string)$productNode->{'gamme-usage'}['ref-id'];

            switch($gammeUsageRefId)
            {
                case '': // Dans le cas où la gamme n'est pas indiquée, on considère que c'est un produit pro.
                case '20140602703000000001': $gammeUsage = '1'; break; // Professionnel
                case '20140602703000000002': $gammeUsage = '0'; break; // Amateur / emploi autorisé dans les jardins

                default:
                    echo "Erreur ! Gamme usage non anticipée !! $gammeUsage - $gammeUsageRefId ($numeroAMM)\n"; exit();
            }

            $dpa = (string)$productNode->{'date-premiere-autorisation'};
            if (!empty($dpa))
                $dpa = implode('-', array_reverse(explode('/', $dpa))); // convert the date from dd/mm/yyyy to yyyy-mm-dd

            $titulaireId = addOrupdateTitulaire($productNode->{'titulaire'});

//            $sql = "INSERT INTO AMMProduct (id, status, product_type, commercial_type, name, company_id, immatriculation_date, professional_use) VALUES ('$numeroAMM', '$etatProduit', 'typeProduit', '$typeCommercial', '$nomProduit', $titulaireId, '$dpa', '$gammeUsage')
//                    ON DUPLICATE KEY UPDATE status='$etatProduit', product_type='$typeProduit', commercial_type='$typeCommercial', name='$nomProduit', company_id=$titulaireId, immatriculation_date='$dpa', professional_use='$gammeUsage'";

//            parseConditions($productNode);

            $fonctions = $productNode->{'fonctions'};
            if (!empty($fonctions))
            {
                foreach ($fonctions->{'ref'} as $itemNode)
                {
                    $caption = (string)$itemNode;
                    $page->addCategory($caption);
                }
            }


//            parseAttributes($numeroAMM, $productNode->{'mention-autorisees'}, 'Mentions');
//            parseAttributes($numeroAMM, $productNode->{'type-formulations'}, 'Formulations');

            parseUsages($productNode, $page);

            $classementDSDNode = $productNode->{'classement-DSD'};
            if ($classementDSDNode)
            {
//                parseAttributes($numeroAMM, $classementDSDNode->{'classes-danger'}, 'Dangers');
//                parseAttributes($numeroAMM, $classementDSDNode->{'phrases-risque'}, 'Risques');
            }

//            parseProduitsLies($productNode);
//            parseSubstances($productNode);

            $page->close();

            $productCount++;
        }
    }

    echo "Processed $productCount products.\n";
}

/**
 * Adds or update a titulaire (that is, a product maker), and returns its ID
 * @param [type] $refId [description]
 * @param [type] $name  [description]
 * returns the id of the titulaire
 */
function addOrupdateTitulaire($nodeTitulaire)
{
    $nomTitulaire = (string)$nodeTitulaire;
    $refId = (string)$nodeTitulaire['ref-id'];
/*
    $sql = "INSERT INTO company (ref_id, name) VALUES ('$refId', '$nomTitulaire')
            ON DUPLICATE KEY UPDATE name='$nomTitulaire'";

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }

    $sql = "SELECT id FROM company WHERE ref_id = $refId";

    if ($query = $GLOBALS['db']->query($sql))
    {
        $row = $query->fetch_assoc();
        $id = $row['id'];

        return $id;
    }
*/
    return false;
}


/**
 * Takes a product node and finds all conditions for it.
 * @param  SimpleXML $productNode ]

 */
function parseConditions($productNode)
{
    $numeroAMM = (string)$productNode->{'numero-AMM'};
/*
    $sql = "DELETE FROM usage_condition WHERE product_id='$numeroAMM'";

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }
*/
    $conditionsNode = $productNode->{'conditions-emploi-produit'};
    if (!$conditionsNode)
        return;

    foreach ($conditionsNode->{'condition-emploi-produit'} as $conditionNode)
    {
        $description = (string)$conditionNode->{'description'};
        $condition = (string)$conditionNode->{'condition-emploi-categorie'};
        $conditionRefID = (string)$conditionNode->{'condition-emploi-categorie'}['ref-id'];
/*
        $sql = "INSERT INTO usage_condition (product_id, cat_ref_id, cat_name, description)
                VALUES ('$numeroAMM', '$conditionRefID', '$condition', '$description')"; // no duplicate key possible

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }*/
    }
}

function parseAttributes($numeroAMM, $rootNode, $attributeName)
{
    /*
    $sql = "DELETE FROM ".$GLOBALS['attributes'][$attributeName]['linkTable']." WHERE ammproduct_id='$numeroAMM'";

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }
*/
    if (!$rootNode)
        return;

    foreach ($rootNode->{'ref'} as $itemNode)
    {
        $caption = (string)$itemNode;
        $refID = (string)$itemNode['ref-id'];
/*
        $sql = "INSERT INTO ".$GLOBALS['attributes'][$attributeName]['attributeTable']." (ref_id, caption) VALUES ('$refID', '$caption')
                ON DUPLICATE KEY UPDATE caption='$caption'";

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }

        $sql = "SELECT id FROM ".$GLOBALS['attributes'][$attributeName]['attributeTable']." WHERE ref_id = '$refID'";
        $id = false;

        if ($query = $GLOBALS['db']->query($sql))
        {
            $row = $query->fetch_assoc();
            $id = $row['id'];
        }

        if (!$id)
        {
            echo "Error - no item inserted - $sql";
            exit();
        }

        $sql = "INSERT IGNORE INTO ".$GLOBALS['attributes'][$attributeName]['linkTable']." (".$GLOBALS['attributes'][$attributeName]['attributeTable']."_id, ammproduct_id)
                VALUES ($id, '$numeroAMM')"; // we ignore possible duplicates within the XML

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }
*/
    }
}

function parseProduitsLies($productNode)
{
    $numeroAMM1 = (string)$productNode->{'numero-AMM'};
/*
    $sql = "DELETE FROM ammproduct_ammproduct WHERE ammproduct_source='$numeroAMM1'";

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }
*/
    $plNode = $productNode->{'produits-lies'};
    if (!$plNode)
        return;

    foreach ($plNode->{'produit-lie'} as $produitLie)
    {
        $numeroAMM2 = (string)$produitLie->produit->{'numero-AMM'};
/*
        $sql = "INSERT IGNORE INTO ammproduct_ammproduct (ammproduct_source, ammproduct_target) VALUES ('$numeroAMM1', '$numeroAMM2')"; // Ignore missing products

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }
*/
    }
}

function parseSubstances($productNode)
{
    $numeroAMM = (string)$productNode->{'numero-AMM'};
/*
    $sql = "DELETE FROM substance_quantity WHERE product_id='$numeroAMM'";

    if (!$GLOBALS['db']->query($sql))
    {
        printf("SQL Error: %s\n", $GLOBALS['db']->error);
        echo  $sql;
        exit();
    }
*/
    $compositionNode = $productNode->{'composition-integrale'};
    if (!$compositionNode)
        return;

    $substancesNode = $compositionNode->{'substances-actives'};
    if (!$substancesNode)
        return;


    foreach ($substancesNode->{'substance-active'} as $substanceActiveNode)
    {
        $caption = (string)$substanceActiveNode->{'substance'};
        $refID = (string)$substanceActiveNode->{'substance'}['ref-id'];

        $variantNode = $substanceActiveNode->{'variant'};
        if ($variantNode)
            $variant = (string)$variantNode->{'nom'};
        else
            $variant = '';
/*
        $sql = "INSERT INTO substance (ref_id, name, variant) VALUES ('$refID', '$caption', '$variant')
                ON DUPLICATE KEY UPDATE name='$caption', ref_id='$refID'";

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }

        $sql = "SELECT id FROM substance WHERE ref_id = '$refID' AND variant = '$variant'";
        $id = false;

        if ($query = $GLOBALS['db']->query($sql))
        {
            $row = $query->fetch_assoc();
            $id = $row['id'];
        }

        if (!$id)
        {
            echo "Error - no item inserted - $sql";
            exit();
        }
*/
        $teneur = (string)$substanceActiveNode->{'teneur-SA-pure'};
        $unit = (string)$substanceActiveNode->{'teneur-SA-pure'}['unite'];
/*
        $sql = "INSERT IGNORE INTO substance_quantity (product_id, substance_id, quantity, unit)
                VALUES ('$numeroAMM', $id, '$teneur', '$unit')"; // we ignore possible duplicates within the XML

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }*/
    }
}

function addSubstance($substanceNode)
{
    $caption = (string)$substanceNode->{'nom-produit'};
    $refID = (string)$substanceNode->{'identifiant'};

    foreach ($substanceNode->{'variants'} as $variantNode)
    {
        $variant = (string)$variantNode->variant->nom;
/*
        $sql = "INSERT INTO substance (ref_id, name, variant) VALUES ('$refID', '$caption', '$variant')
                ON DUPLICATE KEY UPDATE name='$caption', ref_id='$refID'";

        if (!$GLOBALS['db']->query($sql))
        {
            printf("SQL Error: %s\n", $GLOBALS['db']->error);
            echo  $sql;
            exit();
        }
*/
    }
}

function parseUsages($productNode, $page)
{
/*
    <usages>
        <usage date-decision="10/07/2014">
            <id>20151012131805369999</id>
            <identifiant-usage ref-type="identifiant-usage" ref-id="20140901000000001441" lib-court="15653202">Pomme de terre*Trt Part.Aer.*Maladies des taches brunes</identifiant-usage>
            <etat-usage ref-type="type-usage" ref-id="20100401000000000001">Autorisé</etat-usage>
            <dose-retenue unite-id="20100401000000000025" unite="L/ha">0.6</dose-retenue>
            <delai-avant-recolte-jour>21</delai-avant-recolte-jour>
            <nombre-apport-max>3</nombre-apport-max>
            <ZNT-aquatique unite-id="20100401000000000043" unite="m">5.0</ZNT-aquatique>
        </usage>
*/
    $numeroAMM = (string)$productNode->{'numero-AMM'};

    $usagesNode = $productNode->{'usages'};
    if (!$usagesNode)
        return;

    foreach ($usagesNode->{'usage'} as $usageNode)
    {
        $identifiantUsage = (string)$usageNode->{'identifiant-usage'};

        $etatUsage = (string)$usageNode->{'etat-usage'};

        if ($etatUsage != 'Autorisé')
            continue;

        $parts = explode('*', $identifiantUsage);
        if (count($parts) == 3)
        {
            $culture = trim($parts[0]);
            $ravageur = trim($parts[2]);
        }
        else if (count($parts) == 2)
        {
            $culture = trim($parts[0]);
            $ravageur = trim($parts[1]);
        }
        else
            return;

        addCulturesCategories($page, $culture);

        $doseRetenue = (string)$usageNode->{'dose-retenue'};
        $doseRetenue .= ' ' . (string)$usageNode->{'dose-retenue'}['unite'];
    }
}

/**
 * Add the cultures to the page, fixing some stuff so that the cultures look like Geco
 */
function addCulturesCategories($page, $cultureName)
{
    switch ($cultureName)
    {
        case 'Amandier': $page->addCategory('Amande'); break;
        case 'Arachide': $page->addCategory('Arachide'); break;
        case 'Avocatier': $page->addCategory('Avocat'); break;
        case 'Bananier': $page->addCategory('Banane'); break;
        case 'Betterave industrielle et fourragère': $page->addCategory('Betterave fourragère');$page->addCategory('Betterave sucrière'); break;
        case 'Betterave potagère': $page->addCategory('Betterave rouge'); break;
        case 'Blé': $page->addCategory('Blé dur'); $page->addCategory('Blé tendre'); break;
        case 'Bulbes ornementaux': $page->addCategory('Bulbes ornementaux'); break;
        case 'Cassissier': $page->addCategory('Cassis'); break;
        case 'Cerisier': $page->addCategory('Cerise'); break;
        case 'Chataignier': $page->addCategory('Châtaigne'); break;
        case 'Chicorées - Production de chicons': $page->addCategory('Chicorée'); break;
        case 'Chicorées - Production de racines': $page->addCategory('Chicorée'); break;
        case 'Choux': $page->addCategory('Chou'); break;
        case 'Choux feuillus': $page->addCategory('Chou'); break;
        case 'Choux pommés': $page->addCategory('Chou'); break;
        case 'Choux à inflorescence': $page->addCategory('Chou'); break;
        case 'Choux-raves': $page->addCategory('Chou-rave'); break;
        case 'Cultures tropicales': $page->addCategory('Cultures annuelles et pluriannuelles exclusivement tropicales'); break;
        case 'Céleri-branche': $page->addCategory('Céleri branche'); break;
        case 'Céleris': $page->addCategory('Céleri branche'); break;
        case 'Figuier': $page->addCategory('Figue'); break;
        case 'Fraisier': $page->addCategory('Fraise'); break;
        case 'Framboisier': $page->addCategory('Framboise'); break;
        case 'GAZONS DE GRAMINEES': $page->addCategory('Gazons de graminées'); break;
        case 'Goyavier': $page->addCategory('Goyave'); break;
        case 'Haricots': $page->addCategory('Haricot'); break;
        case 'Haricots et Pois non écossées frais': $page->addCategory('Haricot'); break;
        case 'Haricots et pois non écossés frais': $page->addCategory('Haricot'); break;
        case 'Haricots écossées frais': $page->addCategory('Haricot'); break;
        case 'Haricots écossés frais': $page->addCategory('Haricot'); break;
        case 'Manguier': $page->addCategory('Mangue'); break;
        case 'Maïs doux': $page->addCategory('Maïs'); break;
        case 'Noisetier': $page->addCategory('Noisette'); break;
        case 'Noyer': $page->addCategory('Noix'); break;
        case 'Olivier': $page->addCategory('Olive'); break;
        case 'Papayer': $page->addCategory('Papaye'); break;
        case 'Pois écossées frais': $page->addCategory('Pois'); break;
        case 'Pois écossés frais': $page->addCategory('Pois'); break;
        case 'Pommier': $page->addCategory('Pomme'); break;
        case 'Porte graine - Betterave industrielle et fourragère': $page->addCategory('Betterave fourragère'); $page->addCategory('Betterave sucrière'); break;
        case 'Porte graine - Mais': $page->addCategory('Maïs'); break;
        case 'Porte graine - Maïs': $page->addCategory('Maïs'); break;
        case 'Porte graine - PPAMC, Florales et Potagères': $page->addCategory('PPAMC'); break;
        case 'PPAMC': $page->addCategory('PPAMC'); break;
        case 'Prairies': $page->addCategory('Prairie'); break;
        case 'Prunier': $page->addCategory('Prune'); break;
        case 'Pêcher': $page->addCategory('Pêche'); break;
        case 'Pêcher - Abricotier': $page->addCategory('Pêche'); $page->addCategory('Abricot'); break;
        case 'Rosier': $page->addCategory('Rose'); break;
        case 'Salsifis': $page->addCategory('Salsifi'); break;
        case 'Sorgho': $page->addCategory('Sorgho grain, Sorgho ensilage'); break;

        default: $page->addCategory($cultureName);
    }
}