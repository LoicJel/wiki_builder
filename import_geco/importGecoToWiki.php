<?php

########################### Main ###########################
$GLOBALS['debug'] = false;

//ini_set('memory_limit','500M');

include_once(__DIR__ . '/../includes/wikibuilder.php');

set_time_limit(0);
libxml_use_internal_errors(true);

if (!is_dir(__DIR__ . "/../out"))
    mkdir(__DIR__ . "/../out");

$filename = __DIR__ . "/../out/wiki_practices.xml";

if (file_exists($filename))
    unlink($filename);

$GLOBALS['wikiBuilder'] = new wikiImportFile($filename);

$GLOBALS['links'] = array();

initRelations();

$indexURL = __DIR__ . '/geco_index.html';

$doc = new DOMDocument();
$doc->loadHTMLFile($indexURL);
$linknodes = $doc->getElementsByTagName('a');
foreach ($linknodes as $link)
{
	$url = $link->getAttribute('href');

	if (strpos($url, '/web/guest/concept/-/concept/') !== false)
	{
		// Debug for parsing content. It is necssary to pass to https protocol to http protocol before the construction of the array
		$url = str_replace("https","http",$url);
		$GLOBALS['links'][$url] = $link->nodeValue;
	}
}

importGecoToWiki();
$GLOBALS['unmanaged_tags'] = array();
$GLOBALS['wikiBuilder']->close();

if (!empty($GLOBALS['unmanaged_tags']))
	print_r($GLOBALS['unmanaged_tags']);


########################### Functions ###########################
function importGecoToWiki()
{
	$nbMaxTechniques = 50;
	foreach ($GLOBALS['links'] as $url => $conceptName)
	{
		// Stock the url with https protocol for redirection to Geco
		//$url_https = substr($url,0,4)."s".substr($url,4);
		$trueUrl = preg_replace('@^http:@', 'https:', $url);
		$trueUrl = getCanonicalURL($trueUrl);

		$filename = __DIR__ . '/../temp/' . sanitizeFilename(str_replace('http://www.geco.ecophytopic.fr/geco/Concept/', '', getCanonicalURL($url))) . '.html';

		// Download each link in the temp directory as cache
		if (!file_exists($filename))
			copy($url, $filename);

		if ($GLOBALS['debug'])
		{
			if (strpos($filename, "Combiner_un_maximum_de_leviers") === false) {
				continue;
			}
		}
		
		// Now detect the list of concepts, etc...
		// <div class="type-concept-title"> <i class="typeConcept-culture-20"></i> CULTURE </div>
		$doc = new DOMDocument();
		$html = file_get_contents($filename);

		// Inject a <meta charset="UTF-8"> node so that the parser doesn't fail at finding the right encoding:
		$html = str_replace('<head>', '<head><meta charset="UTF-8">', $html);
		$doc->loadHTML($html);

		echo "Loading page: $filename\n";

		//Debuging
		// if ($filename != 'C:\Neayi\tripleperformance_docker\workspace\wiki_builder\import_geco/../temp/https-geco.ecophytopic.fr-geco-concept-hanneton.html')
		// 	continue;
		

		$conceptType = '';
		$xpath = new DOMXpath($doc);
		$elements = $xpath->query("//div[@class='type-concept-title']/i");
		foreach ($elements as $i)
		{
			$conceptType = $i->getAttribute('class');
			$conceptType = str_replace('typeConcept-', '', $conceptType);
			$conceptType = str_replace('-20', '', $conceptType);
			break;
		}

		$emptyPage = emptyPage($doc);
		if (empty($conceptType))
			continue;

		/* $conceptType :
		auxiliaire
		bioagresseur
		culture
		exempleMiseEnOeuvre
		facteurEnvironnemental
		fonctionStrategieService
		materiel
		outilDAide
		technique
		*/

//		if ($nbMaxTechniques-- < 0)
//			continue;

		if ($conceptType == 'technique' ||
			$conceptType == 'exempleMiseEnOeuvre' ||
			$conceptType == 'outilDAide' ||
			$conceptType == 'materiel')
			addPage($conceptName, $xpath, $conceptType, false, $trueUrl, $emptyPage);
		else
			addPage($conceptName, $xpath, $conceptType, true, $trueUrl, $emptyPage);

		// echo  $conceptType . "\t" . $conceptName . "\t" . $date . "\t" . $url  . "\n";
	}
}

function addPage($pageName, $xpath, $conceptType, $bIsCategoryPage, $trueUrl, $emptyPage)
{
	echo "Extracting page: $pageName\n";

	echo "$conceptType $pageName\n";

	$pageName = mb_ucfirst($pageName);

	if ($bIsCategoryPage)
		addCategoryPage($pageName);

	$matchs = array();
	$name = $pageName;
	$latinName = '';
	if (preg_match('@^(.*) \(([^)]+)\)$@', $pageName, $matchs))
	{
		$name = $matchs[1];
		$latinName = $matchs[2];

		if ($latinName != "maïs" &&
			!is_numeric($latinName))
		{
			addRedirect($name, $pageName);
			addRedirect($latinName, $pageName);
		}
	}

	$date = getDateLastUpdate($xpath);
	$page = $GLOBALS['wikiBuilder']->addPage($pageName,$date);

	// Add the content
	$elements = $xpath->query("//div[starts-with(@class, 'contenu-concept')]");
	$wikiTextParsoidBrut = '';
	$wikiText = '';
	$imageName = '';
	$imageCaption = '';

	foreach ($elements as $contentDiv)
	{
		preprocessing($xpath, $contentDiv,$pageName);
		foreach ($contentDiv->childNodes as $node)
		{
			$nodeName = strtolower($node->nodeName);

			if ($nodeName == '#text')
			{
				$text = trim($node->textContent);
				if (!empty($text))
					$wikiText .= $node->textContent . "\n\n";
				continue;
			}

			$class = $node->getAttribute('class');
			$id = $node->getAttribute('id');
			if ($id == 'titreContributeurs')
				break;

			switch ($class)
			{
				case 'depiction-concept-structure': // image node
					$images = getImages($node);
					$imageName = reset($images);
					break;

				case 'cache': // Element caché
					break;

				case 'sommaire-concept-structure': // sommaire
					break;
				
				default:
					$wikiTextParsoidBrut .= getWikiTextParsoid($node) . "\n";
					break;
			}
		}
		break;
	}
	//echo $wikiTextParsoidBrut . "\n"; 
	$wikiTextParsoidClean = cleanWikiTextParsoid($wikiTextParsoidBrut);
	if (isset($wikiTextParsoidClean['imageCaption']))
		$imageCaption = $wikiTextParsoidClean['imageCaption'];
	if (isset($wikiTextParsoidClean['wikiText']))
		$wikiText = $wikiTextParsoidClean['wikiText'];
	if (!empty($imageName))
		resizeImage($imageName);

	if($emptyPage)
		{
			$wikiImage = importImageFromWiki($pageName);
			$intro = importTextFromWiki($pageName);
		}
	// Add a model for redirect to the originial Geco webpage.
	$page->addContent("{{ThanksGeco|url=$trueUrl}}" . "</br>");

	$page->addContent(getTemplate($conceptType, array('Nom' => $name, 'Latin' => $latinName, 'Image' => $imageName, 'ImageCaption' => $imageCaption)). "\n");

	// Add the table of content at a specific place

	if(isset($wikiImage))
		$page->addContent($wikiImage . "\n");
	if(isset($intro))
		$page->addContent($intro . "\n");

	$page->addContent("\n __TOC__ \n");

	$page->addContent($wikiText);

	$page->addContent("\n= Annexes =\n");

	// Add the categories
	$page->addCategory($conceptType,$date);
	addCategoriesForPage($page, $xpath,$pageName);
	$page->close();

	// Add some redirects:
	$elements = $xpath->query("//div[starts-with(@class, 'labels-alternatifs')]");
	foreach ($elements as $element)
	{
		$altNamesText = trim($element->textContent);
		$matches = array();
		if (preg_match('@.*:(.+)@', $altNamesText, $matches))
		{
			$altNames = explode(',', $matches[1]);
			foreach ($altNames as $altName)
				addRedirect(trim($altName), $pageName);
		}
	}
}

/**
 * This function create all the category and annexes for pages 
 */
function addCategoriesForPage($page, $xpath,$pageName)
{
	$elements = $xpath->query("//div[@class='type-concept-title']/i");
	foreach ($elements as $i)
	{
		$conceptType = $i->getAttribute('class');
		$conceptType = str_replace('typeConcept-', '', $conceptType);
		$conceptType = str_replace('-20', '', $conceptType);
		break;
	}
	if (empty($conceptType))
		return;

	$elements = $xpath->query("//div[@class='span2 liens-cell']");

	// Create an array to contain several redirection link to pages linked. 
	$annexes=array();
	foreach ($elements as $div)
	{
		$rel = trim($div->textContent);
		$revrel = '';
		
		if (isset($GLOBALS['rel_labels'][$rel]))
			$rel = $GLOBALS['rel_labels'][$rel];
		else if (isset($GLOBALS['reverse_labels'][$rel]))
			$revrel = $GLOBALS['reverse_labels'][$rel];
		else
		{
			echo "relation not found: $rel \n";
			continue;
		}
		// Now go up one level, and find all relationships
		$containerDiv = $div->parentNode;

		// Access to the relation's categories type
		$relation = $xpath->query("div/div/div[contains(@class, 'row-fluid')]", $containerDiv );

		foreach($relation as $categ)
		{ 
			// Select all the nodes that contain pages link
			$relationLinks = $xpath->query("div/div[contains(@class, 'lien-model-semantique')]/a",$categ);
		
			// Each link will be associate with its relation in the $annexes array.
			foreach ($relationLinks as $l)
			{
				$relurl = getFullUrl($l->getAttribute('href'));
				if (isset($GLOBALS['links'][$relurl]))
				{
					// Replca < and > chars in links
					if(preg_match('@.*(.*[<>].*)$@',$GLOBALS['links'][$relurl]))
					{
						$GLOBALS['links'][$relurl] = str_ireplace('<', 'inférieur à', $GLOBALS['links'][$relurl]);
						$GLOBALS['links'][$relurl] = str_ireplace('>', 'supérieur à', $GLOBALS['links'][$relurl]);
					}
					if($revrel=='')
					{
						switch ($rel)
						{
							case 'evoque':
								// Select the evoked page's type (technique, culture...)
								$catName = $xpath->query("div[contains(@class, 'span4')]",$categ);
								$name = $catName[0]->textContent;
								$name = trim($name);

								if($name =='Technique')
									$annexes['rel'][$rel]=1;
								//add the link to evoque tag, under the right page's type.
								else
									$annexes['evoque_model'][$name][] ="[[" . $GLOBALS['links'][$relurl] . "]]";
							break;

							case 'aPourFils':
								addCategoryPage($pageName);
								$annexes['rel'][$rel]=1;
								break;
							case 'defavorise':
							case 'favorise' : 
								$annexes['rel']['impact']=1;
								break;
							default :
								$annexes['rel'][$rel]=1;
								break;
						}
					}
					else
					{
						switch($revrel)
						{
							case 'aPourFils' :
							//Corresponds to the parent page, so add a categorie.
							$page->addCategory($GLOBALS['links'][$relurl]);
							break;
							default : 
								$annexes['revrel'][$revrel][] = "*". "[[" . $GLOBALS['links'][$relurl] . "]] \n" ;
								break;
						}
					}
				}
				else
					echo "URL not found : $relurl \t" . $l->getAttribute('href') . "\t" . getCanonicalURL($relurl) . "\n";
			}
		}
	}
	// Once the $annexes array filled, write it on the wiki page
	if(isset($annexes['rel']))
	{
		$model=("{{ListingAnnexes|");
		foreach(array_keys($annexes['rel']) as $relation)
		{
			$model .= $relation . "=1|";
		}
		$model = trim($model,"|");
		$model .= "}}";
		$page->addContent($model . "\n");
	}
	if(isset($annexes['revrel']))
	{
		foreach($annexes['revrel'] as $relation => $linkList)
		{
			$page->addContent($GLOBALS['relations'][$relation]['reverse_paragraphSentence']);
			if(count($linkList)>15)
				$page->addContent("<div style='column-count:3;-moz-column-count:3;-webkit-column-count:3'> \n");
			//Write links in paragraph on the page
			foreach($linkList as $redirect)
				{
					$page->addContent($redirect);	
				}
			if(count($linkList)>15)
				$page->addContent("</div>\n\n");
		}
	}
	
	//And write the "evoque" template at the page's end.
	if (isset($annexes['evoque_model']))
	{
		$modele = "{{ConceptsEvoqués|";
		foreach($annexes['evoque_model'] as $type => $links)
		{
			switch ($type)
			{
				case "Culture":
					$modele .= "culture=" . $type . "|"; 
					break;

				case "Bioagresseur":
					$modele .= "bioagresseur=" . $type . "|"; 
					break;

				case "Auxiliaire" :
					$modele .= "auxiliaire=" . $type . "|"; 
					break;

				case "Matériel":
					$modele .= "materiel=" . $type . "|"; 
					break;

				case "Outil d'aide":
					$modele .= "outilAide=" . $type . "|"; 
					break;	
			}
		}
		$modele = trim($modele,"|");
		$modele .= "}}";
		$page->addContent("\n" . $modele);
	}
}

/**
 * Set all the relations found in Geco
 */
function initRelations()
{
	$GLOBALS['relations'] = array();
	$GLOBALS['relations']['aPourFils'] = array('rel' => 'aPourFils', 'label' => 'a pour fils', 'reverse' => 'aPourParent', 'reverse_label' => 'a pour parent');
	$GLOBALS['relations']['estAppliqueA'] = array('rel' => 'estAppliqueA', 'label' => 'est appliqué à', 'reverse' => 'estMobiliseDans', 'reverse_label' => 'est mobilisé dans', 'reverse_paragraphSentence'=>"\n=== Ce type de culture permet l'utilisation des techniques suivantes : ===\n");
	$GLOBALS['relations']['defavorise'] = array('rel' => 'defavorise', 'label' => 'défavorise', 'reverse' => 'estDefavorisePar', 'reverse_label' => 'est défavorisé par','reverse_paragraphSentence'=>"\n=== La prolifération de l'organisme est défavorisée par les pratiques suivantes : ===\n");
	$GLOBALS['relations']['favorise'] = array('rel' => 'favorise', 'label' => 'favorise', 'reverse' => 'estFavorisePar', 'reverse_label' => 'est favorisé par', 'reverse_paragraphSentence'=>"\n=== La prolifération de l'organisme est favorisé par les pratiques suivantes : ===\n",);
	$GLOBALS['relations']['estComplementaire'] = array('rel' => 'estComplementaire', 'label' => '', 'reverse' => 'estComplementaire', 'reverse_label' => 'est complémentaire', 'reverse_paragraphSentence' => "\n=== Ces techniques sont complémentaires de la technique présentées dans cette article ===\n");
	$GLOBALS['relations']['estIncompatible'] = array('rel' => 'estComplementaire', 'label' => '', 'reverse' => 'estIncompatible', 'reverse_label' => 'est incompatible', 'reverse_paragraphSentence' => "\n=== Ces techniques sont incompatibles de la technique présentées dans cette article ===\n");
	$GLOBALS['relations']['caracterise'] = array('rel' => 'caracterise', 'label' => 'caractérise', 'reverse' => 'sAppliqueA', 'reverse_label' => "s'applique à", 'reverse_paragraphSentence'=>"\n=== Contexte pédo-climatique et géographique du retour d'expérience : ===\n");
	$GLOBALS['relations']['contribueA'] = array('rel' => 'contribueA', 'label' => 'contribue à', 'reverse' => 'estAssurePar', 'reverse_label' => 'est assuré par', 'reverse_paragraphSentence'=>"\n=== Cette objectif est assuré par les techniques suivantes : ===\n");
	$GLOBALS['relations']['evoque'] = array('rel' => 'evoque', 'label' => 'évoque', 'reverse' => 'estEvoqueDans', 'reverse_label' => 'est évoqué dans', 'reverse_paragraphSentence'=>"\n=== Ce sujet est évoqué dans les exemples de mise en oeuvre suivant : ===\n",);
	$GLOBALS['relations']['regule'] = array('rel' => 'regule', 'label' => 'régule', 'reverse' => 'estRegulePar', 'reverse_label' => 'est régulé par', 'reverse_paragraphSentence'=>"\n=== La prolifération de ce bioagresseur est régulé par les auxilaires suivants : ===\n");
	$GLOBALS['relations']['informeSur'] = array('rel' => 'informeSur', 'label' => 'informe sur', 'reverse' => 'estRenseignePar', 'reverse_label' => 'est renseigné par', 'reverse_paragraphSentence'=>"\n=== Ce sujet est renseigné dans les guides suivants : ===\n");
	$GLOBALS['relations']['sAttaque'] = array('rel' => 'sAttaque', 'label' => "s'attaque à", 'reverse' => 'estAttquePar', 'reverse_label' => 'est attaqué par', 'reverse_paragraphSentence'=>"\n=== Ce type de culture est attaqué par : ===\n");
	$GLOBALS['relations']['utilise'] = array('rel' => 'utilise', 'label' => 'utilise', 'reverse' => 'estUtilisePour', 'reverse_label' => 'est utilisé pour', 'reverse_paragraphSentence'=>"\n=== Ce matériel est utilisé dans les techniques suivantes  ===\n");
	$GLOBALS['relations']['sAppuieSur'] = array('rel' => 'sAppuieSur', 'label' => "s'appuie sur", 'reverse' => 'aideAAppliquer', 'reverse_label' => 'aide à appliquer', 'reverse_paragraphSentence'=>"\n=== Cette pages s'appuit sur : ===\n",);

/*
All unique relation found in the Geco's xml files :
a pour fils / a pour parent
est appliqué à / est mobilisé dans
défavorise / est défavorisé par
favorise / est favorisé par
est complémentaire
est incompatible
caractérise / s'applique à
contribue à / est assuré par
évoque / est évoqué dans
régule / est régulé par
informe sur / est renseigné par
s'attaque à / est attaqué par
utilise / est utilisé pour
*/

	$GLOBALS['rel_labels'] = array();
	$GLOBALS['reverse_labels'] = array();
	foreach ($GLOBALS['relations'] as $k => $v)
	{
		$GLOBALS['reverse_labels'][$v['reverse_label']] = $k;
		$GLOBALS['rel_labels'][$v['label']] = $k;
	}
	//Debug relation array creation
	// print_r($GLOBALS['rel_labels']);
	// print_r($GLOBALS['reverse_labels']);
	// exit();
}

/**
 * Creates a category page that just redirects to the main page
 */
function addCategoryPage($pageName)
{
	$page = $GLOBALS['wikiBuilder']->addPage('Category:' . $pageName);
	$page->addContent('#Redirect: [['. $pageName . ']]');
	$page->close();
}

/**
 * When a pagename has a form of "Carpocapse des prunes (Cydia funebrana)", creates two redirects to it,
 * for "Carpocapse des prunes" and "Cydia funebrana"
 */
function addRedirect($fromPage, $toPage)
{
	$page = $GLOBALS['wikiBuilder']->addPage(mb_ucfirst($fromPage));
	$page->addContent('#Redirect: [['. $toPage . ']]');
	$page->close();
	echo "Adding redirect: $toPage <-- $fromPage\n";
}

/**
 * Uppercase on the first letter of a string
 */
function mb_ucfirst($str)
{
    $fc = mb_strtoupper(mb_substr($str, 0, 1));
    return $fc.mb_substr($str, 1);
}

/**
 * Gets the wikitext for a template for the page
 */
function getTemplate($conceptType, $fields)
{
	/*
	{{bioagresseur
	|Nom=Carpocapse des pommes et des poires
	|Latin=Cydia pomonella
	|Image=image_carpocapse_des_pommes_et_des_poires__Cydia_pomonella_.jpg
	|ImageCaption=Adulte du carpocapse des pommes et des poires - © INRA}}
	*/
	$lines = array();
	foreach ($fields as $k => $v)
	{
		if (!empty($v))
			$lines[] = "|$k=$v";
	}

	return '{{' . $conceptType . "\n" . implode("\n", $lines) . '}}';
}

/**
 * This function parse the date's last update article in Geco
 */
function getDateLastUpdate($xpath)
{
	// Get the last date update :
	$boldElements = $xpath->query("//b");
	foreach ($boldElements as $aPotentialDate)
	{
		$matches = array();
		if (preg_match('@([0-9]{2})/([0-9]{2})/([0-9]{4})@', $aPotentialDate->textContent, $matches))
			$date = date_create_from_format('d/m/Y',$matches[0]);
	}
	return $date;
}

#### Url processing functions ####
/**
 * This function do several test in order to determine if the page have content or not
 */
function emptyPage($xml_loaded)
{
	$xpath = new DOMXpath($xml_loaded);	
	// More common case is the tag <div[@class='contenu-concept-non-structure-content>
	$elements = $xpath->query("//div[@class='contenu-concept-non-structure-content']");
	//This means the tag is absent, so search the other tag case.
	if(count($elements)==0)
		$elements = $xpath->query("//div[@class='contenu-concept-structure']");
	else 
	{
		// This means the page's content is empty or has only one image or on little sentence saying the page isn't complete.
        if (count($elements[0]->childNodes)<3)
        {
			return true;
        }
	}
	// In the case where there is more than 3 childNodes or the other tag is choosen, we search in the textContent if there is the sentence "fiche en cours de rédaction" or "A compléter".
 	$pageContent = $elements[0]->textContent;
	if(stristr($pageContent,"fiche en cours de rédaction") or stristr($pageContent,"A compléter"))
		return true;
	else 
		return false;
}

/**
 * This function import content from wikipedia for pages that doesn't have content in geco
 */
function importTextFromWiki($pageName)
{
	$source = 'https://fr.wikipedia.org/wiki/';
	$endPoint = "https://fr.wikipedia.org/w/api.php";
	$params = [
		"action" => "query",
		"format" => "json",
		"titles" => "$pageName",
		"prop" => "extracts",
		"explaintext" => true,
		"exintro" => true,
		"exsectionformat" => "wiki",
		"redirects" => true
	];

	$url = $endPoint . "?" . http_build_query( $params );
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	$output = curl_exec( $ch );
	if($output)
	{
		$result = json_decode( $output, true );
		if (!isset($result['query']['pages']['-1']['missing']))
		{
			foreach($result['query']['pages'] as $page=>$id)
			$text = "";
			$text .= $id['extract'];
			$text .= "{{extractedFromWikipedia|page=$pageName}}";
			return $text;
		}
		else 
			echo "The page $pageName doesn't exist in wikipedia \n";
	}
	curl_close( $ch );
}

/**
 * This function import images from wikipedia for pages that doesn't have content in geco
 */
function importImageFromWiki($pageName)
{
	$source = 'https://fr.wikipedia.org/wiki/';
	$endPoint = "https://fr.wikipedia.org/w/api.php";
	$params = [
		"action" => "query",
		"format" => "json",
		"prop" => "pageimages",
		"titles" => "$pageName",
		"piprop" => "name",
		"redirects" => true
	];

	$url = $endPoint . "?" . http_build_query( $params );
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	$output = curl_exec( $ch );
	if($output)
	{
		$result = json_decode( $output, true );
		if (!isset($result['query']['pages']['-1']['missing']))
		{
			foreach($result['query']['pages'] as $page=>$image)
			return '[[file:' . $image['pageimage'] . '|thumb|' . $image['title'] . ']]';
		}
		else 
			echo "The page $pageName doesn't exist in wikipedia \n";
	}
	curl_close( $ch );
}

#### Url processing functions ####
function getFullUrl($url)
{
	$url = str_replace('http://geco.ecophytopic.fr/web/guest', '', $url);
	$url = str_replace('http://geco.ecophytopic.fr', '', $url);

	if (strpos($url, '/concept/-/concept') === 0)
		return 'http://geco.ecophytopic.fr/web/guest' . $url;
	else if (strpos($url, '/web/guest') === 0)
		return 'http://geco.ecophytopic.fr' . $url;
	else if (strpos($url, '/') === 0)
		return 'http://geco.ecophytopic.fr' . $url;

	return $url;
}

function getCanonicalURL($url)
{
	// Start from http://geco.ecophytopic.fr/web/guest/concept/-/concept/voir/http%253A%252F%252Fwww%252Egeco%252Eecophytopic%252Efr%252Fgeco%252FConcept%252FPratiquer_L_Enherbement_Total_En_Vigne

	// First remove the http://geco.ecophytopic.fr/web/guest/concept/-/concept/voir/ part
	$url = preg_replace('@http[s]*://geco.ecophytopic.fr/web/guest/concept/-/concept/voir/@', '', $url);
	$url = urldecode(urldecode($url));

	$url = str_replace('http:', 'https:', $url);
	$url = str_replace('www.geco', 'geco', $url);

	return $url;
}

function sanitizeFilename($str = '')
{
    $str = strip_tags($str);
    $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
    $str = preg_replace('/[\"\*\/\:\<\>\?\'\|]+/', ' ', $str);
    $str = strtolower($str);
    $str = html_entity_decode( $str, ENT_QUOTES, "utf-8" );
    $str = htmlentities($str, ENT_QUOTES, "utf-8");
    $str = preg_replace("/(&)([a-z])([a-z]+;)/i", '$2', $str);
    $str = str_replace(' ', '-', $str);
    $str = rawurlencode($str);
    $str = str_replace('%', '-', $str);
    return $str;
}


#### Images processing functions ####
/**
 * Returns an array of filenames for all the img tags found within the node
 */
function getImages($node)
{
	if (is_a($node, 'DOMNodeList'))
	{
		$ret = array();
		foreach ($node as $childnode)
			$ret = array_merge($ret, getImages($childnode));

		return $ret;
	}

	switch ($node->nodeName)
	{
		case '#text':
		case '#cdata-section':
			return array();

		case 'img':
			$imageName = basename($node->getAttribute('src'));

			// Remove the part after the '?'
			$imageName = mb_substr($imageName, 0, strpos($imageName, '?'), 'UTF-8');

			return array($imageName);

		default:
			return getImages($node->childNodes);
	}

	return array();
}

/**
 * This function reseize the images from Geco and saves them into the "out" folder. We used the importImages script from mediawiki to add them on the website. 
*/ 
function resizeImage(&$imageName)
{
	$srcImageFilePath = __DIR__ . '/geco_index_files/'. $imageName;
	$path_parts = pathinfo($srcImageFilePath);
	$srcExt = $path_parts['extension'];
 

	$imageName = str_replace('.' . $srcExt, '.jpg', $imageName);

	$destImageFilePath = __DIR__ . '/../out/images/'. $imageName;

	if (file_exists($destImageFilePath))
		return;

	$finfo = new finfo(FILEINFO_EXTENSION);
	$realSrcExt = $finfo->file($srcImageFilePath);
	
	try
	{
		switch ($realSrcExt)
		{
			case 'bmp':
				$srcimage = imagecreatefrombmp($srcImageFilePath);
				break;

			case 'gif':
				$srcimage = imagecreatefromgif($srcImageFilePath);
				break;

			case 'jpeg/jpg/jpe/jfif':
			case 'jpeg':
			case 'jpg':
				$srcimage = imagecreatefromjpeg($srcImageFilePath);
				break;

			case 'png':
				$srcimage = imagecreatefrompng($srcImageFilePath);
				break;

			case 'wbmp':
				$srcimage = imagecreatefromwbmp($srcImageFilePath);
				break;

			case 'webp':
				$srcimage = imagecreatefromwebp($srcImageFilePath);
				break;

			case 'xbm':
				$srcimage = imagecreatefromxbm($srcImageFilePath);
				break;

			case 'xpm':
				$srcimage = imagecreatefromxpm($srcImageFilePath);
				break;

			default:
				// Ignore this image!
				$imageName = '';
				return;
		}

		if (empty($srcimage))
		{
			$imageName = '';
			return;
		}

		// Calcul des nouvelles dimensions
		list($orig_width, $orig_height) = getimagesize($srcImageFilePath);

		$width = $orig_width;
		$height = $orig_height;

		$max_height = $max_width = 800;

		# taller
		if ($height > $max_height) {
			$width = ($max_height / $height) * $width;
			$height = $max_height;
		}

		# wider
		if ($width > $max_width) {
			$height = ($max_width / $width) * $height;
			$width = $max_width;
		}

		// Chargement
		$destImage = imagecreatetruecolor($width, $height);

		// Redimensionnement
		imagecopyresized($destImage, $srcimage, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
		imagejpeg($destImage, $destImageFilePath);

		// Libération de la mémoire
		imagedestroy($destImage);
		imagedestroy($srcimage);
	}
	catch (\Throwable $th)
	{
		echo "Failed to save image: $srcImageFilePath \n";
		echo $th->getMessage() . "\n";
		$imageName = '';
	}
}


#### Parsoid processing functions ####
/**
 * Pre-trasnform the page's content into wikitext. 
 */
function getWikiTextParsoid($node)
{
	$data = array("html" => '<html><body>' . $node->C14N() . '</body></html>');
	$data_string = json_encode($data);                                                                                   
																														 
	$ch = curl_init('http://localhost:8080/localhost/v3/transform/html/to/wikitext/');                                                                      
	
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
		'Content-Type: application/json',                                                                                
		'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   																										 
	$result = curl_exec($ch);
	return $result;
}

/**
 * Clean the text from parsoid parsing.
 */
function cleanWikiTextParsoid($text)
{
	$text = preg_replace('@<span style="color:#1AA0E0;"><strong>@', "===", $text);
	$text = preg_replace('@</strong></span>@', "=== \n", $text);
	$text = preg_replace('@<strong>@', "'''", $text);
	$text = preg_replace('@</strong>@', "'''", $text);
	$text = strip_tags($text, '<br>');
	$text = preg_replace('@^.*@', '', $text);
	$text = preg_replace('@function proposerEnrichissement.*;$@', '', $text);
	$text = preg_replace('@[fF]iche en cours de rédaction[,\s\.]*@', '', $text);
	$text = preg_replace('@pour plus d\'information@', "\n Pour plus d\'information", $text);
	$text = preg_replace('@-{4}@', '', $text);
	$text = preg_replace('@•@', '*', $text);
	$text = preg_replace("@== ''''@", '==', $text);
	$text = preg_replace('@[nN]ull@', '', $text);
	$text = preg_replace('@[dD]ate.*:.*[0-9]@', '', $text);
	$text = str_replace('Contributeurs initiaux :', '', $text);
	$text = trim($text);
	$lines = explode("\n",$text);
	return findCaption($lines);
}

/**
 * Try to find if there's caption for images in the wikitext
 */
function findCaption($lines)
{
	$wikiText='';
	$results = array();
	foreach ($lines as $line)
	{
		//echo $line . "\n";
		$matches = array();
		// Test the differents cases for data source quotation in geco :
		// search symbol © for copyright
		if (preg_match('@.*:([^©]+©[^[]*)@', $line, $matches))
			$results['imageCaption'] = ucfirst(trim($matches[1]));
		// Search the word "photo"
		else if (empty($imageCaption) && preg_match('@[^:]*[pP]hoto[^:]*:(.+)@', $line, $matches))
			$results['imageCaption'] = ucfirst(trim($matches[1]));
		// Search the sentence "image en entête"
		else if (empty($imageCaption) && mb_ereg_match("[iI]mage en en\-?tête ;", trim($line,'()')))
		{
			$line = trim($line,'()');
			$line = mb_ereg_replace('image en en\-?tête ;', '', $line);
			$results['imageCaption'] = ucfirst(trim($line));
		}
		// Search the word "image"
		else if (empty($imageCaption) && preg_match('@[iI]mage:(.*)@',  $line, $matches))
			$results['imageCaption'] = ucfirst(trim($matches[1]));
		// If any imageCaption is identified, add the line to the wiki content page
		else if (trim($line) != 'A compléter...')
		{
			$line = trim($line, "\t\n\r\0\x0B\xC2\xA0");
			if (preg_match("@^'''@",$line) && preg_match("@'''$@", $line))
			{
				$line = preg_replace("@^'+@", '====', $line);
				$line = preg_replace("@'+$@", '====', $line);
			}
			if (preg_match("@^=+ ''''@",$line))
			{
				$line = preg_replace("@^=+ ''''@", '==', $line);
			}
			$wikiText .= trim($line, "\t\n\r\0\x0B\xC2\xA0") . "\n";
		}
	}
	$results['wikiText'] = $wikiText;
	return $results;
}


#### XML preprocessing functions ####
/**
 * Call several functions to replace or delete problematics nodes in the xml.
 */
function preprocessing($xpath, $contentDiv, $pageName)
{
	// Remove smiley tags
	$smileys = $xpath->query("i[starts-with(@class, 'smiley')]",$contentDiv);
	foreach ($smileys as $smiley)
	{
		replaceSmiley($smiley);
	}

	// Remove tables tags
	$tables = $contentDiv->getElementsByTagName('table');
	removeTables($tables);


	// Change the image url which are integrated as string in the wikitext.
	$imagesIntegrated = $xpath->query("//img[starts-with(@src, 'data')]", $contentDiv);
	imageIntext($imagesIntegrated, $pageName);

	// Change geco url in the page content into Internal link.
	$urlNodeInText = $xpath->query("///a[starts-with(@href, 'https://geco')]", $contentDiv);
	gecoUrlInText($urlNodeInText);
}

/**
 * Remove tables from the xml document
 */
function removeTables($tables)
{
	$nb = $tables->length;
	for ($i = $nb - 1; $i >= 0; $i--)
	{
		$table = $tables->item($i);
		$parent = $table->parentNode;
		$parent->removeChild($table);
	}

}

/**
 * Replace smiley tags on geco xml by an image tag.
 */
function replaceSmiley($smiley)
{
	$parent = $smiley->parentNode;
	$smileyType = $smiley->getAttribute('class');
	$smileyImage = "";
	switch ($smileyType)
	{
		// Smiley with down face
		case 'smiley-diminution-inverse-20':
		case 'smiley-augmentation-20':
			$smileyImage = "smiley_downface.png";
			break;
		
		// Smiley with up face
		case 'smiley-augmentation-inverse-20':
		case 'smiley-diminution-20':
		case 'smiley-facilement-20':
			$smileyImage = "smiley_upface.png";
			break;
		
		// Smiley with neutral face
		case 'smiley-delicate-20':
		case 'smiley-neutre-20':
		case 'smiley-variable-20':
			$smileyImage = "smiley_neutralface.png";
			break;	
	}
	// Some case are just undetermined, so just remove them
	if (empty($smileyImage))
		$parent->removeChild($smiley);
	else
	{
		// Replace the <i> node by and <img> node
		$newNode = new DOMElement('img');
		$parent->replaceChild($newNode,$smiley);
		$newNode->setAttribute('src', $smileyImage);
	}
}

/**
 * Change all the <img> node in the xml, because of parsoid interpretation. 
 * This function make the nodes clearer.
 */
function imageInText($imagesIntegrated, $pageName)
{
	// Images doesn't have alternative display or title, i choosen to give then a number associted with the pageName to save it.
	$i = 0;
	foreach($imagesIntegrated as $image)
	{
		// Get the base64 code
		$image_string = $image->getAttribute('src');
		// Clean the begining
		// Obviously, they're is two type of possible pictures extension.
		$image_string = str_ireplace("data:image/png;base64,", '', $image_string);
		$image_string = str_ireplace("data:image/jpeg;base64,", '', $image_string);
		$image_string = base64_decode($image_string);
		$img = imagecreatefromstring($image_string);

		if ($img)
		{
			$pageName = str_replace(' ', '_', $pageName);
			//Create an unique name for save the image
			$md5Name = md5("$pageName-$i").".png";
			// Save the image
			imagepng($img, "C:\Neayi\\tripleperformance_docker\workspace\wiki_builder\import_geco\geco_index_files\\$md5Name");
			// Release image memory 
			imagedestroy($img);
			// Resize the image
			resizeImage($md5Name);
			// Set the new img attribute
			$image->setAttribute('src', "$md5Name");
		}
		else 
			echo 'An error occured \n';
		$i++;
	}
}

/**
 * Change Geco interanl link into internal wiki link
 */
function gecoUrlInText($nodeList)
{
	foreach($nodeList as $link)
	{
		$relurl = str_replace('https:', 'http:', $link->getAttribute('href'));
		if (isset($GLOBALS['links'][$relurl]))
		{
			$nodeValue = $link->nodeValue;
			$link->nodeValue = '[[' . $GLOBALS['links'][$relurl] . "|" . $nodeValue . "]]";
			$link->removeAttribute('href');
		}
		else if ($relurl !== 'http://geco.ecophytopic.fr/mentions-legales')
		{
			echo "internal link not found : $relurl \n";
		}
	}
}







