<?php

namespace InfyOm\Generator\Generators;

use InfyOm\Generator\Common\CommandData;
use InfyOm\Generator\Utils\FileUtil;
use InfyOm\Generator\Utils\TableFieldsGenerator;
use InfyOm\Generator\Utils\TemplateUtil;

class ModelGenerator
{
    /** @var CommandData */
    private $commandData;

    /** @var string */
    private $path;

    /**
     * ModelGenerator constructor.
     * @param \InfyOm\Generator\Common\CommandData $commandData
     */
    public function __construct(CommandData $commandData)
    {
        $this->commandData = $commandData;
        $this->path = $commandData->config->pathModel;
    }

    public function generate()
    {
        $templateData = TemplateUtil::getTemplate('model', 'laravel-generator');

        $templateData = $this->fillTemplate($templateData);

        $fileName = $this->commandData->modelName.'.php';

        FileUtil::createFile($this->path, $fileName, $templateData);

        $this->commandData->commandComment("\nModel created: ");
        $this->commandData->commandInfo($fileName);
    }

    private function fillTemplate($templateData)
    {
        $templateData = TemplateUtil::fillTemplate($this->commandData->dynamicVars, $templateData);

        $templateData = $this->fillSoftDeletes($templateData);

        $fillables = [];

        foreach ($this->commandData->inputFields as $field) {
            if ($field['fillable']) {
                $fillables[] = "'".$field['fieldName']."'";
            }
        }

        $templateData = $this->fillDocs($templateData);

        $templateData = $this->fillTimestamps($templateData);

        if ($this->commandData->getOption('primary')) {
            $primary = str_repeat(' ', 4)."protected \$primaryKey = '".$this->commandData->getOption('primary')."';\n";
        } else {
            $primary = '';
        }

        $templateData = str_replace('$PRIMARY$', $primary, $templateData);

        $templateData = str_replace('$FIELDS$', implode(','.PHP_EOL.str_repeat(' ', 8), $fillables), $templateData);

        $templateData = str_replace('$RULES$', implode(','.PHP_EOL.str_repeat(' ', 8), $this->generateRules()), $templateData);

        $templateData = str_replace('$CAST$', implode(','.PHP_EOL.str_repeat(' ', 8), $this->generateCasts()), $templateData);
        
        $templateData = str_replace('$FUNCTIONS$', implode(','.PHP_EOL.str_repeat(' ', 8), $this->generateCasts()), $templateData);

        return $templateData;
    }

    private function fillSoftDeletes($templateData)
    {
        if (!$this->commandData->getOption('softDelete')) {
            $templateData = str_replace('$SOFT_DELETE_IMPORT$', '', $templateData);
            $templateData = str_replace('$SOFT_DELETE$', '', $templateData);
            $templateData = str_replace('$SOFT_DELETE_DATES$', '', $templateData);
        } else {
            $templateData = str_replace(
                '$SOFT_DELETE_IMPORT$', "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n",
                $templateData
            );
            $templateData = str_replace('$SOFT_DELETE$', str_repeat(' ', 4)."use SoftDeletes;\n", $templateData);
            $deletedAtTimestamp = config('infyom.laravel_generator.timestamps.deleted_at', 'deleted_at');
            $templateData = str_replace(
                '$SOFT_DELETE_DATES$', PHP_EOL.str_repeat(' ', 4)."protected \$dates = ['".$deletedAtTimestamp."'];\n",
                $templateData
            );
        }

        return $templateData;
    }

    private function fillDocs($templateData)
    {
        if ($this->commandData->getAddOn('swagger')) {
            $templateData = $this->generateSwagger($templateData);
        } else {
            $docsTemplate = TemplateUtil::getTemplate('docs.model', 'laravel-generator');
            $docsTemplate = TemplateUtil::fillTemplate($this->commandData->dynamicVars, $docsTemplate);
            $templateData = str_replace('$DOCS$', $docsTemplate, $templateData);
        }

        return $templateData;
    }

    private function fillTimestamps($templateData)
    {
        $timestamps = TableFieldsGenerator::getTimestampFieldNames();

        $replace = '';

        if ($this->commandData->getOption('fromTable')) {
            if (empty($timestamps)) {
                $replace = infy_nl_tab()."public \$timestamps = false;\n";
            } else {
                list($created_at, $updated_at) = collect($timestamps)->map(function ($field) {
                    return !empty($field) ? "'$field'" : 'null';
                });

                $replace .= infy_nl_tab()."const CREATED_AT = $created_at;";
                $replace .= infy_nl_tab()."const UPDATED_AT = $updated_at;\n";
            }
        }

        return str_replace('$TIMESTAMPS$', $replace, $templateData);
    }

    public function generateSwagger($templateData)
    {
        $fieldTypes = SwaggerGenerator::generateTypes($this->commandData->inputFields);

        $template = TemplateUtil::getTemplate('model.model', 'swagger-generator');

        $template = TemplateUtil::fillTemplate($this->commandData->dynamicVars, $template);

        $template = str_replace('$REQUIRED_FIELDS$', implode(', ', $this->generateRequiredFields()), $template);

        $propertyTemplate = TemplateUtil::getTemplate('model.property', 'swagger-generator');

        $properties = SwaggerGenerator::preparePropertyFields($propertyTemplate, $fieldTypes);

        $template = str_replace('$PROPERTIES$', implode(",\n", $properties), $template);

        $templateData = str_replace('$DOCS$', $template, $templateData);

        return $templateData;
    }

    private function generateRequiredFields()
    {
        $requiredFields = [];

        foreach ($this->commandData->inputFields as $field) {
            if (!empty($field['validations'])) {
                if (str_contains($field['validations'], 'required')) {
                    $requiredFields[] = $field['fieldName'];
                }
            }
        }

        return $requiredFields;
    }

    private function generateRules()
    {
        $rules = [];

        foreach ($this->commandData->inputFields as $field) {
            if (!empty($field['validations'])) {
                $rule = "'".$field['fieldName']."' => '".$field['validations']."'";
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public function generateCasts()
    {
        $casts = [];

        $timestamps = TableFieldsGenerator::getTimestampFieldNames();

        foreach ($this->commandData->inputFields as $field) {
            if (in_array($field['fieldName'], $timestamps)) {
                continue;
            }

            switch ($field['fieldType']) {
                case 'integer':
                    $rule = "'".$field['fieldName']."' => 'integer'";
                    break;
                case 'double':
                    $rule = "'".$field['fieldName']."' => 'double'";
                    break;
                case 'float':
                    $rule = "'".$field['fieldName']."' => 'float'";
                    break;
                case 'boolean':
                    $rule = "'".$field['fieldName']."' => 'boolean'";
                    break;
                case 'dateTime':
                case 'dateTimeTz':
                    $rule = "'".$field['fieldName']."' => 'datetime'";
                    break;
                case 'date':
                    $rule = "'".$field['fieldName']."' => 'date'";
                    break;
                case 'enum':
                case 'string':
                case 'char':
                case 'text':
                    $rule = "'".$field['fieldName']."' => 'string'";
                    break;
                default:
                    $rule = '';
                    break;
            }

            if (!empty($rule)) {
                $casts[] = $rule;
            }
        }

        return $casts;
    }
    
    public function generateEloquentFunctions()
    {
        $x = 1;
    }
}
