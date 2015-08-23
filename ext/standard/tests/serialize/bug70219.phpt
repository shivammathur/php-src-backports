--TEST--
Bug #70219 Use after free vulnerability in session deserializer
--FILE--
<?php

session_start();
$exploit = 'ryat|a:2:{i:0;i:1;i:1;a:1:{i:1;chtg|a:1:{i:0;R:4;}';

session_decode($exploit);

for ($i = 0; $i < 5; $i++) {
    $v[$i] = 'hi'.$i;
}

var_dump($_SESSION);
?>
--EXPECTF--
Warning: session_decode(): Failed to decode session object. Session has been destroyed in %s on line %d
array(0) {
}
