<?php


class wikiImportFile
{
    private $filename = '';
    private $categories = array();
    private $pages = array();
    private $bOpen = false;

    private $rootfilename = '';
    private $filecount = 1;

    const max_file_size = 800000;

    function __construct(string $filename)
    {
        if (file_exists($filename))
            throw new Exception("Can't create wiki file, file exists already: $filename", 1);

        $this->filename = $filename;
        $this->categories = array();
        $this->pages = array();
        $this->bOpen = true;

        $path_parts = pathinfo($filename);
        $this->rootfilename = $path_parts['dirname'] . '/' . $path_parts['filename'] . '_';

        $this->initWiki();
    }

    function __destruct()
    {
        $this->close();
    }

    private function initWiki()
    {
        $wikiXML = <<<EOT
<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.10/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.mediawiki.org/xml/export-0.10/ http://www.mediawiki.org/xml/export-0.10.xsd" version="0.10" xml:lang="en">
      <siteinfo>
        <generator>wikibuilder</generator>
        <case>first-letter</case>
        <namespaces>
          <namespace key="0" case="first-letter" />
        </namespaces>
      </siteinfo>
EOT;

        file_put_contents($this->filename, $wikiXML);
    }

    public function close()
    {
        if (!$this->bOpen)
            return;
        $this->bOpen = false;

        foreach ($this->pages as $page)
            $page->close();

        file_put_contents($this->filename, '</mediawiki>', FILE_APPEND);
    }

    public function addPage(string $pageName, DateTime $datePage = null) : wikiPage
    {
        $thePage = new wikiPage($this, $pageName,$datePage);
        $this->pages[] = $thePage;
        return $thePage;
    }

    public function addData($data)
    {
        file_put_contents($this->filename, $data, FILE_APPEND);
        clearstatcache();

        if (filesize($this->filename) > wikiImportFile::max_file_size)
        {
            // we've reached the max file size we allow ourself, let's create another output file:
            file_put_contents($this->filename, '</mediawiki>', FILE_APPEND);

            $this->filename = $this->rootfilename . $this->filecount . ".xml";
            $this->filecount++;

            $this->initWiki();
        }
    }

    public function addCategory($catName) : wikiPage
    {
        if (!isset($this->categories[$catName]))
            $this->categories[$catName] = $this->addPage("Category:$catName");

        return $this->categories[$catName];
    }
}


class wikiPage
{
    private $content = '';
    private $endContent = '';
    private $wiki = null;
    private $wikiPageName = '';
    private $lastUpdate = '';
    private $bOpen = false;
    private $categories = array();

    function __construct($wiki, $wikiPageName, DateTime $datePage = null)
    {
        $this->wiki = $wiki;
        $this->wikiPageName = $wikiPageName;
        $this->lastUpdate = $datePage;
        $this->categories = array();
        $this->bOpen = true;
    }

    function __destruct()
    {
        $this->close();
    }


    public function close()
    {
        if (!$this->bOpen)
            return;

        $this->bOpen = false;

        $this->content = $this->cleanUpContent();
        $this->wikiPageName = trim($this->wikiPageName);
        echo $this->wikiPageName . "\n";

        if (empty($this->wikiPageName) || empty($this->content))
            return; // if the page is empty, don't bother adding it.

        $this->wikiPageName = htmlspecialchars($this->wikiPageName, ENT_COMPAT, 'UTF-8');
        $this->content = htmlspecialchars($this->content, ENT_COMPAT, 'UTF-8');

        /* Not possible for the moment, the date is always overwritten by the importation revision
        if (!empty($this->lastUpdate))
        {
            $date = $this->lastUpdate->format(DateTimeInterface::RFC3339);
            echo "Set the last update date to $date \n";
            $timestamp = "<timestamp>$date</timestamp>";
        }
        else
            $timestamp = '';
        */
        $wikiPage = <<<EOT

  <page>
    <title>$this->wikiPageName</title>
    <ns>0</ns>
    <id>2</id>
    <revision>
      <model>wikitext</model>
      <format>text/x-wiki</format>

      <text xml:space="preserve" >
EOT;

        $wikiPage .= $this->content . "\n\n";
        $wikiPage .= $this->endContent;

        $wikiPage .= "</text></revision></page>\n";

        $this->wiki->addData($wikiPage);
    }

    public function addContent($content)
    {
        if (!$this->bOpen)
            throw new Exception("Trying to add content to a closed page", 1);

        $this->content .= $content;
    }

    public function addContentAtEnd($content)
    {
        if (!$this->bOpen)
            throw new Exception("Trying to add content to a closed page", 1);

        $this->endContent .= $content;
    }

    public function addTemplate(string $templateName, array $variables)
    {
        $vars = array();
        foreach ($variables as $k => $v)
            $vars[] = "$k=$v";

        if (empty($vars))
            $wikiPageContent = "{{$templateName}}\n";
        else
            $wikiPageContent = '{{' . $templateName .  '|' . implode('|', $vars)."}}\n";

        $this->addContent($wikiPageContent);
    }

    public function addCategory($catName)
    {
        if (isset($this->categories[$catName]))
            return $this->wiki->addCategory($catName);

        $this->categories[$catName] = $catName;

        $this->addContentAtEnd("[[Category:$catName]]");

        return $this->wiki->addCategory($catName);
     }

     /**
      * Returns the wikitext of the page after a gentle cleanup:
      * * Remove unnecessary triple cariage returns
      * * Trim all lines (we don't allow spaces in front of lines)
      */
    private function cleanUpContent()
    {
        $lines = explode("\n", $this->content);
        $wikiText = '';
        $emptylinesCount=0;

        foreach ($lines as $line)
        {
            $line = trim($line);
            // if the line begins by "*", it's a listing in mediaWiki. The carriage return is automatic.
            if (preg_match("@^\*@",$line))
                $emptylinesCount++;

            if (empty($line))
                $emptylinesCount++;
            else
                $emptylinesCount = 0;

            if ($emptylinesCount < 2)
                $wikiText .= $line . "\n";
        }

         return $wikiText;
    }

    /**
     * Makes sure that the pagename respects MediaWiki rules:
     * @see https://en.wikipedia.org/wiki/Wikipedia:Page_name
     */
    public function replaceForbidenPagenameCharacters($pageName)
    {
        $origPageName = $pageName;

        // A pagename cannot be . or ..; or begin with ./ or ../; or contain /./ or /../; or end with /. or /...
        // A pagename cannot begin with a colon :.
        $pageName = mb_eregi_replace('^\.+/', '', $pageName);
        $pageName = mb_eregi_replace('^:+', '', $pageName);
        $pageName = mb_eregi_replace('/\.+/', '', $pageName);
        $pageName = mb_eregi_replace('/\.+$', '', $pageName);

        /* A pagename cannot contain any of the following characters: # < > [ ] | { } _ (which all have special meanings in wiki syntax); 
        the non-printable ASCII characters (coded 0–31 decimal); the delete character (coded 127 decimal); the Unicode replacement 
        character U+FFFD �; or any HTML character codes, such as &amp;.[6] A pagename also cannot contain 3 or more continuous tildes ~~~, 
        as these are used for marking signatures on Wikipedia. */
        $pageName = mb_eregi_replace('>', 'supérieur à', $pageName);
        $pageName = mb_eregi_replace('<', 'inférieur à', $pageName);
        $pageName = mb_eregi_replace('_', ' ', $pageName);
        $pageName = mb_eregi_replace('\[|{', '(', $pageName);
        $pageName = mb_eregi_replace(']|}', ')', $pageName);
        $pageName = mb_eregi_replace('\||#', '-', $pageName);
        
        // A pagename cannot exceed 255 bytes in length. Be aware that non-ASCII characters may take up to four bytes in UTF-8 encoding, so the total number of characters 
        //that can fit into a title may be less than 255.
        // ==> There's few chances that case happen.
        $bytesLength = mb_strlen($pageName);
        if ($bytesLength > 255)
            echo "Title lenght to hight,: it's $bytesLength and must be lower than 255";
        
        // A pagename cannot begin with a lowercase letter in any alphabet except for the German letter ß.[5]
        $ucase = mb_strtoupper($origPageName); 
        if ($ucase == $origPageName)
            $pageName = mb_ucfirst($pageName);

        $pageName = str_replace("\n", ' ', $pageName);
        if ($origPageName != $pageName)
            echo "Replaced page title special characters : $origPageName --> $pageName\n";
        
        $this->wikiPageName = $pageName;
        return $pageName;
    }
    public function mb_ucfirst($str)
    {
        $fc = mb_strtoupper(mb_substr($str, 0, 1));
        return $fc.mb_substr($str, 1);
    }
};