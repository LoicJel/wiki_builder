<?php


$wikiText = "test


Photo d'en tête : adulte de carpocapse des prunes - © INRA [https://www7.inra.fr/hyppz/IMAGES/7030280.jpg lien vers la photo]

test";

$lines = explode("\n", $wikiText);
$wikiText = '';

foreach ($lines as $line)
{
	$matches = array();
	if (preg_match('@.*:([^©]+©[^[]*)@', $line, $matches))
		$imageCaption = $matches[1];
	else
		$wikiText .= $line . "\n";
}

echo $imageCaption . "\n\n---\n\n$wikiText";