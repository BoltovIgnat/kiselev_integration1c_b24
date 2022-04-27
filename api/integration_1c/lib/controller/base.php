<?php

namespace Partnerslkp\Controller;

use Bitrix\Main\Loader,
    Bitrix\Main\LoaderException,
    Partnerslkp\Config\Core,
    Partnerslkp\Objects\Partnerslkp;

try {
    Loader::includeModule('tasks');
} catch (LoaderException $e) {
}

class Base
{
    private const NAME_VARIABLE_OBJECTS = 'className',
        NAME_VARIABLE_METHOD = 'methodName',
        NAME_VARIABLE_ID = 'entityID',
        AR_VARIABLE = [
        self::NAME_VARIABLE_ID,
        self::NAME_VARIABLE_METHOD,
        self::NAME_VARIABLE_OBJECTS
    ];

    private $className = '',
        $methodName = '',
        $entityID = 0,
        $arResult = [
        'STATUS' => true,
        'TEXT' => '',
    ];

    /**
     * @param array $params
     * @return Base
     */
    public static function getInstance(array $params): Base
    {
        return new Base($params);
    }

    /**
     * Base constructor.
     * @param array $params
     * @throws \CTaskAssertException
     */
    public function __construct(array $params)
    {
        $this->setParams($params);
        $this->checkParams();

        return $this;
    }

    /**
     * @return void
     * @throws \CTaskAssertException
     */
    private function checkParams(): void
    {
        /*foreach (self::AR_VARIABLE as $item) {

            if (empty($this->$item) || (is_numeric($this->$item) && $this->$item == 0)) {
                $this->setResult([
                    'STATUS' => false,
                    'TEXT' => 'Variable ' . $item . ' is empty or equal to zero'
                ]);
                return;
            }
        }

        if (!class_exists(Core::BASE_NAME_SPACE_OBJECTS . mb_convert_case($this->className, MB_CASE_TITLE))) {
            $this->setResult([
                'STATUS' => false,
                'TEXT' => 'Class ' . mb_convert_case($this->className, MB_CASE_TITLE) . ' does not exist'
            ]);
            return;
        }

        if (!method_exists(Core::BASE_NAME_SPACE_OBJECTS . mb_convert_case($this->className, MB_CASE_TITLE), $this->methodName)) {
            $this->setResult([
                'STATUS' => false,
                'TEXT' => 'Method ' . $this->methodName . ' does not exist'
            ]);
            return;
        }

        $oTask = \CTaskItem::getInstance($this->entityID, Core::USER_ID);

        $arData = $oTask->getData(false);
        if ($arData['GROUP_ID'] != Core::GROUP_DEBIT_ID) {
            $this->setResult([
                'STATUS' => false,
                'TEXT' => 'Task id ' . $this->entityID . ' not from the group of receivables'
            ]);
            return;
        }*/


    }

    /**
     * @param array $params
     */
    private function setParams(array $params): void
    {
        foreach (self::AR_VARIABLE as $item) {
            if (property_exists(__CLASS__, $item) && isset($params[$item])) {
                $this->$item = $params[$item];
            }
        }
    }

    /**
     * @return $this
     */
    public function run(): Base
    {
        if ($this->getStatus()) {
            $className = Core::BASE_NAME_SPACE_OBJECTS.mb_convert_case($this->className, MB_CASE_TITLE);
            $object = new $className($this->entityID);
            $methodName = $this->methodName;
            $this->setResult($object->$methodName()->getResult());
        }
        return $this;
    }

    /**
     * @param array $arResult
     */
    private function setResult(array $arResult): void
    {
        $this->arResult = $arResult;
    }

    /**
     * @return bool
     */
    private function getStatus(): bool
    {
        $arResult = $this->getResult();
        return $arResult['STATUS'];
    }

    /**
     * @return array
     */
    public function getResult(): array
    {
        return $this->arResult;
    }
}