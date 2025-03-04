--TEST--
GHSA-hrwm-9436-5mv3: pgsql escaping error checks
--SKIPIF--
<?php include("skipif.inc"); ?>
--FILE--
<?php
error_reporting(E_ALL);

include 'config.inc';

$db = pg_connect($conn_str);
pg_query($db, "SET client_encoding TO 'UTF8'");
@pg_query($db, $table_def);

/* Invalid UTF-8 byte: PQescapeStringConn() reports an error. */
$bad = "abc\xff-def";
/* Truncated UTF-8 character: PQescapeIdentifier() returns NULL. */
$trunc = "tbl\xc3";

var_dump(pg_escape_string($db, $bad));
var_dump(pg_convert($db, $table_name, array('str' => $bad)));
var_dump(pg_meta_data($db, $bad));
var_dump(pg_select($db, $trunc, array('num' => 1), PGSQL_DML_NO_CONV | PGSQL_DML_STRING));
var_dump(pg_insert($db, $trunc, array('num' => '1'), PGSQL_DML_NO_CONV | PGSQL_DML_STRING));
var_dump(pg_update($db, $trunc, array('num' => '1'), array('num' => '1'), PGSQL_DML_NO_CONV | PGSQL_DML_STRING));
var_dump(pg_delete($db, $trunc, array('num' => '1'), PGSQL_DML_NO_CONV | PGSQL_DML_STRING));

/* Valid input still escapes as before. */
var_dump(pg_escape_string($db, "o'brien"));
?>
--EXPECTF--
Warning: pg_escape_string(): Escaping string failed in %s on line %d
bool(false)

Notice: pg_convert(): String value escaping failed for PostgreSQL 'text' (str) in %s on line %d
bool(false)

Warning: pg_meta_data(): Escaping table name 'abc%c-def' failed in %s on line %d
bool(false)

Notice: pg_select(): Failed to escape table name 'tbl%c' in %s on line %d
bool(false)

Notice: pg_insert(): Failed to escape table name 'tbl%c' in %s on line %d
bool(false)

Notice: pg_update(): Failed to escape table name 'tbl%c' in %s on line %d
bool(false)

Notice: pg_delete(): Failed to escape table name 'tbl%c' in %s on line %d
bool(false)
string(8) "o''brien"
