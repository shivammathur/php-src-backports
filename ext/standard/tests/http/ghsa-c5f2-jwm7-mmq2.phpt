--TEST--
GHSA-c5f2-jwm7-mmq2 (Configuring a proxy in a stream context might allow for CRLF injection in URIs)
--INI--
allow_url_fopen=1
--FILE--
<?php

$host = "localhost";
$port = 12342;
$host_port = "$host:$port";

# Fake the http server. We don't need an actual connection, the request is
# expected to fail before trying to connect.
define("PHP_CLI_SERVER_HOSTNAME", $host);
define("PHP_CLI_SERVER_PORT", $port);
define("PHP_CLI_SERVER_ADDRESS", PHP_CLI_SERVER_HOSTNAME.":".PHP_CLI_SERVER_PORT);

$userinput = "index.php HTTP/1.1\r\nHost: $host_port\r\n\r\nGET /index2.php HTTP/1.1\r\nHost: $host_port\r\n\r\nGET /index.php";
$context = stream_context_create(array('http' => array('proxy' => 'tcp://' . $host_port, 'request_fulluri' => true)));
echo file_get_contents("http://$host_port/$userinput", false, $context);

?>
--EXPECTF--
Warning: file_get_contents(http://localhost:12342/index.php HTTP/1.1
Host: localhost:12342

GET /index2.php HTTP/1.1
Host: localhost:12342

GET /index.php): failed to open stream: HTTP wrapper full URI path does not allow CR or LF characters in %s on line %d
