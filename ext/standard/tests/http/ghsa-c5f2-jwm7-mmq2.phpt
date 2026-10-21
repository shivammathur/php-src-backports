--TEST--
GHSA-c5f2-jwm7-mmq2 (Configuring a proxy in a stream context might allow for CRLF injection in URIs)
--INI--
allow_url_fopen=1
--CONFLICTS--
server
--FILE--
<?php
// URI validation occurs before sending the request. Keep a listening socket
// open so the legacy wrapper can establish its proxy connection.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$host = stream_socket_get_name($server, false);
$userinput = "index.php HTTP/1.1\r\nHost: $host\r\n\r\nGET /index2.php HTTP/1.1\r\nHost: $host\r\n\r\nGET /index.php";
$context = stream_context_create(array('http' => array('proxy' => 'tcp://' . $host, 'request_fulluri' => true)));
echo file_get_contents("http://$host/$userinput", false, $context);
fclose($server);
?>
--EXPECTF--
Warning: file_get_contents(http://127.0.0.1:%d/index.php HTTP/1.1
Host: 127.0.0.1:%d

GET /index2.php HTTP/1.1
Host: 127.0.0.1:%d

GET /index.php): failed to open stream: HTTP wrapper full URI path does not allow CR or LF characters in %s on line %d
