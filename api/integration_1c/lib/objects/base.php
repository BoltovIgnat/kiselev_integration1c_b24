<?php
namespace Partnerslkp\Objects;

class Base {
    private $arResult = [
        'STATUS' => true,
        'TEXT' => ''
    ],
    $entityID = 0;

    protected function setID($entityID) : void {
        $this->entityID = $entityID;
    }

    protected function getID() : int {
        return $this->entityID;
    }

    public function getResult() : array {
        return $this->arResult;
    }

    protected function setResult(array $arResult) : void {
        $this->arResult = $arResult;
    }

    protected function getStatus() {
        $arResult = $this->getResult();
        return $arResult['STATUS'];
    }

    protected function getMessage() {
        $arResult = $this->getResult();
        return $arResult['TEXT'];
    }
}