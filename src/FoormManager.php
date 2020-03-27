<?php

namespace Gecche\Foorm;

use Gecche\Breeze\Breeze;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class FoormManager
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
     * @var array
     */

    protected $defaultConfig;

    protected $config;

    protected $model;

    protected $form;

    protected $params;

    protected $inputManipulationFunction;


    /**
     * FormList constructor.
     * @param array $input
     * @param Breeze $model
     * @param array $params
     */
    public function __construct($formName, Request $request, $params = [])
    {

        $this->formName = $formName;
        $this->request = $request;
        $this->buildParams($params);
        $this->setDefaultConfig();
        $this->getConfig();
        $this->setModel();


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


    public function buildParams($params) {
//        $this->setFixedConstraintsToParams($params);
        $this->params = $params;
    }

    protected function setFixedConstraintsToParams($params) {
        if (is_array(Arr::get($params,'fixed_constraints'))) {
            return $params;
        }

        $requestFixedConstraints = $this->request->input('fixed_constraints');
        if (is_array($requestFixedConstraints)) {
            $params['fixed_constraints'] = $requestFixedConstraints;
        }

        return $params;
    }

    protected function fallbackFormName($formName)
    {
        $formNameParts = explode('.', $this->formName);

        $formType = Arr::get($formNameParts, 1, '');

        $formTypeFallbacks = config('foorm.types_fallbacks', []);
        if (!Arr::get($formTypeFallbacks, $formType)) {
            return $formName;
        }

        return $formNameParts[0] . '.' . $formTypeFallbacks[$formType];

    }


    public function setDefaultConfig($defaultConfig = null)
    {


        if (is_null($defaultConfig)) {
            $defaultConfig = config('foorm', []);
        }

        $defaultConfig['models_namespace'] = Arr::get($defaultConfig, 'models_namespace', "App\\");
        $defaultConfig['foorms_namespace'] = Arr::get($defaultConfig, 'foorms_namespace', "App\\Foorm\\");
        $defaultConfig['foorms_defaults_namespace'] = Arr::get($defaultConfig, 'foorms_defaults_namespace', "Gecche\\Foorm\\");

        $this->defaultConfig = $defaultConfig;

    }

    public function getConfig()
    {

        $defaultConfig = $this->defaultConfig;

        $formNameParts = explode('.', $this->formName);
        if (count($formNameParts) != 2) {
            throw new \InvalidArgumentException('A foorm name should be of type "<FORMNAME>.<FORMTYPE>".');
        }

        $formConfig = $this->getFormTypeConfig($this->formName);

        $finalConfig = array_replace_recursive($defaultConfig, $formConfig);

        $snakeModelName = Arr::get($formConfig, 'model', $formNameParts[0]);
        $relativeModelName = Str::studly($snakeModelName);
        $fullModelName = $finalConfig['models_namespace'] . $relativeModelName;

        if (!class_exists($fullModelName))
            throw new \InvalidArgumentException("Model class $fullModelName does not exists");

        $finalConfig = array_merge($finalConfig,$this->getRealFoormClass($formConfig, $relativeModelName, $formNameParts[1]));

        $finalConfig['model'] = $snakeModelName;
        $finalConfig['relative_model_name'] = $relativeModelName;
        $finalConfig['full_model_name'] = $fullModelName;

        foreach (Arr::get($formConfig,'dependencies',[]) as $dependencyKey => $dependencyFormType) {
            $dependencyConfig = $this->getFormTypeConfig($formNameParts[0].'.'.$dependencyFormType);


            $dependencyConfig = array_replace_recursive($defaultConfig, $dependencyConfig);
            $dependencyConfig = array_merge($dependencyConfig,$this->getRealFoormClass($formConfig, $relativeModelName, $dependencyFormType));

            $dependencyConfig['model'] = $snakeModelName;
            $dependencyConfig['relative_model_name'] = $relativeModelName;
            $dependencyConfig['full_model_name'] = $fullModelName;


            $finalConfig['dependencies'][$dependencyKey] = $dependencyConfig;
        }

        $this->config = $finalConfig;

        return $finalConfig;

    }


    protected function getFormTypeConfig($formName) {
        $formConfig = config('foorms.' . $formName, false);

        if (!is_array($formConfig)) {
            $formConfig = config('foorms.' . $this->fallbackFormName($formName), false);
            if (!is_array($formConfig)) {
                throw new \InvalidArgumentException('Configuration of foorm ' . $formName . ' not found');
            }
        }

        return $formConfig;
    }

    protected function getRealFoormClass($formConfig, $relativeModelName, $formNameToCheck)
    {
        $snakeFormName = Arr::get($formConfig, 'form_type', $formNameToCheck);
        $relativeFormName = Str::studly($snakeFormName);
        $fullFormName = $this->defaultConfig['foorms_namespace'] . $relativeModelName . "\\Foorm" . $relativeFormName;


        if (!class_exists($fullFormName)) {//Example: exists App\Foorm\User\List class?

            $fullFormName = $this->defaultConfig['foorms_namespace'] . $relativeFormName;
            if (!class_exists($fullFormName)) {//Example: exists App\Foorm\List class?
                $fullFormName = $this->defaultConfig['foorms_defaults_namespace'] . 'Foorm' . $relativeFormName;

                if (!class_exists($fullFormName)) {//Example: exists Gecche\Foorm\List class?
                    throw new \InvalidArgumentException("Foorm class not found");
                }

            }

        }

        return [
            'form_type' => $snakeFormName,
            'relative_form_name' => $relativeFormName,
            'full_form_name' => $fullFormName,
        ];

    }

    /**
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->config = array_merge($this->getConfig(), $config);
    }


    protected function setModel()
    {

        $fullModelName = Arr::get($this->config, 'full_model_name');

        $id = Arr::get($this->params, 'id');
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

    protected function setForm()
    {


        $input = $this->setInputForForm($this->request->input());

        $fullFormName = Arr::get($this->config, 'full_form_name');



        $this->form = new $fullFormName($this->config, $this->model, $input, $this->params);

        $dependentForms = [];

        foreach (Arr::get($this->config,'dependencies',[]) as $dependencyKey => $dependencyConfig) {
            $dependentFormName =  Arr::get($dependencyConfig, 'full_form_name');
            $dependentForms[$dependencyKey] = new $dependentFormName($dependencyConfig, $this->model, $input, $this->params);
        }

        $this->form->setDependentForms($dependentForms);

    }


    public function setInputManipulationFunction(\Closure $closure)
    {
        $this->inputManipulationFunction = $closure;
    }

    protected function setInputForForm($input)
    {


        $inputManipulationFunction = $this->inputManipulationFunction;

        if ($inputManipulationFunction instanceof \Closure) {
            return $inputManipulationFunction($input);
        }


    }


    /**
     * @return Breeze
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
     * @return mixed
     */
    public function getForm()
    {
        if (!$this->form) {
            $this->setForm();
        }
        return $this->form;
    }


}
