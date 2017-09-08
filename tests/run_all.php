<?php
$files = scandir(__DIR__);
if ($files === false || count($files) < 4) {
	echo "No tests found!\n";
	exit(127);
}

$me = basename(__FILE__);
$executedScripts = false;
foreach ($files as $f) {
	if ($f[0] == '.' || $f == $me) continue;
	if (preg_match("/^.*\\.php$/", $f)) {
		$executedScripts = true;

		echo "Running '$f':\n";

		$out = array();
		$retCode = 0;
		exec("php \"$f\"", $out, $retCode);

		foreach ($out as $o) echo $o . "\n";
		if ($retCode !== 0) exit($retCode);
		echo "\n$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$\n\n";
	}
}

if (!$executedScripts) {
	echo "No tests were executed due to name mismatch!\n";
	exit(-1);
}

exit(0);
?>
