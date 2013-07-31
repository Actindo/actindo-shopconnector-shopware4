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
 * the main entry point of the connector (when being contacted by actindo) is Controllers/Frontend/Actindo.php -> xmlrpcServerPhpAction()
 * 
 * all the xmlrpc callback functions are grouped as modules in Components/Service
 * example: product.get gets passed to the module Components/Service/Product -> public method get()
 *          actindo.get_connector_version gets passed to the module Components/Service/Actindo  -> public method get_connector_version()
 * exceptions are "customer." which gets routed to Components/Service/Customers (with trailing s)
 *      and callback names like "list" (which can't be a method name in php); see Components/XmlRpc/Server.php -> _handle() for further detail
 * !all public methods in those service classes are callable via xmlrpc!
 */

class Shopware_Plugins_Core_Actindo_Bootstrap extends Shopware_Components_Plugin_Bootstrap {
    /**
     * the version number MUST match this regex (in order for the update mechanism to work):
     * ^\d+\.\d+$
     */
    const VERSION = '2.255';
    
    /**
     * the installed shopware version must be this or greater, otherwise this plugin won't install 
     */
    const REQUIRED_SHOPWARE_VERSION_MIN = '4.0.4';
    
    /**
     * the installed shopware version must be smaller than this, otherwise this plugin won't install 
     */
    const REQUIRED_SHOPWARE_VERSION_MAX = '5.0';
    
    /**
     * all of the plugins listed here must be installed, otherwise this plugin won't install
     * @var array list of required plugins
     */
    private static $requiredPlugins = array();
    
    
    
    /*
     * install and update methods
     */
    
    /**
     * installs this plugin, the actual installation steps are split up in methods further down and they're invoced by this method
     * 
     * @return boolean will always return true; however: exceptions may be thrown (which are handled by the plugin manager and displayed as errors in the ui)
     */
    public function install() {
        $this->checkCompatability();
        
        $this->setupModels();
        $this->createConfig();
        $this->createEvents();
        return true;
    }
    
    /**
     * checks if this plugin is compatible with its environment
     * -> min version, max version, plugin dependencies
     * 
     * @return void
     * @throws Enligh_Exception if something is wrong 
     */
    public function checkCompatability() {
        if(!version_compare(Shopware()->Config()->sVERSION, self::REQUIRED_SHOPWARE_VERSION_MIN, '>=')) {
            throw new Enlight_Exception(sprintf('Need at least Shopware %s or later to install this plugin', self::REQUIRED_SHOPWARE_VERSION_MIN));
        }
        if(!version_compare(Shopware()->Config()->sVERSION, self::REQUIRED_SHOPWARE_VERSION_MAX, '<')) {
            throw new Enlight_Exception(sprintf('Shopware %s is not supported by this plugin', self::REQUIRED_SHOPWARE_VERSION_MAX));
        }
        if(!$this->assertRequiredPluginsPresent(self::$requiredPlugins)) {
            throw new Enlight_Exception("This plugin requires the following other plugins to be installed: " . implode(', ', self::$requiredPlugins));
        }
    }
    
    /**
     * creates the plugin configuration values 
     */
    private function createConfig() {
        $form = $this->Form();
        
        $form->setElement('text', 'notificationUrl', array(
            'label' => 'Bestell-Benachrichtigungs-URL',
            'description' => 'Diese Einstellung nimmt der Connector automatisch vor. Um eine korrekte Funktionalität sicherzustellen darf sie nicht manuell verändert werden.',
            'value' => 'Dieses Feld wird automatisch ausgefüllt wenn Sie den Connector in Actindo anbinden.',
        ));
        
        $form->setElement('boolean', 'pingOrder', array(
            'label' => 'Bestellungen an Actindo melden',
            'description' => 'Actindo wird über neue Bestellungen informiert und importiert sie sofort',
            'value' => false,
            'scope' => Shopware\Models\Config\Element::SCOPE_SHOP,
        ));
    }
    
    /**
     * registers the event listeners for this plugin
     * 
     * @return void
     */
    private function createEvents() {
        $this->subscribeEvent(
            'Enlight_Controller_Dispatcher_ControllerPath_Frontend_Actindo',
            'onGetControllerPathFrontendActindo'
        );
        
        $this->subscribeEvent(
            'sOrder::sSaveOrder::after',
            'onSaveOrder'
        );
    }
    
    /**
     * generally: sets up the database for this plugin
     * specifically: create a new column in s_articles_attributes to store the master article number on variant articles
     * 
     * @return void
     * @throws Enlight_Exception 
     */
    private function setupModels() {
        try {
            Shopware()->Models()->addAttribute('s_articles_attributes', 'actindo', 'masternumber', 'varchar(64)', true, null);
            $this->Application()->Models()->generateAttributeModels(array('s_articles_attributes'));
        } catch(Zend_Db_Statement_Exception $e) {
            // Duplicate column name
            // => Plugin was previously installed and was removed, now we're reinstalling
        }
    }
    
    /**
     * is called after the plugin was updated.
     * It looks at all methods in this class that match the regex '^_updateFrom(\d+_\d+)$', determines the version number from the match
     * and, if the prior version was smaller or equal to that version, calls them (it also makes sure that they're called in the right order).
     * For more infos on the update methods check the docblock above the update methods of this class.
     * 
     * @param string $priorVersion the version that was installed before it was updated to this current version
     * @return boolean true on success, false on failure
     */
    public function update($priorVersion) {
        $updateMethods = array();
        foreach(get_class_methods($this) AS $method) {
            if(preg_match('/^_updateFrom(\d+_\d+)$/', $method, $matches)) { // method matches the update format
                $version = str_replace('_', '.', $matches[1]);
                if(version_compare($priorVersion, $version, '<=')) { // if prior plugin version is smaller or equal, call this update method
                    $updateMethods[$version] = $method;
                }
            }
        }
        
        ksort($updateMethods); // make sure the methods are called in the right order
        foreach($updateMethods AS $version => $method) {
            if(false === $this->$method()) {
                return false;
            }
        }
        
        // clear method definition cache
        if(!class_exists('Actindo_Components_XmlRpc_Server')) {
            require_once(dirname(__FILE__) . '/Components/XmlRpc/Server.php');
        }
        $rpcServer = new Actindo_Components_XmlRpc_Server();
        $rpcServer->purgeCacheFile();
        
        return true;
    }
    
    /**
     * uninstalls this plugin (keep our master article column in s_articles_attributes in case the plugin is reinstalled at a later time)
     * 
     * @return boolean always true
     */
    public function uninstall() {
        return true;
    }
    
    /**
     * called by the shopware plugin manager to retrieve information about this plugin
     * 
     * @return array infos about this plugin
     */
    public function getInfo() {
        return array(
            'version'     => $this->getVersion(),
            'autor'       => 'Actindo GmbH',
            'copyright'   => 'Actindo GmbH',
            'label'       => 'Actindo Connector',
            'description' => 'Verknüpft den Shop mit Actindo und ermöglicht die Synchronisierung von Artikeln und Bestellungen',
            'license'     => 'GPLv2',
            'support'     => 'http://www.actindo.de/',
            'link'        => 'http://www.actindo.de/',
        );
    }
    
    /**
     * returns the current plugin version
     * 
     * @return string plugin version
     */
    public function getVersion() {
        return self::VERSION;
    }
    
    /**
     * is called when shopware tries to locate the frontend controller "actindo"
     * this callback is registered in createEvents()
     * 
     * @param Enlight_Event_EventArgs $args
     * @return string path to the controller
     */
    public function onGetControllerPathFrontendActindo(Enlight_Event_EventArgs $args) {
        return $this->Path() . '/Controllers/Frontend/Actindo.php';
    }
    
    public function onSaveOrder(Enlight_Hook_HookArgs $args) {
        if(!function_exists('curl_init')) {
            return; // tell admin?
        }
        
        $config = Shopware()->Plugins()->Core()->Actindo()->Config();
        if(!$config->pingOrder) {
            return; // plugin configured not to notify actindo about new orders
        }
        $notificationUrl = $config->notificationUrl;
        if(!class_exists('Actindo_Components_Util')) {
            require_once(dirname(__FILE__) . '/Components/Util.php');
        }
        if(!Actindo_Components_Util::isValidUrl($notificationUrl)) {
            return;
        }
        
        if(false !== ($curl = @curl_init($notificationUrl))) {
            @curl_setopt_array($curl, array(
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => 2,
            ));
            @curl_exec($curl);
        }
    }
    
    
    /*
     * update methods
     * 
     * these methods are called automatically, depending on their name, by the update() method
     * all update methods must start with "_updateFrom" followed by the version number they update from (dots replaces with underscores)
     * all updates methods might be called multiple times, so make sure they still maintain the plugin integrity if that happens
     */
}
