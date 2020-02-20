<?php

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

$GLOBALS['emptyPages'] = array(array('Page','Type','Status de redaction','Contenu','Lien'));


countEmptyPageCsv();
$GLOBALS['unmanaged_tags'] = array();
$GLOBALS['wikiBuilder']->close();

function emptyPage($xml_loaded)
{
	$xpath = new DOMXpath($xml_loaded);	
	// More common case is the tag <div[@class='contenu-concept-non-structure-content>
	$elements = $xpath->query("//div[@class='contenu-concept-non-structure-content']");
	//This means the tag is absent, so search the other tag case.
	if(count($elements)==0)
	{
		$elements = $xpath->query("//div[@class='contenu-concept-structure']");
	}
	else 
	{
		// This means the page's content is empty or has only one image or on little sentence saying the page isn't complete.
		if (count($elements[0]->childNodes)<3)
			return true;
	}
	
	// In the case where there is more than 3 childNodes or the other tag is choosen, we search in the textContent if there is the sentence "fiche en cours de rédaction" or "A compléter".
	if (!empty($elements))
		$pageContent = $elements[0]->textContent;
	else
		$xml_loaded->save('C:/Neayi/wiki_builder/import_geco/bonk.xml');
	
	if(stristr($pageContent,"fiche en cours de rédaction") or stristr($pageContent,"A compléter"))
		return true;
	else 
		return false;
}



function countEmptyPageCsv()
{
	$total = 0;
	$pageStatusEnRedac = 0;
	$pageStatusAboutie = 0;
	$pageVide = 0;
	$pageNonVideEnRedac = 0;

	foreach ($GLOBALS['links'] as $url => $conceptName)
	{
		$total++;
		// Stock the url with https protocol for redirection to Geco
		//$url_https = substr($url,0,4)."s".substr($url,4);
		$trueUrl = preg_replace('@^http:@', 'https:', $url);
		$trueUrl = getCanonicalURL($trueUrl);

		$filename = __DIR__ . '/../temp/' . sanitizeFilename(str_replace('http://www.geco.ecophytopic.fr/geco/Concept/', '', getCanonicalURL($url))) . '.html';

		// Download each link in the temp directory as cache
		if (!file_exists($filename))
            copy($url, $filename);
            
		// Now detect the list of concepts, etc...
		// <div class="type-concept-title"> <i class="typeConcept-culture-20"></i> CULTURE </div>
		$doc = new DOMDocument();
		$html = file_get_contents($filename);

		// Inject a <meta charset="UTF-8"> node so that the parser doesn't fail at finding the right encoding:
		$html = str_replace('<head>', '<head><meta charset="UTF-8">', $html);
		$doc->loadHTML($html);

		echo "Loading page: $filename\n";
		// Call emptyPage to check if the page's content is empty.

		$content = '';
		$redacStatus='';
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
		$elements = $xpath->query("//div[@class='etat-concept etat-en_cours_de_redaction']");
		if (count($elements) == 0)
		{
			$redacStatus = "Aboutie";
			$pageStatusAboutie++;
		}
		else 
		{
			$redacStatus = "En cours de rédaction";
			$pageStatusEnRedac++;
		}
		if (emptyPage($doc))
		{
			$content = 'Vide';
			$pageVide++;
		}
		elseif($redacStatus=="Aboutie")
			$content ='Non vide';
		else
		{
			$content = 'Non vide';
			$pageNonVideEnRedac++;
		}
		$GLOBALS['emptyPages'][] = array($conceptName,$conceptType,$redacStatus,$content,$trueUrl);
	}
	$GLOBALS['emptyPages'][] = array(
		"Nombre total de page ". $total,
		"Nombre de page avec un status 'en rédaction' ".$pageStatusEnRedac,
		"Nombre de page avec un status 'aboutie' ".$pageStatusAboutie, 
		"Nombre de page sans contenu ".$pageVide,
		"Nombre de page avec un status 'en rédaction' mais non vide ".$pageNonVideEnRedac);
	writeCSV($GLOBALS['emptyPages']);
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

function writeCSV($array)
{
	$filename = __DIR__ . "/../out/inventaire_page_vide_Geco.csv";
	$f = fopen($filename,'w');
	fputs($f, "\xEF\xBB\xBF");

	foreach($GLOBALS['emptyPages'] as $line)
	{
		fputcsv($f,$line,",");
	}
	fclose($f);
	echo "Fichier terminé";
}

?>