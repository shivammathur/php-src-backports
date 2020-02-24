--TEST--
GHSA-hgf5-96fm-v528: Stream HTTP wrapper header check might omit basic auth header (incorrect inside pos)
--INI--
allow_url_fopen=1
--SKIPIF--
<?php require 'server.inc'; http_server_skipif('tcp://127.0.0.1:12342'); ?>
--FILE--
<?php
require 'server.inc';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: foo=bar\nauthorization:x\r\n",
    ],
];
$ctx = stream_context_create($options);
$responses = ["data://text/plain,HTTP/1.1 200\r\nConnection: close\r\n\r\nOK"];

$pid = http_server('tcp://127.0.0.1:12342', $responses, $output);
@file_get_contents('http://user:pwd@127.0.0.1:12342/', false, $ctx);
fseek($output, 0, SEEK_SET);
echo stream_get_contents($output);
http_server_kill($pid);
?>
Done
--EXPECT--
GET / HTTP/1.0
Authorization: Basic dXNlcjpwd2Q=
Host: 127.0.0.1:12342
Cookie: foo=bar
authorization:x

Done
