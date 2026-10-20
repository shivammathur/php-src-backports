--TEST--
CVE-2016-7479: defer __wakeup until all serialized references are registered
--FILE--
<?php
class WakeupReference {
    public $value;
    public function __wakeup() {
        $this->value = 42;
    }
}
$data = unserialize('a:2:{i:0;O:15:"WakeupReference":1:{s:5:"value";i:1;}i:1;R:3;}');
var_dump($data[0]->value, $data[1]);
?>
--EXPECT--
int(42)
int(42)
