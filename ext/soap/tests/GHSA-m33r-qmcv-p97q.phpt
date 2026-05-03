--TEST--
GHSA-m33r-qmcv-p97q: Use-after-free after header parsing failure with SOAP_PERSISTENCE_SESSION
--CREDITS--
Ilia Alshanetsky (iliaal)
--SKIPIF--
<?php
if (!extension_loaded("soap")) die("skip soap extension not available");
if (!extension_loaded("session")) die("skip session extension not available");
?>
--FILE--
<?php

class Handler {
    public function triggerReturn() {
        return new SoapFault('Server', 'denied');
    }
    public function triggerThrow() {
        throw new SoapFault('Server', 'denied');
    }
    public function hello() {
        return 'ok';
    }
}

session_start();

$srv = new SoapServer(null, array('uri' => 'urn:a'));
$srv->setClass('Handler');
$srv->setPersistence(SOAP_PERSISTENCE_SESSION);

$xml1 = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:a="urn:a">
    <soap:Header>
        <a:triggerReturn/>
    </soap:Header>
    <soap:Body>
        <a:hello/>
    </soap:Body>
</soap:Envelope>
XML;
$srv->handle($xml1);

$xml2 = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:a="urn:a">
    <soap:Header>
        <a:triggerThrow/>
    </soap:Header>
    <soap:Body>
        <a:hello/>
    </soap:Body>
</soap:Envelope>
XML;
$srv->handle($xml2);

?>
--EXPECT--
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><SOAP-ENV:Fault><faultcode>SOAP-ENV:Server</faultcode><faultstring>denied</faultstring></SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><SOAP-ENV:Fault><faultcode>SOAP-ENV:Server</faultcode><faultstring>denied</faultstring></SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>
