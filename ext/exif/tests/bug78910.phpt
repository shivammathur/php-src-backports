--TEST--
Bug #78910: Heap-buffer-overflow READ in exif (OSS-Fuzz #19044)
--FILE--
<?php

var_dump(exif_read_data('data:image/jpg;base64,TU0AKgAAAAwgICAgAAIBDwAEAAAAAgAAACKSfCAgAAAAAEZVSklGSUxN'));

?>
--EXPECTF--
Warning: exif_read_data(): Unable to open file in %s on line %d
bool(false)
