--TEST--
Bug #68044 Integer overflow in unserialize() (32-bits only)
--FILE--
<?php
echo unserialize('C:3:"XYZ":18446744075857035259:{}');
?>
===DONE==
--EXPECTF--
Warning: Class __PHP_Incomplete_Class has no unserializer in %s/bug68044.php on line 2

Notice: unserialize(): Error at offset %d of 33 bytes in %s/bug68044.php on line %d
===DONE==
