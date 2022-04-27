<?php
const BX_SKIP_USER_LIMIT_CHECK = true;
const NOT_CHECK_PERMISSIONS = true;
const STOP_STATISTICS = true;
const BX_SENDPULL_COUNTER_QUEUE_DISABLE = true;

//$_SERVER['DOCUMENT_ROOT'] = substr(__FILE__, 0, strpos(__FILE__, '/api'));
require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Application,
    Partnerslkp\Controller\Base;

/*$get = Application::getInstance()->getContext()->getRequest()->getQueryList()->toArray();

//TODO когда будет готова автоматизация то убрать

$arResult = Base::getInstance($get)->run()->getResult();*/
$arResult = ['ibc here!'];
echo json_encode($arResult);