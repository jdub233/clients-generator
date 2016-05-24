<?php
abstract class ClientGeneratorFromXml 
{
	protected $_txt = "";
	protected $_files = array();
	protected $_xmlFile = "";
	protected $_sourcePath = "";
	protected $_params = array();
	
	protected $generateDocs = false;
	protected $package = 'External';
	protected $subpackage = 'Kaltura';
	protected $excludeSourcePaths = array();
	
	/**
	 * @var array
	 */
	private $_config = array();
	
	/**
	 * @var DOMDocument
	 */
	protected $_doc = null;
	
	/**
	 * @var array
	 */
	protected $_ignoreTypes = null;
	
	/**
	 * @var array
	 */
	protected $_includeTypes = null;

	/**
	 * @var array
	 */
	protected $_excludesServices = null;
	
	/**
	 * @var array
	 */
	protected $_includeServices = array();
	
	public function setGenerateDocs($generateDocs)
	{
		$this->generateDocs = $generateDocs;
	}
	
	public function setPackage($package)
	{
		$this->package = $package;
	}
	
	public function setSubpackage($subpackage)
	{
		$this->subpackage = $subpackage;
	}
	
	public function setExcludeSourcePaths ($excludeSourcePaths)
	{
		$this->excludeSourcePaths = explode(',', $excludeSourcePaths);
	}

	private function loadExcludeServiceList()
	{
		if($this->_config->exclude)
		{
			$this->_excludesServices = array();
			$serviceList = explode(',', str_replace(' ', '', $this->_config->exclude));
			foreach ($serviceList as $service)
			{
				$serviceName = explode('.', $service);
				if($serviceName)
					$this->_excludesServices[$serviceName[0]] = 1;
			}
		}
	}

	protected function shouldExcludeService($serviceName)
	{
		if($this->_excludesServices)
		{
			if (array_key_exists($serviceName, $this->_excludesServices))
				return true;
		}
		return false;
	}

	public function __construct($xmlFile, $sourcePath, Zend_Config $config)
	{
		$this->_xmlFile = realpath($xmlFile);
		$this->_config = $config;
		$this->_sourcePath = realpath($sourcePath);
		
		if (!file_exists($this->_xmlFile))
			throw new Exception("The file [" . $this->_xmlFile . "] was not found");
			
		if (!file_exists($sourcePath))
			throw new Exception("Source path was not found [$sourcePath]");

		$this->_doc = new DOMDocument();
		$this->_doc->load($this->_xmlFile);
		
		$this->loadExcludeList();
		$this->loadExcludeServiceList();

		$singleLineCommentMarker = $this->getSingleLineCommentMarker();
		if($singleLineCommentMarker === null)
			$singleLineCommentMarker = '';
		
		$this->_licenseBuffer = file_get_contents(dirname(__FILE__).'/sources/license.txt');
		$this->_licenseBuffer = str_replace('//', $this->getSingleLineCommentMarker(), $this->_licenseBuffer);
		$this->_licenseBuffer = str_replace("\r\n", "\n", $this->_licenseBuffer);
		
		$this->addFile('agpl.txt', file_get_contents(dirname(__FILE__).'/sources/agpl.txt'), false);
	}
	
	protected function shouldIncludeType($type)
	{
		if(in_array($type, $this->_ignoreTypes))
			return false;
		
		$type = strval($type);
		return !count($this->_includeTypes) || isset($this->_includeTypes[$type]);
	}
	
	protected function shouldIncludeAction($serviceId, $actionId)
	{
		$serviceId = strtolower(strval($serviceId));
		
		if(!count($this->_includeServices))
			return true;

		if(!isset($this->_includeServices[$serviceId]))
			return false;

		if($this->_includeServices[$serviceId] === 'all')
			return true;
				
		return isset($this->_includeServices[$serviceId][$actionId]);
	}
	
	protected function shouldIncludeService($serviceId)
	{
		$serviceId = strval($serviceId);
		return !count($this->_includeServices) || isset($this->_includeServices[$serviceId]);
	}
	
	protected function loadExcludeList()
	{
		$xpath = new DOMXPath($this->_doc);

		if($this->_config->ignore)
			$this->_ignoreTypes = explode(',', str_replace(' ', '', $this->_config->ignore));
		
		if($this->_config->include)
		{
			$includes = explode(',', str_replace(' ', '', $this->_config->include));
			foreach($includes as $include)
			{
				if(!strpos($include, '.'))
					continue;
				
				list($serviceId, $actionId) = explode('.', $include);
				$serviceId = strtolower($serviceId);
				if($actionId === '*')
				{
					$this->_includeServices[$serviceId] = 'all';
				}
				elseif(isset($this->_includeServices[$serviceId]))
				{
					if($this->_includeServices[$serviceId] != 'all')
						$this->_includeServices[$serviceId][$actionId] = $actionId;
				}
				else
				{
					$this->_includeServices[$serviceId] = array($actionId => $actionId);
				}
			}
		}
		elseif($this->_config->exclude)
		{
			$serviceNodes = $xpath->query("/xml/services/service");
			foreach($serviceNodes as $serviceNode)
			{
				$serviceId = $serviceNode->getAttribute("id");
				$this->_includeServices[$serviceId] = array();

				$actionNodes = $serviceNode->getElementsByTagName("action");
				foreach($actionNodes as $actionNode)
				{
					$actionId = $actionNode->getAttribute("name");
					$this->_includeServices[$serviceId][$actionId] = $actionId;
				}
			}

			$excludes = explode(',', str_replace(' ', '', $this->_config->exclude));
			foreach($excludes as $exclude)
			{
				if(!strpos($exclude, '.'))
					continue;
				
				list($serviceId, $actionId) = explode('.', $exclude);
				$serviceId = strtolower($serviceId);

				if(!isset($this->_includeServices[$serviceId]))
					continue;
				
				if($actionId === '*')
				{
					unset($this->_includeServices[$serviceId]);
				}
				elseif(isset($this->_includeServices[$serviceId][$actionId]))
				{
					unset($this->_includeServices[$serviceId][$actionId]);
				}
			}
		}
		else
		{
			return;
		}
		
		foreach($this->_includeServices as $serviceId => $actions)
		{
			$serviceNodes = $xpath->query("/xml/services/service[@id = '$serviceId']");
			$serviceNode = $serviceNodes->item(0);
			if(!$serviceNode)
			{
				KalturaLog::warning("Service [$serviceId] not found");
				continue;
			}

			$actionNodes = $serviceNode->getElementsByTagName("action");
			foreach($actionNodes as $actionNode)
			{
				$actionId = $actionNode->getAttribute("name");
				if($actions === 'all' || in_array($actionId, $actions))
					$this->loadActionTypes($actionNode);
			}
		}
		
		if($this->_config->additional)
		{
			$additionals = explode(',', str_replace(' ', '', $this->_config->additional));
			foreach($additionals as $additional)
				$this->loadTypesRecursive($additional, false);
		}

		$alwaysAdd = array(
				'KalturaApiExceptionArg',
				'KalturaClientConfiguration',
				'KalturaRequestConfiguration',
		);
		
		foreach($alwaysAdd as $additional)
			$this->loadTypesRecursive($additional, false);
	}
	
	protected function loadTypesRecursive($type, $strict = true)
	{
		if($type == 'KalturaObjectBase')
			return;

		if(in_array($type, $this->_ignoreTypes))
			return;
		
		if(!$this->isComplexType($type))
			return;
				
		if(isset($this->_includeTypes[$type]))
			return;
		
		$xpath = new DOMXPath($this->_doc);
		$enumNodes = $xpath->query("/xml/enums/enum[@name = '$type']");
		$enumNode = $enumNodes->item(0);
		if($enumNode)
		{
			$this->_includeTypes[$type] = $type;
			return;
		}

		$classNodes = $xpath->query("/xml/classes/class[@name = '$type']");
		$classNode = $classNodes->item(0);
		/* @var $classNode DOMElement */
		if(!$classNode)
		{
			if($strict)
				throw new Exception("Missing type [$type]");
			
			KalturaLog::warning("Missing type [$type]");
			return;
		}

		$this->_includeTypes[$type] = $type;
		if($classNode->hasAttribute("base"))
			$this->loadTypesRecursive($classNode->getAttribute("base"));
		
		foreach($classNode->childNodes as $propertyNode)
		{
			if ($propertyNode->nodeType != XML_ELEMENT_NODE)
				continue;
			
			$propertyType = $propertyNode->getAttribute("type");
			if ($propertyNode->hasAttribute("enumType"))
				$propertyType = $propertyNode->getAttribute("enumType");
			if ($propertyNode->hasAttribute("arrayType"))
				$propertyType = $propertyNode->getAttribute("arrayType");
			
			$this->loadTypesRecursive($propertyType);
		}

		$classNodes = $xpath->query("/xml/classes/class[@base = '$type']");
		foreach($classNodes as $classNode)
		{
			$this->loadTypesRecursive($classNode->getAttribute("name"));
		}
		
		if($this->endsWith($type, 'Filter'))
		{
			$orderBy = preg_replace('/Filter$/', 'OrderBy', $type);
			$classNodes = $xpath->query("/xml/enums/enum[@name = '$orderBy']");
			$classNode = $classNodes->item(0);
			if($classNode)
				$this->_includeTypes[$orderBy] = $orderBy;
		}
	}
	
	protected function loadActionTypes(DOMElement $actionNode)
	{
		$resultNode = $actionNode->getElementsByTagName("result")->item(0);
		$resultType = $resultNode->getAttribute("type");

		if($resultNode->hasAttribute("enumType"))
			$resultType = $resultNode->getAttribute("enumType");

		if($resultNode->hasAttribute("arrayType"))
			$resultType = $resultNode->getAttribute("arrayType");
		
		if($resultType)
			$this->loadTypesRecursive($resultType);
			
		$paramNodes = $actionNode->getElementsByTagName("param");
		foreach($paramNodes as $paramNode)
		{
			$paramType = $paramNode->getAttribute("type");

			if($paramNode->hasAttribute("enumType"))
				$paramType = $paramNode->getAttribute("enumType");
			
			if($paramNode->hasAttribute("arrayType"))
				$paramType = $paramNode->getAttribute("arrayType");
		
			$this->loadTypesRecursive($paramType);
		}
	}
	
	public function generate()
	{
		if (is_dir($this->_sourcePath))
			$this->addSourceFiles($this->_sourcePath, $this->_sourcePath . DIRECTORY_SEPARATOR, "");
	}
	
	public function getOutputFiles()
	{
		return $this->_files;
	}
	
	public function setParam($key, $value)
	{
		$this->_params[$key] = $value;		
	}
	
	public function getParam($key)
	{
		if (!array_key_exists($key, $this->_params))
			return null;
		return $this->_params[$key];
	}
	
	protected function addFile($fileName, $fileContents, $addLicense = true)
	{
		if ($addLicense)
		{
			if ($this->beginsWith($fileContents, '<?php'))
			{
				$fileContents = "<?php\n" . $this->_licenseBuffer . substr($fileContents, 5);
			}
			else
			{
				$fileContents = $this->_licenseBuffer . $fileContents;
			}
		}
		
		$this->_files[$fileName] = str_replace('@DATE@', date('y-m-d'), $fileContents);
	}
	
	protected function addSourceFiles($directory, $rootSourceFolder, $rootDestFolder)
	{
		//if excluded- return without adding
		foreach ($this->excludeSourcePaths as $excludePath)
		{
			if (realpath($directory) == realpath("$rootSourceFolder/$excludePath"))
			{
				return;
			}
		}
		// add if file
		if (is_file($directory)) 
		{
			$file = str_replace($rootSourceFolder, $rootDestFolder, $directory);
			$this->addFile($file, file_get_contents($directory), false);
			return;
		}
		
		// loop through the folder
		$dir=null;
		if (is_dir($directory)){
		    $dir = dir($directory);
		}
		//                                                         
		$sourceFilesPaths = array();
		while (get_class($dir)==='Directory' && (false !== $entry = $dir->read())) 
		{
			// skip source control files
			if ($this->beginsWith($entry, ".svn") || 
				$this->beginsWith($entry, ".cvs") || 
				$this->beginsWith($entry, ".git") || 
				$entry == '.'  || 
				$entry == '..' 
			)
			{
				continue;
			} 
			
			$sourceFilesPaths[] = realpath("$directory/$entry");
		}
		// clean up
		if(get_class($dir)==='Directory'){
		    $dir->close();
		}
		
		foreach($sourceFilesPaths as $sourceFilesPath)
			$this->addSourceFiles($sourceFilesPath, $rootSourceFolder, $rootDestFolder);
	}
	
	protected function endsWith($str, $end) 
	{
		return (substr($str, strlen($str) - strlen($end)) === $end);
	}
	
	protected function beginsWith($str, $start) 
	{
		return (substr($str, 0, strlen($start)) === $start);
	}
	
	protected function upperCaseFirstLetter($str)
	{
		return ucwords($str); 
	}
	
	protected function camelCaseToUnderscoreAndLower($value)
	{
		$separator = '_';
		$matchPattern = array('#(?<=(?:[A-Z]))([A-Z]+)([A-Z][A-z])#', '#(?<=(?:[a-z]))([A-Z])#');
		$replacement = array('\1' . $separator . '\2', $separator . '\1');
		$newValue = preg_replace($matchPattern, $replacement, $value);
		return strtolower($newValue);
		//$filter = new Zend_Filter_Word_CamelCaseToUnderscore();
		//return strtolower($filter->filter($value));
	}
	
	protected function isArrayType($type)
	{
		return in_array($type, array("array","map"));
	}
	
	protected function isSimpleType($type)
	{
		return in_array($type, array("int","string","bool","float","bigint"));
	}
	
	protected function isComplexType($type)
	{
		return !$this->isSimpleType($type) && $type != 'file';
	}
	
	protected function startNewTextBlock()
	{
		$this->_txt = "";
	}
	
	protected function append($txt = "")
	{
		$this->_txt .= $txt;
	}
	
	protected function appendLine($txt = "")
	{
		$this->append($txt ."\n");
	}
	
	protected function getTextBlock()
	{
		return $this->_txt;
	}

	/* 
	 * returns the symbol used for single line comments, e.g. //
	 * 
	 * @return string 
	 */
	protected function getSingleLineCommentMarker()
	{
		return '//';
	}
	
	public function done($outputPath)
	{
	}
}
