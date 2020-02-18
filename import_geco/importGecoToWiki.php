<?php
$GLOBALS['debug'] = false;

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

//------

// This function took an xml file in entry, and return a boolean. Return true if the page is considered empty
function emptyPage($xml_loaded)
{
	$xpath = new DOMXpath($xml_loaded);	
	$elements = $xpath->query("//div[@class='contenu-concept-non-structure-content']/*");
	foreach($elements as $element)
	{
		switch ($element->nodeName)
		{
			// Only one case observed with h1 tag.
			case 'h1':
				if (stristr($element->textContent, "en cours de rédaction"))
					return true;
			// Two cases observed with p tag.
			case 'p': 
				if (stristr($element->textContent, "en cours de rédaction"))
					return true;
				elseif (stristr($element->textContent, "A compléter"))
					return true;
		}
	}	
	return false;
}

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

		// Call emptyPage to check if the page's content is empty.
		if (emptyPage($doc))
			continue;
		
		echo "Loading page: $filename\n";

		$date = "";
		$title = "";
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
			addPage($conceptName, $xpath, $conceptType, false, $trueUrl);
		else
			addPage($conceptName, $xpath, $conceptType, true, $trueUrl);

		// echo  $conceptType . "\t" . $conceptName . "\t" . $date . "\t" . $url  . "\n";
	}
}

function addPage($pageName, $xpath, $conceptType, $bIsCategoryPage, $trueUrl)
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

	$page = $GLOBALS['wikiBuilder']->addPage($pageName);

	// Add the content
	$elements = $xpath->query("//div[starts-with(@class, 'contenu-concept')]");
	$wikiText = '';
	$imageName = '';
	$imageCaption = '';

	foreach ($elements as $contentDiv)
	{
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

				case 'sommaire-concept-structure': // sommaire
					break;
				case 'cache': // Element caché
					break;
				default:
					$wikiText .= getWikiText($node) . "\n";
					break;
			}
		}

		break;
	}

	// Try to find the caption:
	$lines = explode("\n", $wikiText);
	$wikiText = '';

	foreach ($lines as $line)
	{
		$matches = array();
		if (preg_match('@.*:([^©]+©[^[]*)@', $line, $matches))
			$imageCaption = ucfirst(trim($matches[1]));
		else if (empty($imageCaption) && preg_match('@[^:]*[pP]hoto[^:]*:(.+)@', $line, $matches))
			$imageCaption = ucfirst($line);
		else if (trim($line) != 'A compléter...')
			$wikiText .= trim($line) . "\n";
	}

	if (!empty($imageName))
		resizeImage($imageName);

	// Add a model for redirect to the originial Geco webpage.
	$page->addContent("{{ThanksGeco|url=$trueUrl}}" . "</br>");

	$page->addContent(getTemplate($conceptType, array('Nom' => $name, 'Latin' => $latinName, 'Image' => $imageName, 'ImageCaption' => $imageCaption)). "\n");

	$page->addContent($wikiText);

	$page->addContent("[[Category:Informations importé de GECO]]");

	$page->addContent("\n= Annexes =\n");

	if ($bIsCategoryPage)
	{
		$page->addContent("{{#dpl: category=$pageName }}");
	}

	$page->addCategory($conceptType);

	// Add the categories
	addCategoriesForPage($page, $xpath);

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

function addCategoriesForPage($page, $xpath)
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
	$annexes = array(
		// "aPourfils_rev not needed because in this case, a new category is created
		'aPourFils'=>array("\n=== Pour plus de précision, consulter les pages suivantes : ===\n"),
		'defavorise'=>array("\n=== Cette pratique défavorise : ===\n"),
		'defavorise_rev'=>array("\n=== La prolifération du ravageur est défavorisée par les pratiques suivantes : ===\n"),
		'favorise'=>array("\n=== Cette pratique favorise : ===\n"),
		'favorise_rev'=>array("\n=== La prolifération du ravageur est favorisé par les pratiques suivantes : ===\n"),
		'evoque' =>array("\n=== Cet exemple de mise en oeuvre évoque les techniques suivantes : ===\n"),
		'evoque_rev'=>array("\n=== Ce sujet est évoqué dans les exemples de mise en oeuvre suivant : ===\n"),
		'evoque_model'=>array(),
		'caracterise'=>array("\n=== Ce facteur de contexte caractérise les mise en oeuvre suivante : ===\n"),
		'caracterise_rev'=>array("\n=== Contexte pédo-climatique et géographique du retour d'expérience : ===\n"),
		'sAppuieSur'=>array("\n=== Cette technique s\'appuie sur les outils suivants : ===\n"),
		'sAppuieSur_rev'=>array("\n=== Cette pages s'appuit sur : ===\n"),
		'sAttaque' =>array("\n=== Ce ravageur s'attaque à : ===\n"),
		'sAttaque_rev'=>array("\n=== Ce type de culture est attaqué par : ===\n"),
		'estAppliqueA' =>array("\n=== Cette technique est appliquée aux cultures suivantes : ===\n"),
		'estAppliqueA_rev'=>array("\n=== Ce type de culture permet l'utilisation des techniques suivantes : ===\n"),
		'utiliseDans' =>array("\n=== Cette technique utilise les matériaux suivants :  ===\n"),
		'utiliseDans_rev'=>array("\n=== Ce matériel est utilisé dans les techniques suivantes : ===\n"),
		'estComplementaire'=>array("\n=== Ces pages présentent des techniques complémentaires à celle présentée précédement : ===\n"),
		'estIncompatible'=>array("\n=== Les techniques suivantes sont incompatible avec la technique décrite précédement : ===\n"),
		'contribueA'=>array("\n=== Cette technique contribue a la réussite des objectif suivants : ===\n"),
		'contribueA_rev'=>array("\n=== Cette objectif est assuré par les techniques suivantes : ===\n"),
		'informeSur'=>array("\n=== Ce guide renseigne les sujets suivants : ===\n"),
		'informeSur_rev'=>array("\n=== Ce sujet est renseigné dans les guides suivants : ===\n"),
		'influence' =>array("\n===  ===\n"),
		'influence_rev'=>array("\n===  ===\n"),
		'impacte'=>array("\n===  ===\n"),
		'impacte_rev'=>array("\n===  ===\n"),
		'regule'=>array("\n===  ===\n"),
		'regule_rev'=>array("\n===  ===\n"),
		'estUtilisePour'=>array("\n===  ===\n")
	);
	
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
					if($revrel=='')
					{
						// "evoque" categorie is a particular case because it's a special MediaWiki model.
						if($rel=='evoque')
						{
							// Select the page's type (technique, culture...)
							$catName = $xpath->query("div[contains(@class, 'span4')]",$categ);
							$name = $catName[0]->textContent;
							$name = trim($name);

							if($name =='Technique')
								$annexes[$rel][] = "*". "[[" . $GLOBALS['links'][$relurl] . "]] \n" ;
							//add the link to evoque tag, under the right page's type.
							else
								$annexes['evoque_model'][$name][] ="[[" . $GLOBALS['links'][$relurl] . "]]";
						}
						else
							$annexes[$rel][] = "*". "[[" . $GLOBALS['links'][$relurl] . "]] \n" ;
					}
					
					else
					{
						if($revrel=='aPourFils')
							//Corresponds to the parent page, so add a categorie.
							$page->addCategory($GLOBALS['links'][$relurl]);
						
						else 
							$annexes[$revrel."_rev"][] = "*". "[[" . $GLOBALS['links'][$relurl] . "]] \n" ;
					}

				}
				else
					echo "URL not found : $relurl \t" . $l->getAttribute('href') . "\t" . getCanonicalURL($relurl) . "\n";
			}
		}
	}
	// Once the $annexes array filled, write it on the wiki page
	foreach($annexes as $relation => $linkList)
	{
		print_r($linkList);
		print_r(count($linkList));
		//if the relation is "evoque_model", construct the wiki model
		if ($relation == 'evoque_model' && $linkList!=0)
		{
			$modele = "{{ConceptsEvoqués|";
			foreach($linkList as $type => $links)
			{
				switch ($type)
				{
					case "Culture":
						$modele = $modele . "culture=" . $type . "|cultureListe="; break;
					case "Bioagresseur":
						$modele = $modele . "bioagresseur=" . $type . "|listeBioagresseur="; break;
					case "Auxiliaire" :
						$modele = $modele . "auxiliaire=" . $type . "|listeAuxiliaire="; break;
					case "Matériel":
						$modele = $modele . "materiel=" . $type . "|listeMateriel="; break;
					case "Outil d'aide":
						$modele = $modele . "outilAide=" . $type . "|listeOutilAide="; break;
				}
				foreach($links as $link)
				{
					$modele = $modele . $link . ", ";
				}
				$modele = trim($modele,", ");
				$modele = $modele . "|";
			}
			$modele = trim($modele,"|");
			$modele = $modele . "}}";
			$page->addContent($modele);
		}

		// This test if there's links in the array for the other index.

		elseif(count($linkList)>1)
		{
			//Write links in paragraph on the page
			foreach($linkList as $redirect)
			{
				$page->addContent($redirect);	
			}

		}
	}
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

function initRelations()
{
	$GLOBALS['relations'] = array();
	$GLOBALS['relations']['defavorise'] = array('rel' => 'defavorise', 'label' => 'défavorise', 'reverse' => 'estDefavorisePar', 'reverse_label' => 'est défavorisé par');
	$GLOBALS['relations']['estAppliqueA'] = array('rel' => 'estAppliqueA', 'label' => 'est appliqué à', 'reverse' => 'estMobiliseDans', 'reverse_label' => 'est mobilisé dans');
	$GLOBALS['relations']['estComplementaire'] = array('rel' => 'estComplementaire', 'label' => 'est complémentaire', 'reverse' => 'estComplementaire', 'reverse_label' => '');
	$GLOBALS['relations']['estIncompatible'] = array('rel' => 'estIncompatible', 'label' => 'est incompatible', 'reverse' => 'estIncompatible', 'reverse_label' => '');
	$GLOBALS['relations']['estUtilisePour'] = array('rel' => 'estUtilisePour', 'label' => 'est utilisé pour', 'reverse' => 'utilisePour', 'reverse_label' => '');
	$GLOBALS['relations']['favorise'] = array('rel' => 'favorise', 'label' => 'favorise', 'reverse' => 'estFavorisePar', 'reverse_label' => 'est favorisé par');
	$GLOBALS['relations']['impacte'] = array('rel' => 'impacte', 'label' => 'impacte', 'reverse' => 'estImpactePar', 'reverse_label' => 'est impacté par');
	$GLOBALS['relations']['influence'] = array('rel' => 'influence', 'label' => 'influence', 'reverse' => 'estInfluencePar', 'reverse_label' => 'est influencé par');
	$GLOBALS['relations']['informeSur'] = array('rel' => 'informeSur', 'label' => 'informe sur', 'reverse' => 'estRenseignePar', 'reverse_label' => 'est renseigné par');
	$GLOBALS['relations']['regule'] = array('rel' => 'regule', 'label' => 'régule', 'reverse' => 'estRegulePar', 'reverse_label' => 'est régulé par');
	$GLOBALS['relations']['sAttaque'] = array('rel' => 'sAttaque', 'label' => "s'attaque à", 'reverse' => 'estAttaquePar', 'reverse_label' => 'est attaqué par');
	$GLOBALS['relations']['utiliseDans'] = array('rel' => 'utiliseDans', 'label' => 'utilise', 'reverse' => 'estUtiliseDans', 'reverse_label' => 'est utilisé dans');
	$GLOBALS['relations']['aPourFils'] = array('rel' => 'aPourFils', 'label' => 'a pour fils', 'reverse' => 'aPourParent', 'reverse_label' => 'a pour parent');
	$GLOBALS['relations']['caracterise'] = array('rel' => 'caracterise', 'label' => 'caractérise', 'reverse' => 'sAppliqueA', 'reverse_label' => "s'applique à");
	$GLOBALS['relations']['contribueA'] = array('rel' => 'contribueA', 'label' => 'contribue à', 'reverse' => 'estAssurePar', 'reverse_label' => 'est assuré par');
	$GLOBALS['relations']['evoque'] = array('rel' => 'evoque', 'label' => 'évoque', 'reverse' => 'estEvoqueDans', 'reverse_label' => 'est évoqué dans');
	$GLOBALS['relations']['sAppuieSur'] = array('rel' => 'sAppuieSur', 'label' => "s'appuie sur", 'reverse' => 'aideAAppliquer', 'reverse_label' => 'aide à appliquer');

	$GLOBALS['rel_labels'] = array();
	$GLOBALS['reverse_labels'] = array();
	foreach ($GLOBALS['relations'] as $k => $v)
	{
		$GLOBALS['reverse_labels'][$v['reverse_label']] = $k;
		$GLOBALS['rel_labels'][$v['label']] = $k;
	}	
}

function getWikiText($node, $context = '', $bNewParagraph = true)
{
	if (is_a($node, 'DOMNodeList'))
	{
		$text = '';
		foreach ($node as $childnode)
		{
			$text .= getWikiText($childnode, $context, $bNewParagraph);
			if ($bNewParagraph != 'always')
				$bNewParagraph = false;
		}

		return $text;
	}

	switch ($node->nodeName)
	{
		case '#text':
		case '#cdata-section':
			if ($bNewParagraph)
			{
				$cariageReturn = "\n";
				$paragraph = $node->textContent;

				if (preg_match("@^·[ ]+@", $paragraph))
					$cariageReturn = "";
				$paragraph = preg_replace("@^·[ ]+@", "* ", $paragraph);
				return $paragraph . $cariageReturn;
			}
			else
				return $node->textContent;

		case 'li':
			if ($context == 'ol')
				return "# " .trim(getWikiText($node->childNodes, '', false)). "\n";
			else
				return "* " .trim(getWikiText($node->childNodes, '', false)) . "\n";

		case 'h1': return "\n=" .getWikiText($node->childNodes, '', false) . "=\n";
		case 'h2': return "\n==" .getWikiText($node->childNodes, '', false) . "==\n";
		case 'h3': return "\n===" .getWikiText($node->childNodes, '', false) . "===\n";

		case 'b':
		case 'strong':
			return "'''" .getWikiText($node->childNodes, '', false) . "'''";

		case 'br':
			return "\n\n";
		
		// This case manages the particular case where the text is considered as paragraph title, but without the h4 tag. It's identified by the color style. 
		case 'span'	:
			// Select the attribute's node
			$style = $node->getAttribute("style");
			// Test if it corresponds to the title's color: 
			if (strpos($style, "#1AA0E0") !== false){
				return "\n====" .getwikiText($node->childNodes,'',false) . "====\n";
			}
			//if it's false, just deal with the text as usual. 
			else 
				return getWikiText($node->childNodes,'',false);

		case 'script':
		case 'style':
			return "";

		case 'ol':
		case 'ul':
			return getWikiText($node->childNodes, $node->nodeName, 'always');

		case 'p':
		case 'div':
			return getWikiText($node->childNodes);

		case 'a':
			$url = getFullUrl($node->getAttribute('href'));

			if (isset($GLOBALS['links'][$url]))
				return "[[".$GLOBALS['links'][$url]."|". getWikiText($node->childNodes, '', false)."]]";
			else
				return "[$url ". getWikiText($node->childNodes, '', false)."]";

		default:
			if (!isset($GLOBALS['unmanaged_tags'][$node->nodeName]))
				$GLOBALS['unmanaged_tags'][$node->nodeName] = 1;
			else
				$GLOBALS['unmanaged_tags'][$node->nodeName] ++;

			return getWikiText($node->childNodes, '', false);
	}

	return '------';
}

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
// This function reseize the images from Geco and saves them into the "out" folder. We used the importImages script from mediawiki to add them on the website. 
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