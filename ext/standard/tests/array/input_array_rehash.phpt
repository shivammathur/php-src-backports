--TEST--
Input arrays retain valid hash buckets after sorting
--FILE--
<?php
parse_str('x[a]=1&x[b]=2', $data);
sort($data['x']);
unset($data['x'][0]);
$other = array('a' => 'unrelated');
var_dump(isset($data['x']['a']));

parse_str('x[a]=1&x[b]=2', $data);
array_multisort($data['x']);
var_dump(isset($data['x']['z']));
?>
--EXPECT--
bool(false)
bool(false)
