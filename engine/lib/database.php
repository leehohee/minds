<?php
/**
 * Cassandra implementation for elgg.
 *
 * Includes the core functionalities.
 *
 * @package Elgg.Core
 * @subpackage Database
 */

require(dirname(dirname(dirname(__FILE__))) . '/vendors/phpcassa/lib/autoload.php');

use phpcassa\ColumnFamily;
use phpcassa\ColumnSlice;
use phpcassa\Connection\ConnectionPool;
use phpcassa\SystemManager;
use phpcassa\Schema\StrategyClass;
use phpcassa\Index\IndexClause;
use phpcassa\Index\IndexExpression;
use phpcassa\Schema\DataType\LongType;
use phpcassa\UUID;


global $DB;
$DB = new stdClass(); //make a database class for caching etc

/**
 * Initialise Cassandra  DB connection 
 *
 * @return void
 * @access private
 */
function db_init() {
	global $CONFIG, $DB;

	$servers = $CONFIG->cassandra->servers;
	
	$pool = new ConnectionPool($CONFIG->cassandra->keyspace, $servers);
	
	$DB->pool = $pool;

	$cfs = array('site','plugin', 'config','object', 'user', 'group');

	register_cfs($cfs);
}

/**
 * Insert to cassandra
 */
function db_insert($guid = NULL, array $options = array()){
	global $DB;

	if(!$guid){
		$guid = UUID::uuid1()->string;
	}
	
	$type = $options['type'] ? $options['type'] : 'object'; // assume its an object if no type is specified
	
	//unset guid
	unset($options['guid']);	
try{	
	$DB->cfs[$type]->insert($guid, $options);
} catch(Exception $e){
echo '<pre>';
var_dump($e);
echo '</pre>';
exit;
}
	return $guid;
}

/**
 * Get from cassandra
 */
function db_get(array $options = array()){
	global $DB;

	$defaults = array(	'type' => "object",
				'subtype' => "",

				'limit' => 12,
				'offset' => "",
			);

	$options = array_merge($defaults, $options);		

	if($options['limit'] == 0){
		unset($options['limit']);
	}

	$type = $options['type'];

	//1. If guids are passed then return them all. Subtypes and other values don't matter in this case
        if($options['guids']){

                $rows = $DB->cfs[$type]->multiget($options['guids']);

        }

        //2. If it's an object, but rows have not been filled (ie. no guids specified, do this
        if($type == 'object' && !$rows){

        	//2a. If owner_guids have been specified then grab from user_object column family
		//var_dump($options);
                //2b. If not then just return all 
                $index_exps[] = new IndexExpression('subtype', 'blog');
                $index_clause = new IndexClause($index_exps, $options['offset'], $options['limit']);
                $rows = $DB->cfs[$type]->get_indexed_slices($index_clause);

        } elseif($type == 'user') {
		
		foreach($options['attrs'] as $k => $v){
		       $index_exps[] = new IndexExpression($k, $v);
                }

		$index_clause = new IndexClause($index_exps);
                $rows = $DB->cfs[$type]->get_indexed_slices($index_clause);

        } elseif($type == 'plugin'){
	
		//do we even have any attrs?
		if($attr = $options['attrs']){
			foreach($options['attrs'] as $k => $v){
	        	       $index_exps[] = new IndexExpression($k, $v);
			}		               

			$index_clause = new IndexClause($index_exps);
        		$rows = $DB->cfs[$type]->get_indexed_slices($index_clause);
        	} else {
			$rows = $DB->cfs[$type]->get_range("", "");
		}
	}
        
	foreach($rows as $k => $row){
		
		$row['guid'] = $k;
		
		$new_row = new StdClass;
	
		foreach($row as $k=>$v){
			$new_row->$k = $v;
                }
		$entities[] = entity_row_to_elggstar($new_row, $options['type']);
        
	}
	return $entities;
}

	/**
	 * Creates a column family. This should be run automatically
	 * for each new subtype that is created.
	 */
	function create_cfs($name, array $indexes = array(), array $attrs = array(), $plugin_id){
		global $CONFIG, $DB;

		$sys = new SystemManager($CONFIG->cassandra->servers);

	$attr = array("comparator_type" => UTF8Type);

        $sys->create_column_family($CONFIG->cassandra->keyspace, $name, $attr);
	foreach($indexes as $index){
	       $sys->create_index($CONFIG->cassandra->keyspace, $name, $index, UTF8Type);
	}
}

/**
 * Register a cfs thats has already been installed by the schema. 
 * These are sent via the plugins start.php files.
 */
function register_cfs($name){
	
	global $DB;

	if(is_array($name)){
		
		foreach($name as $n){
			$DB->cfs[$n] = new ColumnFamily($DB->pool, $n);
		}

	} else {

		$DB->cfs[$name] = new ColumnFamily($DB->pool, $name);
	
	}
}
//db_validate_column('plugin', array('active'=> "IntegerType"));
/** 
 * Create a column validation value
 * DATA TYPES: 
 * "BytesType", "LongType", "IntegerType", "Int32Type", "FloatType", "DoubleType", "AsciiType", "UTF8Type"
 * "TimeUUIDType", "LexicalUUIDType", "UUIDType", "DateType"
 */
function db_validate_column($cf, $options){
	global $CONFIG,$DB;
var_dump($CONFIG->cassandra->servers[0]);
	$sys = new SystemManager($CONFIG->cassandra->servers[0]);

//	$sys->truncate_column_family($CONFIG->cassandra->keyspace, $cf);
	
	foreach($options as $column => $data_type){
//		var_dump($column, $data_type, $CONFIG->cassandra->keyspace, $cf); exit;
		try{
			$sys->alter_column($CONFIG->cassandra->keyspace, $cf, $column, $data_type);
		} catch(Exception $e){
			echo "<pre>";
			var_dump($e);
			echo "</pre>";
		}
	}
}

//elgg_register_event_handler('init', 'system', 'db_init');
