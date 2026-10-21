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
parse_str('..Host-test=value&outer[__Secure-test]=value&outer[__Secure-test][child]=value', $result);
var_dump(count($result));
var_dump(count($result['outer']));
// Appending near the maximum index must leave earlier entries intact.
parse_str('x[' . (PHP_INT_MAX - 1) . ']=value&x[]=kept&x[][child]=discard', $result);
var_dump($result['x'][PHP_INT_MAX - 1] === 'value');
var_dump($result['x'][PHP_INT_MAX] === 'kept');
echo "Done\n";
?>
--EXPECT--
bool(true)
int(1)
int(0)
bool(true)
bool(true)
Done
