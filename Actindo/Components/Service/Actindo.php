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


class Actindo_Components_Service_Actindo extends Actindo_Components_Service {
    /**
     * get versions
     * @return array
     */
    public function get_connector_version() {
        $pluginInfo = Shopware()->Plugins()->Core()->Actindo()->getInfo();
        
        list($version, $revision) = explode('.', $pluginInfo['version']);
        
        $arr = array(
            'revision'     => $revision,
            'protocol_version' => $pluginInfo['version'],
            'shop_type'    => 'shopware4',
            'shop_version' => Shopware()->System()->sCONFIG['sVERSION'],
            'capabilities' => $this->_getShopCapabilities(),
            'cpuinfo'      => @file_get_contents('/proc/cpuinfo'),
            'meminfo'      => @file_get_contents('/proc/meminfo'),
            'extensions'   => array(),
        );
        
        foreach(get_loaded_extensions() as $extension) {
            $arr['extensions'][$extension] = phpversion($extension);
        }
        
        if(is_callable('phpinfo')) {
            ob_start();
            phpinfo();
            $c = ob_get_contents();
            ob_end_clean();
            $arr['phpinfo'] = new Zend_XmlRpc_Value_Base64($c);
        }
        
        return $arr;
    }
    
    /**
     * 
     * @return array
     */
    public function get_time() {
        $arr = array(
            'time_server'     => date('Y-m-d H:i:s'),
            'gmtime_server'   => gmdate('Y-m-d H:i:s'),
            'time_database'   => date('Y-m-d H:i:s'),
            'gmtime_database' => gmdate('Y-m-d H:i:s'),
        );

        if(!empty($arr['gmtime_database'])) {
            $diff = strtotime($arr['time_database']) - strtotime($arr['gmtime_database']);
        }
        else {
            $diff = strtotime($arr['time_server']) - strtotime($arr['gmtime_server']);
        }
        $arr['diff_seconds'] = $diff;
        $diff_neg = $diff < 0;
        $diff = abs($diff);
        $arr['diff'] = ($diff_neg ? '-' : '') . sprintf('%02d:%02d:%02d', floor($diff / 3600), floor(($diff % 3600) / 60), $diff % 60);
        
        return $arr;
    }
    
    /**
     * ping
     * pong
     * 
     * @return array
     */
    public function ping() {
        return array(
            'ok'   => true,
            'pong' => 'pong',
        );
    }
    
    /**
     * @return array associative array explaining the shops capabilities
     */
    private function _getShopCapabilities() {
        return array(
            'artikel_vpe' => 1,                 // Verpackungseinheiten
            'artikel_shippingtime' => 0,        // Produkt Lieferzeit als fest definierte werte
            'artikel_shippingtime_days' => 1,   // Produkt Lieferzeit als int für n Tage
            'artikel_properties' => 1,
            'artikel_property_sets' => 1,
            'artikel_contents' => 1,
            'artikel_attributsartikel' => 1,    // Attributs-Kombinationen werden tatsächlich eigene Artikel
            'wg_sync' => 1,
            'artikel_list_filters' => 1,
            'multi_livelager' => 1,
        );
    }
}
