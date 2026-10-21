--TEST--
Bug 72482 (Ilegal write/read access caused by gdImageAALine overflow)
--SKIPIF--
<?php
if (!extension_loaded('gd')) die('skip gd extension not available');
?>
--FILE--
<?php
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'func.inc';

$im = imagecreatetruecolor(10, 10);
imagefilledrectangle($im, 0, 0, 9, 9, imagecolorallocate($im, 255, 255, 255));
imageantialias($im, true);
imageline($im, 0, 0, 10, 10, imagecolorallocate($im, 0, 0, 0));

$expected = imagecreatefrompng(dirname(__FILE__) . '/bug72482_2.png');
// PHP 5.1's bundled GD excludes the clipped final endpoint from an AA line.
imagesetpixel($expected, 9, 9, imagecolorallocate($expected, 255, 255, 255));
$differences = 0;
for ($y = 0; $y < 10; $y++) {
    for ($x = 0; $x < 10; $x++) {
        if (imagecolorat($expected, $x, $y) != imagecolorat($im, $x, $y)) $differences++;
    }
}
echo $differences ? "The images differ.\n" : "The images are equal.\n";
?>
===DONE===
--EXPECT--
The images are equal.
===DONE===
