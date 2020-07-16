<?php

############################ Main ###########################


include_once(__DIR__ . '/../includes/wikibuilder.php');

libxml_use_internal_errors(true);

if (!is_dir(__DIR__ . "/../out"))
    mkdir(__DIR__ . "/../out");

//Out file name
$filename = __DIR__ . "/../out/wiki_agrifind.xml";

if (file_exists($filename))
    unlink($filename);

$GLOBALS['wikiBuilder'] = new wikiImportFile($filename);

$GLOBALS['pages'] = array();

//Initialize an array with the agrifind csv file
initAgrifindCSV();
//Initialize a list of articles that must be exclude
initArticlesList();

$doc = new DOMDocument();

importAgrifindToWiki();
echo "It's done !";

########################### Functions ###########################
function importAgrifindToWiki()
{
   //curlRequestAgrifind();
    foreach ($GLOBALS['agrifind'] as $page => $informations)
    {
        if ($page == 'fields')
            continue;
        
        $url = $informations[1];
        $fileName = __DIR__ . '/../temp/articles/agriFind/' . sanitizeFilename($url) . '.html';

        if (!file_exists($fileName))
            copy($url, $fileName);

        echo "Loading page: $fileName\n";

        $pageName = mb_ucfirst($page);
		if (key_exists($pageName, $GLOBALS['pages_to_exclude']))
		{
			echo "Article $pageName exclude by the list \n";
			continue;
        }
        
        $doc = new DOMDocument();
        $html = file_get_contents($fileName);
        $doc -> loadHTML($html);

        $xpath = new DOMXpath($doc);
        $articleContentNode = $xpath -> query("//article");

        preprocessing($articleContentNode[0], $xpath);
        echo "Preprocessing Done \n";

        $articleContent = getWikiTextParsoid($articleContentNode[0]);
        echo $articleContent;

    }
}





### Global array initialisation ### 
/**
 * Init an array with the content of agrifind csv
 */
function initAgrifindCSV()
{
    $file = __DIR__ . "/../temp/pages_agrifind.csv";
    if (($f = fopen($file, 'r')) !== FALSE)
    {
        $GLOBALS['agrifind']['fields'] = fgetcsv($f);
        while(($line = fgetcsv($f, 1000, ",")) !== False)
	    {
            $GLOBALS['agrifind'][$line[2]] = $line;
            unset($GLOBALS['agrifind'][$line[2]][2]);
	    }
	    fclose($f);
    }
}

### Global array initialisation ### 

/**
 * Initialize an array which contains the articles' list to exclude
 */
function initArticlesList()
{
	include(__DIR__ . '/../temp/hpwiki_page.php');
	$pages_to_exclude = array();
	foreach ($hpwiki_page as $a)
	{
		$page = mb_ucfirst(str_replace('_', ' ', reset($a)));
		$GLOBALS['pages_to_exclude'][$page] = $page;
	}
	$GLOBALS['pages_to_exclude'];
}

### General functions ###
/**
 * Uppercase on the first letter of a string
 */
function mb_ucfirst($str)
{
    $fc = mb_strtoupper(mb_substr($str, 0, 1));
    return $fc.mb_substr($str, 1);
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

### Preprocessing functions ### 
/**
 * Do some changes inside the xml file to be more easy to use with parsoid
 */
function preprocessing($xmlContent, $xpath)
{
    //Change <strong font-size 29> into title h2
    $titles = $xpath -> query(".//ul/li/strong", $xmlContent);
    changeNodeTag($titles, 'h1');

    //Delete the table of content
    $tableOfContent = $xpath -> query(".//p/a[starts-with(@href, '#')]", $xmlContent);
    removeNodes($tableOfContent);

    $tableOfContent = $xpath -> query(".//p/strong/a[starts-with(@href, '#')]", $xmlContent);
    removeNodes($tableOfContent);

    $source = $xpath -> query(".//ul/li/span[starts-with(@style, 'color: #0000ff;')]", $xmlContent);
    $startDiv = $source[0]->parentNode->parentNode;
    $startDiv->parentNode->removeChild($startDiv);

}

/**
 * Change several nodes as node title
 */
function changeNodeTag($nodes, $nodeTag)
{
    foreach($nodes as $oldTitle)
    {
        $parent = $oldTitle->parentNode;
        $title = $oldTitle->textContent;
        $newNode = new DOMElement($nodeTag, $title);
        $parent->replaceChild($newNode, $oldTitle);
    }
}

/**
 * Remove first article's row witch are the table of content (mediaWiki will do it alone)
 */
function removeNodes($nodes)
{
    //The node list can't be remove in a simple foreach loop, each node must be queue in an array
    $nodesToRemove = array();
    foreach($nodes as $articlePart)
        $nodesToRemove[] = $articlePart;

    foreach( $nodesToRemove as $node)
        $node->parentNode->removeChild($node);
}

#### Parsoid processing functions ####
/**
 * Pre-trasnform the page's content into wikitext. 
 */
function getWikiTextParsoid($node)
{
    //print_r($data);
    $data = array("html" => '<html><body>' . $node->C14N() . '</body></html>');
    $data_string = json_encode($data);
	$md5 = md5($data_string);

	if (file_exists(__DIR__ . "/../temp/apiWiki/agrifind/$md5-agrifind.parsoid"))
	{
		$result = file_get_contents(__DIR__ . "/../temp/apiWiki/agrifind/$md5-agrifind.parsoid");
	}
	else
	{																									 
		$ch = curl_init('http://localhost:8080/localhost/v3/transform/html/to/wikitext/');	
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
			'Content-Type: application/json',                                                                                
			'Content-Length: ' . strlen($data_string))                                                                       
		);    
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		$result = curl_exec($ch);
		if (curl_errno($ch)) {
            print "Error: " . curl_error($ch);
        } else {
            // Show me the result
            echo $result;
			//file_put_contents(__DIR__ . "/../temp/apiWiki/agrifind/$md5-agrifind.parsoid", $result);
        }
		curl_close($ch);
	}
	return $result;
}