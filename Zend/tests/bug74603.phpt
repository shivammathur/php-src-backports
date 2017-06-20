--TEST--
Bug #74603 (PHP INI Parsing Stack Buffer Overflow Vulnerability)
--SKIPIF--
<?php
if (PHP_INT_MAX !== 2147483647)
        die('skip for 32-bit only');
--FILE--
<?php
var_dump(parse_ini_file(dirname(__FILE__) . "/bug74603.ini", true));
?>
--EXPECT--
array(1) {
  [0]=>
  string(1) "0"
}
