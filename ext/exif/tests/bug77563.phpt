--TEST--
Bug 77563 (Uninitialized read in exif_process_IFD_in_MAKERNOTE)
--SKIPIF--
<?php if (!extension_loaded('exif')) print 'skip exif extension not available';?>
--FILE--
<?php
$s = exif_thumbnail(dirname(__FILE__)."/bug77563.jpg");
?>
DONE
--EXPECTF--
%s
DONE