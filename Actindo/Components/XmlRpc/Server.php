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


class Actindo_Components_XmlRpc_Server extends Zend_XmlRpc_Server {
    const ACTINDO_TRANSPORT_CHARSET = 'ISO-8859-1';
    const SHOPWARE_CHARSET = 'UTF-8';
    
    private $cacheFile = null;
    
    public function __construct() {
        parent::__construct();
        
        Zend_XmlRpc_Server_Fault::attachFaultException('Exception');
        $this->setCacheFile();
        $this->registerServices();
    }
    
    private function registerServices() {
        if($this->cacheFile === null || !Zend_XmlRpc_Server_Cache::get($this->cacheFile, $this)) {
            $this->setClass('Actindo_Components_Service_Actindo', 'actindo');
            $this->setClass('Actindo_Components_Service_Category', 'category');
            $this->setClass('Actindo_Components_Service_Customers', 'customers');
            $this->setClass('Actindo_Components_Service_Customers', 'customer');
            $this->setClass('Actindo_Components_Service_Orders', 'orders');
            $this->setClass('Actindo_Components_Service_Product', 'product');
            $this->setClass('Actindo_Components_Service_Settings', 'settings');
            if($this->cacheFile !== null) {
                Zend_XmlRpc_Server_Cache::save($this->cacheFile, $this);
            }
        }
    }
    
    /**
     * this method catches the original return value of called xmlrpc methods
     * here we can do processing, specifically charset-processing, before passing the response along
     * 
     * @see Zend_Server_Abstract::dispatch()
     * @param Zend_Server_Method_Definition $invocable
     * @param array $params
     * @return mixed
     */
    protected function _dispatch(Zend_Server_Method_Definition $invocable, array $params) {
        if(self::ACTINDO_TRANSPORT_CHARSET == 'ISO-8859-1' && self::SHOPWARE_CHARSET == 'UTF-8') {
            Actindo_Components_Util::utf8Encode($params, array('images'));
        }
        
        $result = parent::_dispatch($invocable, $params);
        
        if(self::ACTINDO_TRANSPORT_CHARSET == 'ISO8859-1' && self::SHOPWARE_CHARSET == 'UTF-8') {
            Actindo_Components_Util::utf8Decode($result, array('images'));
        }
        
        return $result;
    }
    
    /**
     * actindo calls some functions that for technical reasons we can't map directly to class methods
     * (customers.list for example, can't create a method called "list" as that is a reserved php keyword).
     * We overload this function to map those calls to different callables
     * 
     * @staticvar array $map translation map of methods
     * @param Zend_XmlRpc_Request $request
     * @return Zend_XmlRpc_Response 
     */
    protected function _handle(Zend_XmlRpc_Request $request) {
        static $map = array(
            'customers.list' => 'customers.getList',
            'orders.list'    => 'orders.getList',
        );
        
        $method = $request->getMethod();
        if(isset($map[$method])) {
            $request->setMethod($map[$method]);
        }
        
        try {
            return parent::_handle($request);
        }
        catch(Zend_XmlRpc_Server_Exception $e) {
            if($e->getCode() != 623) { // Calling parameters do not match signature
                throw $e;
            }
            
            // to make the connector somewhat future proof when actindo changes request parameters
            $request = $this->forceMatchingParameters($request); // !check method docblock!
            return parent::_handle($request);
        }
    }
    
    /**
     * tries to match the params in the request object with the expected ones by the xmlrpc method to be called.
     * this is called when the "Calling parameters do not match signature" exception is triggered.
     * !Warning! currently the only case that is handled is when there are more params than the xmlrpc method takes (trailing params are simply cut off).
     * Other cases that can happen (and are NOT currently handled):
     * - param types don't match (string expected, int received)
     * - too few parameters, xmlrpc method expected more
     * 
     * @param Zend_XmlRpc_Request $request
     * @return Zend_XmlRpc_Request 
     */
    protected function forceMatchingParameters(Zend_XmlRpc_Request $request) {
        $info     = $this->_table->getMethod($request->getMethod());
        $params   = $request->getParams();
        $argv     = $info->getInvokeArguments();
        if (0 < count($argv) and $this->sendArgumentsToAllMethods()) {
            $params = array_merge($params, $argv);
        }
        
        $signature = array_shift($info->getPrototypes());
        $parameters = $signature->getParameters();
        
        $methodParamCount  = count($parameters);
        $requestParamCount = count($params);
        
        if($requestParamCount > $methodParamCount) {
            // additional args added from actindo that we can't handle, throw them away
            $params = array_slice($params, 0, $methodParamCount);
            $request->setParams($params);
        }
        elseif($requestParamCount < $methodParamCount) {
            // method was called with less parameters than it requires
            // don't fix, this will throw an error which is probably best
        }
        else {
            // parameter count matched, check types
            // don't fix, throw error
        }
        
        return $request;
    }
    
    /**
     * tries to find a file path that is writable and sets the absolute path in $cacheFile 
     */
    private function setCacheFile() {
        $cacheFile = sprintf('%s/cache/actindo.xmlrpc.cache', rtrim(Shopware()->System()->sBasePath, '/'));
        if(@touch($cacheFile)) {
            $this->cacheFile = $cacheFile;
        }
    }
}
