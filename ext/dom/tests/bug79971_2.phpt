--TEST--
Bug #79971 (special character is breaking the path in xml function)
--SKIPIF--
<?php
if (!extension_loaded('dom')) die('skip dom extension not available');
?>
--FILE--
<?php
$imp = new DOMImplementation;
if (substr(PHP_OS, 0, 3) === 'WIN') {
    $path = '/' . str_replace('\\', '/', dirname(__FILE__));
} else {
    $path = dirname(__FILE__);
}
$uri = "file://$path/bug79971_2.xml";
var_dump($imp->createDocumentType("$uri%00foo"));
?>
--EXPECTF--
Warning: DOMImplementation::createDocumentType(): URI must not contain percent-encoded NUL bytes in %s on line %d
bool(false)
