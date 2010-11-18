--TEST--
Bug #39863: Incorrect handling of paths with \0
--FILE--
<?php
$dir = dirname(__FILE__);
file_put_contents("$dir/include1", "PASS-Include1\n");
file_put_contents("$dir/require1", "PASS-Require1\n");
include_once "$dir/include1";
include "$dir/include1";
require_once "$dir/require1";
require "$dir/require1";
file_put_contents("$dir/include2", "FAIL-Include2\n");
file_put_contents("$dir/require2", "FAIL-Require2\n");
var_dump(@include_once "$dir/include2\0extra");
var_dump(@include "$dir/include2\0extra");
# not testing require, as it produces fatal error
#require_once "$dir/require2\0extra";
#require "$dir/require2\0extra";
@unlink("$dir/include1");
@unlink("$dir/require1");
@unlink("$dir/include2");
@unlink("$dir/require2");
?>
Done
--EXPECTF--
PASS-Include1
PASS-Include1
PASS-Require1
PASS-Require1
bool(false)
bool(false)
Done
