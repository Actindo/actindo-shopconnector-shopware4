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


class Actindo_Components_Service_Customers extends Actindo_Components_Service {
    protected static $customerColumnMapping = array(
        '_customers_id' => '`su`.`id`',
        'deb_kred_id'   => '`sub`.`customernumber`',
        'vorname'       => '`sub`.`firstname`',
        'name'          => '`sub`.`lastname`',
        'firma'         => '`sub`.`company`',
        'land'          => '`scc`.`countryiso`',
        'email'         => '`su`.`email`',
    );
    
    
    public function count() {
        $counts = array(
            'count'            => (int) Shopware()->Db()->fetchOne('SELECT count(*) FROM `s_user`'),
            'max_customers_id' => (int) Shopware()->Db()->fetchOne('SELECT max(`id`) FROM `s_user`'),
            'max_deb_kred_id'  => (int) Shopware()->Db()->fetchOne('SELECT max(`customernumber`) FROM `s_user_billingaddress`'),
        );
        
        return array(
            'ok'     => true,
            'counts' => $counts,
        );
    }
    
    /**
     * This is where customers.list is handled (despite the different method name).
     * Exports the customer list or a customers details.
     * 
     * @param boolean $list if true, a customerlist is returned. if false, a single customers details are returned
     * @param struct $filters
     * @return struct
     */
    public function getList($list, $filters) {
        error_log('customers.get_list; ' . $this->util->dump($list));
        error_log($this->util->dump($filters));
        if($list) {
            $res = $this->exportList($filters);
        }
        else {
            $res = $this->exportCustomer($filters);
        }
        error_log($this->util->dump($res));
        return $res;
    }
    
	/**
	 * sets the customernumber of a customer
	 * 
	 * @param int $userID userid whos customernumber should be set
	 * @param int $customernumber the customernumber to set
     * @return struct
	 */
    public function set_deb_kred_id($userID, $customernumber) {
        $whereClause = Shopware()->Db()->quoteInto('userID = ?', $userID);
        
        Shopware()->Db()->update('s_user_billingaddress',  array('customernumber' => $customernumber), $whereClause);
        
        return array('ok' => true);
    }
    
    /**
     * exports the details of all customers matching the filters
     * 
     * @param array $filters filters array compatible with Actindo_Components_Util::createQueryFromFilters
     * @return array (
     *     'ok' => true,
     *     'count' => (int) total number of customers,
     *     'customers => (array) associative array for each customer
     * )
     */
    protected function exportCustomer($filters) {
        $query = $this->util->createQueryFromFilters($filters, self::$customerColumnMapping);
        $query['order'][] = '`su`.`id` DESC';
        error_log('exportCustomer query: ' . $this->util->dump($query));
        // select user information and billing address info
        $sql = sprintf('
            SELECT SQL_CALC_FOUND_ROWS
                `su`.`email`, `su`.`language`,
                `sub`.*,
                `scc`.`countryiso`,
                `scg`.`id` AS `groupID`, `scg`.`tax` AS `gross`
            FROM `s_user` `su`
            INNER JOIN `s_user_billingaddress` `sub` ON `sub`.`userID` = `su`.`id`
            LEFT JOIN `s_core_countries` `scc` ON `scc`.`id` = `sub`.`countryID`
            LEFT JOIN `s_core_customergroups` `scg` ON `scg`.`groupkey` = `su`.`customergroup`
            WHERE %s
            GROUP BY `su`.`id`
            ORDER BY %s
            LIMIT %d
            OFFSET %d
        ', implode(' AND ', $query['where']), implode(', ', $query['order']), $query['limit'], $query['offset']);
        
        $result = Shopware()->Db()->fetchAll($sql);
        $count  = (int) Shopware()->Db()->fetchOne('SELECT FOUND_ROWS()');
        
        $customers = array();
        while($customer = array_shift($result)) {
            $info = array(
                'deb_kred_id'   => ((int) $customer['customernumber'] > 0) ? (int) $customer['customernumber'] : 0,
                'anrede'        => $this->util->getSalutation($customer['salutation']),
                'kurzname'      => !empty($customer['company']) ? $customer['company'] : sprintf('%s, %s', $customer['lastname'], $customer['firstname']),
                'firma'         => $customer['company'],
                'name'          => $customer['lastname'],
                'vorname'       => $customer['firstname'],
                'adresse'       => sprintf('%s %s', $customer['street'], str_replace(' ', '', $customer['streetnumber'])),
                'adresse2'      => $customer['department'],
                'plz'           => $customer['zipcode'],
                'ort'           => $customer['city'],
                'land'          => $customer['countryiso'],
                'tel'           => $customer['phone'],
                'fax'           => $customer['fax'],
                'ustid'         => $customer['ustid'],
                'email'         => $customer['email'],
                'print_brutto'  => empty($customer['net']) ? 0 : 1, // i think this should be the other way round
                '_customers_id' => (int) $customer['userID'],
                'currency'      => 'EUR',
                'preisgruppe'   => (string) $customer['groupID'],
                'gebdat'        => empty($customer['birthday']) ? '0000-00-00' : $customer['birthday'],
                'delivery_addresses' => array(), // filled with shipping addresses below
            );
            
            // select shipping addresses for user
            $sql = sprintf('
                SELECT `sus`.*, `scc`.`countryiso`
                FROM `s_user_shippingaddress` AS `sus`
                LEFT JOIN `s_core_countries` `scc` ON `scc`.`id` = `sus`.`countryID`
                WHERE `sus`.`userID` = %d
                ORDER BY `sus`.`id` DESC
            ', $customer['userID']);
            
            $addresses = Shopware()->Db()->fetchAll($sql);
            
            while($address = array_shift($addresses)) {
                $info['delivery_addresses'][] = array(
                    'delivery_id'       => (int) $address['id'],
                    'delivery_kurzname' => (string) (!empty($address['company']) ? $address['company'] : $address['lastname']),
                    'delivery_firma'    => $address['company'],
                    'delivery_name'     => $address['lastname'],
                    'delivery_vorname'  => $address['firstname'],
                    'delivery_adresse'  => sprintf('%s %s', $address['street'], str_replace(' ', '', $address['streetnumber'])),
                    'delivery_adresse2' => $address['department'],
                    'delivery_plz'      => $address['zipcode'],
                    'delivery_ort'      => $address['city'],
                    'delivery_land'     => $address['countryiso'],
                );
            }
            
            if(!empty($info['delivery_addresses'])) {
                $info = array_merge($info, end($info['delivery_addresses']));
            }
            
            $customers[] = $info;
        }
        
        return array(
            'ok' => true,
            'count' => $count,
            'customers' => $customers,
        );
    }
    
    /**
     * exports the customer list
     * 
     * @param array $filters filters array compatible with Actindo_Components_Util::createQueryFromFilters
     * @return array (
     *     'ok' => true,
     *     'count' => (int) total number of customers,
     *     'customers' => (array) associative array for each customer
     * )
     */
    protected function exportList($filters) {
        $query = $this->util->createQueryFromFilters($filters, self::$customerColumnMapping);
        $query['order'][] = '`su`.`id` DESC';
        error_log('export list query: ' . $this->util->dump($query));
        
        $sql = sprintf('
            SELECT SQL_CALC_FOUND_ROWS
                `su`.`email`, `su`.`language`,
                `sub`.*,
                `scc`.`countryiso`
            FROM `s_user` `su`
            INNER JOIN `s_user_billingaddress` `sub` ON `sub`.`userID` = `su`.`id`
            LEFT JOIN `s_core_countries` `scc` ON `scc`.`id` = `sub`.`countryID`
            WHERE %s
            GROUP BY `su`.`id`
            ORDER BY %s
            LIMIT %d
            OFFSET %d
        ', implode(' AND ', $query['where']), implode(', ', $query['order']), $query['limit'], $query['offset']);
        
        $result = Shopware()->Db()->fetchAll($sql);
        $count  = (int) Shopware()->Db()->fetchOne('SELECT FOUND_ROWS()');
        
        $customers = array();
        while($customer = array_shift($result)) {
            $customers[] = array(
                'deb_kred_id' => ((int) $customer['customernumber'] > 0) ? (int) $customer['customernumber'] : 0,
                'anrede'      => $this->util->getSalutation($customer['salutation']),
                'kurzname'    => !empty($customer['company']) ? $customer['company'] : sprintf('%s, %s', $customer['lastname'], $customer['firstname']),
                'firma'       => $customer['company'],
                'name'        => $customer['lastname'],
                'vorname'     => $customer['firstname'],
                'adresse'     => sprintf('%s %s', $customer['street'], str_replace(' ', '', $customer['streetnumber'])),
                'plz'         => $customer['zipcode'],
                'ort'         => $customer['city'],
                'land'        => $customer['countryiso'],
                'email'       => $customer['email'],
                '_customers_id' => (int) $customer['userID'],
            );
        }
        
        return array(
            'ok' => true,
            'count' => $count,
            'customers' => $customers,
        );
    }
}
