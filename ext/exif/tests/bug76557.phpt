--TEST--
Bug 76557 (heap-buffer-overflow (READ of size 48) while reading exif data)
--SKIPIF--
<?php if (!extension_loaded('exif')) print 'skip exif extension not available';?>
--FILE--
<?php
var_dump(count(exif_read_data(dirname(__FILE__) . "/bug76557.jpg")));
?>
DONE
--EXPECTF--
%A
int(1)
DONE
