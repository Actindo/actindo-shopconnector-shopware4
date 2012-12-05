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


use \Shopware\Components\Api\Manager;

/**
 * singleton wrapper class for shopwares api resource manager to provide lazy access to the api resource classes 
 */
class Actindo_Components_ApiResourceManager extends Manager {
    /**
     * singleton instance of this class
     * @var Actindo_Components_ApiResourceManager
     */
    private static $instance = null;
    
    /**
     * holds shopwares api resource classes
     * @var array
     */
    private $resources = array();
    
    
    private function __construct() {    
    }
    
    /**
     * returns singleton instance of this class
     * 
     * @return Actindo_Components_ApiResourceManager
     */
    public static function getInstance() {
        if(self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * provides lazy access for api resource classes
     * 
     * @param string $name
     * @return \Shopware\Components\Api\Resource\Resource
     */
    public function __get($name) {
        $name = ucfirst(strtolower($name));
        if(!isset($this->resources[$name])) {
            $this->resources[$name] = parent::getResource($name);
        }
        return $this->resources[$name];
    }
}
