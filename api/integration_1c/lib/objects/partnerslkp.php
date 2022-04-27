<?php
namespace Partnerslkp\Objects;

use Bitrix\Crm\CompanyTable,
    Bitrix\Main\Loader,
    Bitrix\Main\ArgumentException,
    Bitrix\Main\ObjectPropertyException,
    Bitrix\Main\SystemException,
    Oktell\Base\Controller\Base as OBase,
    Bitrix\Main\LoaderException,
    Bitrix\Crm\ProductRowTable,
    Bitrix\Main\Mail\Event,
    modules\companies_base\entity\CompaniesCashboxesTable,
    modules\companies_base\entity\CompaniesTable,
    Bitrix\Main\ArgumentNullException,
    Debit\Config\Core,
    \Bitrix\Tasks\Item\Task as BT,
    Bitrix\Main\UserTable,
    Bitrix\Main\Entity\ReferenceField,
    Bitrix\Tasks\Internals\TaskTable,
    Collection\Controller\IlmaCollection as IC,
    Collection\Entity\CollectionStatusTrackerTable as CSTT;
use Collection\Controller\Controller_1c;
use Collection\Utils;
use Lib\Crm\ThreeInOne\DealUtils;
use Lib\Crm\ThreeInOne\Lead as TLead;
use Lib\Crm\ThreeInOne\LeadUtils;

try {
    Loader::includeModule('tasks');
    Loader::includeModule('bizproc');
} catch (LoaderException $e) {
}

class Partnerslkp extends Base{
    private int $companyID = 0;
    private array $arPhones = [];
    private array $arEmails = [];
    private string $inn = '';
    private string $taskNameToOktel = '';

    private const
        TASK_NAME_DEBIT_DFO = 'ИЗ - ПО Дебиторка прокат ДФО свежие',
        TASK_NAME_DEBIT_CENTRE = 'ИЗ - ПО Дебиторка прокат Центр свежие',
        TASK_NAME_BEFORE_COURT = 'ИЗ – ПО Иван 3.Дебиторка перед судом (2)',
        EMAIL_TEMPLATE_NAME = 'FIRST_NOTIFICATION',
        STATUS_OKTEL_OK = 1,
        STATUS_OKTEL_DOUBLE = 2,
        STATUS_OKTEL_ERROR = 3,
        WORK_FLOW_TEMPLATE_ID_FIRST_NOTIFICATION = 939,
        WORK_FLOW_TEMPLATE_ID_SECOND_NOTIFICATION = 940;

    public const
        USER_ID = 1, //TODO найти где используется и удалить
        GROUP_ID_REPLACEMENT = 195,
        GROUP_ID_EXTENSION = 188,

        CATEGORY_ID = 71,

        STAGE_BANK_NEW = 'C71:NEW',
        STAGE_CHOISE_PARTNER = 'C71:PREPARATION', //Выбор парнера
        STAGE_IN_LKP = 'C71:PREPAYMENT_INVOIC', //Переданов в ЛКП
        STAGE_IN_LKP_WORKING = 'C71:EXECUTING', //Переданов в работу в ЛКП
        STAGE_REFUSAL_LKP = 'C71:FINAL_INVOICE', //Отказ партнера в ЛКП
        STAGE_CHANGE_PARTNER = 'C71:1', //Сменить партнера
        STAGE_IN_SERVICE = 'C71:2', //Сменить партнера
        STAGE_HELP_PARTNER = 'C71:3', //Помощь партнеру
        STAGE_PROBLEM_CLIENT = 'C71:4', //Помощь партнеру
        STAGE_ADD_DOC = 'C71:5', //Добавить документы
        STAGE_INVOICE_ISSUED = 'C71:6', //Счет выставлен
        STAGE_INVOICE_PAID = 'C71:7', //Счет оплачен
        STAGE_PROMO = 'C71:8', //Промо код
        STAGE_REFUSAL_RENEW = 'C71:9', //Отказ в перевыпуске
        STAGE_WAITING_ACCEPTANCE = 'C71:10', //Ожидание акцепта
        STAGE_ACCEPTANCE = 'C71:11'; // акцепта



    /**
     * @throws \CTaskAssertException
     */
    public function __construct($id)
    {
        $this->setID($id);
    }


    //ibc Работа с лидами
    public function addLeadBankRequest()
    {

        Loader::includeModule('tasks');
        Loader::includeModule('crm');

        $arFields = [
            'TITLE' => 'Заявка на продукт 3 в 1',
            //Lib\Crm\ThreeInOne\LeadUtils::FIELD_NAME_INN => $this->inn,
            //'UF_CRM_1536237105' => $this->countCashBox,
            'STATUS_ID' => 'NEW',
            'NAME' => 'Заявка на продукт 3 в 1',
            /*LeadUtils::FIELD_NAME_ACQUIRING_BANK => LeadUtils::VALUE_ACQUIRING_BANK_AKBARS,
            LeadUtils::FIELD_NAME_TYPE_LEAD => LeadUtils::VALUE_TYPE_LEAD,
            LeadUtils::FIELD_NAME_SUB_SOURCE => LeadUtils::VALUE_SUB_SOURCE_CLIENT_BANK,*/
            'ASSIGNED_BY_ID' => 1,//self::ASSIGNED,
            'OPENED' => 'Y',
            'FM' => [
                \CCrmFieldMulti::PHONE => [
                    'n0' => [
                        'VALUE' => '+79827920592',
                        'VALUE_TYPE' => 'WORK',
                    ]
                ],
                \CCrmFieldMulti::EMAIL => [
                    'n0' => [
                        'VALUE' => 'a@a.ru',
                        'VALUE_TYPE' => 'WORK',
                    ]
                ],
            ],
        ];

        $obLead = new TLead();
        $result = $obLead->addLead($arFields);

        return $result;
    }

    //ibc Работа со сделками
    public function addDealCashBoxRent() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(226204);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }

        $productHTML .= '<ul>';
        $productHTML .= '<li>Создать акт приема-передачи</li>';
        $productHTML .= '<li>Зарегистрировать в ОФД и ФНС</li>';
        $productHTML .= '<li>Выдать клиенту, подписав акт</li>';
        $productHTML .= '<li>Указать дату передачи</li>';
        $productHTML .= '</ul>';

        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                //ibc inn
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = 'C63:NEW';

        $arDealFields = [
            'TITLE' => $arDeal['TITLE'],
            'OPPORTUNITY' => $arDeal['OPPORTUNITY'],
            'CATEGORY_ID' => 63,
            'TYPE_ID' => 4, //Касса на прокат
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'],
            'UF_CRM_1537510012' => 831, //Касса напрокат КЦ
            'UF_CRM_1524748128' => $arDeal['CRM_DEAL_REGION_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_REGION_SHORTNAME'],
            'UF_CRM_CITYLB' => ($arDeal['CRM_DEAL_CITY_OFFNAME'] || $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) ?
                ($arDeal['CRM_DEAL_CITY_OFFNAME'] ? $arDeal['CRM_DEAL_CITY_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_CITY_SHORTNAME'] : $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) :
                $arDeal['UF_CRM_1508746630'],
            'COMMENTS' => $arDeal['COMMENTS'] . $productHTML,
            'UF_CRM_1537512224' => $tarif[(int)$arDeal['UF_CRM_1537512156']],
            'UF_CRM_1537363245' => $_GET['id'],
            'STAGE_ID' => $STAGE_ID,
            'OPENED' => 'Y',
            'UF_CRM_1529914464' => is_numeric($arDeal['UF_CRM_5B911FADF17D1']) ? $arDeal['UF_CRM_5B911FADF17D1'] : 0,
            //ibc inn
            'UF_CRM_INNLB' => $arDeal[\Lib\Custom\CDeal::NAME_FIELD_INN],
            'UF_CRM_1543565571387' => $arDeal['UF_CRM_5B8FDE88BF164'],
            //'UF_CRM_1545291051' => $get['source'] == 'box' ? 1631 : 1629
        ];
        //ud($arDealFields);
        $res = $obDeal->add($arDealFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDealActivationKKT() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(226204);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }

        $productHTML .= '<ul>';
        $productHTML .= '<li>Зарегистрировать в ОФД и ФНС без выдачи оборудования</li>';
        $productHTML .= '<li>Вложить скан чек-листа, подписанного Клиентом.</li>';
        $productHTML .= '</ul>';

        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                //ibc inn
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = 'C63:NEW';

        $arDealFields = [
            'TITLE' => $arDeal['TITLE'],
            'OPPORTUNITY' => $arDeal['OPPORTUNITY'],
            'CATEGORY_ID' => 63,
            'TYPE_ID' => 4, //Касса на прокат
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'],
            'UF_CRM_1537510012' => 831, //Касса напрокат КЦ
            'UF_CRM_1524748128' => $arDeal['CRM_DEAL_REGION_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_REGION_SHORTNAME'],
            'UF_CRM_CITYLB' => ($arDeal['CRM_DEAL_CITY_OFFNAME'] || $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) ?
                ($arDeal['CRM_DEAL_CITY_OFFNAME'] ? $arDeal['CRM_DEAL_CITY_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_CITY_SHORTNAME'] : $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) :
                $arDeal['UF_CRM_1508746630'],
            'COMMENTS' => $arDeal['COMMENTS'] . $productHTML,
            'UF_CRM_1537512224' => $tarif[(int)$arDeal['UF_CRM_1537512156']],
            'UF_CRM_1537363245' => $_GET['id'],
            'STAGE_ID' => $STAGE_ID,
            'OPENED' => 'Y',
            'UF_CRM_1529914464' => is_numeric($arDeal['UF_CRM_5B911FADF17D1']) ? $arDeal['UF_CRM_5B911FADF17D1'] : 0,
            //ibc inn
            'UF_CRM_INNLB' => $arDeal[\Lib\Custom\CDeal::NAME_FIELD_INN],
            'UF_CRM_1543565571387' => $arDeal['UF_CRM_5B8FDE88BF164'],
            //'UF_CRM_1545291051' => $get['source'] == 'box' ? 1631 : 1629
        ];
        //ud($arDealFields);
        $res = $obDeal->add($arDealFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDealCashBoxOneCheck() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(226204);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }

        $productHTML .= '<ul>';
        $productHTML .= '<li>Оказать услугу «Касса на 1 чек» (короткая ссылка на описание услуги)</li>';
        $productHTML .= '<li>Вложить скан УПД, подписанный клиентом (во вложении)</li>';
        $productHTML .= '<li>Копия карточки о снятии ККТ с учета в ФНС</li>';
        $productHTML .= '</ul>';

        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                //ibc inn
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = 'C63:NEW';

        $arDealFields = [
            'TITLE' => $arDeal['TITLE'],
            'OPPORTUNITY' => $arDeal['OPPORTUNITY'],
            'CATEGORY_ID' => 63,
            'TYPE_ID' => 4, //Касса на прокат
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'],
            'UF_CRM_1537510012' => 831, //Касса напрокат КЦ
            'UF_CRM_1524748128' => $arDeal['CRM_DEAL_REGION_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_REGION_SHORTNAME'],
            'UF_CRM_CITYLB' => ($arDeal['CRM_DEAL_CITY_OFFNAME'] || $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) ?
                ($arDeal['CRM_DEAL_CITY_OFFNAME'] ? $arDeal['CRM_DEAL_CITY_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_CITY_SHORTNAME'] : $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) :
                $arDeal['UF_CRM_1508746630'],
            'COMMENTS' => $arDeal['COMMENTS'] . $productHTML,
            'UF_CRM_1537512224' => $tarif[(int)$arDeal['UF_CRM_1537512156']],
            'UF_CRM_1537363245' => $_GET['id'],
            'STAGE_ID' => $STAGE_ID,
            'OPENED' => 'Y',
            'UF_CRM_1529914464' => is_numeric($arDeal['UF_CRM_5B911FADF17D1']) ? $arDeal['UF_CRM_5B911FADF17D1'] : 0,
            //ibc inn
            'UF_CRM_INNLB' => $arDeal[\Lib\Custom\CDeal::NAME_FIELD_INN],
            'UF_CRM_1543565571387' => $arDeal['UF_CRM_5B8FDE88BF164'],
            //'UF_CRM_1545291051' => $get['source'] == 'box' ? 1631 : 1629
        ];
        //ud($arDealFields);
        $res = $obDeal->add($arDealFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDealOneTimeService() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(226204);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }

        $productHTML .= '<ul>';
        $productHTML .= '<li>Оказать услугу, указанную в товарах.</li>';
        $productHTML .= '<li>Вложить скан чек-листа, подписанного Клиентом.</li>';
        $productHTML .= '</ul>';

        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                //ibc inn
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = 'C63:NEW';

        $arDealFields = [
            'TITLE' => $arDeal['TITLE'],
            'OPPORTUNITY' => $arDeal['OPPORTUNITY'],
            'CATEGORY_ID' => 63,
            'TYPE_ID' => 4, //Касса на прокат
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'],
            'UF_CRM_1537510012' => 831, //Касса напрокат КЦ
            'UF_CRM_1524748128' => $arDeal['CRM_DEAL_REGION_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_REGION_SHORTNAME'],
            'UF_CRM_CITYLB' => ($arDeal['CRM_DEAL_CITY_OFFNAME'] || $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) ?
                ($arDeal['CRM_DEAL_CITY_OFFNAME'] ? $arDeal['CRM_DEAL_CITY_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_CITY_SHORTNAME'] : $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) :
                $arDeal['UF_CRM_1508746630'],
            'COMMENTS' => $arDeal['COMMENTS'] . $productHTML,
            'UF_CRM_1537512224' => $tarif[(int)$arDeal['UF_CRM_1537512156']],
            'UF_CRM_1537363245' => $_GET['id'],
            'STAGE_ID' => $STAGE_ID,
            'OPENED' => 'Y',
            'UF_CRM_1529914464' => is_numeric($arDeal['UF_CRM_5B911FADF17D1']) ? $arDeal['UF_CRM_5B911FADF17D1'] : 0,
            //ibc inn
            'UF_CRM_INNLB' => $arDeal[\Lib\Custom\CDeal::NAME_FIELD_INN],
            'UF_CRM_1543565571387' => $arDeal['UF_CRM_5B8FDE88BF164'],
            //'UF_CRM_1545291051' => $get['source'] == 'box' ? 1631 : 1629
        ];
        //ud($arDealFields);
        $res = $obDeal->add($arDealFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDealExtensionOffd() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(226204);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }

        $productHTML .= '<ul>';
        $productHTML .= '<li>Оказать услугу, указанную в товарах.</li>';
        $productHTML .= '<li>Вложить скан чек-листа, подписанного Клиентом.</li>';
        $productHTML .= '</ul>';

        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                //ibc inn
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = 'C63:NEW';

        $arDealFields = [
            'TITLE' => 'Ручное продление ОФД, ИНН ',
            'OPPORTUNITY' => $arDeal['OPPORTUNITY'],
            'CATEGORY_ID' => 63,
            'TYPE_ID' => 12, //Касса на прокат
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'COMMENTS' => $productHTML,
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'],

            'UF_CRM_INNLB' => $arDeal['UF_CRM_INNLB'],
            'UF_CRM_1543565571387' => 1,
            'UF_CRM_1537363245' => $arDeal['ID'],
        ];
        //ud($arDealFields);
        $res = $obDeal->add($arDealFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDealReplacementFn() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        require_once $_SERVER['DOCUMENT_ROOT'].'/local/php_interface/cron/tasksreplacementfn.php';

        $oTask = \CTaskItem::getInstance($this->getID(), \Custom\Task\Core::U_ADMIN);
        $arTask = $oTask->getData(false, ['select' => [\Custom\Task\Core::FIELDS_INN_IN_TASKS, 'DESCRIPTION']]);
        $companyID = getCompanyIDFromRequisite($arTask[\Custom\Task\Core::FIELDS_INN_IN_TASKS]);
        $arCompany = getCompanyInfoByInn($arTask[\Custom\Task\Core::FIELDS_INN_IN_TASKS]);
        $arPhone = getPhoneByCompanyIDDetail($companyID);
        $partnerID = \Lib\Custom\CCompany::getPartnerByCompanyID($companyID);
        $assigned = 366;
        $issetPartner = false;


        $comments = '
        <p>Вам необходимо оказать услугу «Перерегистрация в связи с заменой ФН». Услуга предоставляется в рамках 
        закрепления Клиента за вами. Не забудьте вложить в сделку сканы: Отчет о закрытии архива ФН и Отчет о 
        регистрации нового ФН.</p>
        <p><b>Внимание!</b> Изменение типа ФН (например, ФН 15 на ФН 36) всегда происходит через заявление на смену 
        тарифа, отправленного на: <a href="mailto:tarif@litebox.ru">tarif@litebox.ru</a></p>
        ';

        $arTask['DESCRIPTION'] = str_replace(TasksReplacementFN::DEFAULT_DESCRIPTION,'',$arTask['DESCRIPTION']);

        $table = str_replace([
            '[TABLE]','[/TABLE]','[TR]','[/TR]','[TD]','[/TD]','[B]','[/B]',
        ],[
            '<table>','</table>','<tr>','</tr>','<td>','</td>','<b>','</b>'
        ],
            $arTask['DESCRIPTION']);

        $array = [];
        foreach ($arPhone as $arItems)
        {
            foreach ($arItems as $value_type => $value) {
                $array[] = [
                    'VALUE' => $value,
                    'VALUE_TYPE' => mb_convert_case($value_type, MB_CASE_UPPER)
                ];
            }
        }

        $arFields = [
            'TITLE' => 'Перерегистрация ['.$arTask[\Custom\Task\Core::FIELDS_INN_IN_TASKS].']',
            'COMMENTS' => $comments.'<br/>'.$table,
            'ASSIGNED_BY_ID' => $assigned,
            'CATEGORY_ID' => 63,
            'TYPE_ID'=> 12,
            'COMPANY_ID' => $arTask['COMPANY_ID'],
            'UF_CRM_INNLB' => $arTask[\Custom\Task\Core::FIELDS_INN_IN_TASKS],
            'UF_CRM_1537363245' => $this->getID(),
            'STAGE_ID' => 'C63:NEW',
            'SOURCE_ID' => 6
        ];


        $res = $obDeal->add($arFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDealDeactivation() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(226204);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }


        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                //ibc inn
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = 'C63:NEW';

        $arFields = [
            'TITLE' => $arDeal['TITLE'],
            'CATEGORY_ID' => 3, //Декактивация
            'STAGE_ID' => 'C3:NEW',
            'UF_CRM_1537363245' => $arDeal['ID'],
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'], //Миронова
            //ibc inn
            'UF_CRM_INNLB' => $arDeal[\Lib\Custom\CDeal::NAME_FIELD_INN], //инн
            //ibc inn
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'COMMENTS' => $arDeal['COMMENTS'] . $productHTML,
            'UF_CRM_CITYLB' => $arDeal['UF_CRM_1508746630'],
            //'UF_CRM_1567663996' => isset($get['type_termination']) && !empty($get['type_termination']) ? $get['type_termination'] : '',
        ];
        //ud($arDealFields);
        $res = $obDeal->add($arFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDeal3To1() : Partnerslkp
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(226204);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }

        $productHTML .= '<ul>';
        $productHTML .= '<li>Создать акт приема-передачи</li>';
        $productHTML .= '<li>Зарегистрировать в ОФД и ФНС</li>';
        $productHTML .= '<li>Выдать клиенту, подписав акт</li>';
        $productHTML .= '<li>Указать дату передачи</li>';
        $productHTML .= '<li>Вложить скан акта с подписями</li>';
        $productHTML .= '</ul>';

        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                //ibc inn
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = 'C63:NEW';

        $arDealFields = [
            'TITLE' => $arDeal['TITLE'],
            'OPPORTUNITY' => $arDeal['OPPORTUNITY'],
            'CATEGORY_ID' => 63,
            'TYPE_ID' => 4, //Касса на прокат
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'],
            'UF_CRM_1537510012' => 831, //Касса напрокат КЦ
            'UF_CRM_1524748128' => $arDeal['CRM_DEAL_REGION_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_REGION_SHORTNAME'],
            'UF_CRM_CITYLB' => ($arDeal['CRM_DEAL_CITY_OFFNAME'] || $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) ?
                ($arDeal['CRM_DEAL_CITY_OFFNAME'] ? $arDeal['CRM_DEAL_CITY_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_CITY_SHORTNAME'] : $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) :
                $arDeal['UF_CRM_1508746630'],
            'COMMENTS' => $arDeal['COMMENTS'] . $productHTML,
            'UF_CRM_1537512224' => $tarif[(int)$arDeal['UF_CRM_1537512156']],
            'UF_CRM_1537363245' => $_GET['id'],
            'STAGE_ID' => $STAGE_ID,
            'OPENED' => 'Y',
            'UF_CRM_1529914464' => is_numeric($arDeal['UF_CRM_5B911FADF17D1']) ? $arDeal['UF_CRM_5B911FADF17D1'] : 0,
            //ibc inn
            'UF_CRM_INNLB' => $arDeal[\Lib\Custom\CDeal::NAME_FIELD_INN],
            'UF_CRM_1543565571387' => $arDeal['UF_CRM_5B8FDE88BF164'],
            //'UF_CRM_1545291051' => $get['source'] == 'box' ? 1631 : 1629
        ];
        //ud($arDealFields);
        $res = $obDeal->add($arDealFields);
        //ud($res);

        ud($res);
        return $this;
    }

    public function addDealBankRequest() : Partnerslkp
    {

        AddMessage2Log("ibc web hook working!");

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);

        $arProduct = self::getProductByDealID(125780);

        if ($arProduct) {
            $productHTML = '<p><b>Товары/Усулуги: </b></p>';
            $productHTML .= '<ul>';
            foreach ($arProduct as $atItem) {
                $productHTML .= '<li>' . $atItem['NAME'] . ' (' . $atItem['QUANTITY'] . ')';
            }
            $productHTML .= '</ul>';
        }

        $tarif = [
            1882 => 835,
            1884 => 837,
            1886 => 839
        ];
        $arDeal = \Bitrix\Crm\DealTable::getRow([
            'filter' => ['ID' => 226204],
            'select' => [
                'ID', 'TITLE', 'OPPORTUNITY', 'COMMENTS', 'UF_CRM_1537512156',
                'UF_CRM_5B911FADF17D1', 'UF_CRM_5B8FDE88BF164', 'UF_CRM_1508746630',
                'UF_CRM_1536050270',
                'COMPANY_ID', 'CONTACT_ID',
                'NAME' => 'CONTACT.NAME',
                \Lib\Custom\CDeal::NAME_FIELD_INN,
                'LAST_NAME' => 'CONTACT.LAST_NAME',
                'SECOND_NAME' => 'CONTACT.SECOND_NAME',
                'C_PHONE' => 'CONTACT.PHONE_WORK',
                'CM_PHONE' => 'CONTACT.PHONE_MOBILE',
                'C_EMAIL' => 'CONTACT.EMAIL_WORK',
                'CM_EMAIL' => 'CONTACT.EMAIL_HOME',
                'COMPANY_TITLE' => 'COMPANY.TITLE',
                'COPMANY_PHONE' => 'COMPANY.PHONE_WORK',
                'COPMANY_EMAIL' => 'COMPANY.EMAIL_WORK',
                'REGION', 'CITY', 'LOCALITY'

            ],
            'runtime' => [
                'CONTACT' => [
                    'data_type' => '\Bitrix\Crm\ContactTable',
                    'reference' => ['this.CONTACT_ID' => 'ref.ID']
                ],
                'COMPANY' => [
                    'data_type' => '\Bitrix\Crm\CompanyTable',
                    'reference' => ['this.COMPANY_ID' => 'ref.ID']
                ],
                'REGION' => [
                    'data_type' => '\Fias\Entity\RegionTable',
                    'reference' => ['this.UF_REGION_FIAS_ID' => 'ref.REGIONCODE']
                ],
                'CITY' => [
                    'data_type' => '\Fias\Entity\CityTable',
                    'reference' => ['this.UF_CITY_FIAS_ID' => 'ref.AOGUID']
                ],
                'LOCALITY' => [
                    'data_type' => '\Fias\Entity\LocalityTable',
                    'reference' => ['this.UF_LOCALITY_FIAS' => 'ref.AOGUID']
                ]
            ]
        ]);

        $STAGE_ID = self::STAGE_WAITING_ACCEPTANCE;

        $arDealFields = [
            'TITLE' => 'Тестирование работы внутреннего веб хука',
            'OPPORTUNITY' => $arDeal['OPPORTUNITY'],
            'CATEGORY_ID' => self::CATEGORY_ID,
            'TYPE_ID' => 4,
            'COMPANY_ID' => $arDeal['COMPANY_ID'],
            'CONTACT_ID' => $arDeal['CONTACT_ID'],
            'ASSIGNED_BY_ID' => $arDeal['ASSIGNED_BY_ID'],
            'UF_CRM_1537510012' => 831, //Касса напрокат КЦ
            'UF_CRM_1524748128' => $arDeal['CRM_DEAL_REGION_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_REGION_SHORTNAME'],
            'UF_CRM_CITYLB' => ($arDeal['CRM_DEAL_CITY_OFFNAME'] || $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) ?
                ($arDeal['CRM_DEAL_CITY_OFFNAME'] ? $arDeal['CRM_DEAL_CITY_OFFNAME'] . ' ' . $arDeal['CRM_DEAL_CITY_SHORTNAME'] : $arDeal['CRM_DEAL_LOCALITY_OFFNAME']) :
                $arDeal['UF_CRM_1508746630'],
            'COMMENTS' => $arDeal['COMMENTS'] . $productHTML,
            'UF_CRM_1537512224' => $tarif[(int)$arDeal['UF_CRM_1537512156']],
            'UF_CRM_1537363245' => $_GET['id'],
            'STAGE_ID' => $STAGE_ID,
            'OPENED' => 'Y',
            'UF_CRM_1529914464' => is_numeric($arDeal['UF_CRM_5B911FADF17D1']) ? $arDeal['UF_CRM_5B911FADF17D1'] : 0,
            'UF_CRM_INNLB' => $arDeal[\Lib\Custom\CDeal::NAME_FIELD_INN],
            'UF_CRM_1543565571387' => $arDeal['UF_CRM_5B8FDE88BF164'],

        ];

        $res = $obDeal->add($arDealFields);

        return $this;
    }

    public function moveDealRefusalBankRequest()
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);
        $arFields = ['STAGE_ID'=>self::STAGE_WAITING_ACCEPTANCE];
        $bSuccess = $obDeal->Update(323280,$arFields);

        return $bSuccess;
    }

    public function moveDealRefusalBankRequestByStage($stage)
    {

        Loader::includeModule('tasks');

        $obDeal = new \CCrmDeal(false);
        $arFields = ['STAGE_ID'=>$stage];
        $bSuccess = $obDeal->Update(323280,$arFields);

        return $bSuccess;
    }

    public function sayHelloInner() : Partnerslkp
    {
        ud('Hello');
        return $this;
    }

    public function sayHello()
    {
        ud('Hello');
    }
    /**
     * @param int $template
     * @param array $params
     * @return bool
     */
    private function startBP(int $template,  array $params) : bool {
        $runtime = \CBPRuntime::GetRuntime();
        try {
            $wi = $runtime->CreateWorkflow($template,
                [
                    'crm',
                    'CCrmDocumentCompany',
                    'COMPANY_'.$this->companyID
                ],
                $params

            );
            $wi->Start();


        } catch (ArgumentNullException | \CBPArgumentOutOfRangeException | \Exception | \CBPArgumentNullException $e) {
            $this->setResult([
                'STATUS' => false,
                'TEXT' => $e->getMessage()
            ]);
        }

        return $this->getStatus();
    }

    /**
     * @param $text
     */
    private function addComment($text) {
        \Bitrix\Tasks\Integration\Forum\Task\Comment::add($this->getID(),['AUTHOR_ID'=>Core::USER_ID,'POST_MESSAGE'=>':!: '.$text]);
    }

    private function getCompanyID() {
        $oTask = \CTaskItem::getInstance($this->getID(), Core::USER_ID);
        $arData = $oTask->getData(false);
        $arUFCrmTask = $arData['UF_CRM_TASK'];
        $inn = $arData['UF_AUTO_817599841537'];

        $this->clearArValue($arUFCrmTask);

        if (count($arUFCrmTask) == 0) {
            $this->setResult(['STATUS'=>false,'TEXT'=>'The task is missing a company']);
        } else {
            try {
                $this->companyID = $this->getCompanyClientID($arUFCrmTask,$inn);

                if ($this->companyID == 0) {
                    throw new SystemException('Client company not found');
                }

            } catch (ObjectPropertyException | SystemException $e) {
                $this->setResult(['STATUS'=>false,'TEXT'=>$e->getMessage()]);
            }
        }
    }


    function getProductByDealID($dealID = false)
    {
        $arProduct = false;
        if (!$dealID) {
            return false;
        }

        $ob = ProductRowTable::getList([
            'filter' => [
                'OWNER_ID' => $dealID,
                'OWNER_TYPE' => 'D'
            ],
            'runtime' => [
                'ELEMENT' => [
                    'data_type' => 'Bitrix\Iblock\ElementTable',
                    'reference' => ['=this.PRODUCT_ID' => 'ref.ID']
                ],
            ],
            'select' => [
                'ELEMENT', '*'
            ]
        ]);

        while ($ar = $ob->fetch()) {
            $arProduct[] = [
                'NAME' => $ar['CRM_PRODUCT_ROW_ELEMENT_NAME'],
                'QUANTITY' => (int)$ar['QUANTITY']
            ];
        }
        return $arProduct;
    }
}