--TEST--
MOPS-2010-052: PHP pack() Interruption Information Leak Vulnerability
--FILE--
<?php
$first = true;
function my_error($a,$x)
{
    global $first;
    if ($first) {
        var_dump($x);
        $GLOBALS['var'].=str_repeat("A",2000);
    }
    $first = false;
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
$GLOBALS['var'] = base64_decode(str_repeat("-", 1000).base64_encode("AAAAAAAAAAAAAAAA"));
/* Trigger the Code */
set_error_handler("my_error");
$x = pack("h20", &$GLOBALS['var']);
restore_error_handler();
hexdump($x);

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
string(%d) "pack(): Type h: not enough characters in string"
Hexdump
-------
00000000: AA AA AA AA AA AA AA AA -- -- -- -- -- -- -- --   ........
