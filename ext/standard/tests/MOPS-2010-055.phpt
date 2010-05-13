--TEST--
MOPS-2010-055: ArrayObject::uasort() Interruption Memory Corruption Vulnerability
--FILE--
<?php
error_Reporting(E_ALL);
    $first = true;
    function uc($a,$b)
    {
        global $first;
       
        /* Detect 32 vs 64 bit */
        $i = 0x7fffffff;
        $i++;
        if (is_float($i)) {
            $y = str_repeat("A", 39);
        } else {
            $y = str_repeat("A", 67);      
        }    
        if ($first) {
        unset($GLOBALS['arr']["B"]);
        $GLOBALS['_____________________________________________________________________________a'] = 1;
        $GLOBALS['_____________________________________________________________________________b'] = 2;
        $GLOBALS['_____________________________________________________________________________x'] .= $y;
        }
        $first=false;
    }

    $arr = new ArrayObject(array("A" => str_repeat("A", 164),"B" => str_repeat("B", 164), "C" => str_repeat("C", 164), "D" => str_repeat("D", 164)));
   
    $arr->uasort("uc");
?>
--EXPECTF--
Warning: Modification of ArrayObject during sorting is prohibited in %sMOPS-2010-055.php on line %d

Notice: Undefined index:  _____________________________________________________________________________x in %sMOPS-2010-055.php on line %d
