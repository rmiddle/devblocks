<?php
abstract class DevblocksApplication {
	
}

/**
 * The superclass of instanced extensions.
 *
 * @abstract 
 * @ingroup plugin
 */
class DevblocksExtension {
	public $manifest = null;
	public $id  = '';
	private $params = array();
	private $params_loaded = false;
	
	/**
	 * Constructor
	 *
	 * @private
	 * @param DevblocksExtensionManifest $manifest
	 * @return DevblocksExtension
	 */
	function DevblocksExtension($manifest) { /* @var $manifest DevblocksExtensionManifest */
        if(empty($manifest)) return;
        
		$this->manifest = $manifest;
		$this->id = $manifest->id;
//		$this->params = $this->_getParams();
	}
	
	function getParams() {
	    if(!$this->params_loaded) {
	        $this->params = $this->_getParams();
	        $this->params_loaded = true;
	    }
	    return $this->params;
	}
	
	function setParam($key, $value) {
	    $this->params[$key] = $value;
	    
		$db = DevblocksPlatform::getDatabaseService();
		$prefix = (APP_DB_PREFIX != '') ? APP_DB_PREFIX.'_' : ''; // [TODO] Cleanup

		$db->Execute(sprintf(
			"REPLACE INTO ${prefix}property_store (extension_id, property, value) ".
			"VALUES (%s,%s,%s)",
			$db->qstr($this->id),
			$db->qstr($key),
			$db->qstr($value)	
		));			
	}
	
	function getParam($key,$default=null) {
	    $params = $this->getParams(); // make sure we're fresh
	    return isset($params[$key]) ? $params[$key] : $default;
	}
	
	/**
	 * Loads parameters unique to this extension instance.  Returns an 
	 * associative array indexed by parameter key.
	 *
	 * @private
	 * @return array
	 */
	private function _getParams() {
//		static $params = null;
		
		if(empty($this->id))
			return null;
		
//		if(null != $params)
//			return $params;
		
		$params = $this->manifest->params;
		$db = DevblocksPlatform::getDatabaseService();
		$prefix = (APP_DB_PREFIX != '') ? APP_DB_PREFIX.'_' : ''; // [TODO] Cleanup
		
		$sql = sprintf("SELECT property,value ".
			"FROM %sproperty_store ".
			"WHERE extension_id=%s ",
			$prefix,
			$db->qstr($this->id)
		);
		$results = $db->GetArray($sql) or die(__CLASS__ . ':' . $db->ErrorMsg()); 
		
		foreach($results as $row) {
			$params[$row['property']] = $row['value'];
		}
		
		return $params;
	}
};

abstract class DevblocksHttpResponseListenerExtension extends DevblocksExtension {
	function __construct($manifest) {
		$this->DevblocksExtension($manifest);
	}
    
	function run(DevblocksHttpResponse $request, Smarty $tpl) {
	}
}

abstract class DevblocksTranslationsExtension extends DevblocksExtension {
	function __construct($manifest) {
		$this->DevblocksExtension($manifest);
	}
	
	function getTmxFile() {
		return NULL;
	}
}

abstract class Extension_DevblocksStorageEngine extends DevblocksExtension {
	protected $_options = array();

	function __construct($manifest) {
		$this->DevblocksExtension($manifest);
	}
	
	abstract function renderConfig(Model_DevblocksStorageProfile $profile);
	abstract function saveConfig(Model_DevblocksStorageProfile $profile);
	abstract function testConfig();
	
	abstract function exists($namespace, $key);
	abstract function put($namespace, $id, $data);
	abstract function get($namespace, $key);
	abstract function delete($namespace, $key);
	
	public function setOptions($options=array()) {
		if(is_array($options))
			$this->_options = $options;
	}

	protected function escapeNamespace($namespace) {
		return strtolower(DevblocksPlatform::strAlphaNumUnder($namespace));
	}
};

abstract class Extension_DevblocksStorageSchema extends DevblocksExtension {
	function __construct($manifest) {
		$this->DevblocksExtension($manifest);
	}
	
	abstract function render();
	abstract function renderConfig();
	abstract function saveConfig();
	
	abstract public static function getActiveStorageProfile();

	abstract public static function get($object);
	abstract public static function put($id, $contents, $profile=null);
	abstract public static function delete($ids);
	abstract public static function archive($stop_time=null);
	abstract public static function unarchive($stop_time=null);
	
	protected function _stats($table_name) {
		$db = DevblocksPlatform::getDatabaseService();
		
		$stats = array();
		
		$results = $db->GetArray(sprintf("SELECT storage_extension, count(id) as hits, sum(storage_size) as bytes FROM %s GROUP BY storage_extension ORDER BY storage_extension",
			$table_name
		));
		foreach($results as $result) {
			$stats[$result['storage_extension']] = array(
				'count' => intval($result['hits']),
				'bytes' => intval($result['bytes']),
			);
		}
		
		return $stats;
	}
	
};

/**
 * 
 */
abstract class DevblocksPatchContainerExtension extends DevblocksExtension {
	private $patches = array();

	function __construct($manifest) {
		$this->DevblocksExtension($manifest);
	}
		
	public function registerPatch(DevblocksPatch $patch) {
		// index by revision
		$rev = $patch->getRevision();
		$this->patches[$rev] = $patch;
		ksort($this->patches);
	}
	
	public function run() {
		if(is_array($this->patches))
		foreach($this->patches as $rev => $patch) { /* @var $patch DevblocksPatch */
			if(!$patch->run())
				return FALSE;
		}
		
		return TRUE;
	}
	
	public function runRevision($rev) {
		die("Overload " . __CLASS__ . "::runRevision()");
	}
	
	/**
	 * @return DevblocksPatch[]
	 */
	public function getPatches() {
		return $this->patches;
	}
};

abstract class DevblocksControllerExtension extends DevblocksExtension implements DevblocksHttpRequestHandler {
    function __construct($manifest) {
        self::DevblocksExtension($manifest);
    }

	public function handleRequest(DevblocksHttpRequest $request) {}
	public function writeResponse(DevblocksHttpResponse $response) {}
};

abstract class DevblocksEventListenerExtension extends DevblocksExtension {
    function __construct($manifest) {
        self::DevblocksExtension($manifest);
    }
    
    /**
     * @param Model_DevblocksEvent $event
     */
    function handleEvent(Model_DevblocksEvent $event) {}
};

interface DevblocksHttpRequestHandler {
	/**
	 * @param DevblocksHttpRequest
	 * @return DevblocksHttpResponse
	 */
	public function handleRequest(DevblocksHttpRequest $request);
	public function writeResponse(DevblocksHttpResponse $response);
}

class DevblocksHttpRequest extends DevblocksHttpIO {
	/**
	 * @param array $path
	 */
	function __construct($path, $query=array()) {
		parent::__construct($path, $query);
	}
}

class DevblocksHttpResponse extends DevblocksHttpIO {
	/**
	 * @param array $path
	 */
	function __construct($path, $query=array()) {
		parent::__construct($path, $query);
	}
}

abstract class DevblocksHttpIO {
	public $path = array();
	public $query = array();
	
	/**
	 * Enter description here...
	 *
	 * @param array $path
	 */
	function __construct($path,$query=array()) {
		$this->path = $path;
		$this->query = $query;
	}
}
