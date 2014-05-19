<?php
namespace Asgard\Orm;

class MigrationsManager {
	protected $app;

	public function __construct($app) {
		$this->app = $app;
	}

	public function diff($filename='Diff', $verbose=false) {
		$tracking = $this->readTracking();
		foreach($this->readList() as $time=>$migration) {
			if(!isset($tracking[$migration]) || $tracking[$migration]!=='up')
				return false;
		}

		list($up, $down) = $this->_diff();
		return $this->createMigration($up, $down, $filename, $verbose);
	}

	protected function _diff() {
		$bundles = $this->app['bundlesmanager']->getBundlesPath();
		
		foreach($bundles as $bundle) {
			foreach(glob($bundle.'/Entities/*.php') as $entity)
				include $entity;
		}

		$newSchemas = array();
		$oldSchemas = array();
		$tables = $this->app['db']->query('SHOW TABLES')->all();
		foreach($tables as $k=>$v) {
			$table = array_values($v)[0];
			$oldSchemas[$table] = $this->tableSchema($table);
		}


		$entityClasses = array();
		foreach(get_declared_classes() as $class) {
			if(is_subclass_of($class, 'Asgard\Entity\Entity')) {
				if($class == 'Asgard\Entity\Entity')
					continue;
				$reflection = new \ReflectionClass($class);
				if($reflection->isAbstract())
					continue;
				$entityClasses[] = $class;
			}
		}

		foreach($entityClasses as $class) {
			$schema = array();
			
			foreach($class::getDefinition()->properties() as $name=>$prop) {
				if(!$prop->orm)
					$neworm = array();
				else
					$neworm = $prop->orm;
				if(!isset($prop->orm['type'])) {
					if(method_exists($prop, 'getSQLType'))
						$neworm['type'] = $prop->getSQLType();
					else {
						switch($prop->type) {
							default:
								throw new \Exception('Cannot convert '.$prop->type.' type');
						}
					}
				}

				if(!isset($prop->orm['default']))
					$neworm['default'] = false;
				if(!isset($prop->orm['nullable']))
					$neworm['nullable'] = true;
				if(!isset($prop->orm['key']))
					$neworm['key'] = '';
				if(!isset($prop->orm['auto_increment']))
					$neworm['auto_increment'] = false;
				$neworm['position'] = $prop->params['position'];

				if($prop->i18n) {
					if(!isset($newSchemas[$class::getTable().'_translation'])) {
						$newSchemas[$class::getTable().'_translation'] = array(
							'id' => array(
								'type'	=>	'int(11)',
								'nullable'	=>	false,
								'auto_increment'	=>	false,
								'default'	=>	null,
								'key'	=>	null,
							),
							'locale' => array(
								'type'	=>	'varchar(50)',
								'nullable'	=>	false,
								'auto_increment'	=>	false,
								'default'	=>	null,
								'key'	=>	null,
							),
						);
					}
					$newSchemas[$class::getTable().'_translation'][$prop->getName()] = $neworm;
				}
				else
					$schema[$name] = $prop->orm = $neworm;
			}

			foreach($class::getDefinition()->relations as $name=>$rel) {
				if($rel['type'] == 'HMABT') {
					$table_name = $rel['join_table'];
					if(!isset($newSchemas[$table_name])) {
						$arr = array(
							$rel['link_a']	=>	array(
								'type'	=>	'int(11)',
								'nullable'	=>	false,
								'auto_increment'	=>	false,
								'default'	=>	null,
								'key'	=>	null,
							),
							$rel['link_b']	=>	array(
								'type'	=>	'int(11)',
								'nullable'	=>	false,
								'auto_increment'	=>	false,
								'default'	=>	null,
								'key'	=>	null,
							),
						);
						$newSchemas[$table_name] = $arr;
					}
					if($rel['sortable'])
						$newSchemas[$table_name][$rel['sortable']] = array(
							'type'	=>	'int(11)',
							'nullable'	=>	false,
							'auto_increment'	=>	false,
							'default'	=>	null,
							'key'	=>	null,
						);
				}
			}

			uasort($schema, function($a, $b) {
				if(!isset($a['position']))
					return -1;
				if(!isset($b['position']))
					return 1;
				if($a['position'] < $b['position'])
					return -1;
				return 1;
			});

			$i = 0;
			foreach($schema as $k=>$col)
				$schema[$k]['position'] = $i++;

			$newSchemas[$class::getTable()] = $schema;
		}
		
		$oldSchemas = array_filter($oldSchemas);

		$up = $this->diffBetween($newSchemas, $oldSchemas);
		$down = $this->diffBetween($oldSchemas, $newSchemas, true);

		return array($up, $down);
	}

	protected function diffBetween(array $newSchemas, array $oldSchemas, $down=false) {
		$migrations = array();
		foreach($newSchemas as $class=>$schema) {
			$table = $class;
			if(!in_array($class, array_keys($oldSchemas))) {
				if(!$down)
					$migrations[] = $this->buildTableFor($class, $newSchemas[$class]);
				continue;
			}
			$tableSchema = $oldSchemas[$class];
			$schema = $newSchemas[$class];
			$oldcols = array_diff(array_keys($tableSchema), array_keys($schema));
			$newcols = array_diff(array_keys($schema), array_keys($tableSchema));
			$colsmigration = '';
			foreach(array_keys($schema) as $k=>$col) {
				if(!in_array($col, array_keys($tableSchema)))
					$colsmigration .=  $this->buildColumnFor($table, $col, $schema[$col]);
				else {
					$diff = array_diff_assoc($schema[$col], $tableSchema[$col]);
					if(isset($diff['position'])) {
						if($k === 0)
							$diff['after'] = false;
						else
							$diff['after'] = array_keys($schema)[$k-1];
						unset($diff['position']);
					}
					if($diff)
						$colsmigration .=  $this->updateColumn($table, $col, $diff);
				}
			}
			foreach(array_keys($tableSchema) as $col) {
				if(!in_array($col, array_keys($schema)))
					$colsmigration .=  $this->dropColumn($col);
			}
			if($colsmigration)
				$migrations[] = "\Asgard\Core\App::get('schema')->table('$table', function(\$table) {".$colsmigration."\n});";
		}
		return $migrations;
	}

	public function addMigrationFile($migration) {
		$filename = \Asgard\Utils\FileManager::move($migration, _DIR_.'migrations/'.basename($migration));

		$list = $this->readList();
		$list[time()] = explode('.', $filename)[0];
		$this->writeList($list);
	}

	public function migrateFile($file) {
		$before = array_merge(get_declared_classes(), get_declared_interfaces());
		include $file;
		$after = array_merge(get_declared_classes(), get_declared_interfaces());
		$class = array_values(array_diff($after, $before));
		// d($class);
		foreach($class as $_class) {
			$reflection = new \ReflectionClass($_class);
			if($reflection->isAbstract())
				continue;
			$migration = new $_class($this->app);
			$migration->up();
		}
		// d(array_diff($after, $before));
	}

	public function migrate($migration, $verbose=false, $force=false) {
		if(!file_exists(_DIR_.'migrations/'.$migration.'.php')) {
			echo $migration.' does not exist.';
			return false;
		}
		if(!$force && $this->isUp($migration)) {
			if($verbose)
				echo $migration.' has already been migrated.';
			return false;
		}

		$this->app['db']->beginTransaction();
		$class = $migration;
		include_once _DIR_.'migrations/'.$migration.'.php';
		$class::up();
		if($verbose)
			echo 'Running '.$class."\n";
		$this->app['db']->commit();

		if(!in_array($migration, $this->readList())) {
			$list = $this->readList();
			$list[time()] = $migration;
			$this->writeList($list);
		}

		$tracking = $this->readTracking();
		$tracking[$migration] = 'up';
		$this->writeTracking($tracking);
		return true;
	}

	public function isUp($migration) {
		$tracking = $this->readTracking();
		return isset($tracking[$migration]) && $tracking[$migration] == 'up';
	}

	public function isDown($migration) {
		$tracking = $this->readTracking();
		return !isset($tracking[$migration]) || $tracking[$migration] == 'down';
	}

	public function migrateNext($verbose=false) {
		foreach($this->readList() as $migration=>$status) {
			if($this->isDown($migration)) {
				$this->migrate($migration, $verbose);
				break;
			}
		}
	}

	public function unmigrateLast($verbose=false) {
		$json = $this->readList();
		for($i=count($json)-1; $i>=0; $i--) {
			$migration = array_values($json)[$i];
			if($this->isUp($migration)) {
				$this->unmigrate($migration, $verbose);
				break;
			}
		}
	}

	public function unmigrate($migration, $verbose=false) {
		if(!file_exists(_DIR_.'migrations/'.$migration.'.php')) {
			echo $migration.' does not exist.';
			return false;
		}
		if($this->isDown($migration)) {
			if($verbose)
				echo $migration.' has not been migrated yet.';
			return false;
		}

		$this->app['db']->beginTransaction();
		$class = $migration;
		include(_DIR_.'migrations/'.$migration.'.php');
		$class::down();
		if($verbose)
			echo 'Running '.$class."\n";
		$this->app['db']->commit();

		$tracking = $this->readTracking();
		$tracking[$migration] = 'down';
		$this->writeTracking($tracking);
		return true;
	}

	public function migrateAll($downOnly=true) {
		foreach($this->readList() as $timestamp=>$migration) {
			if(!$downOnly || $this->isDown($migration))
				$this->migrate($migration, false, true);
		}
	}

	protected function readList() {
		if(!file_exists(_DIR_.'migrations/list.json'))
			return array();
		$json = json_decode(file_get_contents(_DIR_.'migrations/list.json'), true);
		ksort($json);
		return $json;
	}

	protected function writeList(array $arr) {
		\Asgard\Utils\FileManager::put(_DIR_.'migrations/list.json', json_encode($arr, JSON_PRETTY_PRINT));
	}

	protected function readTracking() {
		if(!file_exists(_DIR_.'migrations/tracking.json'))
			return array();
		return json_decode(file_get_contents(_DIR_.'migrations/tracking.json'), true);
	}

	protected function writeTracking(array $arr) {
		\Asgard\Utils\FileManager::put(_DIR_.'migrations/tracking.json', json_encode($arr, JSON_PRETTY_PRINT));
	}

	protected function createMigration($up, $down, $filename='Diff', $verbose=false) {
		if(!$up)
			return;
		if(!is_array($up))
			$up = array($up);
		foreach($up as $k=>$v)
			$up[$k] = $this->tabs($v, 2);
		if(!is_array($down))
			$down = array($down);
		foreach($down as $k=>$v)
			$down[$k] = $this->tabs($v, 2);

		$dst = 'migrations/'.$filename.'.php';
		$dst = \Asgard\Utils\FileManager::getNewFilename($dst);
		$filename = str_replace('.php', '', basename($dst));
			
		$migration = '<?php
class '.$filename.' {
	public function up() {
		'.implode("\n\n\t\t", $up).'
	}
	
	public function down() {
		'.implode("\n\n\t\t", $down)."
	}
}";
		\Asgard\Utils\FileManager::put($dst, $migration);

		if($verbose)
			echo 'New migration: '.$dst;

		$list = $this->readList();
		$list[time()] = $filename;
		$this->writeList($list);

		return $filename;
	}
	
	protected function tabs($str, $tabs) {
		return str_replace("\n", "\n".str_repeat("\t", $tabs), $str);
	}
	
	protected function dropColumn($col) {
		$migration = "\n\t\$table->drop('$col');";
		return $migration;
	}
	
	protected function updateColumn($table, $col, array $diff) {
		$migration = "\n\t\$table->col('$col')";
		if(isset($diff['type']))
			$migration .= "\n		->type('$diff[type]')";
		if(isset($diff['after'])) {
			if($diff['after'] === false)
				$migration .= "\n		->first()";
			else
				$migration .= "\n		->after('$diff[after]')";
		}
		if(isset($diff['nullable'])) {
			if($diff['nullable'])
				$migration .= "\n		->nullable()";
			else
				$migration .= "\n		->NotNullable()";
		}
		if(isset($diff['auto_increment'])) {
			if($diff['auto_increment'])
				$migration .= "\n		->autoincrement()";
			else
				$migration .= "\n		->notAutoincrement()";
		}
		if(isset($diff['default'])) {
			if($diff['default'] === false)
				$migration .= "\n		->def(false)";
			else
				$migration .= "\n		->def('$diff[default]')";
		}
		if(isset($diff['key'])) {
			if($diff['key']=='PRI')
				$migration .= "\n		->primary()";
			elseif($diff['key']=='UNI')
				$migration .= "\n		->unique()";
			elseif($diff['key']=='MUL')
				$migration .= "\n		->index()";
			else
				$migration .= "\n		->dropIndex()";
		}
		$migration .= ";";
		
		return $migration;
	}
	
	protected function buildColumnFor($table, $col, array $definition) {
		$migration = '';
		$migration = "\n\t\$table->add('$col', '$definition[type]')";
		if($definition['nullable'])
			$migration .= "\n		->nullable()";
		if($definition['auto_increment'])
			$migration .= "\n		->autoincrement()";
		if($definition['default'])
			$migration .= "\n		->def('$definition[default]')";
		if($definition['key']=='PRI')
			$migration .= "\n		->primary()";
		if($definition['key']=='UNI')
			$migration .= "\n		->unique()";
		if($definition['key']=='MUL')
			$migration .= "\n		->index()";
		$migration .= ";";
		
		return $migration;
	}
	
	protected function buildTableFor($class, array $cols) {
		$table = $class;
		
		$migration = "\Asgard\Core\App::get('schema')->create('$table', function(".'$table'.") {";
		foreach($cols as $col=>$col_definition)
			$migration .= "\t".$this->buildColumnFor($table, $col, $col_definition);
		$migration .= "\n});";
		
		return $migration;
	}

	protected function tableSchema($table) {
		$structure = array();
		try{
			$res = $this->app['db']->query('Describe `'.$table.'`')->all();
		} catch(\Exception $e) {
			return null;
		}
		$position = 0;
		foreach($res as $one) {
			$col = array();
			$col['position'] = $position++;
			$col['type'] = $one['Type'];
			$col['default'] = $one['Default'];
			$col['nullable'] = $one['Null'] == 'YES';
			$col['key'] = $one['Key'];
			$col['auto_increment'] = strpos($one['Extra'], 'auto_increment') !== false;
			$struc[$one['Field']] = $col;
		}
		
		return $struc;
	}
}
