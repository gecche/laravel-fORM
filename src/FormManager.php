<?php

namespace Gecche\Foorm;

use Cupparis\Acl\Facades\Acl;
use Cupparis\Ardent\Ardent;
use Gecche\DBHelper\Contracts\DBHelper;
use Gecche\Foorm\Contracts\ListBuilder;
use Gecche\ModelPlus\ModelPlus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;

class FormManager
{

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var string
     */
    protected $formName;

    /**
     * @var Array
     */
    protected $config;

    protected $model;

    protected $form;

    protected $params;


    /**
     * FormList constructor.
     * @param array $input
     * @param ModelPlus $model
     * @param array $params
     */
    public function __construct($formName,Request $request,$params = [])
    {

        $this->formName = $formName;
        $this->request = $request;
        $this->params = $params;
        $this->getConfig();
        $this->setModel();
        $this->setForm();


    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return string
     */
    public function getFormName()
    {
        return $this->formName;
    }



    public function getConfig() {

        $defaultConfig = config('foorm',[]);

        $modelsNamespace = array_get($defaultConfig,'models_namespace',"App\\");
        $foormsNamespace = array_get($defaultConfig,'foorms_namespace',"App\\Foorm\\");
        $foormsDefaultsNamespace = array_get($defaultConfig,'foorms_defaults_namespace',"Gecche\\Foorm\\");

        $formNameParts = explode('.',$this->formName);
        if (count($formNameParts) != 2) {
            throw new \InvalidArgumentException('A foorm name should be of type "<FORMNAME>.<FORMTYPE>".');
        }

        $formConfig = config('foorms.'.$this->formName,false);

        if (!is_array($formConfig)) {
            throw new \InvalidArgumentException('Configuration of foorm '.$this->formName.' not found');
        }

        $finalConfig = array_replace_recursive($defaultConfig,$formConfig);

        $snakeModelName = array_get($formConfig,'model',$formNameParts[0]);


        $relativeModelName = studly_case($snakeModelName);
        $fullModelName = $modelsNamespace . $relativeModelName;

        if (!class_exists($fullModelName))
            throw new \InvalidArgumentException("Model class $fullModelName does not exists");


        $snakeFormName = array_get($formConfig,'form_type',$formNameParts[1]);
        $relativeFormName = studly_case($snakeFormName);
        $fullFormName = $foormsNamespace . $relativeModelName . "\\" . $relativeFormName;


        if (!class_exists($fullFormName)) {//Example: exists App\Foorm\User\List class?

            $fullFormName = $foormsNamespace . $relativeFormName;
            if (!class_exists($fullFormName)) {//Example: exists App\Foorm\List class?
                $fullFormName = $foormsDefaultsNamespace . $relativeFormName;

                if (!class_exists($fullFormName)) {//Example: exists Gecche\Foorm\List class?
                    throw new \InvalidArgumentException("Form class not found");
                }

            }

        }

        $finalConfig['model'] = $snakeModelName;
        $finalConfig['form_type'] = $snakeFormName;
        $finalConfig['models_namespace'] = $modelsNamespace;
        $finalConfig['foorms_namespace'] = $foormsNamespace;
        $finalConfig['foorms_default_namespace'] = $foormsDefaultsNamespace;
        $finalConfig['relative_form_name'] = $relativeFormName;
        $finalConfig['relative_model_name'] = $relativeModelName;

        $this->config = $finalConfig;

        return $finalConfig;

    }




    /**
     * @param Array $config
     */
    public function setConfig($config)
    {
        $this->config = array_merge($this->getConfig(), $config);
    }


    protected function setModel() {

        $fullModelName = array_get($this->config,'models_namespace')
            . array_get($this->config,'relative_model_name');

        $id = array_get($this->params,'id');
        if ($id) {
            $model = $fullModelName::find($id);
            if (!$model || !$model->getKey()) {
                throw new \InvalidArgumentException("Model $fullModelName with id $id not found.");
            }
        } else {
            $model = new $fullModelName;
        }

        $this->model = $model;
    }


    /**
     * @return array
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * @return ModelPlus
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @return string
     */
    public function getModelName()
    {
        return $this->modelName;
    }

    /**
     * @return string
     */
    public function getModelRelativeName()
    {
        return $this->modelRelativeName;
    }

    /**
     * @return string
     */
    public function getPrimaryKeyField()
    {
        return $this->primary_key_field;
    }


    public function getRelations()
    {
        if (is_null($this->relations)) {
            return $this->buildRelations();
        }
        return $this->relations;
    }

    public function setRelations(array $relations)
    {
        $this->relations = $relations;
    }

    /**
     * @return mixed
     */
    public function getInactiveRelations()
    {
        return $this->inactiveRelations;
    }

    /**
     * @param mixed $inactiveRelations
     */
    public function setInactiveRelations($inactiveRelations)
    {
        $this->inactiveRelations = $inactiveRelations;
    }


    /**
     * Costruisce l'array delle relazioni del modello principale del form.
     * Si basa sull'array relationsData del modelplus, esclude le relazioni dichiarate inattive
     * da $inactiveRelations
     *
     */
    public function buildRelations()
    {

        $modelName = $this->modelName;

        $relations = $modelName::getRelationsData();

        foreach ($relations as $key => $relation) {
            if (in_array($key, $this->inactiveRelations)) {
                unset($relations[$key]);
            }
        }

        $this->relations = $relations;

        return $relations;
    }


    /**
     * Costruisce l'array interno degli has many
     * Dalle relazioni prende solo quelle di tipo:
     * hasMany, belongsToMany, hasOne, morphMany
     *
     *
     * @return array
     */
    public function setHasManies()
    {

        $relations = $this->getRelations();

        foreach ($relations as $relationName => $relation) {

            $relations[$relationName]['max_items'] = 0;
            $relations[$relationName]['min_items'] = 0;
            switch ($relation[0]) {
                case ModelPlus::BELONGS_TO_MANY:

                    break;
                case ModelPlus::MORPH_MANY:

                    break;
                case ModelPlus::HAS_MANY:
                    break;
                case ModelPlus::HAS_ONE:
                    $relations[$relationName]['max_items'] = 1;
                    break;
                default:
                    unset($relations[$relationName]);
                    continue 2;  // per dire di continuare il ciclo for e non lo switch
                    break;
            }
            $relations[$relationName]['hasManyType'] = $relations[$relationName][0];
            unset($relations[$relationName][0]);
            $modelRelatedName = $relations[$relationName]['related'];
            unset($relations[$relationName][1]);
            $relations[$relationName]['modelName'] = $modelRelatedName;
            $relations[$relationName]['modelRelativeName'] = trim_namespace($this->getModelsNamespace(), $modelRelatedName);
            $relations[$relationName]['relationName'] = snake_case($relations[$relationName]['modelRelativeName']);
        }

        $this->hasManies = $relations;

        return $relations;
    }

    /**
     * @return array
     */
    public function getHasManies()
    {
        if (is_null($this->hasManies)) {
            return $this->setHasManies();
        }
        return $this->hasManies;
    }

    public function setBelongsTos()
    {

        $relations = $this->getRelations();

        foreach ($relations as $relationName => $relation) {

            switch ($relation[0]) {
                case ModelPlus::BELONGS_TO:
//                    $foreignKey = array_get($relations[$relationName], 'foreignKey', snake_case($relationName) . '_id');
                    $relations[$relationName]['relationName'] = $relationName;
                    break;
                default:
                    unset($relations[$relationName]);
                    continue 2;  // per dire di continuare il ciclo for e non lo switch
                    break;
            }
            unset($relations[$relationName][0]);
            $relations[$relationName]['modelName'] = $relations[$relationName]['related'];
            $relations[$relationName]['modelRelativeName'] = trim_namespace($this->getModelsNamespace(), $relations[$relationName]['related']);
            unset($relations[$relationName]['related']);
//QUI CAMBIAVA IL NOME DELLAR ELAZIONE CON IL NOME DELLA FOREIGNKEY, DA MIGLIORARE
//            if ($foreignKey !== $relationName) {
//                $relations[$foreignKey] = $relations[$relationName];
//                unset($relations[$relationName]);
//            }
        }

        $this->belongsTos = $relations;

        return $relations;
    }

    public function getBelongsTos()
    {
        if (is_null($this->belongsTos)) {
            return $this->setBelongsTos();
        }
        return $this->belongsTos;
    }


    protected function prepareRelationsData()
    {
        $this->getRelations();
        $this->getHasManies();
        $this->getBelongsTos();
    }


    /**
     * @param \Closure|string $builder
     */
    public function setListBuilder($builder)
    {
        $this->listBuilder = $builder;
    }


    /**
     *
     */
    protected function generateListBuilder()
    {


        $this->applylistBuilder();

        $this->applyRelations();

        $this->applyFixedConstraints();

    }


    protected function applyListBuilder()
    {
        if ($this->listBuilder instanceof \Closure) {
            $builder = $this->listBuilder;
            $this->formBuilder = $builder($this->model);
            return;
        }

        $modelClass = get_class($this->model);
        $this->formBuilder = $modelClass::query();

    }

    protected function applyRelations()
    {
        foreach (array_keys($this->relations) as $relationName) {
            $this->formBuilder = $this->formBuilder->with($relationName);
        }
    }

    protected function applyFixedConstraints()
    {
        $fixedConstraints = array_get($this->params, 'fixed_constraints', []);

        foreach ($fixedConstraints as $fixedConstraint) {
            $this->applyConstraint($fixedConstraint);
        }
    }

    protected function applySearchFilters()
    {
        $searchFilters = array_get($this->input, 'search_filters', []);

        foreach ($searchFilters as $searchFilter) {
            $this->applyConstraint($searchFilter);
        }
    }


    /**
     * @param \Closure|string $builder
     */
    public function setListOrder($builder)
    {
        $this->listOrder = $builder;
    }


    protected function applyListOrder()
    {
        $orderParams = array_get($this->input, 'order_params', []);

        $field = array_get($orderParams, 'field', null);

        if (!$field || !is_string($field)) {

            return $this->applyListOrderDefault();
        };
        return $this->applyListOrderFromInput($field, $orderParams);


    }

    protected function applyListOrderFromInput($field, $orderParams)
    {
        $direction = array_get($orderParams, 'direction', 'ASC');
        $params = array_get($orderParams, 'params', []);

        return $this->formBuilder = $this->buildOrder($this->formBuilder, $field, $direction, $params);

    }

    protected function applyListOrderDefault()
    {
        if ($this->listOrder instanceof \Closure) {
            $builder = $this->listOrder;
            $this->formBuilder = $builder($this->formBuilder);
        }

        return $this->formBuilder;

    }


    public function setPaginateSelect(array $paginateSelect)
    {
        $this->paginateSelect = $paginateSelect;
    }

    protected function paginateList()
    {


        $paginationInput = array_get($this->input, 'pagination', []);

        $perPage =
            array_get($paginationInput, 'per_page',
                array_get($this->config, 'per_page', 10)
            );

        $page = array_get($paginationInput, 'page', 1);

        $paginateSelect = is_array($this->paginateSelect)
            ? $this->paginateSelect
            : [$this->model->getTable() . ".*"];

        if ($perPage < 0) {
            //No pagination: set a fixed big value in order to have the same output structure
            $perPage = array_get($this->config, 'no_paginate_value', 1000000);
        }


//      $this->summaryResult = $this->formbuilder;
        $this->formbuilder = $this->formbuilder->paginate($perPage, $paginateSelect, 'page', $page);


        return $this->formBuilder;

    }


    public function finalizeData($finalizationFunc = null)
    {

        if ($finalizationFunc instanceof \Closure) {
            $this->formData = $finalizationFunc($this->formBuilder);
        } else {

            $this->formData = $this->finalizeDataStandard();
        }

        return $this->formData;

    }

    protected function finalizeDataStandard()
    {
        $arrayData = $this->formBuilder->toArray();



        return $arrayData;
    }

    /**
     * Generates and returns the data list
     *
     * - Defines the relations of the model involved in the form
     * - Generates an initial builder
     * - Apply search filters if any
     * - Apply the desired list order
     * - Paginate results
     * - Apply last transformations to the list
     * - Format the result
     *
     */
    public function getFormData()
    {

        $this->getFormBuilder();

        $this->paginateList();

        $this->finalizeData();

        return $this->formData;

    }


    public function getAggregatesData()
    {

        $aggregatesBuilder = $this->cloneFormBuilder();


//        count, max, min,  avg, and sum

        $aggregatesArray = array_get($this->params, 'aggregates', []);


        $this->formAggregatesData = $this->applyAggregates($aggregatesBuilder, $aggregatesArray);

    }


    protected function cloneFormBuilder()
    {
        $builder = $this->getFormBuilder();

        return clone($builder);

    }

    public function setFormBuilder()
    {

        $this->prepareRelationsData();

        $this->generateListBuilder();

        $this->applySearchFilters();

        $this->applyListOrder();

    }

    public function getFormBuilder()
    {
        if (is_null($this->formBuilder)) {
            $this->setFormBuilder();
        }

        return $this->formBuilder;
    }

    public function getFormMetadata()
    {
        if (is_null($this->formMetadata)) {
            $this->setFormMetadata();
        }

        return $this->formMetadata;

    }

    public function setFormMetadata()
    {


        $this->resultParams = $this->dbHelper->listColumnsDefault($this->model->getTable());

        $this->setResultParamsAppendsDefaults();
        $this->setResultParamsDefaults();

        foreach (array_keys($this->result) as $resultField) {

            if ($resultField == 'data') {
                $data = $this->result[$resultField];
                if (is_array($data) && !empty($data)) {
                    $firstData = current($data);
                    foreach (array_keys($firstData) as $resultDataField) {
                        $this->createResultParamItem($resultDataField);
                    }
                }
                continue;
            }

            $this->createResultParamItem($resultField);
        }


        $fieldParamsFromModel = $this->model->getFieldParams();
        $modelFieldsParamsFromModel = array_intersect_key($fieldParamsFromModel, $this->resultParams);

        $this->resultParams = array_replace_recursive($this->resultParams, $modelFieldsParamsFromModel);

        foreach ($this->hasManies as $key => $value) {
            $modelName = $value['modelName'];
            $model = new $modelName;
            $value["fields"] = $this->dbHelper->listColumnsDefault($model->getTable());

            $this->resultParams[$key] = $value;
        }

        foreach ($this->belongsTos as $key => $value) {
            $modelName = $value['modelName'];
            $options = $modelName::getForSelectList();
            $value['options'] = $options;
            $value['options_order'] = array_keys($options);
            $this->resultParams[$key] = $value;
        }

        $order_input = Input::get('order_field', false);
        if ($order_input) {
            $this->resultParams['order_field'] = $order_input;
            $this->resultParams['order_direction'] = Input::get('order_direction', 'ASC');
        } else {
            $order = $this->model->getDefaultOrderColumns();
            $orderColumns = array_keys($order);
            $orderDirections = array_values($order);
            $this->resultParams['order_field'] = array_get($orderColumns, 0, 'id');
            $this->resultParams['order_direction'] = array_get($orderDirections, 0, 'ASC');
        }

    }


}
