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


class Actindo_Components_XmlRpc_Request extends Zend_XmlRpc_Request_Http {
    protected $compression = null;
    
    /**
     * Create a new XML-RPC request
     *
     * @param string $method (optional)
     * @param array $params  (optional)
     * @param array $server (optional) $_SERVER array (used to detect content compression)
     */
    public function __construct($method = null, $params = null, $server = null) {
        if($server !== null) {
            if(isset($server['HTTP_CONTENT_ENCODING'])
                    && strtolower($server['HTTP_CONTENT_ENCODING']) == 'gzip')
            {
                $this->compression = 'gzip';
            }
        }
        
        parent::__construct($method, $params);
    }
    
    public function loadXML($request) {
        switch($this->compression) {
            case 'gzip':
                if(function_exists('gzinflate') && ($inflated = @gzinflate(substr($request, 10)))) {
                    $request = $inflated;
                }
                break;
        }
        
        return parent::loadXML($request);
    }
}
