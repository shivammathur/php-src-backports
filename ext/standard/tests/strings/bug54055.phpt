--TEST--
Bug #54055: high precision conversion stays within the conversion buffer
--FILE--
<?php
for ($i = 495; $i <= 1074; $i++) {
    ini_set('precision', $i);
    $value = strval(-1 * pow(2, -1074));
    if (strlen($value) > 1024 || !is_numeric($value)) die("Invalid conversion\n");
}
echo "Conversions remain bounded\n";
?>
--EXPECT--
Conversions remain bounded
