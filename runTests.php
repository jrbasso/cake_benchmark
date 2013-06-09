<?php

function slug($string) {
	return str_replace(' ', '_', $string);
}
function runCake3($params) {
	$pid = exec("php -d \"xdebug.profiler_enable=1\" /var/www/cake_benchmark/cake3/App/Console/cake.php $params > /dev/null & echo \$!");
	sleep(1); // Xdebug takes a bit to generate the output file
	exec('mv /tmp/cachegrind.out.' . $pid . ' ' . escapeshellarg('/tmp/cachegrind.out.' . slug($params)));
}

runCake3('db simpleQuery');
