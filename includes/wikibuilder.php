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

    public function addPage(string $pageName) : wikiPage
    {
        $thePage = new wikiPage($this, $pageName);
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
    private $bOpen = false;
    private $categories = array();

    function __construct($wiki, $wikiPageName)
    {
        $this->wiki = $wiki;
        $this->wikiPageName = $wikiPageName;

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

        if (empty($this->wikiPageName) || empty($this->content))
            return; // if the page is empty, don't bother adding it.

        $this->wikiPageName = htmlspecialchars($this->wikiPageName, ENT_COMPAT, 'UTF-8');
        $this->content = htmlspecialchars($this->content, ENT_COMPAT, 'UTF-8');

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

            if (empty($line))
                $emptylinesCount++;
            else
                $emptylinesCount = 0;

            if ($emptylinesCount < 2)
                $wikiText .= $line . "\n";
        }

         return $wikiText;
     }
}