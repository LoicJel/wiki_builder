<?php

libxml_use_internal_errors(true);

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
		$GLOBALS['links'][$url] = $link->nodeValue;
	}
}

//initNodes();
initRelationships();

function initNodes()
{
	// First, remove all nodes!
	$GLOBALS['neo4jClient']->run("MATCH (m) DELETE (m)");

	foreach ($GLOBALS['links'] as $url => $conceptName)
	{
		$filename = __DIR__ . '/../temp/' . sanitizeFilename(str_replace('http://www.geco.ecophytopic.fr/geco/Concept/', '', getCanonicalURL($url))) . '.html';

		// Download each link in the temp directory as cache
		if (!file_exists($filename))
			copy($url, $filename);

		// Now detect the list of concepts, etc...
		// <div class="type-concept-title"> <i class="typeConcept-culture-20"></i> CULTURE </div>
		$doc = new DOMDocument();
		$doc->loadHTMLFile($filename);

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

		$divnodes = $xpath->query("//div[@class='contributeur-date']");

		foreach ($divnodes as $div)
		{
			$date = trim($div->textContent);
			break;
		}

		// echo  $conceptType . "\t" . $conceptName . "\t" . $date . "\t" . $url  . "\n";

		$GLOBALS['neo4jClient']->run("CREATE (n:$conceptType { name: '".addcslashes($conceptName, "'")."', date: '$date', url: '$url' })");
	}
}

// Now rerun through all concepts and create edges
function initRelationships()
{
	// First, remove all relations!
	$GLOBALS['neo4jClient']->run("MATCH ()-[r]-() DELETE r");

	foreach ($GLOBALS['links'] as $url => $conceptName)
	{
		$filename = __DIR__ . '/../temp/' . sanitizeFilename(str_replace('http://www.geco.ecophytopic.fr/geco/Concept/', '', getCanonicalURL($url))) . '.html';

		$doc = new DOMDocument();
		$doc->loadHTMLFile($filename);

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

		$elements = $xpath->query("//div[@class='span2 liens-cell']");

		foreach ($elements as $div)
		{
			$rel = trim($div->textContent);
			$rel = str_replace('Ã©', 'é', $rel);
			$rel = str_replace('Ã ', 'à', $rel);
			$revrel = '';

			if (isset($GLOBALS['rel_labels'][$rel]))
				$rel = $GLOBALS['rel_labels'][$rel];
			else if (isset($GLOBALS['reverse_labels'][$rel]))
				$revrel = $GLOBALS['reverse_labels'][$rel];
			else
			{
				echo "relation not found\n";
				continue;
			}

			// Now go up one level, and find all relationships
			$containerDiv = $div->parentNode;
			$relationLinks = $xpath->query("div/div/div/div/div[contains(@class, 'lien-model-semantique')]/a", $containerDiv );
			// <div class="lien-model-semantique">
			//   <a href="/web/guest/concept/-/concept/voir/http%253A%252F%252Fwww%252Egeco%252Eecophytopic%252Efr%252Fgeco%252FConcept%252FGerer_Les_Populations_Des_Bioagresseurs_Grace_Aux_Mesures_Prophylactiques">Gérer les populations des bioagresseurs grâce aux mesures prophylactiques</a>
			// </div>
			//
			foreach ($relationLinks as $l)
			{
				$relurl = $l->getAttribute('href');

				if (strpos($relurl, '/concept/-/concept') === 0)
					$relurl = 'http://geco.ecophytopic.fr/web/guest' . $relurl;
				else if (strpos($relurl, '/web/guest') === 0)
					$relurl = 'http://geco.ecophytopic.fr' . $relurl;

				if (isset($GLOBALS['links'][$relurl]))
				{

					if (!empty($revrel))
					{
						$rel = $revrel;
						$aURL = $relurl;
						$bURL = $url;
					}
					else
					{
						$aURL = $url;
						$bURL = $relurl;
					}

					// create the relationship
					$GLOBALS['neo4jClient']->run("MATCH (a:$conceptType),(b)
													WHERE a.url = '".$aURL."' AND b.url = '".$bURL."'
													CREATE (a)-[r:$rel]->(b)
													RETURN type(r), r.name");
					echo getCanonicalURL($aURL) . " $rel " . getCanonicalURL($bURL) . "\n";

				}
				else
					echo "URL not found : $relurl \t" . $l->getAttribute('href') . "\t" . getCanonicalURL($relurl) . "\n";

			}

		}

	}
}


function getCanonicalURL($url)
{
	// Start from http://geco.ecophytopic.fr/web/guest/concept/-/concept/voir/http%253A%252F%252Fwww%252Egeco%252Eecophytopic%252Efr%252Fgeco%252FConcept%252FPratiquer_L_Enherbement_Total_En_Vigne

	// First remove the http://geco.ecophytopic.fr/web/guest/concept/-/concept/voir/ part
	$url = str_replace('http://geco.ecophytopic.fr/web/guest/concept/-/concept/voir/', '', $url);
	$url = urldecode(urldecode($url));

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