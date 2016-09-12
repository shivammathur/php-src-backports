--TEST--
Bug #73029: Missing type check when unserializing SplArray
--FILE--
<?php
try {
$a = 'C:11:"ArrayObject":19:0x:i:0;r:2;;m:a:0:{}}';
$m = unserialize($a);
$x = $m[2];
} catch(UnexpectedValueException $e) {
	print $e->getMessage() . "\n";
}
?>
DONE
--EXPECTF--
Notice: unserialize(): Error at offset 23 of 43 bytes in %sbug73029.php on line %d
Error at offset 10 of 19 bytes
DONE
