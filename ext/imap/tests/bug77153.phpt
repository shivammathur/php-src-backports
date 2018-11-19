--TEST--                                 
Bug #77153 (imap_open allows to run arbitrary shell commands via mailbox parameter)
--SKIPIF--
<?php
        if (!extension_loaded("imap")) { 
                die("skip imap extension not available");  
        }
?>
--FILE--
<?php
define('__DIR__', realpath(dirname(__FILE__)));
$payload = "echo 'BUG'> " . __DIR__ . '/__bug';
$payloadb64 = base64_encode($payload);
$server = "x -oProxyCommand=echo\t$payloadb64|base64\t-d|sh}";
@imap_open('{'.$server.':143/imap}INBOX', '', '');
// clean
imap_errors();
var_dump(file_exists(__DIR__ . '/__bug'));
?>
--EXPECT--
bool(false)
--CLEAN--
<?php
define('__DIR__', realpath(dirname(__FILE__)));
if(file_exists(__DIR__ . '/__bug')) unlink(__DIR__ . '/__bug');
?>
