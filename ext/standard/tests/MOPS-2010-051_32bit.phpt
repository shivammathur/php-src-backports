--TEST--
MOPS-2010-051: PHP unpack() Interruption Information Leak Vulnerability
--SKIPIF--
<?php 
if (PHP_INT_SIZE != 4) die("skip this test is for 32bit platforms only");
?>
--FILE--
<?php
function my_error()
{
    parse_str("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=1", $GLOBALS['var']);
    return 1;
}

/* Detect 32 vs 64 bit */
$i = 0x7fffffff;
$i++;
if (is_float($i)) {
    $l = 39;       
} else {
    $l = 67;
}
$GLOBALS['var'] = str_repeat("A", $l);

/* Trigger the Code */
set_error_handler("my_error");
$x = unpack("@31337/@0/A$l", &$GLOBALS['var']);
restore_error_handler();
hexdump($x[1]);

/* Helper function */
function hexdump($x)
{
    $l = strlen($x);
    $p = 0;

    echo "Hexdump\n";
    echo "-------\n";

    while ($l > 16) {
        echo sprintf("%08x: ",$p);
        for ($i=0; $i<16; $i++) {
            echo sprintf("%02X ", ord($x[$p+$i]));
        }
        echo "  ";
        for ($i=0; $i<16; $i++) {
            $c = ord($x[$p+$i]);
            echo ($c < 32 || $c > 127) ? '.' : chr($c);
        }
        $l-=16;
        $p+=16;
        echo "\n";
    }
    if ($l > 0)
    echo sprintf("%08x: ",$p);
    for ($i=0; $i<$l; $i++) {
        echo sprintf("%02X ", ord($x[$p+$i]));
    }
    for ($i=0; $i<16-$l; $i++) { echo "-- "; }

    echo "  ";
    for ($i=0; $i<$l; $i++) {
        $c = ord($x[$p+$i]);
        echo ($c < 32 || $c > 127) ? '.' : chr($c);
    }
    echo "\n";
}
?>
--EXPECTF--
Hexdump
-------
00000000: 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41   AAAAAAAAAAAAAAAA
00000010: 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41   AAAAAAAAAAAAAAAA
00000020: 41 41 41 41 41 41 41 -- -- -- -- -- -- -- -- --   AAAAAAA
