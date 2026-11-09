--TEST--
session.cookie_samesite can be changed during an active session when upload progress is disabled
--INI--
session.upload_progress.enabled=0
session.use_cookies=0
--FILE--
<?php
session_start();
var_dump(ini_set('session.cookie_samesite', 'Lax'));
var_dump(ini_get('session.cookie_samesite'));
session_write_close();
?>
--EXPECT--
string(0) ""
string(3) "Lax"
