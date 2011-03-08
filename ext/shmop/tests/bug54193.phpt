--TEST--
Integer overflow in shmop_read()
--FILE--
<?php

$shm_key = ftok(__FILE__, 't');
$shm_id = shmop_open($shm_key, "c", 0644, 100);
$shm_data = shmop_read($shm_id, 1, 2147483647);
//if there is no segmentation fault past this point, we have 2gb of memory!
echo $shm_data;
?>
--EXPECTF--
Warning: shmop_read(): count is out of range in %sbug54193.php on line %d