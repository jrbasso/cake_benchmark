<?php

function slug($string) {
	return str_replace(' ', '_', $string);
}
function runCake3($params) {
	$pid = exec("/var/www/cake_benchmark/cake3/App/Console/cake $params > /dev/null & echo \$!");
	exec('mv /tmp/cachegrind.out.' . $pid . ' ' . escapeshellarg('/tmp/cachegrind.out.' . slug($params)));
}

runCake3('db simpleQuery');
