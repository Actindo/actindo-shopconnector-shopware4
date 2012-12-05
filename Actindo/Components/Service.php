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
 * base class for all service classes in Components/Service/
 */
class Actindo_Components_Service {
    /**
     * @var Actindo_Components_ApiResourceManager
     */
    protected $resources;
    
    /**
     * @var Actindo_Components_Util
     */
    protected $util;
    
    
    /**
     * fetches the resource manager and util class instances
     */
    public function __construct() {
        $this->resources = Actindo_Components_ApiResourceManager::getInstance();
        $this->util      = Actindo_Components_Util::getInstance();
    }
}
