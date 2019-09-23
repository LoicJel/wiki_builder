<?php

include_once('wikibuilder.php');

set_time_limit(0);

$baseDir = dirname(__FILE__);

if (!is_dir("$baseDir/out"))
    mkdir("$baseDir/out");

$filename = "$baseDir/out/wiki_practices.xml";

if (file_exists($filename))
    unlink($filename);

$wikiBuilder = new wikiImportFile($filename);

$page1 = $wikiBuilder->addPage("Hello");
$page2 = $wikiBuilder->addPage("Salut");

$page1->addContent("Du contenu dans la page 1");
$page1->addCategory("Pages du site");

$page2->addContent("Du contenu dans la page 2");
$catPage = $page2->addCategory("Pages du site");

$catPage->addContent("du contenu dans la catégorie");
$catPage->addCategory("une surcatégorie");

$wikiBuilder->close();
