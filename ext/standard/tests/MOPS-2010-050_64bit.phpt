--TEST--
MOPS-2010-050: PHP preg_match() Interruption Information Leak Vulnerability
--SKIPIF--
<?php 
if (PHP_INT_SIZE != 8) die("skip this test is for 64bit platforms only");
?>
--FILE--
<?php
class dummy
{
    function __destruct()
    {                      
        /* now the magic */
        parse_str("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=1", $GLOBALS['var']);
        return "";
    }
}

/* Detect 32 vs 64 bit */
$i = 0x7fffffff;
$i++;
if (is_float($i)) {
    $GLOBALS['var'] = str_repeat("A", 39);
} else {
    $GLOBALS['var'] = str_repeat("A", 67);     
}

/* Trigger the Code */
$x = new dummy();
preg_match("/^.*$/", &$GLOBALS['var'], $x);
hexdump($x[0]);

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
00000020: 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41   AAAAAAAAAAAAAAAA
00000030: 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41 41   AAAAAAAAAAAAAAAA
00000040: 41 41 41 -- -- -- -- -- -- -- -- -- -- -- -- --   AAA
