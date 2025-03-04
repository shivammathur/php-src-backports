--TEST--
GHSA-hrwm-9436-5mv3: pdo_pgsql quoter escaping error checks
--SKIPIF--
<?php
if (!extension_loaded('pdo_pgsql')) print 'skip not loaded';
require dirname(__FILE__) . '/config.inc';
require dirname(__FILE__) . '/../../../ext/pdo/tests/pdo_test.inc';
PDOTest::skip();
?>
--FILE--
<?php
require dirname(__FILE__) . '/config.inc';
require dirname(__FILE__) . '/../../../ext/pdo/tests/pdo_test.inc';
$db = PDOTest::factory();
$db->exec("SET client_encoding TO 'UTF8'");

/* Invalid UTF-8 byte: PQescapeStringConn() reports an error, so quote()
   must fail instead of returning an improperly escaped literal. */
var_dump($db->quote("abc\xff-def"));

/* Valid input still quotes as before. */
var_dump($db->quote("o'brien"));
?>
--EXPECT--
bool(false)
string(10) "'o''brien'"
