--TEST--
GHSA-7qpv-r5mr-78m4: SQL injection in ext/pgsql via E'...' backslash breakout
--CREDITS--
expatch.llc
--SKIPIF--
<?php include("skipif.inc"); ?>
--FILE--
<?php
error_reporting(E_ALL);

include 'config.inc';

$db = pg_connect($conn_str);

$payload = array('str' => "zzz\\' OR 1=1 --");

/* PQescapeStringConn() only doubles the single quote here, so wrapping the
   result in E'...' would let the backslash escape it and break out. */
pg_query($db, "SET standard_conforming_strings = 1");

var_dump(pg_convert($db, $table_name, $payload));
echo pg_insert($db, $table_name, $payload, PGSQL_DML_STRING), "\n";

/* With standard_conforming_strings off PQescapeStringConn() doubles the
   backslash as well, which a plain '...' literal then unescapes. */
pg_query($db, "SET standard_conforming_strings = 0");

var_dump(pg_convert($db, $table_name, $payload));
echo pg_insert($db, $table_name, $payload, PGSQL_DML_STRING), "\n";
?>
--EXPECT--
array(1) {
  [""str""]=>
  string(18) "'zzz\'' OR 1=1 --'"
}
INSERT INTO "php_pgsql_test" ("str") VALUES ('zzz\'' OR 1=1 --');
array(1) {
  [""str""]=>
  string(19) "'zzz\\'' OR 1=1 --'"
}
INSERT INTO "php_pgsql_test" ("str") VALUES ('zzz\\'' OR 1=1 --');
