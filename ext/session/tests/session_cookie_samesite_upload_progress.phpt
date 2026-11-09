--TEST--
session.cookie_samesite remains protected while upload progress is enabled
--INI--
session.upload_progress.enabled=1
--FILE--
<?php
try {
    ini_set('session.cookie_samesite', 'Lax');
    echo "unexpected success\n";
} catch (Error $e) {
    echo "rejected\n";
}
var_dump(ini_get('session.cookie_samesite'));
?>
--EXPECT--
rejected
string(0) ""
