<?php

namespace Gecche\Foorm;

use Cupparis\Acl\Facades\Acl;
use Cupparis\Ardent\Ardent;
use Gecche\DBHelper\Contracts\DBHelper;
use Gecche\Foorm\Contracts\ListBuilder;
use Gecche\ModelPlus\ModelPlus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;

class FormList
{

    use ConstraintBuilderTrait;
    use OrderBuilderTrait;
    use AggregateBuilderTrait;

    /**
     * @var array
     */
    protected $input;

    /**
     * @var ModelPlus
     */
    protected $model;

    /**
     * @var array
     */
    protected $params;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $modelName;

    /**
     * @var string
     */
    protected $modelRelativeName;

    /**
     * @var string
     */
    protected $primary_key_field;


    protected $relations;

    protected $hasManies;
    protected $belongsTos;


    /**
     * @var mixed
     */
    protected $formData;

    /**
     * @var array
     */
    protected $formAggregatesData;

    /**
     * @var mixed
     */
    protected $formMetadata = [];

    /**
     * @var \Closure|null
     */
    protected $listBuilder;

    /**
     * @var Builder|null
     */
    protected $formBuilder;

    /**
     * @var DBHelper
     */
    protected $dbHelper;

    /**
     * FormList constructor.
     * @param array $input
     * @param ModelPlus $model
     * @param array $params
     */
    public function __construct(array $config, ModelPlus $model, array $input, $params = [])
    {

        $this->input = $input;
        $this->model = $model;
        $this->params = $params;
        $this->config = $config;


        $this->dbHelper = DBHelper::helper($this->model->getConnectionName());

        $this->config = config('foorm.defaults', []);


    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->config = array_merge($this->config, $config);
    }


    public function getModelsNamespace()
    {
        return array_get($this->config,'models_namespace');
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
            $relations[$relationName]['relationType'] = $relations[$relationName][0];
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
            $relations[$relationName]['relationType'] = $relations[$relationName][0];
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


    protected function createOptions($fieldKey, $fieldValue, $defaultOptionsValues)
    {

        $options = $fieldValue['options'];

        //SE E' un array metto le options così come sono;
        if (is_array($options)) {
            return $options;
        }

        if ($options == 'boolean') {

            return [
                array_get($fieldValue, 'bool-false-value', $defaultOptionsValues['bool-false-value'])
                => array_get($fieldValue, 'bool-false-label', $defaultOptionsValues['bool-false-label']),
                array_get($fieldValue, 'bool-true-value', $defaultOptionsValues['bool-true-value'])
                => array_get($fieldValue, 'bool-true-label', $defaultOptionsValues['bool-true-label']),
            ];
        }

        if ($options == 'dboptions') {
            return $this->dbHelper->listEnumValues($fieldKey);
        }

        if (starts_with($options, 'relation:')) {

            $relationValue = explode($options);
            $relationModelName = $relationValue[1];

            $options = $relationModelName::getForSelectList();

            return $options;
        }

        return [];

    }

    protected function setNullOption($fieldValue, $options, $hasNullOption, $defaultOptionsValues)
    {

        if ($hasNullOption == 'onchoice' && count($options) <= 1) {
            return $options;
        }

        $nullLabel = array_get($fieldValue, 'null-label', $defaultOptionsValues['null-label']);

        $nullOption = [$defaultOptionsValues['null-value'] =>
            ucfirst(trans($nullLabel))];

        return $nullOption + $options;

    }


    protected function setFormMetadataFields() {
        $fields = array_get($this->config, 'fields');
        $this->formMetadata['fields'] = $this->_setFormMetadataFields($fields);

        return $fields;
    }

    protected function _setFormMetadataFields($fields = []) {


        $defaultOptionsValues = [
            'null-value' => $this->config['null-value'],
            'null-label' => $this->config['null-label'],
            'bool-false-value' => $this->config['bool-false-value'],
            'bool-false-label' => $this->config['bool-false-label'],
            'bool-true-value' => $this->config['bool-true-value'],
            'bool-true-label' => $this->config['bool-true-label'],
        ];
        foreach ($fields as $fieldKey => $fieldValue) {

            if (array_get($fieldValue, 'options')) {
                $options = $this->createOptions($fieldKey, $fieldValue, $defaultOptionsValues);

                $hasNullOption = array_get($fieldValue, 'nulloption', true);

                if ($hasNullOption) {
                    $options = $this->setNullOption($fieldValue, $options, $hasNullOption, $defaultOptionsValues);
                }

                $fieldValue['options'] = $options;
                unset($fieldValue['nulloption']);
            }

            $fields[$fieldKey] = $fieldValue;

        }

        return $fields;
    }

    protected function setFormMetadataRelations() {

        $relations = [];


        foreach ($this->hasManies as $key => $relationMetadata) {
            $relationMetadata = array_merge($relationMetadata,array_get($this->config['relations'],$key,[]));
            $relationMetadata['fields'] = $this->_setFormMetadataFields(array_get($relationMetadata,'fields',[]));
            $relations[$key] = $relationMetadata;
        }

        foreach ($this->belongsTos as $key => $relationMetadata) {
            $relationMetadata = array_merge($relationMetadata,array_get($this->config['relations'],$key,[]));
            $relations[$key] = $relationMetadata;
//            $modelName = $value['modelName'];
//            $options = $modelName::getForSelectList();
//            $value['options'] = $options;
//            $value['options_order'] = array_keys($options);
//            $this->resultParams[$key] = $value;
        }

        $this->formMetadata['relations'] = $relations;
        return $relations;
    }

    protected function setFormMetadataOrder() {
        $order_input = array_get($this->input,'order_field', false);
        if ($order_input !== false) {
            $this->formMetadata['order']['order_field'] = $order_input;
            $this->formMetadata['order']['order_direction'] = Input::get('order_direction', 'ASC');
        } else {
            $order = $this->model->getDefaultOrderColumns();
            $orderColumns = array_keys($order);
            $orderDirections = array_values($order);
            $this->formMetadata['order']['order_field'] = array_get($orderColumns, 0, 'id');
            $this->formMetadata['order']['order_direction'] = array_get($orderDirections, 0, 'ASC');
        }

    }

    public function setFormMetadata()
    {


        $this->setFormMetadataFields();

        $this->setFormMetadataRelations();

        $this->setFormMetadataOrder();


    }


}
