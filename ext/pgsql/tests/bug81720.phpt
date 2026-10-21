--TEST--
Bug #81720 (Uninitialized array in pg_query_params() leading to RCE)
--SKIPIF--
<?php include("skipif.inc"); ?>
--FILE--
<?php
include('config.inc');

$conn = pg_connect($conn_str);
set_error_handler(function ($severity, $message) {
    throw new Exception($message);
});

try {
    pg_query_params($conn, 'SELECT $1, $2', array(1, new stdClass()));
} catch (Exception $ex) {
    echo $ex->getMessage(), PHP_EOL;
}

try {
    pg_send_prepare($conn, "my_query", 'SELECT $1, $2');
    pg_get_result($conn);
    pg_send_execute($conn, "my_query", array(1, new stdClass()));
} catch (Exception $ex) {
    echo $ex->getMessage(), PHP_EOL;
}
restore_error_handler();
?>
--EXPECT--
Object of class stdClass could not be converted to string
Object of class stdClass could not be converted to string