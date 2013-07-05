<?php

/**
 * Actindo Faktura/WWS Connector
 * 
 * This software is licensed to you under the GNU General Public License,
 * version 2 (GPLv2). There is NO WARRANTY for this software, express or
 * implied, including the implied warranties of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. You should have received a copy of GPLv2
 * along with this software; if not, see http://www.gnu.org/licenses/gpl-2.0.txt
 * 
 * @copyright Copyright (c) 2012, Actindo GmbH (http://www.actindo.de)
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPLv2
 */


/**
 * singleton class that provides various utility methods used by the connector
 */
class Actindo_Components_Util {
    /**
     * singleton instance of this class
     * @var Actindo_Components_Util
     */
    private static $instance = null;
    
    
    
    /**
     * caches the result of getArticleAttributeFields()
     * @var array
     */
    private $articleAttributeFieldCache = null;
    
    /**
     * caches the result of getCountries()
     * @var array
     */
    private $countryCache = null;
    
    /**
     * caches the result of getCustomerGroups()
     * @var array
     */
    private $customerGroupCache = null;
    
    /**
     * caches the result of getLanguages()
     * @var array
     */
    private $languageCache = null;
    
    /**
     * caches the result of getPaymentMeans()
     * @var array
     */
    private $paymentMeansCache = null;
    
    /**
     * machine readable to human readable salutation map
     * @var array
     */
    private static $salutationMap = array(
        'mr'      => 'Herr',
        'mrs'     => 'Frau',
        'ms'      => 'Frau',
        'company' => 'Firma',
    );
    
    /**
     * special article attribute fields that are used to fill special article fields that sw doesn't support, they're not displayed as attributes in actindo
     * @var array
     */
    private $specialArticleAttributeMap = array(
        //'attr10' => 'fsk18',
    );
    
    /**
     * used to store paths of temporarily created files, they're deleted when the script is finished
     * @see Actindo_Components_Util::writeTemporaryFile()
     * @see Actindo_Components_Util::__destruct()
     * @var array
     */
    private $temporaryFiles;
    
    /**
     * caches the result of getTaxRates()
     * @var array
     */
    private $taxRateCache = null;
    
    /**
     * caches the result of getVPEs()
     * @var array
     */
    private $vpeCache = null;
    
    
    
    private function __construct() {
        $this->temporaryFiles = array();
    }
    
    public function __destruct() {
        foreach($this->temporaryFiles AS $path) {
            if(file_exists($path)) {
                @unlink($path);
            }
        }
    }
    
    /**
     * returns singleton instance of this class
     * 
     * @return Actindo_Components_Util
     */
    public static function getInstance() {
        if(self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    
    
    public static function arrayValuesEqual($a, $b) {
        sort($a, SORT_NUMERIC);
        sort($b, SORT_NUMERIC);
        return array_values($a) == array_values($b);
    }
    
    public static function arrayValuesReplace($arr, $map) {
        foreach($arr AS &$value) {
            if(!isset($map[$value])) {
                return false;
            }
            $value = $map[$value];
        }
        return $arr;
    }
    
    /**
     * takes prices and tax information and returns a gross price
     * 
     * @param float $price the price
     * @param float $taxRate the tax rate
     * @param boolean $isGross true if the price is gross (method won't do anything with the price in that case) or false if its net (tax will be added)
     * @return float gross price
     */
    public static function calculateGrossPrice($price, $taxRate, $isGross) {
        if($isGross) {
            return (float) $price;
        }
        return $price * (1 + $taxRate / 100);
    }
    
    /**
     * takes price and tax information and returns a net price
     * 
     * @param float $price the price
     * @param float $taxRate the tax rate
     * @param boolean $isGross true if the price is gross (tax will be deducted) or false if its net (method won't do anything with the price in that case)
     * @return type
     */
    public static function calculateNetPrice($price, $taxRate, $isGross) {
        if($isGross) {
            $netto = $price / (1 + $taxRate / 100);
            return (round($netto*100)/100);
        }
        return (float) $price;
    }
    
    /**
     * extract auth from request params (first param), validate it and write back the request parms (without the auth)
     * 
     * @param Actindo_Components_XmlRpc_Request $request 
     * @return Actindo_Components_XmlRpc_Request the same request that was put in but the first request param is stripped
     * @throws Actindo_Components_Exception if authenticitation/authorisation fails for some reason
     */
    public static function checkAuth(Actindo_Components_XmlRpc_Request $request) {
        $params = $request->getParams();
        $auth = array_shift($params);
        
        list($hash, $username) = explode('|||', $auth, 2);
        $result = Shopware()->Db()->fetchRow('SELECT `active`, `apiKey`, `password` FROM `s_core_auth` WHERE `username` = ?', array($username));
        if(!$result) {
            throw new Actindo_Components_Exception('Ungültiger Benutzername');
        }
		if(empty($result['apiKey'])) {
            $msg = sprintf('Der API-Zugang ist für diesen Benutzer nicht aktiviert. Bitte im Shop-Backend unter Einstellungen > Benutzerverwaltung > %s [editieren] > API-Zugang aktivieren.', $username);
            throw new Actindo_Components_Exception($msg);
        }
		if(empty($result['active'])) {
            $msg = sprintf('Der Benutzer `%s` ist nicht aktiviert. Bitte im Shop-Backend unter Einstellungen > Benutzerverwaltung > %s [editieren] aktivieren.', $username, $username);
            throw new Actindo_Components_Exception($msg);
        }
		$apikey = md5( 'A9ASD:_AD!_=%a8nx0asssblPlasS$' . md5($result['apiKey']));
		/**
		 * Test Password Login
		 */
		if($result['password']!==$hash && $apikey!==$hash){
			throw new Actindo_Components_Exception('Passwort oder API-Key (Shopware 4.1) ungültig!');
		}
        $request->setParams($params);
        return $request;
    }
    
    /**
     * various list-calls from actindo supply a filter-array that needs to be inspected and converted into sql statements.
     * This method takes care of that
     * 
     * @param array $filters a filter array from an actindo request
     * @param array $columnMap some fields are called differently in actindo than sw4, this translates those columns when building sql
     * @return array keys are query parts (limit, offset, order, where), values are contents for them
     * @throws Actindo_Components_Exception
     */
    public static function createQueryFromFilters($filters, $columnMap) {
        $query = array(
            'limit'  => 0,
            'offset' => 0,
            'order'  => array('1'),
            'where'  => array('1'),
        );
        
        if(isset($filters[0]['field'])) {
            $whereFilters = $filters;
        }
        else {
            $whereFilters = $filters['filter'];
            
            if(!empty($filters['sortColName'])) {
                if(isset($columnMap[$filters['sortColName']])) {
                    $order = $columnMap[$filters['sortColName']];
                    if(!empty($filters['sortOrder'])) {
                        $order .= ' ' . $filters['sortOrder'];
                    }
                    $query['order'][] = $order;
                }
            }

            if(!empty($filters['start'])) {
                $query['offset'] = (int) $filters['start'];
            }
            if(!empty($filters['limit'])) {
                $query['limit'] = (int) $filters['limit'];
            }
        }
        
        if(is_array($whereFilters)) {
            foreach($whereFilters AS $filter) {
                if(!isset($columnMap[$filter['field']])) {
                    throw new Actindo_Components_Exception('Unknown filter field found while building query: ' . $filter['field']);
                }
                if($filter['data']['type'] == 'list') {
                    $values = Shopware()->Db()->quote(explode(',', $filter['data']['value'])); // returns comma separated string of quoted values
                    $query['where'][] = sprintf('%s IN (%s)', $columnMap[$filter['field']], $values);
                }
            }
        }
        
        return $query;
    }
    
    /**
     * converts a string in mysqls datetime format to a unix timestamp
     * 
     * @param string $date date in mysqls datetime format (YYYY-MM-DD HH:MM:SS)
     * @return int the appropiate unix timestamp or -1 if an error occured
     */
    public static function datetimeToTimestamp($date) {
        preg_match('/(\d+)-(\d+)-(\d+)\s+(\d+):(\d+)(:(\d+))/', $date, $date);
        $date = array_map('intval', $date);
        if(!$date[1] && !$date[2] && !$date[0]) {
            return -1;
        }
        return mktime($date[4], $date[5], $date[7], $date[2], $date[3], $date[1]);
    }
    
    /**
     * var_dumps a variable. The var is takes as reference so you can't use this function like this:
     * dump(array('foo' => 'bar'));
     * instead you need to do:
     * $var = array('foo' => 'bar');
     * dump($var);
     * 
     * @param mixed $var the variable to dump
     * @param boolean returnOutput if true the dump is returned as string instead of getting printed directly
     */
    public static function dump(&$var, $returnOutput = true) {
        if($returnOutput) {
            ob_start();
            var_dump($var);
            return ob_get_clean();
        }
        var_dump($var);
    }
    
    /**
     * returns information about a customer group
     * 
     * @param int $id customer group id to look for
     * @return boolean|array false if a group with the given id could not be found, otherwise associative array with information about the group
     */
    public function findCustomerGroupById($id) {
        $groups = $this->getCustomerGroups();
        if(isset($groups[$id])) {
            return $groups[$id];
        }
        return false;
    }
    
    /**
     * finds a customer group by its group key
     * 
     * @param string $key group key to look up
     * @return boolean|array false if a group with the given key could not be found, otherwise assoticative array with information about the group
     */
    public function findCustomerGroupByKey($key) {
        $groups = $this->getCustomerGroups();
        foreach($groups AS $id => &$group) {
            if($group['groupkey'] == $key) {
                return $groups[$id];
            }
        }
        return false;
    }
    
    /**
     * returns information about tax group of customer group
     *
     * 2013-01-23 by Jens Twesmann
     * @param int $id customer group id to look for
     * @return int 1 if tax for group is netto, 0 if not
     */
    public function findCustomerGroupTaxById($id) {
        $groups = $this->getCustomerGroups();
        if(isset($groups[$id])) {
            if($groups[$id]['taxinput'] == 0) {
                return 1;
            }
        }
        return 0;
    }
    
    /**
     * returns country information for a given country id
     * 
     * @param int $id country id to look up
     * @return boolean|array false if a country with the given id could not be found, otherwise associative array containing country information
     */
    public function findCountryById($id) {
        $countries = $this->getCountries();
        if(isset($countries[$id])) {
            return $countries[$id];
        }
        return false;
    }
    
    /**
     * variant articles in sw4 don't have a "main article" anymore (which we need in actindo)
     * this function will take one or many article ids and create unique ordernumbers to use as "main article"
     * they are stored in s_articles_attributes
     * 
     * @param int|array $articleIds either one article (int) or an array of ints
     * @return string|array if only one articleid was given a string with the ordernumber is returned
     *      if multiple articles were given (as array) an associative array is returned where the key is the articleid and the value is the ordernumber
     */
    public function findOrdernumberForVariantArticle($articleIds) {
        if(!is_array($articleIds)) {
            $singleExport = true;
            $articleIds = array($articleIds);
        }
        else {
            $singleExport = false;
        }
        
        $articleIds = array_flip(array_filter(array_map('intval', $articleIds)));
        if(empty($articleIds)) {
            return;
        }
        
        $articles = array(); // key will be article id, value the ordernumber. this is the returned array
        // first try and hit our masternumber field in s_articles_attributes
        $result = Shopware()->Db()->fetchAll(sprintf('
            SELECT DISTINCT `articleID`, `actindo_masternumber` AS `ordernumber`
            FROM `s_articles_attributes`
            WHERE `articleID` IN(%s)
        ', implode(',', array_keys($articleIds))));
        while($row = array_shift($result)) {
            if(empty($row['ordernumber'])) {
                continue; // :(
            }
            $articleID = (int) $row['articleID'];
            $articles[$articleID] = $row['ordernumber'];
            unset($articleIds[$articleID]);
        }
        
        if(!empty($articleIds)) {
            // some articles don't have a masternumber yet, generate and save
            $result = Shopware()->Db()->fetchAll(sprintf('
                SELECT `articleID`, `ordernumber`
                FROM `s_articles_details`
                WHERE `articleID` IN (%s)
            ', implode(',', array_keys($articleIds))));
            $ordernumbersByArticle = array(); // key is article id, value is array of variant ordernumbers
            while($row = array_shift($result)) {
                $articleID = (int) $row['articleID'];
                isset($ordernumbersByArticle[$articleID]) or $ordernumbersByArticle[$articleID] = array();
                $ordernumbersByArticle[$articleID][] = $row['ordernumber'];
            }
            
            foreach($ordernumbersByArticle AS $articleID => $numbers) {
                $number = $this->_findStringCongruence($numbers);
                $number = preg_replace('/[^a-zA-Z0-9]+$/', '', $number);
                if(empty($number)) {
                    $number = sprintf('%s-hauptartikel', current($numbers)); // get first variant ordernumber and append a string
                }
                
                do {
                    $exists = (bool) Shopware()->Db()->fetchOne('SELECT `id` FROM `s_articles_details` WHERE `ordernumber` = ?', array($number));
                    // @todo also check actindo_masternumber in s_articles_attributes?
                    if(!$exists) {
                        break;
                    }
                    $number .= '-hauptartikel';
                } while(True);
                
                $this->setOrdernumberForVariantArticle($articleID, $number);
                $articles[$articleID] = $number;
            }
        }
        
        return $singleExport ? array_shift($articles) : $articles;
    }
    
    public function findOrdernumberByArticleId($articleId) {
        return Shopware()->Db()->fetchOne(sprintf('SELECT `ordernumber` FROM `s_articles_details` WHERE `articleID` = %d AND `kind` = 1 LIMIT 1', $articleId));
    }
    
    /**
     * find an order state by its id
     * 
     * @param int $id the state id to look for
     * @return boolean|array false if a state with the given id could not be found, otherwise an array containing information abouth the state
     */
    public function findOrderStateById($id) {
        $states = $this->getOrderStates();
        if(isset($states[$id])) {
            return $states[$id];
        }
        return false;
    }
    
    /**
     * finds a subshop id by locale (de, en, ...)
     * 
     * @param string $locale the locale to look for
     * @return int the subshop id or false if it couldnt be found
     */
    public function findShopIdByLocale($locale) {
        foreach($this->getMultiShops() AS $shop) {
            if($shop['locale'] == $locale) {
                return $shop['id'];
            }
        }
        
        return false;
    }
    
    /**
     * finds the taxrate for a given taxId
     * 
     * @param int $id taxId
     * @return boolean|float false if the taxrate wasnt found, otherwise the taxrate as float 
     */
    public function findTaxRateById($id) {
        $rates = $this->getTaxRates();
        if(isset($rates[$id])) {
            return $rates[$id];
        }
        return false;
    }
    
    /**
     * returns ALL article fields
     * meaning: article attributes and article filter fields
     * 
     * @see Actindo_Components_Util::getArticleAttributeFields()
     * @see Actindo_Components_Util::getArticleFilterFields()
     * @return array
     */
    public function getAllArticleFields() {
        return array_merge($this->getArticleAttributeFields(), $this->getArticleFilterFields());
    }
    
    /**
     * gets all article attribute field definitions
     * (attrX from s_articles_attributes)
     * 
     * @return array
     */
    public function getArticleAttributeFields() {
        if($this->articleAttributeFieldCache === null) {
            $this->articleAttributeFieldCache = array();

            $domtypeTranslation = array( // maps shopware domtypes to actindo domtypes
                'text'     => 'textfield',
                'price'    => 'numberfield',
                'textarea' => 'textarea',
                'select'   => 'combobox',
                'boolean'  => 'boolean',
                'date'     => 'datefield',
                'time'     => 'timefield',
            );

            $builder = Shopware()->Models()->createQueryBuilder();
            $result = $builder->select(array('elements'))
                                ->from('Shopware\Models\Article\Element', 'elements')
                                ->getQuery()
                                ->getArrayResult();
            while($row = array_shift($result)) {
                if(isset($this->specialArticleAttributeMap[$row['name']])) {
                    continue;
                }

                $field = array(
                    'field_id'      => $row['name'],
                    'field_name'    => $row['label'],
                    'field_i18n'    => (int) $row['translatable'],
                    'field_set'     => 'Shopware',
                    'field_set_ids' => array(0),
                    'field_help'    => $row['help'],
                    'field_noempty' => (int) $row['required'],
                    'field_type'    => isset($domtypeTranslation[$row['type']]) ? $domtypeTranslation[$row['type']] : 'textfield',
                    'variantable'   => (int) $row['variantable'],
                );
                if($field['field_type'] == 'combobox') {
                    // @todo fixme
                }
                $this->articleAttributeFieldCache[$field['field_id']] = $field;
            }
        }
        return $this->articleAttributeFieldCache;
    }
    
    /**
     * returns the configured article filter values
     * 
     * @return array
     */
    public function getArticleFilterFields() {
        $articleFilters = array();
        
        $result = Shopware()->Db()->fetchAll('
            SELECT `sfo`.`id`, `sfo`.`name`, `sfo`.`filterable`, `sfo`.`default`, GROUP_CONCAT(`sfr`.`groupID` SEPARATOR "|") AS `groups`
            FROM `s_filter_options` `sfo`
            LEFT JOIN `s_filter_relations` `sfr` ON `sfr`.`optionID` = `sfo`.`id`
            GROUP BY `sfo`.`id`
            ORDER BY `sfo`.`id`
        ');
        while($row = array_shift($result)) {
            $id = sprintf('filter%d', $row['id']);
            $field = array(
                'field_id'      => $id,
                'field_name'    => $row['name'],
                'field_i18n'    => 1,
                'field_set'     => 'Shopware-Filter',
                'field_set_ids' => !empty($row['groups']) ? array_map('intval', explode('|', $row['groups'])) : array(),
                'field_noempty' => 0,
                'field_type'    => 'textfield',
            );
            $articleFilters[$id] = $field;
        }
        return $articleFilters;
    }
    
    /**
     * returns a list of all configured countries and some of their properties
     * 
     * @return array key is country id, value an associative array with country information
     */
    public function getCountries() {
        if($this->countryCache === null) {
            $result = Shopware()->Db()->fetchAll('SELECT `id`, `countryname`, `countryiso` FROM `s_core_countries`');
            while($country = array_shift($result)) {
                $id = (int) $country['id'];
                $this->countryCache[$id] = $country;
            }
        }
        return $this->countryCache;
    }
    
    /**
     * returns the configured article filter option sets
     * 
     * @return array
     */
    public function getArticleFilterOptions() {            
        $result = Shopware()->Db()->fetchAll('SELECT `id`, `name`, `comparable`, `position` FROM `s_filter`');
        $filterOptions = array();
        while($set = array_shift($result)) {
            $filterOptions[] = array(
                'id'   => (int) $set['id'],
                'name' => sprintf('Filter: %s', $set['name']),
                'comparable' => (int) $set['comparable'],
                'position'   => (int) $set['position'],
            );
        }
        return $filterOptions;
    }
    
    /**
     * finds the article id of the given ordernumber.
     * Takes into consideration the attrX field in s_articles_attributes we use internally to store master article numbers
     * of variant articles
     * 
     * @param string $ordernumber the ordernumber to look for
     * @return int article id
     * @throws Actindo_Components_Exception if an article with the given ordernumber could not be found
     */
    public function findArticleIdByOrdernumber($ordernumber) {
        $articleID = (int) Shopware()->Db()->fetchOne('SELECT `articleID` FROM `s_articles_details` WHERE `ordernumber` = ?', array($ordernumber));
        if(!$articleID) { // look for variant article
            $articleID = (int) Shopware()->Db()->fetchOne('SELECT `articleID` FROM `s_articles_attributes` WHERE `actindo_masternumber` = ? LIMIT 1', array($ordernumber));
            if(!$articleID) {
                throw new Actindo_Components_Exception('Could not find article with this ordernumber: ' . $ordernumber);
            }
        }
        return $articleID;
    }
    
    public function findDetailIdByOrdernumber($ordernumber) {
        return (int) Shopware()->Db()->fetchOne('SELECT `id` FROM `s_articles_details` WHERE `ordernumber` = ?', array($ordernumber));
    }
    
    /**
     * returns information about all configured customer groups
     * 
     * @return array key is the group id, value is an array of information about the group
     */
    public function getCustomerGroups() {
        if($this->customerGroupCache === null) {
            $this->customerGroupCache = array();
            
            $result = Shopware()->Db()->fetchAll('SELECT * FROM `s_core_customergroups`');
            while($group = array_shift($result)) {
                $id = (int) $group['id'];
                $this->customerGroupCache[$id] = $group;
            }
        }
        return $this->customerGroupCache;
    }
    
    /**
     * returns information about all used languages of the shop
     * 
     * @return array key is the language id, value is an array of information about the language
     */
    public function getLanguages() {
        if($this->languageCache === null) {
            $this->languageCache = array();
            
            $languages = Shopware()->Db()->fetchAll('
                SELECT `scl`.`id` AS `language_id`, `scl`.`locale`, `scl`.`language` AS `language_name`, `scl`.`locale` AS `_shopware_code`, `scs`.`default` AS `is_default`
                FROM `s_core_shops` AS `scs`
                INNER JOIN `s_core_locales` `scl` ON `scl`.`id` = `scs`.`locale_id`
                ORDER BY `default` DESC, `scs`.`id`
            ');
            foreach($languages AS $lang) {
                $this->languageCache[(int) $lang['language_id']] = array_merge($lang, array(
                    'language_code' => array_shift(explode('_', $lang['locale'])),
                ));
            }
            
            // always make the first language default (in case no language is marked as default)
            reset($this->languageCache);
            $this->languageCache[key($this->languageCache)]['is_default'] = 1;
        }
        return $this->languageCache;
    }
    
    /**
     * returns the id of the default langauge
     * 
     * @return int the default language id
     */
    public function getDefaultLanguage() {
        foreach($this->getLanguages() AS $id => $language) {
            if(!empty($language['is_default'])) {
                return $id;
            }
        }
    }
    
    /**
     * returns information about the mainshop and all subshops
     * 
     * @return array
     */
    public function getMultiShops() {
        $languages = $this->getLanguages();
        $shops = array();
        
        $result = Shopware()->Db()->fetchAll('
            SELECT `scm`.`id`, `name`, `scl`.`locale`, `scl`.`language`, `default`, `domainaliase`
            FROM `s_core_multilanguage` `scm`
            INNER JOIN `s_core_locales` `scl` ON `scl`.`id` = `scm`.`locale`
            ORDER BY `default` DESC, `scm`.`id`
        ');
        while($row = array_shift($result)) {
            $id = (int) $row['id'];
            if(empty($row['name'])) {
                if(!empty($row['default'])) {
                    $row['name'] = 'Main Store';
                }
                else {
                    $row['name'] = sprintf('%s - %s (%d)', $row['language'], $row['locale'], $row['id']);
                }
            }

            if(!empty($row['domainaliase'])) {
                list($domain) = explode("\n", $row['domainaliase'], 2);
                $urlHttp = 'http://' . trim($domain);
            }
            else {
                $urlHttp = '';
            }

            $shops[$id] = array(
                'id'       => $id,
                'name'     => $row['name'],
                'url_http' => $urlHttp,
                'active'   => 1,
                'locale'   => array_shift(explode('_', $row['locale'], 2)),
            );
            
            foreach($languages AS $language) {
                if($language['language_code'] == $shops[$id]['locale']) {
                    $shops[$id] = array_merge($shops[$id], array(
                        'language_id'   => $language['language_id'],
                        'language_name' => $language['language_name'],
                        
                    ));
                    break;
                }
            }
        }
        return $shops;
    }
    
    /**
     * returns all order states configured in the shop
     * 
     * @return array key is state id, value is an array of state information
     */
    public function getOrderStates() {
        $result = Shopware()->Db()->fetchAll('
            SELECT `id`, `description`
            FROM `s_core_states`
            WHERE `group` = "state"
            ORDER BY position'
        );
        $states = array();
        while($state = array_shift($result)) {
            $id = (int) $state['id'];
            $states[$id] = $state;
        }
        return $states;
    }
    
    /**
     * returns one or all payment means configured in the shop
     * 
     * @param int id if given only the payment mean with this id is returned in a single array
     * @return array key is the payment id, value is an associative array with information about the payment mean
     *      if id is given, only the associative array is returned (or false if it doesnt exist)
     */
    public function getPaymentMeans($id = null) {
        if($this->paymentMeansCache === null) {
            $this->paymentMeansCache = array();
            
            $repository = Shopware()->Models()->Payment();
            $data = $repository->getPaymentsQuery()->getArrayResult();
            while($mean = array_shift($data)) {
                $this->paymentMeansCache[(int) $mean['id']] = $mean;
            }
        }
        if($id !== null) {
            return isset($this->paymentMeansCache[$id]) ? $this->paymentMeansCache[$id] : false;
        }
        return $this->paymentMeansCache;
    }
    
    /**
     * fetch all prices for all pricegroups for an article details id
     * 
     * @param int $articleDetailsID id of the s_articles_details table
     * @return array all prices and pricegroups ordered by pricegroup and the "from" value
     */
    public function getPricesByDetailsID($articleDetailsID) {
        $sql = '
            SELECT `sap`.*, `scc`.`id` AS `customerGroupID`,
                ROUND(`sap`.`price` * IF(`scc`.`taxinput` = 1, (100 + `sct`.`tax`) / 100, 1), 2) as `price`,
                ROUND(`sap`.`pseudoprice` * IF(`scc`.`taxinput` = 1, (100 + `sct`.`tax`) / 100, 1), 2) as `pseudoprice`,
                ROUND(`sap`.`baseprice`, 2) as `baseprice`,
                IF(`scc`.`taxinput` = 1, 0, 1) as `netto`,
                `sap`.`pseudoprice` as `netPseudoprice`,
                `sap`.`price` as `netPrice`
            FROM `s_articles_details` `sad`
            INNER JOIN `s_articles` `sa` ON `sa`.`id` = `sad`.`articleID`
            INNER JOIN `s_articles_prices` `sap` ON `sap`.`articleID` = `sa`.`id` AND `sap`.`articledetailsID` = `sad`.`id`
            INNER JOIN `s_core_tax` `sct` ON `sct`.`id` = `sa`.`taxID`
            LEFT JOIN `s_core_customergroups` `scc` ON `scc`.`groupkey` = `sap`.`pricegroup`
            WHERE `sad`.`id` = ?
            ORDER BY `sap`.`pricegroup`, `from`
        ';
        return Shopware()->Db()->fetchAll($sql, array($articleDetailsID));
    }
    
    /**
     * translates a salutation from machine format into human readable format.
     * Can either return a single mapping or the whole map.
     * 
     * @staticvar array $map machine to human readable map
     * @param string $key the key to get from the map, if omitted the whole map is returned
     * @return mixed if $key is omitted the whole map is returned as array, otherwise just that key as a string
     * @throws Actindo_Components_Exception if $key is given and could not be found in the map
     */
    public static function getSalutation($key = null) {
        if($key !== null) {
            if(isset(self::$salutationMap[$key])) {
                return self::$salutationMap[$key];
            }
            foreach(self::$salutationMap AS $mapped) {
                if($mapped == $key) {
                    return $key;
                }
            }
            return current(self::$salutationMap);
        }
        return self::$salutationMap;
    }
    
    /**
     * looks up all configured tax rates
     * 
     * @return array key is the taxId, value is the rate (as float)
     */
    public function getTaxRates() {
        if($this->taxRateCache === null) {
            $this->taxRateCache = array();
            
            $result = Shopware()->Db()->fetchAll('SELECT `id`, `tax` FROM `s_core_tax`');
            while($rate = array_shift($result)) {
                $this->taxRateCache[(int) $rate['id']] = (float) $rate['tax'];
            }
        }
        return $this->taxRateCache;
    }
    
    /**
     * returns an array of all vpes (=verpackungseinheiten) or details about one vpe
     * 
     * @param int $id vpe id to look for
     * @return array if $id is not given: associative array with all vpes; if $id is given: associative array with information about that unit or false if it couldn't be found
     */
    public function getVPEs($id = null) {
        if($this->vpeCache === null) {
            $this->vpeCache = array();
            
            $result = Shopware()->Db()->fetchAll('SELECT `id`, `unit`, `description` FROM `s_core_units`');
            while($unit = array_shift($result)) {
                $vid = (int) $unit['id'];
                $this->vpeCache[$vid] = $unit;
            }
        }
        
        if($id !== null) {
            if(isset($this->vpeCache[$id])) {
                return $this->vpeCache[$id];
            }
            return false;
        }
        return $this->vpeCache;
    }
    
    /**
     * checks if the given string is a valid url
     * 
     * @param string $str the string to check
     * @return boolean true if it is, otherwise false
     */
    public static function isValidUrl($str) {
        if(function_exists('filter_var')) {
            return filter_var($str, FILTER_VALIDATE_URL) !== false;
        }
    }
    
    /**
     * uses the configured attrX field to store master article numbers
     * 
     * @param int $articleID the article id to set the ordernumber for
     * @param string $ordernumber ordernumber to set
     */
    public function setOrdernumberForVariantArticle($articleID, $ordernumber) {
        Shopware()->Db()->update('s_articles_attributes', array('actindo_masternumber' => $ordernumber), sprintf('articleID = %d', $articleID));
    }
    
    /**
     * uses the s_core_translations table to look up translations for elements.
     * the results are returned differently depending on whether the $objectkey parameter is set
     * 
     * @param string $objecttype type of object to translate; for example: article, propertyvalue, ...
     * @param int $objectkey id of the object to translate, if omitted all keys of the given type are returned
     * @return array if objectkey is null (=omitted) this method returns an array grouped by objectkey, like this:
     *      array('objectkey' => array('language_code' => data), ..... ).
     *      If an objectkey is given however the data is returned without objektkey grouping, like this:
     *      array('language_code' => array(data)).
     */
    public function translate($objecttype, $objectkey = null) {
        $languages = $this->getLanguages();
        
        $whereClause = Shopware()->Db()->quoteInto('WHERE `objecttype` = ?', $objecttype);
        if($objectkey !== null) {
            $whereClause .= Shopware()->Db()->quoteInto(' AND `objectkey` = ?', $objectkey);
        }
        
        $result = Shopware()->Db()->fetchAll('SELECT `objectdata`, `objectlanguage`, `objectkey` FROM `s_core_translations` ' . $whereClause);
        $translations = array();
        while($row = array_shift($result)) {
            foreach($languages AS $language) {
                if($language['language_id'] == $row['objectlanguage']) {
                    if($objectkey === null) {
                        $key = (int) $row['objectkey'];
                        isset($translations[$key]) or $translations[$key] = array();
                        $ref =& $translations[$key];
                    }
                    else {
                        $ref =& $translations;
                    }
                    $ref[$language['language_code']] = unserialize($row['objectdata']);
                }
            }
        }
        
        return $translations;
    }
    
    /**
     * recursively utf8-decodes a var, the value is taken as reference and its content will be overwritten!
     * 
     * @param type $value
     * @param array $omitKeys 
     * @return void
     */
    public static function utf8Decode(&$value, array $omitKeys = array()) {
        if(is_array($value)) {
            foreach($value as $key => &$val) {
                if(!in_array($key, $omitKeys, true)) {
                    self::utf8Decode($val, $omitKeys);
                }
            }
        }
        elseif(is_string($value)) {
            $value = utf8_decode($value);
        }
    }
    
    /**
     * recursively utf8-encodes a var, the value is taken as reference and its content will be overwritten!
     * 
     * @param type $value
     * @param array $omitKeys 
     * @return void
     */
    public static function utf8Encode(&$value, array $omitKeys = array()) {
        if(is_array($value)) {
            foreach($value as $key => &$val) {
                if(!in_array($key, $omitKeys, true)) {
                    self::utf8Encode($val, $omitKeys);
                }
            }
        }
        elseif(is_string($value)) {
            $value = utf8_encode($value);
        }
    }
    
    /**
     * tries to write a file into shopwares media directory
     * 
     * @param mixed $content binary file contents to write
     * @param string $filename the filename to use, if it already exists this method will create a new filename
     * @param string $filetype used as subfolder in the media directory, for example: image, video, ... if it doesnt exist the file is written to the media/unknown/ folder
     * @param int $filesize optional. if given and the filenames we're trying to write exist but have the same size and md5 the file is reused (instead of writing it again)
     * @param string $md5 optional. if given and the filenames we're trying to write exist but have the same size and md5 the file is reused (instead of writing it again)
     * @return string the path (relative from sw root) to the created file or false if the file could not be written
     */
    public function writeMediaFile($content, $filename, $filetype, $filesize = 0, $md5 = null) {
        if($md5 !== null && (!is_string($md5) || !preg_match('/^[a-fA-F0-9]{32}$/', $md5))) {
            $md5 = null;
        }
        
        $basePath = Shopware()->System()->sBasePath;
        $targetFolder = sprintf('media/%s', $filetype);
        if(!is_dir($basePath . $targetFolder)) {
            $targetFolder = 'media/unknown';
            if(!is_dir($basePath . $targetFolder)) {
                return false;
            }
        }
        
        while(true) {
            $targetFile = sprintf('%s/%s', $targetFolder, $filename);
            $absoluteTargetFile = $basePath . $targetFile;
            if(file_exists($absoluteTargetFile)) {
                if($filesize > 0 && $md5 !== null) {
                    if($filesize == filesize($absoluteTargetFile) && $md5 == md5(file_get_contents($absoluteTargetFile))) {
                        // name, size and md5 match, assume its the same file and return its path
                        return $targetFile;
                    }
                }
            }
            else {
                if(false !== @file_put_contents($absoluteTargetFile, $content)) {
                    return $targetFile;
                }
                return false;
            }
            $filename = '_' . $filename;
        }
    }
    
    /**
     * writes data into a temporary file and returns the path of the file
     * 
     * @param string $filename try to create the file with a specific filename
     * @param mixed $contents file contents to write
     * @return string the path to the created file
     * @throws Actindo_Components_Exception if no temp file could be created or if something went wrong while writing the contents
     */
    public function writeTemporaryFile($filename, $contents) {
        $tmpDirs = array(
            '/tmp',
            rtrim(ini_get('upload_tmp_dir'), '/'),
            rtrim(Shopware()->System()->sBasePath, '/') . '/cache',
        );
        foreach($tmpDirs AS $dir) {
            $path = sprintf('%s/%s', $dir, $filename);
            if(false !== @touch($path)) {
                break;
            }
        }
        if($path === false) {
            throw new Actindo_Components_Exception('Could not create a temporary file anywhere.');
        }
        
        $this->temporaryFiles[] = $path; // put into "cleanup" array
        
        $bytesWritten = file_put_contents($path, $contents);
        if($bytesWritten !== ($expected = strlen($contents))) {
            $msg = sprintf('Error while writing temporary file (%s); wrote %d b, expected %d b', $path, $bytesWritten, $expected);
            throw new Actindo_Components_Exception($msg);
        }
        return $path;
    }
    
    /**
     * char-by-char comparison of several strings, returns the bit that is the same for all strings
     * 
     * @param array $strs array of strings
     * @return string
     */
    private function _findStringCongruence($strs) {
        $to = 0;
        foreach($strs AS $str) {
            $to = ($to == 0) ? strlen($str) : min($to, strlen($str));
        }
        
        $out = '';
        for($i = 0; $i < $to; $i++) {
            $char = $strs[0]{$i};
            foreach($strs AS $str) {
                if($str{$i} != $char) {
                    break 2;
                }
            }
            $out .= $char;
        }
        return $out;
    }
}

/**
 * actindo_compareSort
 * compare sort function
 * @par $a first value
 * @par $b second value
 * @return bool
 */
function actindo_compareSort($a,$b){
    return ($a['left']<$b['left'])?-1:1;
}