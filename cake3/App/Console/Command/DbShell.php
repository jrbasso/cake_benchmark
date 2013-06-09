<?php

namespace App\Console\Command;

use Cake\Database\Query;
use Cake\Model\ConnectionManager;

class DbShell extends AppShell {

	public function simpleQuery() {
		$mysql = ConnectionManager::getDataSource('default');
		$query = new Query($mysql);
		$query->select(['category_id', 'name'])->from('category')->limit(20);
		$query->execute();
		foreach ($query as $row) {
			$this->out("{$row['category_id']} - {$row['name']}");
		}
	}

}

