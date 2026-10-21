--TEST--
parse_ini_file rejects embedded NUL bytes with either argument count
--FILE--
<?php
$path = dirname(__FILE__) . '/parse_ini_file_nullbyte.ini';
file_put_contents($path, "foo=bar\n");
var_dump(parse_ini_file($path));
var_dump(parse_ini_file($path . "\0suffix"));
var_dump(parse_ini_file($path . "\0suffix", true));
var_dump(@parse_ini_file(123, true));
unlink($path);
?>
--EXPECT--
array(1) {
  ["foo"]=>
  string(3) "bar"
}
bool(false)
bool(false)
array(0) {
}
