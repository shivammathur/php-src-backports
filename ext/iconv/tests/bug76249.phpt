--TEST--
Bug #76249 (stream filter convert.iconv leads to infinite loop on invalid sequence)
--SKIPIF--
<?php extension_loaded('iconv') or die('skip iconv extension is not available'); ?>
--FILE--
<?php
$fh = fopen('php://memory', 'rw');
fwrite($fh, "abc");
rewind($fh);
stream_filter_append($fh, 'convert.iconv.ucs-2/utf8//IGNORE', STREAM_FILTER_READ, array());
echo stream_get_contents($fh);
?>
DONE
--EXPECTF--
扡DONE
