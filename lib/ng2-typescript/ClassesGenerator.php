<?php

require_once (__DIR__. '/NG2TypescriptGeneratorBase.php');
require_once (__DIR__. '/GeneratedFileData.php');

class ClassesGenerator extends NG2TypescriptGeneratorBase
{

    function __construct($serverMetadata)
    {
        parent::__construct($serverMetadata);
    }

    public function generate()
    {
        foreach ($this->serverMetadata->services as $service) {
            foreach ($service->actions as $serviceAction) {
                $result[] = $this->createServiceActionFile($service, $serviceAction);
            }
        }

        foreach ($this->serverMetadata->classTypes as $class) {
            $result[] = $this->createClassFile($class);
        }

        $result[] = $this->createRequestBaseFile();

        return $result;
    }

    function createRequestBaseFile()
    {
        $createContentArgs = new stdClass();
        $createContentArgs->enumPath = "./enum/";
        $createContentArgs->typesPath = "./class/";
        $createContentArgs->properties = $this->serverMetadata->requestSharedParameters;
        $createContentArgs->importedItems = array('KalturaRequestBase','KalturaObjectBase');
        $content = $this->createContent($createContentArgs);

        $fileContent = "{$this->getBanner()}
import { KalturaObjectBase, KalturaObjectMetadata } from './kaltura-object-base';
{$this->utils->buildExpression($content->imports,NewLine)}
export class KalturaRequestBase extends KalturaObjectBase {

    readonly service : string;
    readonly action : string;
    {$this->utils->buildExpression($content->classProperties, NewLine, 1)}

    constructor(service : string, action : string)
    {
        super();
        this.service = service;
        this.action = action;
    }

     protected _getObjectMetadata(metadata : KalturaObjectMetadata) : void
    {
        super._getObjectMetadata(metadata);
        metadata.isAbstract = true;
        metadata.properties.push(
            ...[
                {$this->utils->buildExpression($content->propertiesMetadata,',' . NewLine,4)}
            ]
        );
    }
}
";

        $file = new GeneratedFileData();
        $file->path = "kaltura-request-base.ts";
        $file->content = $fileContent;
        $result[] = $file;
        return $file;
    }

    function createClassFile(ClassType $class)
    {
        $createClassArgs = new stdClass();
        $createClassArgs->name = $class->name;
        $createClassArgs->description = $class->description;
        $createClassArgs->base = $class->base;
        $createClassArgs->defaultBase = "KalturaObjectBase";
        $createClassArgs->defaultBasePath = "../";
        $createClassArgs->enumPath = "../enum/";
        $createClassArgs->typesPath = "./";
        $createClassArgs->properties = $class->properties;
        $createClassArgs->importedItems = array();

        $classBody = $this->createClass($createClassArgs);

        $fileContent = "{$this->getBanner()}
{$classBody}
";

        $file = new GeneratedFileData();
        $fileName = $this->utils->toLispCase($class->name);
        $file->path = "class/{$fileName}.ts";
        $file->content = $fileContent;
        $result[] = $file;
        return $file;
    }

    function createServiceActionFile(Service $service,ServiceAction $serviceAction)
    {
        $className = $service->name . ucfirst($serviceAction->name) . "Action";
        $actionNG2ResultType = $this->toNG2TypeExp($serviceAction->resultType, $serviceAction->resultClassName);
        $importedItems = array($className,'KalturaRequest');

        $getImportExpForTypeArgs = new stdClass();
        $getImportExpForTypeArgs->enumPath = "../enum/";
        $getImportExpForTypeArgs->typesPath = "../class/";
        $getImportExpForTypeArgs->type = $serviceAction->resultType;
        $getImportExpForTypeArgs->typeClassName = $serviceAction->resultClassName;
        $importResultType = $this->getImportExpForType($getImportExpForTypeArgs,$importedItems);
        if ($importResultType) {
            // prevent duplicate import for the result class
            $importedItems[] = $serviceAction->resultClassName;
        }

        $createClassArgs = new stdClass();
        $createClassArgs->name = $className;
        $createClassArgs->description = $serviceAction->description;
        $createClassArgs->defaultBase = "KalturaRequest<{$actionNG2ResultType}>";
        $createClassArgs->defaultBasePath = null; // will be added manually
        $createClassArgs->enumPath = "../enum/";
        $createClassArgs->typesPath = "../class/";
        $createClassArgs->properties = $serviceAction->params;
        $createClassArgs->superArgs = "'{$service->name}','{$serviceAction->name}'";
        $createClassArgs->importedItems = &$importedItems;

        $classBody = $this->createClass($createClassArgs);

      ;

        $fileContent = "{$this->getBanner()}
import { KalturaRequest } from \"../kaltura-request\";
{$importResultType}

{$classBody}
";

        $file = new GeneratedFileData();
        $fileName = $this->utils->toLispCase($className);
        $file->path = "action/{$fileName}.ts";
        $file->content = $fileContent;
        $result[] = $file;
        return $file;
    }

    function createClass($args)
    {
        $name = $args->name;
        $description = $args->description;
        $base = isset($args->base) ? $args->base : null;
        $abstract = isset($args->abstract) ? $args->abstract : false;
        $defaultBase = $args->defaultBase;
        $defaultBasePath = $args->defaultBasePath;
        $typesPath = $args->typesPath;
        $classTypeName = Utils::upperCaseFirstLetter($name);
        $desc = $description;
        $superArgs = isset($args->superArgs) ? $args->superArgs : null;
        $importedItems = &$args->importedItems;

        $importedItems[] = $name;

        $baseImport = null;
        if ($base)
        {
            $importedItems[] = $base;
            $baseFileName = $this->utils->toLispCase($base);
            $baseImport = "import { {$base}, {$base}Args } from '{$typesPath}{$baseFileName}';";

        }else if ($defaultBase && $defaultBasePath)
        {
            $importedItems[] = $defaultBase;
            $baseFileName = $this->utils->toLispCase($defaultBase);
            $baseImport = "import { {$defaultBase} } from '{$defaultBasePath}{$baseFileName}';";
        }

        $content = $this->createContent($args);

        if ($baseImport) {
            $content->imports[] = $baseImport;
        }

        $result = "import { KalturaObjectMetadata } from '../kaltura-object-base';
{$this->utils->buildExpression($content->imports,NewLine)}

export interface {$classTypeName}Args {$this->utils->ifExp($base, " extends " . $base . "Args","")} {
    {$this->utils->buildExpression($content->constructorArgs, NewLine, 1)}
}

{$this->utils->createDocumentationExp('',$desc)}
export class {$classTypeName} extends {$this->utils->ifExp($base, $base,$defaultBase)} {

    {$this->utils->buildExpression($content->classProperties, NewLine, 1)}

    constructor(data? : {$classTypeName}Args)
    {
        super({$superArgs});
        Object.assign(this, data || {}, { objectType : '{$classTypeName}' });
    }

    protected _getObjectMetadata(metadata : KalturaObjectMetadata) : void
    {
        super._getObjectMetadata(metadata);
        metadata.isAbstract = {$this->utils->ifExp($abstract,"true","false")};
        metadata.properties.push(
            ...[
                {$this->utils->buildExpression($content->propertiesMetadata,',' . NewLine,4)}
            ]
        );
    }
}
";

        return $result;
    }


    function createContent($args)
    {
        $typesPath = $args->typesPath;
        $enumPath = $args->enumPath;
        $properties = $args->properties;
        $importedItems = &$args->importedItems;

        $result = new stdClass();
        $result->constructorArgs = array();
        $result->classProperties = array();
        $result->propertiesMetadata = array();
        $result->imports = array();
        $result->buildContent = array();
        $result->constructorContent = array();

        if (count($properties) != 0)
        {
            foreach($properties as $property) {
                $ng2ParamType = $this->toNG2TypeExp($property->type, $property->typeClassName);
                $default = $this->toNG2DefaultByType($property->type, $property->typeClassName, $property->default);
                $readOnly = isset($property->readOnly) && $property->readOnly;

                // update the properties declaration
                if (!$readOnly) {
                    $isOptional = isset($property->optional) && $property->optional;
                    $result->constructorArgs[] = "{$property->name}{$this->utils->ifExp($isOptional,"?","")} : {$ng2ParamType};";
                }

                $result->classProperties[] = ($readOnly ? "readonly " : "") . "{$property->name} : {$ng2ParamType}" . ($default ? " = {$default}" : "") . ";";
                $isPropertyReadOnly = (isset($property->readOnly) && $property->readOnly) ? ', readOnly : true' : '';
                $propertyMetadataType = $this->toPropertyTypeToken($property->type, $property->typeClassName);
                $result->propertiesMetadata[] = "{ name : '{$property->name}' {$isPropertyReadOnly}, type : '{$propertyMetadataType->type}'" . (isset($propertyMetadataType->subType) ? ", subType : '{$propertyMetadataType->subType}'}" : "}");
                $getImportExpForTypeArgs = new stdClass();
                $getImportExpForTypeArgs->enumPath = $enumPath;
                $getImportExpForTypeArgs->typesPath = $typesPath;
                $getImportExpForTypeArgs->type = $property->type;
                $getImportExpForTypeArgs->typeClassName = $property->typeClassName;
                $propertyImport = $this->getImportExpForType($getImportExpForTypeArgs, $importedItems);
                if ($propertyImport)
                {
                    $result->imports[] = $propertyImport;
                }
            }
        }

        return $result;
    }

    private function getImportExpForType($args, &$importedItems)
    {
        $type = $args->type;
        $typeClassName = $args->typeClassName;
        $typesPath = $args->typesPath;
        $enumPath = $args->enumPath;

        $result = null;
        switch ($type) {
            case KalturaServerTypes::EnumOfInt:
            case KalturaServerTypes::EnumOfString:
            if (in_array($typeClassName,$importedItems) === false) {
                $importedItems[] = $typeClassName;
                $fileName = $this->utils->toLispCase($typeClassName);
                $result = "import { {$typeClassName} } from '{$enumPath}{$fileName}';";
            }
            break;
            case KalturaServerTypes::Object:
            case KalturaServerTypes::ArrayOfObjects:
                if (!in_array($typeClassName,$importedItems)) {
                    $importedItems[] = $typeClassName;

                    if ($typeClassName === 'KalturaObjectBase')
                    {
                        $typesPath = "../";
                    }
                    $fileName = $this->utils->toLispCase($typeClassName);
                    $result = "import { {$typeClassName} } from '{$typesPath}{$fileName}';";
                }
                break;
            default:
                break;
        }

        return $result;
    }

    protected function toPropertyTypeToken($type, $typeClassName)
    {
        $result = new stdClass();

        switch ($type) {
            case KalturaServerTypes::File:
                $result->type = 'f';
                break;
            case KalturaServerTypes::Simple:
                switch ($typeClassName) {
                    case "bool":
                        $result->type = "b";
                        break;
                    case "bigint":
                    case "float":
                    case "int":
                        $result->type = 'n';
                        break;
                    case "string":
                        $result->type = 's';
                        break;
                    default:
                        throw new Exception("toPropertyTypeToken: Unknown simple type {$typeClassName}");
                }
                break;
            case KalturaServerTypes::ArrayOfObjects:
                $result->type = "a";
                $result->subType = $typeClassName;
                break;
            case KalturaServerTypes::EnumOfInt:
                $result->type = "ei";
                $result->subType = $typeClassName;
                break;
            case KalturaServerTypes::EnumOfString:
                $result->type = "es";
                $result->subType = $typeClassName;
                break;
            case KalturaServerTypes::Object:
                $result->type = "o";
                $result->subType = $typeClassName;
                break;
            case KalturaServerTypes::Date:
                $result->type = "d";
                break;
            case KalturaServerTypes::Void:
            default:
                throw new Exception("toPropertyTypeToken: Unknown type requested {$type}");
        }

        return $result;
    }

    protected function toNG2TypeExp($type, $typeClassName, $resultCreatedCallback = null)
    {
        return parent::toNG2TypeExp($type,$typeClassName,function($type,$typeClassName,$result)
        {
            return $result;
        });
    }
}