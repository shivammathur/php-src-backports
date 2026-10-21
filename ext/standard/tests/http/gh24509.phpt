--TEST--
GH-24509 (HTTP wrapper leaks the Location buffer when an empty Location header is followed by another)
--INI--
allow_url_fopen=1
report_memleaks=1
--FILE--
<?php
require __DIR__ . '/../../../openssl/tests/ServerClientTestCase2.inc';

$serverCode = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
phpt_notify_server_start($server);

$responses = array(
    "HTTP/1.1 302 Found\r\nLocation:\r\nLocation: /abcdefghijklmno\r\nContent-Length: 0\r\n\r\n",
    "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK",
);
foreach ($responses as $response) {
    $conn = stream_socket_accept($server, 10);
    if (!$conn) {
        break;
    }
    while (($line = fgets($conn)) !== false && trim($line) !== '') {
    }
    fwrite($conn, $response);
    fclose($conn);
}
PHP;

$clientCode = 'var_dump(file_get_contents("http://{{ ADDR }}/"));';
ServerClientTestCase::getInstance()->run($clientCode, $serverCode);
?>
--EXPECT--
string(2) "OK"
