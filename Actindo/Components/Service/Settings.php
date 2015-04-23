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


class Actindo_Components_Service_Settings extends Actindo_Components_Service {    
    /**
     * returns the shops basic settings like manufacturers, languages, subhopinfo and so on
     * 
     * @api
     * @param struct $vars an associative array with information from actindo that may be relevant for the connector
     * @return struct
     */
    public function get($vars) {
        $ret = array();
        
        if(isset($vars['notification_url_order'])) { // url to ping for new orders
            $shopRepository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop');
            $shop = $shopRepository->find(1); // config element is global
            
            $config = Shopware()->Plugins()->Core()->Actindo()->Form();
            $element = $config->getElement('notificationUrl');
            foreach ($element->getValues() as $value) {
                Shopware()->Models()->remove($value);
            }        
            $value = new Shopware\Models\Config\Value();
            $value->setElement($element);
            $value->setValue($vars['notification_url_order']);
            $value->setShop($shop);
            $element->setValue($value);
            $element->setValues(array(1 => $value));
            Shopware()->Models()->flush($element);
        }
        
        // languages
        $ret['languages'] = $this->util->getLanguages();
        // suppliers (manufacturers)
        $ret['manufacturers'] = array();
        $result = Shopware()->Db()->fetchAll('SELECT `id`, `name` FROM `s_articles_supplier`');
        while($supplier = array_shift($result)) {
            $ret['manufacturers'][] = array(
                'manufacturers_id'   => (int) $supplier['id'],
                'manufacturers_name' => $supplier['name'],
            );
        }
        
        // customer groups
        $ret['customers_status'] = array();
        $result = Shopware()->Db()->fetchAll('SELECT * FROM `s_core_customergroups`');
        while($group = array_shift($result)) {
            $key = (int) $group['id'];
            $ret['customers_status'][$key] = array(
                'customers_status_id' => $key,
                'customers_status_min_order' => (float) $group['minimumorder'],
                'customers_status_discount'  => (float) $group['discount'],
                'customers_status_show_price_tax' => (int) $group['tax'],
                'customers_status_name' => array(),
            );
            foreach(array_keys($ret['languages']) AS $langID) {
                $ret['customers_status'][$key]['customers_status_name'][$langID] = sprintf('%s - %s', $group['groupkey'], $group['description']);
            }
        }
        
        // vpe (units)
        $ret['vpe'] = array();
        $vpes = $this->util->getVPEs();
        foreach($vpes AS $id => $unit) {
            foreach(array_keys($ret['languages']) AS $languageID) {
                if($key === 0) continue;
                $ret['vpe'][$id][$languageID] = array(
                    'products_vpe' => $id,
                    'vpe_name' => sprintf('%s - %s', $unit['unit'], $unit['description']),
                );
            }
        }
        
        // shipping times
        $ret['shipping'] = array();
        for($i = 0; $i <= 31; $i++) {
            $ret['shipping'][] = array(
                'id'   => $i + 1,
                'text' => sprintf("%d Tage", $i),
            );
        }
        
        // order states
        $ret['orders_status'] = array();
        $states = $this->util->getOrderStates();
        foreach($states AS $id => $state) {
            foreach(array_keys($ret['languages']) AS $languageID) {
                $ret['orders_status'][$id][$languageID] = $state['description'];
            }
        }
        
        // Crosselling
        $ret['xsell_groups'] = array(
            1 => array(
                'products_xsell_grp_name_id' => 1,
                'xsell_sort_order' => 0,
                'groupname' => array()
            ),
            2 => array(
                'products_xsell_grp_name_id' => 2,
                'xsell_sort_order' => 1,
                'groupname' => array()
            ),
        );
        foreach($ret['xsell_groups'] as $id => &$group) {
            foreach(array_keys($ret['languages']) as $langID) {
                $group['groupname'][$langID] = ($id == 1) ? 'Zubehör-Artikel' : 'Ähnliche Artikel';
            }
        }
        
        // article attributes and filter options/values
        $ret['artikel_properties'] = $this->util->getAllArticleFields();
        $ret['artikel_property_sets'] = $this->util->getArticleFilterOptions();
        
        // installed payment modules
        $ret['installed_payment_modules'] = array();
        $result = $this->util->getPaymentMeans();
        while($mean = array_shift($result)) {
            $ret['installed_payment_modules'][$mean['name']] = array(
                'id'     => $mean['id'],
                'code'   => $mean['name'],
                'active' => (int) $mean['active'],
                'name'   => $mean['description'],
            );
        }
        
        // installed shipping modules
        $ret['installed_shipping_modules'] = array();
        $result = Shopware()->Db()->fetchAll('SELECT `id`, `name`, `description`, `active` FROM `s_premium_dispatch` ORDER BY `name`');
        while($mean = array_shift($result)) {
            $ret['installed_shipping_modules'][$mean['name']] = array(
                'id'     => (int) $mean['id'],
                'code'   => $mean['name'],
                'active' => (int) $mean['active'],
                'name'   => $mean['description'],
            );
        }
        
        // multi shops
        $ret['multistores'] = $this->util->getMultiShops();
        
        $ret = Actindo_Components_Util::ScanForNullAndCorrect($ret);
        return array(
            'ok' => true,
            'settings' => $ret
        );
    }
}
