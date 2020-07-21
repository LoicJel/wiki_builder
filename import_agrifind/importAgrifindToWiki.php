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

        $GLOBALS['images']= array();

        if ($page == 'fields')
            continue;
        
        $url = $informations[1];
        $fileName = __DIR__ . '/../temp/articles/agriFind/' . sanitizeFilename($url) . '.html';

        if (!file_exists($fileName))
            copy($url, $fileName);

        echo "Loading page: $fileName\n";
        echo $page . "\n";

        if ($page != "Bactériose sur pois d'hiver")
            continue;

        $pageName = mb_ucfirst($page);
		if (key_exists($pageName, $GLOBALS['pages_to_exclude']))
		{
			echo "Article $pageName exclude by the list \n";
			continue;
        }
        
        $page = $GLOBALS['wikiBuilder']->addPage($pageName);
        $doc = new DOMDocument();
        $html = file_get_contents($fileName);
        $doc -> loadHTML($html);

        $xpath = new DOMXpath($doc);
        $articleContentNode = $xpath -> query("//article/div");

        preprocessing($articleContentNode[0], $xpath, $pageName);
        echo "Preprocessing Done \n";

        $articleContentParsoidBrut = getWikiTextParsoid($articleContentNode[0]);
        $articleContentParsoidClean = cleanWikiTextParsoid($articleContentParsoidBrut);
        
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
    $images = $xpath -> query("./div/img", $xmlContent);
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
        $url = urlencode($src);
        file_put_contents("$path/$img", file_get_contents($url));
        resizeImage($img);
        $GLOBALS['images'][$src] = array('src' => $img, 'caption' => $caption);
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
            file_put_contents("$path/$img", file_get_contents($src));
            resizeImage($img);
            $GLOBALS['images'][$src] = array('src' => $img, 'caption' => $caption);
        }
    }
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
			file_put_contents(__DIR__ . "/../temp/apiWiki/agrifind/$md5-agrifind.parsoid", $result);
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
    $articleContent = preg_replace('@\*[ ]*==@', '==', $articleContent);
    $articleContent = preg_replace('@==[ ]*==@', '', $articleContent);
    $articleContent = str_replace('="">', '', $articleContent);
    
    $articleContent = strip_tags($articleContent);
    
    preg_match_all("@http.*jpg|http.*png@", $articleContent, $matches);
    // print_r($GLOBALS['images']);
    // print_r($matches);
    if (!empty($matches))
        {
            foreach ($matches[0] as $urlImage)
            {
                $articleContent = str_replace($urlImage, "[[image:" . $GLOBALS['images'][$urlImage]['src'] . "|thumb|right|" . $GLOBALS['images'][$urlImage]['caption'] . "]]", $articleContent);
            }
        }
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