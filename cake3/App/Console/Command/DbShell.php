<?php

namespace App\Console\Command;

use Cake\Database\Query;
use Cake\Model\ConnectionManager;

class DbShell extends AppShell {

	public function simpleQuery() {
		$mysql = ConnectionManager::getDataSource('default');
		$query = new Query($mysql);
		$query->select(['id'])->from('articles')->where(['reads >' => 0]);
		echo $query;
	}

}

