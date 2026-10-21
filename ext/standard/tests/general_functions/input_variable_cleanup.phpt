--TEST--
Input variable rejection releases temporary values safely
--INI--
max_input_vars=100
magic_quotes_gpc=1
--FILE--
<?php
// Overflow an array of distinct intermediate keys, including escaped names.
$items = array();
for ($i = 0; $i < 110; $i++) $items[] = 'root[k%27' . $i . '][v]=value';
@parse_str(implode('&', $items), $result);
var_dump(count($result['root']) <= 101);
// Mangling must not turn a supplied name into a protected cookie prefix.
parse_str('__Host%20-test=value&outer[__Secure%20-test]=value', $result);
var_dump(count($result));
// Failed append at the maximum integer index must not reuse a stale pointer.
parse_str('x[' . PHP_INT_MAX . ']=value&x[]=discard&x[][child]=discard', $result);
var_dump(count($result['x']));
echo "Done\n";
?>
--EXPECT--
bool(true)
int(1)
int(1)
Done
