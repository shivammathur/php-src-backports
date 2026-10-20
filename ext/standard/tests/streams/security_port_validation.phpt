--TEST--
CVE-2017-7272: reject trailing data in socket port numbers
--FILE--
<?php
foreach (array('tcp://127.0.0.1:1:2', 'tcp://127.0.0.1:1abc') as $address) {
    $socket = @stream_socket_client($address, $errno, $error, 0.01);
    var_dump($socket, strpos($error, 'Failed to parse address') !== false);
}
?>
--EXPECT--
bool(false)
bool(true)
bool(false)
bool(true)
