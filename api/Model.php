<?php
interface IDevblocksTourListener {
    function registerCallouts();
}
class DevblocksTourCallout {
    public $id = '';
    public $title = '';
    public $body = '';
    
    function __construct($id,$title='Callout',$body='...') {
        $this->id = $id;
        $this->title = $title;
        $this->body = $body;
    }
};
interface IDevblocksSearchFields {
    static function getFields();
}
class DevblocksSearchCriteria {
    const OPER_EQ = '=';
    const OPER_EQ_OR_NULL = 'equals or null';
    const OPER_NEQ = '!=';
    const OPER_IN = 'in';
    const OPER_IS_NULL = 'is null';
    const OPER_NIN = 'not in';
    const OPER_FULLTEXT = 'fulltext';
    const OPER_LIKE = 'like';
    const OPER_NOT_LIKE = 'not like';
    const OPER_GT = '>';
    const OPER_LT = '<';
    const OPER_GTE = '>=';
    const OPER_LTE = '<=';
    const OPER_BETWEEN = 'between';
    
    const GROUP_OR = 'OR';
    const GROUP_AND = 'AND';
    
	public $field;
	public $operator;
	public $value;
	
	/**
	 * Enter description here...
	 *
	 * @param string $field
	 * @param string $oper
	 * @param mixed $value
	 * @return DevblocksSearchCriteria
	 */
	 public function DevblocksSearchCriteria($field,$oper,$value=null) {
		$this->field = $field;
		$this->operator = $oper;
		$this->value = $value;
	}
	
	/*
	 * [TODO] [JAS] Having to pass $fields here is kind of silly, but I'm ignoring 
	 * for now since it's only called in 2 abstracted places.
	 */
	public function getWhereSQL($fields) {
		$db = DevblocksPlatform::getDatabaseService();
		$where = '';
		
		$db_field_name = $fields[$this->field]->db_table . '.' . $fields[$this->field]->db_column; 

		// [JAS]: Operators
		switch($this->operator) {
			case "eq":
			case "=":
				$where = sprintf("%s = %s",
					$db_field_name,
					self::_escapeSearchParam($this, $fields)
				);
				break;
				
			case DevblocksSearchCriteria::OPER_EQ_OR_NULL:
				$where = sprintf("(%s = %s OR %s IS NULL)",
					$db_field_name,
					self::_escapeSearchParam($this, $fields),
					$db_field_name
				);
				break;
				
			case "neq":
			case "!=":
				$where = sprintf("%s != %s",
					$db_field_name,
					self::_escapeSearchParam($this, $fields)
				);
				break;
			
			case "in":
				if(!is_array($this->value)) break;
				$value = (!empty($this->value)) ? $this->value : array(-1);
				$vals = array();
				
				// Escape quotes
				foreach($this->value as $idx=>$val) {
					$vals[$idx] = $db->escape($val);
				}
				
				$where = sprintf("%s IN ('%s')",
					$db_field_name,
					implode("','",$vals) // [TODO] Needs BlobEncode compat
				);
				break;

			case DevblocksSearchCriteria::OPER_NIN: // 'not in'
				if(!is_array($this->value)) break;
				$value = (!empty($this->value)) ? $this->value : array(-1);
				$where = sprintf("%s NOT IN ('%s')",
					$db_field_name,
					implode("','",$value) // [TODO] Needs BlobEncode compat
				);
				break;
				
			case DevblocksSearchCriteria::OPER_FULLTEXT: // 'fulltext'
				$where = sprintf("MATCH(%s) AGAINST (%s IN BOOLEAN MODE)", // WITH QUERY EXPANSION
					$db_field_name,
					self::_escapeSearchParam($this, $fields)
				);
				break;
			
			case DevblocksSearchCriteria::OPER_LIKE: // 'like'
				$where = sprintf("%s LIKE %s",
					$db_field_name,
					str_replace('*','%',self::_escapeSearchParam($this, $fields))
				);
				break;
			
			case DevblocksSearchCriteria::OPER_NOT_LIKE: // 'not like'
				$where = sprintf("%s NOT LIKE %s",
					$db_field_name,
					str_replace('*','%%',self::_escapeSearchParam($this, $fields))
				);
				break;
			
			case DevblocksSearchCriteria::OPER_IS_NULL: // 'is null'
				$where = sprintf("%s IS NULL",
					$db_field_name
				);
				break;
			
			/*
			 * [TODO] Someday we may want to call this OPER_DATE_BETWEEN so it doesn't interfere 
			 * with the operator in other uses
			 */
			case DevblocksSearchCriteria::OPER_BETWEEN: // 'between'
				if(!is_array($this->value) && 2 != count($this->value))
					break;
					
				$from_date = $this->value[0];
				if(!is_numeric($from_date)) {
					// Translate periods into dashes on string dates
					if(false !== strpos($from_date,'.'))
						$from_date = str_replace(".", "-", $from_date);
						
					if(false === ($from_date = strtotime($from_date)))
						$from_date = 0;
				}
				
				$to_date = $this->value[1];
				if(!is_numeric($to_date)) {
					// Translate periods into dashes on string dates
					if(false !== strpos($to_date,'.'))
						$to_date = str_replace(".", "-", $to_date);
						
					if(false === ($to_date = strtotime($to_date)))
						$to_date = strtotime("now");
				}
				
				if(0 == $from_date) {
					$where = sprintf("(%s IS NULL OR %s BETWEEN %s and %s)",
						$db_field_name,
						$db_field_name,
						$from_date,
						$to_date
					);
				} else {
					$where = sprintf("%s BETWEEN %s and %s",
						$db_field_name,
						$from_date,
						$to_date
					);
				}
				break;
			
			case DevblocksSearchCriteria::OPER_GT:
			case DevblocksSearchCriteria::OPER_GTE:
			case DevblocksSearchCriteria::OPER_LT:
			case DevblocksSearchCriteria::OPER_LTE:
				$where = sprintf("%s %s %s",
					$db_field_name,
					$this->operator,
					self::_escapeSearchParam($this, $fields)
				);
			    break;
				
			default:
				break;
		}
		
		return $where;
	}
	
//	/**
//	 * @param DevblocksSearchCriteria[] $fields
//	 */
//	public static function whereOr ($params, $fields) {
//		if(!is_array($params)) return '';
//		
//		$wheres = array();
//		
//		foreach($params as $param) { /* @var $param DevblocksSearchCriteria */
//			$wheres[] = $param->getWhereSQL($fields);
//		}
//		
//		return sprintf("(%s)",
//			implode(' OR ', $wheres)
//		);
//	}
	
	static protected function _escapeSearchParam(DevblocksSearchCriteria $param, $fields) {
	    $db = DevblocksPlatform::getDatabaseService();
	    $field = $fields[$param->field];
	    $where_value = null;
/*	    
   	 *	C for character < 250 chars
	 *	X for teXt (>= 250 chars)
	 *	B for Binary
	 * 	N for numeric or floating point
	 *	D for date
	 *	T for timestamp
	 * 	L for logical/Boolean
	 *	I for integer
	 *	R for autoincrement counter/integer
*/	    
//	    $datadict = new ADODB_DataDict($db);
//	    $datadict->MetaType()

        if($field) {
            switch(strtoupper($field->db_type)) {
                case 'B':
                case 'X':
                case 'XL':
                    if($db->blobEncodeType) {
	                    if(!is_array($param->value)) {
	                        $where_value = "'" . $db->BlobEncode($param->value) . "'";
	                    } else {
	                        $where_value = array();
	                        foreach($param->value as $v) {
	                            $where_value[] = "'" . $db->BlobEncode($v) . "'";
	                        }
	                    }
                    } else {
	                    if(!is_array($param->value)) {
	                        $where_value = $db->qstr($param->value);
	                    } else {
	                        $where_value = array();
	                        foreach($param->value as $v) {
	                            $where_value[] = $db->qstr($v);
	                        }
	                    }
                    }
                    break;
                    
                case 'I':
                case 'N':
                case 'L':
                case 'R':
                    if(!is_array($param->value)) {
                        $where_value = $param->value;
                    } else {
                        $where_value = array();
                        foreach($param->value as $v) {
                            $where_value[] = $v;
                        }
                    }
                    break;
                
                case 'C':
                default:
                    if(!is_array($param->value)) {
                        $where_value = $db->qstr($param->value);
                    } else {
                        $where_value = array();
                        foreach($param->value as $v) {
                            $where_value[] = $db->qstr($v);
                        }
                    }
                    break; 
            }
        }
        
        return $where_value;
	}
	
};
class DevblocksSearchField {
	public $token;
	public $db_table;
	public $db_column;
	public $db_type;
	public $db_label;
	
	function __construct($token, $db_table, $db_column, $db_type=null, $db_label=null) {
		$this->token = $token;
		$this->db_table = $db_table;
		$this->db_column = $db_column;
		$this->db_type = $db_type;
		$this->db_label = $db_label;
	}
};

class DevblocksAclPrivilege {
	var $id = '';
	var $plugin_id = '';
	var $label = '';
};

class DevblocksEventPoint {
    var $id = '';
    var $plugin_id = '';
    var $name = '';
    var $param = array();
};

class DevblocksExtensionPoint {
	var $id = '';
    var $plugin_id = '';
	var $extensions = array();
};

/**
 * Manifest information for plugin.
 * @ingroup plugin
 */
class DevblocksPluginManifest {
	var $id = '';
	var $enabled = 0;
	var $name = '';
	var $description = '';
	var $author = '';
	var $revision = 0;
	var $link = '';
	var $file = '';
	var $class = '';
	var $dir = '';
	var $extension_points = array();
	var $extensions = array();
	var $event_points = array();
	var $acl_privs = array();
	var $class_loader = array();
	var $uri_routing = array();
	
	function setEnabled($bool) {
		$this->enabled = ($bool) ? 1 : 0;
		
		// [JAS]: Persist to DB
		$fields = array(
			'enabled' => $this->enabled
		);
		DAO_Platform::updatePlugin($this->id,$fields);
	}
	
	/**
	 * @return DevblocksPatchContainer
	 */
	function getPatchContainer() {
		return null;
	}
};

/**
 * Manifest information for a plugin's extension.
 * @ingroup plugin
 */
class DevblocksExtensionManifest {
	var $id = '';
	var $plugin_id ='';
	var $point = '';
	var $name = '';
	var $file = '';
	var $class = '';
	var $params = array();

	function DevblocksExtensionManifest() {}
	
	/**
	 * Creates and loads a usable extension from a manifest record.  The object returned 
	 * will be of type $class defined by the manifest.  $instance_id is passed as an 
	 * argument to uniquely identify multiple instances of an extension.
	 *
	 * @param integer $instance_id
	 * @return object
	 */
	function createInstance($instance_id=1) {
		if(empty($this->id) || empty($this->plugin_id)) // empty($instance_id) || 
			return null;

		if(null == ($plugin = DevblocksPlatform::getPlugin($this->plugin_id)))
			return;

		$class_file = DEVBLOCKS_PLUGIN_PATH . $plugin->dir . '/' . $this->file;
		$class_name = $this->class;

		DevblocksPlatform::registerClasses($class_file,array($class_name));

		if(!class_exists($class_name, true)) {
			return null;
		}
		
		$instance = new $class_name($this,$instance_id);
		return $instance;
	}
	
	function getPlugin() {
		$plugin = DevblocksPlatform::getPlugin($this->plugin_id);
		return $plugin;
	}
};

/**
 * A single session instance
 *
 * @ingroup core
 * [TODO] Evaluate if this is even needed, or if apps can have their own unguided visit object
 */
abstract class DevblocksVisit {
	private $registry = array();
	
	public function exists($key) {
		return isset($this->registry[$key]);
	}
	
	public function get($key, $default=null) {
		@$value = $this->registry[$key];
		
		if(is_null($value) && !is_null($default)) 
			$value = $default;
			
		return $value;
	}
	
	public function set($key, $object) {
		$this->registry[$key] = $object;
	}
};


/**
 * 
 */
class DevblocksPatch {
	private $plugin_id = ''; // cerberusweb.core
	private $revision = 0; // 100
	private $filename = ''; // 4.0.0.php
	private $class = ''; // ChPatch400
	
	public function __construct($plugin_id, $revision, $filename, $class) { // $one_run=false
		$this->plugin_id = $plugin_id;
		$this->revision = intval($revision);
		$this->filename = $filename;
		$this->class = $class;
	}
	
	public function run() {
	    if($this->hasRun())
	        return TRUE;

		if(empty($this->filename)) { //  || empty($this->class)
			return FALSE;
		}
		
	    if(!file_exists($this->filename)) {
			// [TODO] needs some file error handling
	        return FALSE;   
	    }

		require_once($this->filename);
		// [TODO] Check that the class we want exists
//		$object = new $$this->class;
	    // [TODO] Need to catch failures here (when we wrap in classes)
		
		DAO_Platform::setPatchRan($this->plugin_id,$this->revision);
		
		return TRUE;
	}
	
	/**
	 * @return boolean
	 */
	public function hasRun() {
		// Compare PLUGIN_ID + REVISION in script history
		return DAO_Platform::hasPatchRun($this->plugin_id,$this->revision);
	}
	
	public function getPluginId() {
		return $this->plugin_id;
	}
	
	public function getRevision() {
		return $this->revision;
	}
	
};

class Model_DevblocksEvent {
  public $id = '';
  public $params = array(); 

  function __construct($id='',$params=array()) {
      $this->id = $id;
      $this->params = $params;
  }
};

class Model_Translation {
	public $id;
	public $string_id;
	public $lang_code;
	public $string_default;
	public $string_override;
};
