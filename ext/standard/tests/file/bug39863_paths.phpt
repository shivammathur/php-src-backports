--TEST--
Bug #39863: Incorrect handling of paths with \0
--INI--
error_reporting=0
include_path=.:/usr/share/pear:/usr/share/php
--FILE--
<?php
$dir = dirname(__FILE__);
$file = basename(__FILE__);

echo "-- error_log --\n";
echo "test.log: ";
var_dump(error_log("test message", 3, "$dir/test.log"));
echo @file_get_contents("$dir/test.log")."\n";
unlink("$dir/test.log");
echo "test.log\\0extra: ";
var_dump(error_log("test message", 3, "$dir/test.log\0extra"));
echo @file_get_contents("$dir/test.log")."\n";
@unlink("$dir/test.log");

echo "-- highlight_file --\n";
echo "$file: ";
echo gettype(highlight_file(__FILE__, true))."\n";
echo "$file\\0extra: ";
echo gettype(highlight_file(__FILE__."\0extra", true))."\n";

echo "-- php_strip_whitespace --\n";
echo "$file: ";
var_dump(strlen(php_strip_whitespace(__FILE__)) != 0);
echo "$file\\0extra: ";
var_dump(strlen(php_strip_whitespace(__FILE__."\0extra")) != 0);

echo "-- set_include_path --\n";
$oldpath = set_include_path("");
echo "/path/to/php: ";
var_dump(set_include_path("/path/to/php"));
var_dump(get_include_path());
set_include_path("");
echo "/other/path/to/php\\0extra: ";
var_dump(set_include_path("/other/path/to/php\0extra"));
var_dump(get_include_path());
set_include_path($oldpath);

# no check for is_uploaded_file

# no check for move_uploaded_file

echo "-- parse_ini_file --\n";
file_put_contents("$dir/test.ini", "foo = bar\n");
echo "test.ini: ";
var_dump(parse_ini_file("$dir/test.ini"));
echo "test.ini\\0extra: ";
var_dump(parse_ini_file("$dir/test.ini\0extra"));
unlink("$dir/test.ini");

echo "-- chdir --\n";
mkdir("$dir/testdir");
$cwd = getcwd();
echo "testdir: ";
var_dump(chdir("$dir/testdir"));
$cwd1 = getcwd();
chdir($cwd);
echo "testdir\\0extra: ";
var_dump(chdir("$dir/testdir\0extra"));
$cwd2 = getcwd();
chdir($cwd);
echo "same dir: ";
var_dump($cwd1 == $cwd2);
rmdir("$dir/testdir");

echo "-- glob --\n";
mkdir("$dir/testdir");
$cwd = getcwd();
chdir("$dir/testdir");
touch("testfile");
echo "testf*: ";
var_dump(glob("testf*"));
echo "testf*\\0extra: ";
var_dump(glob("testf*\0extra"));
chdir($cwd);
unlink("$dir/testdir/testfile");
rmdir("$dir/testdir");

echo "-- scandir --\n";
mkdir("$dir/testdir");
touch("$dir/testdir/testfile");
echo "testdir: ";
var_dump(scandir("$dir/testdir"));
echo "testdir\\0extra: ";
var_dump(scandir("$dir/testdir\0extra"));
unlink("$dir/testdir/testfile");
rmdir("$dir/testdir");

echo "-- get_meta_tags --\n";
file_put_contents("$dir/test.html", "<meta name=\"keywords\" content=\"foobar\">\n");
echo "test.html: ";
var_dump(get_meta_tags("$dir/test.html"));
echo "test.html\\0extra: ";
var_dump(get_meta_tags("$dir/test.html\0extra"));
unlink("$dir/test.html");

echo "-- file_get_contents --\n";
file_put_contents("$dir/test.txt", "here be foobars");
echo "test.txt: ";
var_dump(file_get_contents("$dir/test.txt"));
echo "test.txt\\0extra: ";
var_dump(file_get_contents("$dir/test.txt\0extra"));
unlink("$dir/test.txt");

echo "-- file_put_contents --\n";
echo "test.txt: ";
var_dump(file_put_contents("$dir/test.txt", "here be foobars"));
unlink("$dir/test.txt");
echo "test.txt\\0extra: ";
var_dump(file_put_contents("$dir/test.txt\0extra", "here be foobars"));
@unlink("$dir/test.txt");

echo "-- file --\n";
file_put_contents("$dir/test.txt", "here be foobars");
echo "test.txt: ";
var_dump(file("$dir/test.txt"));
echo "test.txt\\0extra: ";
var_dump(file("$dir/test.txt\0extra"));
unlink("$dir/test.txt");

echo "-- tempnam --\n";
mkdir("$dir/testdir");
echo "testdir/prefix: ";
$tmp = tempnam("$dir/testdir", "prefix");
echo gettype($tmp)."\n";
if ($tmp) unlink($tmp);
echo "testdir\\0extra/prefix: ";
$tmp = tempnam("$dir/testdir\0extra", "prefix");
echo gettype($tmp)."\n";
if ($tmp) unlink($tmp);
echo "testdir/prefix\\0extra: ";
$tmp = tempnam("$dir/testdir", "prefix\0extra");
echo gettype($tmp)."\n";
if ($tmp) unlink($tmp);
rmdir("$dir/testdir");

echo "-- fopen --\n";
file_put_contents("$dir/test.txt", "here be foobars\n");
echo "test.txt: ";
$fh = fopen("$dir/test.txt", "r");
echo gettype($fh)."\n";
if ($fh) { echo fgets($fh); fclose($fh); }
echo "$file\\0extra: ";
$fh = fopen("$dir/test.txt\0extra", "r");
echo gettype($fh)."\n";
if ($fh) { echo fgets($fh); fclose($fh); }
unlink("$dir/test.txt");

echo "-- mkdir --\n";
echo "testdir: ";
var_dump(mkdir("$dir/testdir"));
rmdir("$dir/testdir");
echo "testdir\\0extra: ";
var_dump(mkdir("$dir/testdir\0extra"));
@rmdir("$dir/testdir");

echo "-- rmdir --\n";
echo "testdir: ";
mkdir("$dir/testdir");
var_dump(rmdir("$dir/testdir"));
echo " exists? "; var_dump(file_exists("$dir/testdir"));
@rmdir("$dir/testdir");
echo "testdir\\0extra: ";
mkdir("$dir/testdir");
var_dump(rmdir("$dir/testdir\0extra"));
echo " exists? "; var_dump(file_exists("$dir/testdir"));
@rmdir("$dir/testdir");

echo "-- readfile --\n";
file_put_contents("$dir/test.txt", "here be foobars ");
echo "test.txt: ";
var_dump(readfile("$dir/test.txt"));
echo "test.txt\\0extra: ";
var_dump(readfile("$dir/test.txt\0extra"));
unlink("$dir/test.txt");

echo "-- rename --\n";
mkdir("$dir/testdir");
$cwd = getcwd();
chdir("$dir/testdir");
echo "src -> dst: ";
touch("src");
var_dump(rename("src", "dst"));
echo " src exists? "; var_dump(file_exists("src"));
echo " dst exists? "; var_dump(file_exists("dst"));
@unlink("src"); @unlink("dst");
echo "src\\0extra -> dst: ";
touch("src");
var_dump(rename("src\0extra", "dst"));
echo " src exists? "; var_dump(file_exists("src"));
echo " dst exists? "; var_dump(file_exists("dst"));
@unlink("src"); @unlink("dst");
echo "src -> dst\\0extra: ";
touch("src");
var_dump(rename("src", "dst\0extra"));
echo " src exists? "; var_dump(file_exists("src"));
echo " dst exists? "; var_dump(file_exists("dst"));
@unlink("src"); @unlink("dst");
chdir($cwd);
rmdir("$dir/testdir");

echo "-- unlink --\n";
echo "testfile: ";
touch("$dir/testfile");
var_dump(unlink("$dir/testfile"));
echo " exists? "; var_dump(file_exists("$dir/testfile"));
@unlink("$dir/testfile");
echo "testfile\\0extra: ";
touch("$dir/testfile");
var_dump(unlink("$dir/testfile\0extra"));
echo " exists? "; var_dump(file_exists("$dir/testfile"));
@unlink("$dir/testfile");

echo "-- copy --\n";
mkdir("$dir/testdir");
$cwd = getcwd();
chdir("$dir/testdir");
echo "src -> dst: ";
touch("src");
var_dump(copy("src", "dst"));
echo " src exists? "; var_dump(file_exists("src"));
echo " dst exists? "; var_dump(file_exists("dst"));
@unlink("src"); @unlink("dst");
echo "src\\0extra -> dst: ";
touch("src");
var_dump(copy("src\0extra", "dst"));
echo " src exists? "; var_dump(file_exists("src"));
echo " dst exists? "; var_dump(file_exists("dst"));
@unlink("src"); @unlink("dst");
echo "src -> dst\\0extra: ";
touch("src");
var_dump(copy("src", "dst\0extra"));
echo " src exists? "; var_dump(file_exists("src"));
echo " dst exists? "; var_dump(file_exists("dst"));
@unlink("src"); @unlink("dst");
chdir($cwd);
rmdir("$dir/testdir");

echo "-- realpath --\n";
echo "/tmp/../etc/.././tmp///./: ";
var_dump(realpath("/tmp/../etc/.././tmp///./"));
echo "/tmp\\0extra/../etc/.././tmp///./: ";
var_dump(realpath("/tmp\0extra/../etc/.././tmp///./"));

echo "-- fnmatch --\n";
echo "testf*/testfile: ";
var_dump(fnmatch("testf*", "testfile"));
echo "nomatch*/testfile: ";
var_dump(fnmatch("nomatch*", "testfile"));
echo "testf*\\0extra/testfile: ";
var_dump(fnmatch("testf*\0extra", "testfile"));
echo "testf*/testfile\\0extra: ";
var_dump(fnmatch("testf*", "testfile\0extra"));

echo "-- disk_free_space --\n";
echo "dir: ";
echo gettype(disk_free_space("$dir"))."\n";
echo "dir\\0extra: ";
echo gettype(disk_free_space("$dir\0extra"))."\n";

# no check for chgrp

# no check for chown

echo "-- chmod --\n";
touch("$dir/testfile");
echo "testfile: ";
chmod("$dir/testfile", 0600);
echo substr(sprintf('%o', fileperms("$dir/testfile")), -4)." ";
var_dump(chmod("$dir/testfile", 0641));
clearstatcache();
echo " new perms: ".substr(sprintf('%o', fileperms("$dir/testfile")), -4)."\n";
echo "testfile\\0extra: ";
chmod("$dir/testfile", 0600);
echo substr(sprintf('%o', fileperms("$dir/testfile")), -4)." ";
var_dump(chmod("$dir/testfile\0extra", 0641));
clearstatcache();
echo " new perms: ".substr(sprintf('%o', fileperms("$dir/testfile")), -4)."\n";
unlink("$dir/testfile");

echo "-- touch --\n";
echo "testfile: ";
var_dump(touch("$dir/testfile"));
echo " exists? "; var_dump(file_exists("$dir/testfile"));
@unlink("$dir/testfile");
echo "testfile\\0extra: ";
var_dump(touch("$dir/testfile\0extra"));
echo " exists? "; var_dump(file_exists("$dir/testfile"));
@unlink("$dir/testfile");

echo "-- stat --\n";
echo "$file: ";
echo gettype(stat(__FILE__))."\n";
echo "$file\\0extra: ";
echo gettype(stat(__FILE__."\0extra"))."\n";

# explicit test for file_exists() mentioned in bug #39863
echo "-- file_exists/stat --\n";
echo "$file: ";
var_dump(file_exists(__FILE__));
echo "$file\\0extra: ";
var_dump(file_exists(__FILE__."\0extra"));

echo "-- readlink --\n";
symlink($file, "$dir/testlink");
echo "testlink: ";
var_dump(readlink("$dir/testlink"));
echo "testlink\\0extra: ";
var_dump(readlink("$dir/testlink\0extra"));
unlink("$dir/testlink");

echo "-- symlink --\n";
mkdir("$dir/testdir");
$cwd = getcwd();
chdir("$dir/testdir");
touch("target");
echo "link -> target: ";
var_dump(symlink("target", "link"));
echo " link exists? "; var_dump(file_exists("link"));
echo " link target: "; var_dump(@readlink("link"));
@unlink("link");
echo "link\\0extra -> target: ";
var_dump(symlink("target", "link\0extra"));
echo " link exists? "; var_dump(file_exists("link"));
echo " link target: "; var_dump(@readlink("link"));
@unlink("link");
echo "link -> target\\0extra: ";
var_dump(symlink("target\0extra", "link"));
echo " link exists? "; var_dump(file_exists("link"));
echo " link target: "; var_dump(@readlink("link"));
@unlink("link");
@unlink("target");
chdir($cwd);
rmdir("$dir/testdir");

echo "-- link --\n";
mkdir("$dir/testdir");
$cwd = getcwd();
chdir("$dir/testdir");
touch("target");
echo "link -> target: ";
var_dump(link("target", "link"));
echo " link exists? "; var_dump(file_exists("link"));
@unlink("link");
echo "link\\0extra -> target: ";
var_dump(link("target", "link\0extra"));
echo " link exists? "; var_dump(file_exists("link"));
@unlink("link");
echo "link -> target\\0extra: ";
var_dump(link("target\0extra", "link"));
echo " link exists? "; var_dump(file_exists("link"));
@unlink("link");
@unlink("target");
chdir($cwd);
rmdir("$dir/testdir");
?>
Done
--EXPECTF--
-- error_log --
test.log: bool(true)
test message
test.log\0extra: bool(false)

-- highlight_file --
bug39863_paths.php: string
bug39863_paths.php\0extra: boolean
-- php_strip_whitespace --
bug39863_paths.php: bool(true)
bug39863_paths.php\0extra: bool(false)
-- set_include_path --
/path/to/php: string(32) ".:/usr/share/pear:/usr/share/php"
string(12) "/path/to/php"
/other/path/to/php\0extra: bool(false)
string(12) "/path/to/php"
-- parse_ini_file --
test.ini: array(1) {
  ["foo"]=>
  string(3) "bar"
}
test.ini\0extra: bool(false)
-- chdir --
testdir: bool(true)
testdir\0extra: bool(false)
same dir: bool(false)
-- glob --
testf*: array(1) {
  [0]=>
  string(8) "testfile"
}
testf*\0extra: bool(false)
-- scandir --
testdir: array(3) {
  [0]=>
  string(1) "."
  [1]=>
  string(2) ".."
  [2]=>
  string(8) "testfile"
}
testdir\0extra: bool(false)
-- get_meta_tags --
test.html: array(1) {
  ["keywords"]=>
  string(6) "foobar"
}
test.html\0extra: bool(false)
-- file_get_contents --
test.txt: string(15) "here be foobars"
test.txt\0extra: bool(false)
-- file_put_contents --
test.txt: int(15)
test.txt\0extra: bool(false)
-- file --
test.txt: array(1) {
  [0]=>
  string(15) "here be foobars"
}
test.txt\0extra: bool(false)
-- tempnam --
testdir/prefix: string
testdir\0extra/prefix: boolean
testdir/prefix\0extra: boolean
-- fopen --
test.txt: resource
here be foobars
bug39863_paths.php\0extra: boolean
-- mkdir --
testdir: bool(true)
testdir\0extra: bool(false)
-- rmdir --
testdir: bool(true)
 exists? bool(false)
testdir\0extra: bool(false)
 exists? bool(true)
-- readfile --
test.txt: here be foobars int(16)
test.txt\0extra: bool(false)
-- rename --
src -> dst: bool(true)
 src exists? bool(false)
 dst exists? bool(true)
src\0extra -> dst: bool(false)
 src exists? bool(true)
 dst exists? bool(false)
src -> dst\0extra: bool(false)
 src exists? bool(true)
 dst exists? bool(false)
-- unlink --
testfile: bool(true)
 exists? bool(false)
testfile\0extra: bool(false)
 exists? bool(true)
-- copy --
src -> dst: bool(true)
 src exists? bool(true)
 dst exists? bool(true)
src\0extra -> dst: bool(false)
 src exists? bool(true)
 dst exists? bool(false)
src -> dst\0extra: bool(false)
 src exists? bool(true)
 dst exists? bool(false)
-- realpath --
/tmp/../etc/.././tmp///./: string(4) "/tmp"
/tmp\0extra/../etc/.././tmp///./: bool(false)
-- fnmatch --
testf*/testfile: bool(true)
nomatch*/testfile: bool(false)
testf*\0extra/testfile: bool(false)
testf*/testfile\0extra: bool(false)
-- disk_free_space --
dir: double
dir\0extra: boolean
-- chmod --
testfile: 0600 bool(true)
 new perms: 0641
testfile\0extra: 0641 bool(false)
 new perms: 0600
-- touch --
testfile: bool(true)
 exists? bool(true)
testfile\0extra: bool(false)
 exists? bool(false)
-- stat --
bug39863_paths.php: array
bug39863_paths.php\0extra: boolean
-- file_exists/stat --
bug39863_paths.php: bool(true)
bug39863_paths.php\0extra: bool(false)
-- readlink --
testlink: string(12) "bug39863_paths.php"
testlink\0extra: bool(false)
-- symlink --
link -> target: bool(true)
 link exists? bool(true)
 link target: string(6) "target"
link\0extra -> target: bool(false)
 link exists? bool(false)
 link target: bool(false)
link -> target\0extra: bool(false)
 link exists? bool(false)
 link target: bool(false)
-- link --
link -> target: bool(true)
 link exists? bool(true)
link\0extra -> target: bool(false)
 link exists? bool(false)
link -> target\0extra: bool(false)
 link exists? bool(false)
Done
