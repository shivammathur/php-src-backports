--TEST--
GHSA-9pqp-7h25-4f32
--SKIPIF--
<?php
if (!getenv('TEST_PHP_CGI_EXECUTABLE')) {
    die("skip php-cgi not available");
}
?>
--FILE--
<?php

define('FILLUNIT', 5 * 1024);

function test($boundaryLen) {
    printf("Boundary len: %d\n", $boundaryLen);

    $cmd = array(
        getenv('TEST_PHP_CGI_EXECUTABLE'),
        '-C',
        '-n',
        '-d', 'display_errors=1',
        '-d', 'html_errors=1',
        '-d', 'error_reporting=-1',
        '-d', 'default_charset=UTF-8',
        dirname(__FILE__) . '/GHSA-9pqp-7h25-4f32.inc',
    );

    $boundary = str_repeat('A', $boundaryLen);
    $body = ""
        . "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"koko\"\r\n"
        . "\r\n"
        . "BBB\r\n--" . substr($boundary, 0, -1) . "CCC\r\n"
        . "--$boundary--\r\n"
        ;

    $env = array_merge($_ENV, array(
        'REDIRECT_STATUS' => '1',
        'CONTENT_TYPE' => "multipart/form-data; boundary=$boundary",
        'CONTENT_LENGTH' => strlen($body),
        'REQUEST_METHOD' => 'POST',
        'SCRIPT_FILENAME' => dirname(__FILE__) . '/GHSA-9pqp-7h25-4f32.inc',
    ));

    $spec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => STDOUT,
    );

    $pipes = array();

    print "Starting...\n";

    $handle = proc_open(implode(' ', array_map('escapeshellarg', $cmd)), $spec, $pipes, getcwd(), $env);

    fwrite($pipes[0], $body);

    fclose($pipes[0]);
    $response = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($handle);
    $start = strpos($response, "Hello world");
    echo $start === false ? "Missing CGI response\n" : substr($response, $start);

    print "\n";
}

for ($offset = -1; $offset <= 1; $offset++) {
    test(FILLUNIT - strlen("\r\n--") + $offset);
}

?>
--EXPECTF--
Boundary len: 5115
Starting...
Hello world
array(1) {
  ["koko"]=>
  string(5124) "BBB
--AAA%sCCC"
}

Boundary len: 5116
Starting...
Hello world
array(1) {
  ["koko"]=>
  string(5125) "BBB
--AAA%sCCC"
}

Boundary len: 5117
Starting...
Hello world
array(0) {
}

