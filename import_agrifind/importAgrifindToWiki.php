<?php

############################ Main ###########################


include_once(__DIR__ . '/../includes/wikibuilder.php');

libxml_use_internal_errors(true);

if (!is_dir(__DIR__ . "/../out"))
    mkdir(__DIR__ . "/../out");

//Initialize an array with the agrifind csv file
initAgrifindCSV();
//Initialize a list of articles that must be exclude
initArticlesList();

foreach($GLOBALS['agrifind'] as $concept => $articles)
{
    //Out file name
    $filename = __DIR__ . "/../out/wiki_agrifind_$concept.xml";

    if (file_exists($filename))
        unlink($filename);

    $GLOBALS['wikiBuilder'] = new wikiImportFile($filename);
    importAgrifindToWiki($articles);
}
echo "It's done !";

########################### Functions ###########################
function importAgrifindToWiki($articles)
{
   //curlRequestAgrifind();
    foreach ($articles as $page => $informations)
    {
        if ($page != "Orge")
            continue;

        $GLOBALS['images']= array();

        //print_r($informations);
        
    
        $url = $informations[1];
        $fileName = __DIR__ . '/../temp/articles/agriFind/' . sanitizeFilename($url) . '.html';

        if (!file_exists($fileName))
            copy($url, $fileName);

        echo "Loading page: $fileName\n";
        echo $page . "\n";



        $pageName = mb_ucfirst($page);
		if (key_exists($pageName, $GLOBALS['pages_to_exclude']))
		{
			echo "Article $pageName exclude by the list \n";
			continue;
        }
        
        $page = $GLOBALS['wikiBuilder']->addPage($pageName);
        $doc = new DOMDocument();
        $html = file_get_contents($fileName);
        $html = str_replace('<head>', '<head><meta charset="UTF-8">', $html);
        $doc -> loadHTML($html);
        $url_agrifind = $informations[1];

        $xpath = new DOMXpath($doc);
        $articleContentNode = $xpath -> query("//article/div");

        preprocessing($articleContentNode[0], $xpath, $pageName);
        echo "Preprocessing Done \n";

        $articleContentParsoidBrut = getWikiTextParsoid($articleContentNode[0], $pageName);
        $articleContentParsoidClean = cleanWikiTextParsoid($articleContentParsoidBrut);
        
        $concept = $informations[0];
        $name = $informations[3];
        if ($informations[4] != "")
        {
            $templateParameterValue = $informations[3];
            $templateParameter = "Culture";
        }
        else
        {
            $templateParameterValue = "";
            $templateParameter = "Latin";
        }
    
        $subcategory = $informations[4];

        $page->addContent("{{Article issu d'agriFind|url=$url_agrifind}}" . "\n");
        $conceptTemplate = getTemplate($informations[0], array("Nom" => $pageName, $templateParameter => $templateParameterValue, 'Sous-categorie' => $subcategory, 'Image' => "", 'ImageCaption' => "")) . "\n";
        $page->addContent($conceptTemplate . "}}");

        $page->addContent($articleContentParsoidClean);
        $page->close();
    }
}


### Global array initialisation ### 
/**
 * Init an array with the content of agrifind csv
 */
function initAgrifindCSV()
{
    $agrifind = array();
    $file = __DIR__ . "/../temp/pages_agrifind.csv";
    if (($f = fopen($file, 'r')) !== FALSE)
    {
        $agrifind['agrifind']['fields'] = fgetcsv($f);
        while(($line = fgetcsv($f, 1000, ",")) !== False)
	    {
            $agrifind['agrifind'][$line[2]] = $line;
            unset($agrifind['agrifind'][$line[2]][2]);
	    }
	    fclose($f);
    }
    unset($agrifind['agrifind']['fields']);

    $GLOBALS["url-page"] = array();
    $GLOBALS['agrifind']['bioagresseur'] = array();
    $GLOBALS['agrifind']['culture'] = array();
    $GLOBALS['agrifind']['pratique agricole'] = array();
    $GLOBALS['agrifind']['auxiliaire'] = array();
    foreach($agrifind['agrifind'] as $page => $informations)
    {
        $GLOBALS["url-page"][$informations[1]] = $page;
        
        if($informations[0] != "Bioagresseur")
            $informations[0] = preg_replace("@[0-9] - @", '', $informations[0]);

        switch($informations[0])
        {
            case "Bioagresseur":
                $GLOBALS['agrifind']['bioagresseur'][$page] = $informations;
                break;
            case "Cultures":
                $GLOBALS['agrifind']['culture'][$page] = $informations;
                break;
            case "Pratiques agronomiques":
                $GLOBALS['agrifind']['pratique agricole'][$page] = $informations;
                break;
            case "Auxiliaire":
                $GLOBALS['agrifind']['auxiliaire'][$page] = $informations;
                break;
        }
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


/**
 * Gets the wikitext for a template for the page
 */
function getTemplate($conceptType, $fields)
{
	/*
	{{bioagresseur
	|Nom=Carpocapse des pommes et des poires
	|Latin=Cydia pomonella
	|Sous-category= 
	|Image=image_carpocapse_des_pommes_et_des_poires__Cydia_pomonella_.jpg
	|ImageCaption=Adulte du carpocapse des pommes et des poires - © INRA}}
	*/
	$lines = array();
	foreach ($fields as $k => $v)
	{
		$lines[] = "|$k=$v";
	}

	return '{{' . $conceptType . "\n" . implode("\n", $lines);
}




### Preprocessing functions ### 
/**
 * Do some changes inside the xml file to be more easy to use with parsoid
 */
function preprocessing($xmlContent, $xpath, $pageName)
{
    //Change <strong font-size 29> into title h2
    $titles = $xpath -> query(".//ul/li/strong", $xmlContent);
    if (count($titles)>0)
    {
        changeNodeTag($titles, 'h2');
    }

    //Delete the table of content
    $tableOfContent = $xpath -> query(".//p/a[starts-with(@href, '#')]", $xmlContent);
    if (count($tableOfContent)>0)
    {
        removeNodes($tableOfContent);
    }

    $tableOfContent = $xpath -> query(".//p/strong/a[starts-with(@href, '#')]", $xmlContent);
    if (count($tableOfContent)>0)
    {
        removeNodes($tableOfContent);
    }

    $source = $xpath -> query(".//ul/li/span[starts-with(@style, 'color: #0000ff;')]", $xmlContent);
    if (count($source)>0)
    {
        $startDiv = $source[0]->parentNode->parentNode;
        $startDiv->parentNode->removeChild($startDiv);
    }

    $breakNode = $xpath -> query(".//br", $xmlContent);
    if (count($breakNode)>0)
    {
        doubleBreakTag($breakNode);
    }
    

    $figures = $xpath -> query("./figure", $xmlContent);
    if (count($figures)>0)
    {
        saveFigures($figures);
    }
    $figures = $xpath -> query(".//figure", $xmlContent);
    if (count($figures)>0)
    {
        saveFigures($figures);
    }

    $images = $xpath -> query("./p/img", $xmlContent);
    if (count($images)>0)
    {
        saveImage($images);
    }
    $images = $xpath -> query("./p/a/img", $xmlContent);
    if (count($images)>0)
    {
        saveImage($images);
    }
    $images = $xpath -> query("./div/p/a/img", $xmlContent);
    if (count($images)>0)
    {
        saveImage($images);
    }
    $images = $xpath -> query("./div/img", $xmlContent);
    if (count($images)>0)
    {
        saveImage($images);
    }
    $images = $xpath -> query("./div/div/img", $xmlContent);
    if (count($images)>0)
    {
        saveImage($images);
    }
    $images = $xpath -> query("./p/strong/img", $xmlContent);
    if (count($images)>0)
    {
        saveImage($images);
    }
}

/**
 * Change several nodes as node title
 */
function changeNodeTag($nodes, $nodeTag)
{
    foreach($nodes as $oldTitle)
    {
        $title = $oldTitle->textContent;
        $newNode = new DOMElement($nodeTag, $title);  
        $oldTitle->parentNode->replaceChild($newNode, $oldTitle);
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
    {
        $pNode = $node->parentNode;
        $pNode->parentNode->removeChild($pNode);
    }
}

function doubleBreakTag($nodes)
{
    $newNode = new DOMElement('br');
    foreach($nodes as $breakNode)
    {
        $newNode = new DOMElement('p');
        $breakNode->parentNode->replaceChild($newNode, $breakNode);
    }
}

function saveImage($nodes)
{
    $path = __DIR__ . '/agrifind_index_files';
    foreach($nodes as $image)
    {
        $src = $image->getAttribute('src');
        $caption = "";
        $img = basename($src);
        $img = preg_replace("@\.jpg.[0-9]*x[0-9]*\.png@", ".jpg", $img);
        $src_parsoid = preg_replace("@\.jpg.{0,3}[0-9]*[x\-][0-9]*\.png@", ".jpg", $src);
        // echo $src . "\n";
        // echo $src_parsoid . "\n";
        $GLOBALS['images'][$src_parsoid] = array('src' => $img, 'caption' => $caption);
        $src = dirname($src) . '/' . urlencode($img);
        file_put_contents("$path/$img", file_get_contents($src));
        resizeImage($img);
        
    }
}


function saveFigures($nodes)
{
    $path = __DIR__ . '/agrifind_index_files';
    foreach($nodes as $figureTag)
    {
        $imageTag = $figureTag->getElementsByTagName('img');
        $captionTag = $figureTag->getElementsByTagName('figcaption');
        if(count($captionTag)>0)
            $caption = $captionTag[0]->textContent;
        else 
            $caption = '';

        foreach($imageTag as $image)
        {
            $src = $image->getAttribute('src');
            $img = basename($src);
            $img = preg_replace("@\.jpg.[0-9]*x[0-9]*\.png@", ".jpg", $img);
            $src_parsoid = preg_replace("@\.jpg.[0-9]*x[0-9]*\.png@", ".jpg", $src);
            $GLOBALS['images'][$src_parsoid] = array('src' => $img, 'caption' => $caption);
            $src = dirname($src) . '/' . urlencode($img);
            copy($src, "$path/$img");
            resizeImage($img);        
        }
    }
}
#### Parsoid processing functions ####
/**
 * Pre-trasnform the page's content into wikitext. 
 */
function getWikiTextParsoid($node, $pageName)
{
    //print_r($data);
    $path = __DIR__ . "/../temp/apiWiki/agrifind";
    $fileName = "$pageName-agrifind.parsoid";
    $data = array("html" => '<html><body>' . $node->C14N() . '</body></html>');
    $data_string = json_encode($data);
	

	if (file_exists("$path/$fileName"))
	{
		$result = file_get_contents("$path/$fileName");
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
			file_put_contents("$path/$fileName", $result);
        }
		curl_close($ch);
	}
	return $result;
}



function cleanWikiTextParsoid($articleContent)
{
    //echo $articleContent . "\n";
    $matches = array();
    $text = $articleContent;
    $articleContent = preg_replace('@<strong>@', "'''", $articleContent);
    $articleContent = preg_replace('@\</strong\>@', "'''", $articleContent);
    $articleContent = preg_replace("@'''[ ]*==@", " ==", $articleContent);
    $articleContent = preg_replace('@\*[ ]*==@', '==', $articleContent);
    $articleContent = preg_replace('@==[ ]*==@', '', $articleContent);
    $articleContent = str_replace('="">', '', $articleContent);
    $articleContent = str_replace('.jpg https', ".jpg\nhttps", $articleContent);
    $articleContent = str_replace('.jpg', ".jpg \n", $articleContent);
    $articleContent = strip_tags($articleContent);
    
    
    preg_match_all("@https://www.agrifind.fr/alertes/wp-content/uploads.*jpg|https://www.agrifind.fr/alertes/wp-content/uploads.*png@", $articleContent, $matches);
    // print_r($GLOBALS['images']);
    // print_r($matches);
    if (!empty($matches))
        {
            foreach ($matches[0] as $urlImage)
            {
                $articleContent = str_replace($urlImage, "[[image:" . $GLOBALS['images'][$urlImage]['src'] . "|thumb|right|" . $GLOBALS['images'][$urlImage]['caption'] . "]]", $articleContent);
            }
        }

    $matches = array();
    preg_match_all("@\[https://www.agrifind.fr/alertes/.*/ .*]@", $articleContent, $matches);
    if(!empty($matches))
    {
        foreach ($matches[0] as $url)
        {
            $internalLink = trim($url, "[]");
            $internalLink = preg_replace('@/ .*@', '', $internalLink);
            $internalLink = '[[' . $GLOBALS['url-page'][$internalLink] . ']]';
            $articleContent = str_replace($url, $internalLink, $articleContent);
        }
    }

    //echo $articleContent;
    return $articleContent;
}

/**
 * This function reseize the images from Geco and saves them into the "out" folder. We used the importImages script from mediawiki to add them on the website. 
*/ 
function resizeImage(&$imageName)
{
	$srcImageFilePath = __DIR__ . '/agrifind_index_files/'. $imageName;
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