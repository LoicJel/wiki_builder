<?php

foreach (glob(__DIR__ . '/temp/xmlaccess/*.xml') as $filename)
{
    if (basename($filename) == 'alllink.xml')
        continue;

    echo basename($filename);

    $doc = simplexml_load_file($filename);

    $prevCode = "";

    foreach ($doc as $element)
    {
        if (isset($element->lang) && $element->lang == 'fr')
        {
            if ($prevCode  == $element->code)
                continue;
            $prevCode  = $element->code;
//            echo $element->code . "\t" . $element->fullname . "\t" . $element->shortname . "\n";

echo $element->fullname . "\n";
        }
    }

    /*

SimpleXMLElement Object
(
    [identifier] => 50432
    [datatype] => GAF
    [code] => ACPHFU
    [codeid] => 18
    [lang] => la
    [langno] => 1
    [preferred] => 1
    [status] => A
    [codestatus] => A
    [creationcode] => 1999-07-11
    [modificationcode] => 1999-07-11
    [creationname] => 1999-07-11
    [modificationname] => 1999-07-11
    [fullname] => Acrophialophora fusispora
    [shortname] => Acrophialophora fusispora
    [authority] => (S.B.Saksena) Samson
)

    */
}