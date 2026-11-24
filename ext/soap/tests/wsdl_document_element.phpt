--TEST--
SOAP loads the document element of a WSDL and imported schema with document types
--SKIPIF--
<?php if (!extension_loaded("soap")) die("skip soap extension required"); ?>
--INI--
soap.wsdl_cache_enabled=0
--FILE--
<?php
$client = new SoapClient(__DIR__ . "/wsdl_document_element.wsdl");
echo implode("\n", $client->__getTypes()), "\n";
?>
--EXPECT--
struct Record {
 string value;
}
