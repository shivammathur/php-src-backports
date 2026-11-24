--TEST--
session_set_save_handler reports failure when upload progress blocks handler changes
--INI--
session.upload_progress.enabled=1
--FILE--
<?php
var_dump(@session_set_save_handler());
$callback = function () { return true; };
var_dump(@session_set_save_handler(new SessionHandler()));
var_dump(@session_set_save_handler($callback, $callback, $callback, $callback, $callback, $callback));
var_dump(ini_get('session.save_handler'));
?>
--EXPECT--
NULL
bool(false)
bool(false)
string(5) "files"
