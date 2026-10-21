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
$payload = "echo 'BUG'> " . dirname(__FILE__) . '/__bug';
$payloadb64 = base64_encode($payload);
$server = "x -oProxyCommand=echo\t$payloadb64|base64\t-d|sh}";
@imap_open('{'.$server.':143/imap}INBOX', '', '');
// clean
imap_errors();
var_dump(file_exists(dirname(__FILE__) . '/__bug'));
?>
--EXPECT--
bool(false)
--CLEAN--
<?php
if(file_exists(dirname(__FILE__) . '/__bug')) unlink(dirname(__FILE__) . '/__bug');
?>
