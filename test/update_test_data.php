<?php

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../src/Common/all.php");

$from = $argv[1];
if ($argc>2)
{
	$to = $argv[2];
}
else
{
	$to = ".";
}

list($stdout, $stderr) = exec2("cp -f {$from} ".get_path("test_data_folder")."{$to}");
?>