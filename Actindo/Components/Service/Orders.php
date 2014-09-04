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


class Actindo_Components_Service_Orders extends Actindo_Components_Service {
	const PAYONEDB = 's_order_attributes';
	const PAYONEFIELD = 'mopt_payone_txid';
    private static $paymentMap = array(
        'debit'         => 'L',
        'sepa'         => 'LSCORE',
        'cash'          => 'NN',
        'invoice'       => 'U',
        'prepayment'    => 'VK',
        'debituos'      => 'L',
        'credituos'     => 'KK',
        'giropayuos'    => 'GP',
        'uos_ut'        => 'UT',
        'uos_ut_gp'     => 'GP',
        'uos_ut_kk'     => 'KK',
        'uos_ut_ls'     => 'L',
        'uos_ut_vk'     => 'VK',
        'prepaiduos'    => 'VK',
        'invoiceuos'    => 'U',
        'paypal'        => 'PP',
        'paypalexpress' => 'PP',
        'ipayment'      => 'KK',
        'cashExpress'   => 'NN',
        'FinanzkaufBySantander' => 'FZ',
        'sofortueberweisung' => 'SU',
    );
    
    
    /**
     * exports the last order id and the total number of orders
     * 
     * @api
     * @return struct
     */
    public function count() {
        $result = Shopware()->Db()->fetchRow('SELECT max(`id`) AS `lastID`, count(`id`) AS `count` FROM `s_order`');
        
        return array(
            'ok' => true,
            'counts' => array(
                'count' => (int) $result['count'],
                'max_order_id' => (int) $result['lastID'],
            ),
        );
    }
    
    /**
     * this is where orders.list is handled (despite the different method name).
     * Exports the order list
     * 
     * @api
     * @param struct $filters to filter the returned orders
     */
    public function getList($filters) {
        $columnMap = array(
         // actindo field => sw field
            'order_id'    => 'id'
        );
        
        if(!empty($filters['sortColName'])) {
            if(isset($columnMap[$filters['sortColName']])) {
                $filters['sortColName'] = $columnMap[$filters['sortColName']];
            }
            else {
                $filters['sortColName'] = '';
            }
        }
        
        isset($filters['start']) or $filters['start'] = 0;
        isset($filters['limit']) or $filters['limit'] = 50;
        !empty($filters['sortColName']) or $filters['sortColName'] = 'id';
        !empty($filters['sortOrder'])   or $filters['sortOrder'] = 'DESC';
        
        $orders = $this->resources->order;
        $list = $orders->getList($filters['start'], $filters['limit'], array(), array(array('property' => $filters['sortColName'], 'direction' => $filters['sortOrder'])));
        if(empty($list['total'])) {
            throw new Actindo_Components_Exception('Could not find any orders');
        }
        
        $response = array();
        while($order = array_shift($list['data'])) {
            if($order['orderStatusId']<0)continue;
            try {
                $customer = $this->_getCustomerById($order['customerId']);
                $customerGroup = $this->util->findCustomerGroupByKey($customer['groupKey']);
            } catch(Shopware\Components\Api\Exception\NotFoundException $e) {
                // shouldn't happen but some beta testers reported this error
                continue;
            }
            $payment = $this->util->getPaymentMeans($order['paymentId']);
            
            $response[$order['id']] = null;
            $ref =& $response[$order['id']];
            
            if(!isset($customer['shipping'])) {
                $customer['shipping'] =& $customer['billing'];
            }
            
            $billingCountry  = $this->util->findCountryById($customer['billing']['countryId']);
            $shippingCountry = $this->util->findCountryById($customer['shipping']['countryId']);
            $billingCountryIso  = ($billingCountry === false)  ? 'de' : $billingCountry['countryiso']; // fallback to de
            $shippingCountryIso = ($shippingCountry === false) ? 'de' : $shippingCountry['countryiso']; // fallback to de
            
            $ref = array(
                '_customers_id'     => $order['customerId'],
                '_payment_method'   => is_array($payment) ? $payment['name'] : '',
                'beleg_status_text' => trim(str_replace("'", '', $order['customerComment'])),
                'bill_date'         => '', // set below array definition 
                'currency'          => $order['currency'],
                'currency_value'    => $order['currencyFactor'],
                'external_order_id' => $order['number'],
                'language'          => $order['languageIso'],
                'langcode'          => $order['languageIso'],
                'netto'             => $order['invoiceAmountNet'],
                'netto2'            => $order['invoiceAmountNet'],
                'order_id'          => $order['id'],
                'orders_status'     => $order['orderStatusId'],
                'project_id'        => $order['number'],
                'rabatt_type'       => 'betrag',
                'rabatt_betrag'     => 0.0,
                'saldo'             => $order['invoiceAmount'],
                'subshop_id'        => $order['shopId'],
                'tstamp'            => '', // set below array definition 
                
                'customer' => array(
                    'anrede'       => (string) $this->util->getSalutation($customer['billing']['salutation']),
                    'kurzname'     => !empty($customer['billing']['company']) ? $customer['billing']['company'] : sprintf('%s, %s', $customer['billing']['lastName'], $customer['billing']['firstName']),
                    'firma'        => (string) $customer['billing']['company'],
                    'name'         => (string) $customer['billing']['lastName'],
                    'vorname'      => (string) $customer['billing']['firstName'],
                    'adresse'      => $customer['billing']['street'] . ' ' . str_replace(' ', '', $customer['billing']['streetNumber']),
                    'adresse2'     => (string) $customer['billing']['department'],
                    'plz'          => (string) $customer['billing']['zipCode'],
                    'ort'          => (string) $customer['billing']['city'],
                    'land'         => $billingCountryIso,
                    'tel'          => (string) $customer['billing']['phone'],
                    'fax'          => (string) $customer['billing']['fax'],
                    'ustid'        => (string) $customer['billing']['vatId'],
                    'email'        => (string) $customer['email'],
                    'preisgruppe'  => (int) $customerGroup['id'],
                    'gebdat'       => ($customer['billing']['birthday'] instanceof DateTime) ? $customer['billing']['birthday']->format('Y-m-d') : '0000-00-00',
                    'print_brutto' => $order['net'] ? 0 : 1,
                ),
                'delivery' => array(
                    'anrede'   => (string) $this->util->getSalutation($customer['shipping']['salutation']),
                    'firma'    => (string) $customer['shipping']['company'],
                    'name'     => (string) $customer['shipping']['lastName'],
                    'vorname'  => (string) $customer['shipping']['firstName'],
                    'adresse'  => $customer['shipping']['street'] . ' ' . str_replace(' ', '', $customer['shipping']['streetNumber']),
                    'adresse2' => (string) $customer['shipping']['department'],
                    'plz'      => (string) $customer['shipping']['zipCode'],
                    'ort'      => (string) $customer['shipping']['city'],
                    'land'     => $shippingCountryIso,
                    'tel'      => (string) $customer['billing']['phone'],
                    'fax'      => (string) $customer['billing']['fax'],
                    'ustid'    => (string) $customer['billing']['vatId'],
                ),
            );
            
            if($order['orderTime'] instanceof DateTime) {
                $ref['bill_date'] = $order['orderTime']->format('Y-m-d H:i:s');
                $ref['tstamp']    = $order['orderTime']->getTimestamp();
                $ref['webshop_order_date'] = $order['orderTime']->format('Y-m-d');
                $ref['webshop_order_time'] = $order['orderTime']->format('H:i:s');
            }
            else {
                $ref['bill_date'] = '0000-00-00 00:00:00';
                $ref['tstamp']    = 0;
                $ref['webshop_order_date'] = '0000-00-00';
                $ref['webshop_order_time'] = '00:00:00';
            }
            $ref['val_date'] = $ref['bill_date'];

            $ref['customer']['verf'] = isset(self::$paymentMap[$ref['_payment_method']]) ? self::$paymentMap[$ref['_payment_method']] : 'VK';
            switch($ref['customer']['verf']) {
                case 'L':
                    if(preg_match('/[a-zA-Z]{2}[0-9]{2}[a-zA-Z0-9]{4}[0-9]{7}([a-zA-Z0-9]?){0,16}/',$customer['debit']['account'])){
                        $ref['customer']['iban'] = (string) $customer['debit']['account'];
                        $ref['customer']['swift'] = (string) $customer['debit']['bankCode'];
                    }else{
                        $ref['customer']['blz'] = str_replace(array(' ', '-', '/'), '', (string) $customer['debit']['bankCode']);
                        $ref['customer']['kto'] = (string) $customer['debit']['account'];
                    }
                    if(!in_array($customer['debit']['accountHolder'], array('', 'Inhaber'))) {
                        $ref['customer']['kto_inhaber'] = $customer['debit']['accountHolder'];
                    }
                break;
                case 'LSCORE':
                    $customer['paymentData'] = (array)$customer['paymentData'];
                    if(count($customer['paymentData']))
                    {
                        foreach($customer['paymentData'] as $paymentData)
                        {
                            if(isset($paymentData['bic']) && !empty($paymentData['bic']) && isset($paymentData['iban']) && !empty($paymentData['iban']))
                            {
                                $ref['customer']['iban'] = (string)$paymentData['iban'];
                                $ref['customer']['swift'] = (string)$paymentData['bic'];
                                if(!in_array((string)$paymentData['accountHolder'], array('', 'Inhaber')))
                                {
                                    $ref['customer']['kto_inhaber'] = (string)$paymentData['accountHolder'];
                                }
                                break;
                            }
                        }
                    }
                break;
                case 'PP': // paypal
                    // @todo
                break;
            }
            //CON-534
            //Payone Integration
            try{
                $payOneResult = Shopware()->Db()->fetchRow('SELECT '.self::PAYONEFIELD.' FROM '.self::PAYONEDB.' WHERE orderID='.(int)$order['id'].';');
                    if(
                        $payOneResult 
                        && 
                        $payOneResult!==null 
                        && 
                        is_array($payOneResult) 
                        && 
                        isset($payOneResult[self::PAYONEFIELD])
                    )
                    {
                        $ref['payment_type'] = 'payone';
                        $ref['payment_order_id'] = $payOneResult[self::PAYONEFIELD];
                    }
            }
            catch(\Exception $ex)
            {
                //do nothing, payone not installed!
            }

            // to get order positions via api we'd have to use the getOne() call which fetches far too much information for this purpose
            // => do it manually
            $ref['rabatt_betrag'] = (float) Shopware()->Db()->fetchOne(sprintf('
                SELECT ABS(SUM(`price`))
                FROM `s_order_details`
                WHERE `orderID` = %d
                    AND `modus` = 3
            ', $order['id']));

            $ref = Enlight()->Events()->filter(
                'Actindo_Connector_Service_Orders_getList_filterOrder',
                $ref,
                array(
                    'subject' => $this,
                    'id'      => $order['id'],
                )
            );
        }

        $response = Actindo_Components_Util::ScanForNullAndCorrect($response);
        
        return array('ok' => true, 'orders' => $response);
    }
    
    /**
     * exports all positions of one order
     * 
     * @api
     * @param int $orderID order id to export the positions of
     * @return struct
     */
    public function list_positions($orderID) {
        /*
         * article modes (incomplete!?)
         * 0 - regular article
         * 1 - premium article
         * 2 - voucher
         * 3 - discount
         * 4 - payment (+/-)
         * 10 - bundle rebate
         */
        $orders = $this->resources->order;
        $order = $orders->getOne($orderID);
        $response = array();
        
        $maxTaxRate = 0; // remember highest tax rate from positions to use as shipping tax rate
        
        foreach($order['details'] AS &$position) {
            $maxTaxRate = max($maxTaxRate, $position['taxRate']);
            if($position['mode'] == 3) { // discount
                continue;
            }
            
            $item = array(
                'art_nr'      => $position['articleNumber'],
                'art_nr_base' => $position['articleNumber'],
                'art_name'    => (string) htmlspecialchars_decode($position['articleName']),
                'preis'       => $position['price'],
                'is_brutto'   => $order['net'] ? 0 : 1,
                'type'        => in_array($position['mode'], array(0, 1), true) ? 'Lief' : 'NLeist',
                'mwst'        => (string) $position['taxRate'],
                'menge'       => $position['quantity'],
                'attributes'  => array(), // filled below array definition
                'langtext'    => '',      // filled below array definition
            );
            
            if($position['mode'] == 0) { // regular article
                // liveshopping not yet avi?
            }
            if($position['mode'] == 1) { // premium article
                $item['langtext'] = sprintf('<p><i>Prämienartikel</i></p>'); // @todo replace with snippet
            }
            if($position['mode'] == 2) { // voucher
                
            }
            if($position['mode'] == 0 || $position['mode'] == 1) { // article (regular or premium)
                // @todo variant handling
            }
            $response[] = $item;
        }
        
        // append shipping costs
        $response[] = array(
            'art_nr'      => sprintf('SHIPPING%d', $order['dispatch']['id']),
            'art_nr_base' => sprintf('SHIPPING%d', $order['dispatch']['id']),
            'art_name'    => (string) $order['dispatch']['name'],
            'preis'       => $order['net'] ? $order['invoiceShippingNet'] : $order['invoiceShipping'],
            'is_brutto'   => $order['net'] ? 0 : 1,
            'type'        => 'NLeist',
            'mwst'        => $maxTaxRate,
            'menge'       => 1,
            'langtext'    => (string) $order['dispatch']['description'],
        );
        
        return $response;
    }
    
    /**
     * updates an order status and triggers the notification email to the customer
     * 
     * @api
     * @param int $orderID the order id to update
     * @param string $status the status id to set the order to
     * @param string $comment a comment to attach to the order
     * @param int $notifyCustomer whether to send an email to the customer
     * @param int $sendComments ignored
     */
    public function set_status($orderID, $status, $comment, $notifyCustomer, $sendComments) {
        // sw4 order api fails to update the state, do it manually
        if(false === $this->util->findOrderStateById($status)) {
            return array('ok' => false, 'error' => 'could not find order status with id ' . $status);
        }
        Shopware()->Db()->update('s_order', array('status' => $status, 'comment' => $comment), sprintf('id = %d', $orderID));
        
        // send customer mail if requested
        if($notifyCustomer) {
            try {
                $this->sendStatusEmail($orderID, (int) $status);
            } catch(Exception $e) {
                return array('ok' => false, 'error' => 'error sending status email to customer: ' . $e->getMessage());
            }
        }
        
        return array('ok' => true);
    }
    
    /**
     * when an invoice is edited the shop is called with some info (status, stock, ...).
     * this is done here
     * 
     * @api
     * @param array $params 
     */
    public function set_status_invoice($params) {
        return call_user_func_array(array($this, 'set_status'), $params);
    }
    
    /**
     * sets the tracking code of an order
     * 
     * @api
     * @param int $orderID the order id to set the tracking code for
     * @param string $trackingCode the tracking code to set
     * @return struct
     */
    public function set_trackingcode($orderID, $trackingCode) {
        $this->resources->order->update($orderID, array('trackingCode' => $trackingCode));
        return array('ok' => true);
    }
    
    /**
     * returnes all customer details for a customer id, results are cached for reuse
     * 
     * @param int $id customer id
     * @return array result from customer->getOne() api call
     */
    protected function _getCustomerById($id) {
        static $cache = array();
        
        if(!isset($cache[$id])) {
            $customers = $this->resources->customer;
            $cache[$id] = $customers->getOne($id);
        }
        return $cache[$id];
    }
    
    /**
     * send the order status email to the customer
     * 
     * @param int $orderID the order id to operate on
     * @param int $statusID status id of the order, used to determine which email template to use
     * @return void
     * @throws Exception
     */
    protected function sendStatusEmail($orderID, $statusID) {
        $mail = Shopware()->Modules()->Order()->createStatusMail($orderID, $statusID);
        if($mail instanceof Enlight_Components_Mail) {
            Shopware()->Modules()->Order()->sendStatusMail($mail);
        }
    }
}
