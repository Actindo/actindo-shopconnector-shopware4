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


use Shopware\Components\Api\Exception\NotFoundException;

class Shopware_Controllers_Frontend_Actindo extends Enlight_Controller_Action {
    /**
     * implements autoloading of all the components used by this plugin
     * 
     * @param string $className the classname to load
     * @return boolean true if the class was found and loaded, otherwise false
     */
    public static function autoloader($className) {
        $pieces = explode('_', $className);
        array_shift($pieces); // remove leading "Actindo_"
        
        $path = sprintf('%s%s.php', Shopware()->Plugins()->Core()->Actindo()->Path(), implode('/', $pieces));
        if(is_file($path)) {
            require($path);
            return true;
        }
        return false;
    }
    
    /**
     * pseudo action, redirect to shop index
     */
    public function indexAction() {
        $this->redirect('');
    }
    
    /**
     * main entry point of the connector
     * creates xmlrpc server, checks auth and processes the request
     */
    public function xmlrpcServerPhpAction() {
        // disable template output
        $this->View()->setTemplate();

        // catch cryptmode query, this is handled in plaintext (not the xmlrpc protocol)
        if(null !== $this->Request()->getParam('get_cryptmode')) {
            die('cryptmode=MD5Shopware&connector_type=XMLRPC');
        }
        
        // register own class autoloader
        $loader = Zend_Loader_Autoloader::getInstance();
        $loader->pushAutoloader(array(__CLASS__, 'autoloader'), 'Actindo_');
        
        // instanciate xmlrpc server
        $server = new Actindo_Components_XmlRpc_Server();
        
        // check auth and call request method
        // class constructor parses request method and all request params from raw post data; also takes care of compression
        $request = new Actindo_Components_XmlRpc_Request(null, null, $this->Request()->getServer());
        try {
            $request = Actindo_Components_Util::checkAuth($request);
        } catch(Actindo_Components_Exception $e) {
            $response = $server->fault($e);
        }
        
        if($response === null) {
            $response = $server->handle($request);
        }
        
        // return response
        echo $response;
        exit;
    }
    
    public function testAction() {
        $this->View()->setTemplate();
        $loader = Zend_Loader_Autoloader::getInstance();
        $loader->pushAutoloader(array($this, 'autoloader'), 'Actindo_');
        
        $util = Actindo_Components_Util::getInstance();
        $res  = Actindo_Components_ApiResourceManager::getInstance();
        
        
    }
}
