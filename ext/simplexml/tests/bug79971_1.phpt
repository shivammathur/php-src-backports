--TEST--
Bug #79971 (special character is breaking the path in xml function)
--SKIPIF--
<?php
if (!extension_loaded('simplexml')) die('skip simplexml extension not available');
?>
--FILE--
<?php
if (substr(PHP_OS, 0, 3) === 'WIN') {
    $path = '/' . str_replace('\\', '/', dirname(__FILE__));
} else {
    $path = dirname(__FILE__);
}
$uri = "file://$path/bug79971_1.xml";
var_dump(simplexml_load_file("$uri%00foo"));

$sxe = simplexml_load_file($uri);
$sxe->asXML("$uri.out%00foo");
// Rejected output must not create the path truncated at the encoded NUL.
var_dump(file_exists("$path/bug79971_1.xml.out"));
?>
--EXPECTF--

Warning: simplexml_load_file(): I/O warning : failed to load external entity "%s/bug79971_1.xml%00foo" in %s on line %d
bool(false)

bool(false)
