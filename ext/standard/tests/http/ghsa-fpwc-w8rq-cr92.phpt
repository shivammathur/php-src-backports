--TEST--
GHSA-fpwc-w8rq-cr92: strip credentials from user headers on cross-origin redirect
--INI--
allow_url_fopen=1
--SKIPIF--
<?php require 'server.inc'; http_server_skipif("tcp://127.0.0.1:0"); ?>
--FILE--
<?php
require 'server.inc';

/* A hardcoded port that happens to be busy makes http_server() return false, and
 * on 5.3/5.4/5.5 %check ends with `exit $?`, so that failure takes the build with
 * it. Ask the kernel for a free port instead; SO_REUSEADDR makes the immediate
 * rebind safe. */
function pick_port() {
    $s = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$s) {
        return 0;
    }
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/* server.inc's http_server_kill() calls posix_kill($pid, SIGTERM) unguarded, and
 * http_server() returns false when the port is busy -- false becomes 0, and
 * posix_kill(0) signals the whole process group, killing the build instead of
 * failing this test. Never hand it anything but a real pid. */
function kill_server($pid) {
    if (is_int($pid) && $pid > 0) {
        http_server_kill($pid);
    }
}

function count_header($requests, $name) {
    return preg_match_all('/^' . preg_quote($name, '/') . ':/mi', $requests);
}

function report($label, $requests) {
    echo $label, "\n";
    foreach (['Authorization', 'Cookie', 'Proxy-Authorization', 'X-Custom'] as $name) {
        echo "  $name: ", count_header($requests, $name), "\n";
    }
}

$ctx = stream_context_create(['http' => [
    'header' => "Authorization: Bearer SECRET\r\n"
        . "Cookie: sid=abc\r\n"
        . "Proxy-Authorization: Basic Zm9vOmJhcg==\r\n"
        . "X-Custom: keep-me",
    'follow_location' => 1,
]]);

/* server B listens on a different port than server A, so the hop from A to B is
 * cross-origin; B then redirects to itself: credentials must stay withheld for
 * that same-origin hop too */
$captureB = null;
$portB = pick_port();
$uriB = "http://127.0.0.1:{$portB}";
$pidB = http_server("tcp://127.0.0.1:{$portB}", [
    "data://text/plain,HTTP/1.1 302 Found\r\nLocation: /second\r\nContent-Length: 0\r\n\r\n",
    "data://text/plain,HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK",
], $captureB);

$captureA = null;
$portA = pick_port();
$uriA = "http://127.0.0.1:{$portA}";
$pidA = http_server("tcp://127.0.0.1:{$portA}", [
    "data://text/plain,HTTP/1.1 302 Found\r\nLocation: $uriB/first\r\nContent-Length: 0\r\n\r\n",
], $captureA);

var_dump(file_get_contents($uriA . '/src', false, $ctx));

kill_server($pidA);
kill_server($pidB);

rewind($captureA);
rewind($captureB);
report('--- origin A (1 request) ---', stream_get_contents($captureA));
report('--- origin B (2 requests) ---', stream_get_contents($captureB));

/* same origin throughout: credentials must be sent on both hops */
$captureC = null;
$portC = pick_port();
$uriC = "http://127.0.0.1:{$portC}";
$pidC = http_server("tcp://127.0.0.1:{$portC}", [
    "data://text/plain,HTTP/1.1 302 Found\r\nLocation: /next\r\nContent-Length: 0\r\n\r\n",
    "data://text/plain,HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK",
], $captureC);

var_dump(file_get_contents($uriC . '/first', false, $ctx));

kill_server($pidC);

rewind($captureC);
report('--- origin C (2 requests) ---', stream_get_contents($captureC));

/* a stripped header that was last in the bag must not leave a trailing line break
 * behind, or the request body would be pushed out of the request */
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'content' => 'hello=world',
    'header' => "X-Custom: keep-me\r\nAuthorization: Bearer SECRET",
    'follow_location' => 1,
]]);

$captureH = null;
$portH = pick_port();
$uriH = "http://127.0.0.1:{$portH}";
$pidH = http_server("tcp://127.0.0.1:{$portH}", [
    "data://text/plain,HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK",
], $captureH);

$captureG = null;
$portG = pick_port();
$uriG = "http://127.0.0.1:{$portG}";
$pidG = http_server("tcp://127.0.0.1:{$portG}", [
    "data://text/plain,HTTP/1.1 307 Temporary Redirect\r\nLocation: $uriH/second\r\nContent-Length: 0\r\n\r\n",
], $captureG);

var_dump(@file_get_contents($uriG . '/first', false, $ctx));

kill_server($pidG);
kill_server($pidH);

rewind($captureH);
echo "--- credential header last in the bag (307) ---\n";
echo preg_replace('/^Host:.*$/m', 'Host: ...', stream_get_contents($captureH));

echo "--- malformed header bags ---\n";
foreach ([
    'folded value      ' => "Authorization:\r\n Bearer SECRET\r\nX-Custom: keep-me",
    'folded value (tab)' => "Authorization:\r\n\tBearer SECRET\r\nX-Custom: keep-me",
    'lone CR           ' => "X-Custom: keep-me\rAuthorization: Bearer SECRET",
    'space before colon' => "Authorization : Bearer SECRET\r\nX-Custom: keep-me",
    'tab before colon  ' => "Authorization\t: Bearer SECRET\r\nX-Custom: keep-me",
] as $label => $header) {
    $ctx = stream_context_create(['http' => ['header' => $header, 'follow_location' => 1]]);

    $captureF = null;
    $portF = pick_port();
    $uriF = "http://127.0.0.1:{$portF}";
    $pidF = http_server("tcp://127.0.0.1:{$portF}", [
        "data://text/plain,HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK",
    ], $captureF);

    $captureE = null;
    $portE = pick_port();
    $uriE = "http://127.0.0.1:{$portE}";
    $pidE = http_server("tcp://127.0.0.1:{$portE}", [
        "data://text/plain,HTTP/1.1 302 Found\r\nLocation: $uriF/second\r\nContent-Length: 0\r\n\r\n",
    ], $captureE);

    file_get_contents($uriE . '/first', false, $ctx);

    kill_server($pidE);
    kill_server($pidF);

    rewind($captureF);
    $request = stream_get_contents($captureF);
    printf("  %s  SECRET leaked: %d, X-Custom kept: %d\n", $label,
        strpos($request, 'SECRET') !== false, strpos($request, 'X-Custom') !== false);
}
?>
--EXPECT--
string(2) "OK"
--- origin A (1 request) ---
  Authorization: 1
  Cookie: 1
  Proxy-Authorization: 1
  X-Custom: 1
--- origin B (2 requests) ---
  Authorization: 0
  Cookie: 0
  Proxy-Authorization: 0
  X-Custom: 2
string(2) "OK"
--- origin C (2 requests) ---
  Authorization: 2
  Cookie: 2
  Proxy-Authorization: 2
  X-Custom: 2
string(2) "OK"
--- credential header last in the bag (307) ---
GET /second HTTP/1.0
Host: ...
X-Custom: keep-me

--- malformed header bags ---
  folded value        SECRET leaked: 0, X-Custom kept: 1
  folded value (tab)  SECRET leaked: 0, X-Custom kept: 1
  lone CR             SECRET leaked: 0, X-Custom kept: 1
  space before colon  SECRET leaked: 0, X-Custom kept: 1
  tab before colon    SECRET leaked: 0, X-Custom kept: 1
