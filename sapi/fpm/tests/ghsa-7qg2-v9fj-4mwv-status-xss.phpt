--TEST--
FPM: Test status page
--SKIPIF--
<?php include "skipif.inc"; ?>
--FILE--
<?php

include "include.inc";

$logfile = dirname(__FILE__).'/php-fpm.log.tmp';
$port = 9000+PHP_INT_SIZE;

$cfg = <<<EOT
[global]
error_log = $logfile
[unconfined]
listen = 127.0.0.1:$port
pm.status_path = /status
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 3
EOT;

$fpm = run_fpm($cfg, $tail);
if (is_resource($fpm)) {
    fpm_display_log($tail, 2);
    try {

		$html = run_request('127.0.0.1', $port, '/<script>alert(1)</script>', '<script>alert(2)</script>');

		$html = run_request('127.0.0.1', $port, '/status', 'full&html');
		var_dump(strpos($html, 'text/html') && strpos($html, 'DOCTYPE') && strpos($html, 'PHP-FPM Status Page'));

		// output only if script present but not escaped
		if (strpos($html, 'alert') && strpos($html, '<script>')) {
			var_dump($html);
		}

		echo "IPv4 ok\n";
	} catch (Exception $e) {
		echo "IPv4 error\n";
	}

	proc_terminate($fpm);
    stream_get_contents($tail);
    fclose($tail);
    proc_close($fpm);
}

?>
--EXPECTF--
[%d-%s-%d %d:%d:%d] NOTICE: fpm is running, pid %d
[%d-%s-%d %d:%d:%d] NOTICE: ready to handle connections
bool(true)
IPv4 ok
--CLEAN--
<?php
    $logfile = dirname(__FILE__).'/php-fpm.log.tmp';
    @unlink($logfile);
?>
