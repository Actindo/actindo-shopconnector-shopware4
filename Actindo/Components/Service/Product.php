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


class Actindo_Components_Service_Product extends Actindo_Components_Service {
    /**
     * retrieves the article count in total and per category
     * 
     * @api
     * @param int $categoryID only retrieves article count for the given category id if > 0
     * @param string $ordernumber NOT SUPPORTED! has no effect. only here for compatability reasons
     * @return struct
     */
    public function count($categoryID, $ordernumber) {
        if($categoryID > 0) {
            $searchWhere = sprintf('`sac`.`categoryID` = ?', $categoryID);
        }
        else {
            $searchWhere = '';
        }
        
        $sql = '
            SELECT `sac`.`categoryID`, count(`sac`.`articleID`) AS `count`
            FROM `s_articles_categories` `sac`
            ' . (!empty($searchWhere) ? 'WHERE ' . $searchWhere : '') . '
            GROUP BY `sac`.`categoryID`
        ';
        $categories = array();
        $result = Shopware()->Db()->fetchAll($sql);
        while($row = array_shift($result)) {
            $categories[(int) $row['categoryID']] = (int) $row['count'];
        }
        
        $categories[-1] = (int) Shopware()->Db()->fetchOne('SELECT count(*) FROM `s_articles`');
        
        return array('ok' => true, 'count' => $categories);
    }
    
    /**
     * called to create or update a single product
     * here we figure out if its an update or a new article and delegate to the respective methods
     * 
     * @api
     * @param struct $product an array with all product details
     * @return struct
     */
    public function create_update($product) {
        try {
            $articleID = $this->util->findArticleIdByOrdernumber($product['art_nr']);
			// article found, update
            return $this->updateProduct($articleID, $product);            
        } catch(Actindo_Components_Exception $e) {
            // article not found, create new one
            return $this->importProduct($product);
        }
    }
    
    /**
     * deletes an article
     * 
     * @api
     * @param string $ordernumber the ordernumber of the article to be deleted
     * @return struct
     */
    public function delete($ordernumber) {
        $articleID = $this->util->findArticleIdByOrdernumber($ordernumber);
        
        $articles = $this->resources->article;
        $articles->delete($articleID);
        return array('ok' => true);
    }
    
    /**
     * exports either the article list or all the details of one specific article
     * the 2nd param, $ordernumber, is actually the shops article id if article details are exported!
     * 
     * @api
     * @param string $categoryID the category to export the list from
     * @param string $ordernumber the articles ordernumber if the list is requested, otherwise its the shops article id
     * @param string $language not supported
     * @param int $justList is 1 if an article listing is requested, otherwise its 0
     * @param int $offset only for list: offset to start with
     * @param int $limit  only for list: limits the number of articles
     * @param struct $filters only for list: an array of filters
     * @return array
     */
    public function get($categoryID, $ordernumber, $language, $justList, $offset, $limit, $filters) {
        if(empty($ordernumber)) {
            // return an article listing
            return $this->exportList($offset, $limit, $filters);
        }
        elseif(!empty($justList)) {
            // $ordernumber given but $justList requested, get "list" of just that product
            $filters = array_merge($filters, array(
                'ordernumber' => $ordernumber
            ));
            return $this->exportList($offset, $limit, $filters);
        }
        // export all details of a single product
        return $this->exportProduct($ordernumber, $language);
    }
    
    /**
     * updates an articles stock (and possibly the status) of one or more articles
     * 
     * @api
     * @param array $product this can be either an array containing a single articles information
     *                          or an array of arrays with article information
     * @return array
     */
    public function update_stock($product) {
        if(!isset($product['art_nr']) && count($product)) {
            // multiple article stock updates
            $response = array(
                'ok'      => true,
                'success' => array(),
                'failed'  => array(),
            );
            foreach($product AS $key => $item) {
                $result = $this->_importStock($item);
                if(!$response['success'][$key] = $result['ok']) {
                    $response['failed'][$key] = $result;
                }
            }
        }
        else {
            // single product stock udpate
            $response = $this->_importStock($product);
        }
        
        return $response;
    }
    
    
    
    /**
     * returns an article listing
     * 
     * @param int $offset offset to start the list with
     * @param int $limit maximum amount of items in the returned list
     * @param array $filters an array of filters to apply to the list query
     * @return array
     */
    protected function exportList($offset, $limit, $filters) {
        $offset = isset($filters['start']) ? (int) $filters['start'] : $offset;
        $limit  = isset($filters['limit']) ? (int) $filters['limit'] : $limit;
        
        $whereClause = '';
        if(!empty($filters['ordernumber'])) {
            try {
                $articleID = $this->util->findArticleIdByOrdernumber($filters['ordernumber']);
            } catch(Actindo_Components_Exception $e) {
                $articleID = 0;
            }
            $whereClause = Shopware()->Db()->quoteInto('WHERE `sa`.`id` = ?', $articleID);
        }
        
        $result = Shopware()->Db()->fetchAll(sprintf('
            SELECT `sa`.`id`, `sa`.`active`, `sad`.`ordernumber`, `name`, `datum`, `changetime`, count(`variants`.`id`) AS `variants`,
                (SELECT `categoryID`
                 FROM `s_articles_categories` `sac`
                 INNER JOIN `s_categories` `sc` ON `sc`.`id` = `sac`.`categoryID`
                 WHERE `sac`.`articleID` = `sa`.`id`
                 ORDER BY `left`
                 LIMIT 1) AS `categoryID`
            FROM `s_articles` `sa`
            INNER JOIN `s_articles_details` `sad` ON `sad`.`articleID` = `sa`.`id` AND `sad`.`kind` = 1
            LEFT JOIN `s_articles_details` `variants` ON `variants`.`articleID` = `sa`.`id` AND `variants`.`kind` = 2
            ' . $whereClause . '
            GROUP BY `sa`.`id`
            ORDER BY `sa`.`id`
            LIMIT %d
            OFFSET %d
        ', $limit, $offset));
        
        $products = array();
        $variantIds = array();
        while($article = array_shift($result)) {
            $id = (int) $article['id'];
            $products[$id] = array(
                'products_id'     => $id,
                'art_nr'          => $article['ordernumber'],
                'art_name'        => $article['name'],
                'grundpreis'      => 0.0,
                'categories_id'   => (int) $article['categoryID'],
                'products_status' => (int) $article['active'],
                'created'         => $this->util->datetimeToTimestamp($article['datum']),
                'last_modified'   => $this->util->datetimeToTimestamp($article['changetime']),
            );
            if(!empty($article['variants'])) {
                $variantIds[] = $id;
            }
        }
        // this is to create a "fake" master article for variants, needs its own ordernumber
        if(!empty($variantIds)) {
            $variantOrdernumbers = $this->util->findOrdernumberForVariantArticle($variantIds);
            foreach($variantOrdernumbers AS $id => $ordernumber) {
                $products[$id]['art_nr'] = $ordernumber;
            }
        }
        return array('ok' => true, 'products' => $products);
    }
    
    /**
     * main entry point of single product export (with all details)
     * 
     * @param int $articleID the article id to export
     * @param string $language deprecated
     * @return array
     */
    protected function exportProduct($articleID, $language) {
        $article = $this->resources->article->getOne($articleID);
        $articleMainDetails =& $article['mainDetail'];
        
        $article['esd'] = Shopware()->Db()->fetchOne('SELECT count(*) FROM `s_articles_esd` WHERE `articleID` = ?', array($article['id']));
        $unit = $this->util->getVPEs($articleMainDetails['unitId']);
        $response = array(
            'abverkauf'         => $article['lastStock'] ? 1 : 0,
            'all_categories'    => array(),     // is set below array definition: $this->_exportCategories()
            'art_name'          => $article['name'],
            'art_nr'            => $articleMainDetails['number'],
            'articledetailsID'  => $articleMainDetails['id'],
            'attributes'        => array(),     // is set below array definition: $this->_exportVariants()
            'bundle'            => empty($article['crossBundleLook']) ? 0 : 1,
            'categories_id'     => 0,           // is set below array definition: $this->_exportCategories()
            'content'           => array(),     // is set below array definition: $this->_exportContent()
            'created'           => ($article['added'] instanceof DateTime) ? $article['added']->getTimestamp() : -1,
            'description'       => array(),     // translations, exported below array definition: $this->_exportTranslations()
            'einheit'           => (string) $unit['description'],
			'ek'                => 0.0,         // is set below array definition: $this->_exportPrices(),
            'filtergroup_id'    => (int) $article['filterGroupId'],
            'fsk18'             => 0,           // @todo fsk18 article
            'group_permissions' => array(),     // is set below array definition: $this->_exportCustomerGroupPermissions()
            'grundpreis'        => 0,           // is set below array definition: $this->_exportPrices()
            'height'            => (string) $articleMainDetails['height'],
            'images'            => array(),     // is set below array definition: $this->_exportImages()
            'is_brutto'         => 0,           // is set below array definition: $this->_exportPrices()
            'last_modified'     => ($article['changed'] instanceof DateTime) ? $article['changed']->getTimestamp() : -1,
            'l_bestand'         => (int) $articleMainDetails['inStock'],
            'length'            => (string) $articleMainDetails['len'],
            'manufacturers_id'  => (int) $article['supplierId'],
            'mwst'              => (float) $article['tax']['tax'],
            'notification'      => $article['notification'] ? 1 : 0,
            'preisgruppen'      => array(),     // is set below array definition: $this->_exportPrices()
            'products_date_available' => ($articleMainDetails['releaseDate'] instanceof DateTime) ? $articleMainDetails['releaseDate']->format('Y-m-d') : '0000-00-00',
            'products_description' => '',       // done in $this->_exportTranslations()
            'products_digital'  => empty($article['esd']) ? 0 : 1,
            'products_ean'      => (string) $articleMainDetails['ean'],
            'products_id'       => $article['id'],
            'products_keywords' => '',          // done in $this->_exportTranslations()
            'products_pseudoprices' => array(), // is set below array definition: $this->_exportPrices()
            'products_quantity' => (int) $articleMainDetails['inStock'],
            'products_short_description' => '', // done in $this->_exportTranslations()
            'products_sort'     => $articleMainDetails['position'],
            'products_status'   => $article['active'] ? 1 : 0,
            'products_vpe'      => (int) $articleMainDetails['unitId'],
            'products_vpe_status' => empty($articleMainDetails['unitId']) ? 0 : 1,
            'products_vpe_value' => (string) $articleMainDetails['purchaseUnit'],
            'products_vpe_referenzeinheit' => (string) $articleMainDetails['referenceUnit'],
            'products_vpe_staffelung' => (string) $articleMainDetails['purchaseSteps'],
            'products_weight'   => (float) $articleMainDetails['weight'],
            'properties'        => array(),     // is set below array definition: $this->_exportProperties()
            'pseudosales'       => $article['pseudoSales'],
            'shipping_free'     => $articleMainDetails['shippingFree'] ? 1 : 0,
            'shipping_status'   => 1 + (int) $articleMainDetails['shippingTime'],
            'suppliernumber'    => (string) $articleMainDetails['supplierNumber'],
            'topseller'         => $article['highlight'] ? 1 : 0,
            'weight'            => (float) $articleMainDetails['weight'],
            'weight_unit'       => 'kg',
            'width'             => (string) $articleMainDetails['width'],
            'xselling'          => array(),     // is set below array definition: $this->_exportCrossellings()
        );
        
        if(count($article['details']) > 0) {
            // article is variant article
            
            // find ordernumber based on variants ordernumbers, master article needs its own ordernumber in actindo
            $response['art_nr'] = $this->util->findOrdernumberForVariantArticle($article['id']);
        }
        
        $this->_exportCategories($article, $response);
        $this->_exportContent($article, $response);
        $this->_exportCustomerGroupPermissions($article, $response);
        $this->_exportCrossellings($article, $response);
        $this->_exportImages($article, $response);
        $this->_exportPrices($articleMainDetails['id'], $response);
        $this->_exportProperties($article, $response);
        $this->_exportTranslations($article, $response);
        $this->_exportVariants($article, $response);
        
        return array('ok' => 'true', 'products' => array($response));
    }
    
    /**
     * sets the category data in the response array (article export):
     * all assigned categories and a main category
     * fills keys in response array: categories_id, all_categories
     * 
     * @param array $article article array coming from the api call
     * @param array $response response array, assumes that the key 'all_categories' is initialised as array
     */
    protected function _exportCategories(&$article, &$response) {
        $lowestLevel = null;
        foreach($article['categories'] AS $category) {
            $response['all_categories'][] = $category['id'];
            
            if($lowestLevel === null || $category['level'] < $lowestLevel) {
                $lowestLevel = $category['level'];
                $response['categories_id'] = $category['id'];
            }
        }
    }
    
    /**
     * writes article contents into the response array (article export):
     * links, downloads
     * fills keys in response array: content
     * 
     * @param array $article array coming from api call
     * @param array $response response array, assumes that the key 'content' is initialised as array
     */
    protected function _exportContent(&$article, &$response) {
        $languages = $this->util->getLanguages();
        $defaultLanguage = $languages[$this->util->getDefaultLanguage()]['language_code'];
        
        // read attached downloads
        foreach($article['downloads'] AS &$file) {
            $absolutePath = Shopware()->System()->sBasePath . $file['file'];
            $content = file_get_contents($absolutePath);
            
            $response['content'][] = array(
                'language_code' => $defaultLanguage,
                'type'          => 'file',
                'content'       => new Zend_XmlRpc_Value_Base64($content),
                'content_file_name' => $file['file'],
                'content_file_size' => empty($file['size']) ? filesize($absolutePath) : (int) $file['size'],
                'content_file_md5'  => md5($content),
                'content_name'      => $file['name'],
            );
        }
        
        // read attached links
        foreach($article['links'] AS &$link) {
            $response['content'][] = array(
                'language_code'  => $defaultLanguage,
                'type'           => 'link',
                'content'        => $link['link'],
                'content_target' => $link['target'],
                'content_name'   => $link['name'],
            );
        }
    }
    
    /**
     * writes similar and related articles into the response array (article export)
     * fills keys in response array: xselling
     * 
     * @param array $article array coming from api call
     * @param array $response response array, assumes that the key 'xselling' is initialised as array
     */
    protected function _exportCrossellings(&$article, &$response) {
        // related articles ("Zubehör-Artikel")
        $i = 0;
        foreach($article['related'] AS &$related) {
            $ordernumber = $this->util->findOrdernumberByArticleId($related['id']);
            if(empty($ordernumber)) continue;
            $response['xselling'][] = array(
                'art_nr'     => $ordernumber,
                'group'      => 1, // 1 = related = Zubehör-Artikel
                'sort_order' => $i++,
            );
        }
        
        // similar articles ("Ähnliche Artikel")
        $i = 0;
        foreach($article['similar'] AS &$similar) {
            $ordernumber = $this->util->findOrdernumberByArticleId($similar['id']);
            if(empty($ordernumber)) continue;
            $response['xselling'][] = array(
                'art_nr'     => $ordernumber,
                'group'      => 2, // 2 = similar = Ähnliche Artikel
                'sort_order' => $i++,
            );
        }
    }
    
    /**
     * sets customer group permissions
     * unfortunately we get a blacklist from sw but need to write a whitelist to actindo
     * fills keys in response array: group_permission
     * 
     * @param array $article array coming from api call
     * @param array $response response array, assumes that the key 'group_permission' is initialised as array
     */
    protected function _exportCustomerGroupPermissions(&$article, &$response) {
        $blacklist = array();
        foreach($article['customerGroups'] AS $group) {
            $blacklist[] = $group['id'];
        }
        
        $groups = $this->util->getCustomerGroups();
        foreach(array_keys($groups) AS $groupID) {
            if(!in_array($groupID, $blacklist)) {
                $response['group_permission'][] = $groupID;
            }
        }
    }
    
    /**
     * adds images to the response array (article export):
     * fills keys in response array: images
     * 
     * @param array $article array coming from api call
     * @param array $response response array, assumes that the key 'images' in initialised as array
     */
    protected function _exportImages(&$article, &$response) {
        $imageIds = array();
        foreach($article['images'] AS $image) {
            $imageIds[] = $image['id'];
        }
        $variantImages = $this->_getVariantImages($imageIds);
        
        foreach($article['images'] AS $image) {
            if(!empty($variantImages[$image['id']])) {
                // image is only for some variants, don't add to main article
                continue;
            }
            
            $response['images'][] =& $this->_getImageEntry($image);
        }
    }
    
    /**
     * writes all article price information into the reponse array
     * fills keys in response array: grundpreis, is_brutto, preisgruppen, product_pseudoprices
     * 
     * @param int $detailId id from s_articles_details
     * @param array $response  response array, assumes that the keys 'preisgruppen' and 'products_pseudoprices' are initialised as arrays
     *      and that the keys 'is_brutto' and 'grundpreis' are initialised
     */
    protected function _exportPrices($detailId, &$response) {
        $prices = $this->util->getPricesByDetailsID($detailId);
        
        $groupedPrices = array();
        while($price = array_shift($prices)) {
            // fill 'is_brutto' and 'grundpreis' in response array and group prices by customer group
            if($price['pricegroup'] == 'EK' && $price['from'] == 1) {
                $response['is_brutto'] = empty($price['netto']) ? 1 : 0;
                $response['grundpreis'] = (float) $price['price'];
            }
            
            $customerGroupId = (int) $price['customerGroupID'];
            isset($groupedPrices[$customerGroupId]) or $groupedPrices[$customerGroupId] = array();
            $groupedPrices[$customerGroupId][] = $price;
			#bug fix
			$response['ek'] = max((float)$response['ek'], (float) $price['baseprice']); // einkaufspreis
        }
        
        foreach($groupedPrices AS $customerGroupId => $prices) {
            $i = 0;
            foreach($prices AS $price) {
                isset($response['preisgruppen'][$customerGroupId]) or $response['preisgruppen'][$customerGroupId] = array(
                    'is_brutto' => empty($price['netto']) ? 1 : 0,
                );
                
                if($price['from'] == 1) {
                    $response['products_pseudoprices'][$customerGroupId] = (float) $price['pseudoprice'];
                    $response['preisgruppen'][$customerGroupId]['grundpreis'] = (float) $price['price'];
                }
                else {
                    $i++;
                    $response['preisgruppen'][$customerGroupId]['preis_gruppe' . $i] = (float) $price['price'];
                    $response['preisgruppen'][$customerGroupId]['preis_range' . $i]  = (int) $price['from'];
                }
            }
        }
    }
    
    /**
     * writes article properties into the response array:
     * configured article properties (=Zusatzfelder) and filter values
     * fills keys in response array: properties
     * 
     * @param array $article array coming from api call
     * @param array $response response array, assumes that the key 'properties' is initialised as array
     */
    protected function _exportProperties(&$article, &$response) {
        $languages = $this->util->getLanguages();
        $defaultLangaugeId = $this->util->getDefaultLanguage();#
        $defaultLanguage = $languages[$defaultLangaugeId]['language_code'];
        
        // article attributes (default language)
        $propertyFields = array_keys($this->util->getArticleAttributeFields());
        
        foreach($article['mainDetail']['attribute'] AS $fieldID => $value) {
            if(!in_array($fieldID, $propertyFields)) { // not a configured article attribute field, skip
                continue;
            }
            
            $response['properties'][] = array(
                'field_id'      => $fieldID,
                'language_code' => $defaultLanguage,
                'field_value'   => (string) $value,
            );
        }
        
        // article attributes (translations)
        foreach($article['translations'] AS $languageId => $items)  {
            if(!isset($languages[$languageId])) continue;
            $languageCode = $languages[$languageId]['language_code'];
            
            foreach($items AS $fieldID => $value) {
                if(!in_array($fieldID, $propertyFields)) { // not a configured article attribute field, skip
                    continue;
                }
                
                $response['properties'][] = array(
                    'field_id' => $fieldID,
                    'language_code' => $languageCode,
                    'field_value' => (string) $value,
                );
            }
        }
        
        
        // article filter fields
        if(is_array($article['propertyGroup'])) { // article has a property group, extract values
            $groupedValues = array(); // values grouped by option and language
            
            $fieldValueTranslations = $this->util->translate('propertyvalue');
            
            foreach($article['propertyValues'] AS $value) {
                $optionId = 'filter' . $value['optionId'];
                isset($groupedValues[$optionId]) or $groupedValues[$optionId] = array();
                isset($groupedValues[$optionId][$defaultLanguage]) or $groupedValues[$optionId][$defaultLanguage] = array();
                $groupedValues[$optionId][$defaultLanguage][] = $value['value'];
                
                if(isset($fieldValueTranslations[$value['id']])) {
                    foreach($fieldValueTranslations[$value['id']] AS $language => $data) {
                        $groupedValues[$optionId][$language][] = $data['optionValue'];
                    }
                }
            }
            
            foreach($groupedValues AS $optionId => $data) {
                foreach($data AS $language => $values) {
                    $response['properties'][] = array(
                        'field_id' => $optionId,
                        'language_code' => ($language == '-1') ? '' : $language,
                        'field_value' => implode('|', $values),
                    );
                }
            }
        }
    }
    
    /**
     * writes all article translations into the response array (article export)
     * fills keys in response array: description
     * 
     * @staticvar array $map maps array keys from the shopware translation array to keys of actindo translation array
     * @param array $article article array coming from api call
     * @param array $response response array, assumes that the key 'description' is initialised as array
     */
    protected function _exportTranslations(&$article, &$response) {
        static $map = array(
         // 'shopware-key'    => 'actindo-key'
            'name'            => 'products_name',
            'description'     => 'products_short_description',
            'descriptionLong' => 'products_description',
            'keywords'        => 'products_keywords'
        );
        $response['description'][$this->util->getDefaultLanguage()] = array(
            'language_id' => $this->util->getDefaultLanguage(),
            'products_description' => (string) $article['descriptionLong'],
            'products_short_description' => (string) $article['description'],
            'products_keywords' => (string) $article['keywords'],
        );
        foreach($article['translations'] AS $languageID => $translation) {
            $response['description'][$languageID] = array(
                'language_id' => $languageID,
            );
            
            foreach($map AS $key => $target) {
                if(isset($translation[$key])) {
                    $response['description'][$languageID][$target] = $translation[$key];
                }
            }
        }
    }
    
    /**
     * checks if the article being exported is a variant article and if it is; writes all variant info into the response array (article export)
     * 
     * @param array $article article array coming from api call
     * @param array $response response array, assumes that the key 'attributes' is initialised as array
     * @return void
     */
    protected function _exportVariants(&$article, &$response) {
        if(!count($article['details'])) return; // no variants
        
        $languages = $this->util->getLanguages();
        $defaultLanguageCode = $languages[$this->util->getDefaultLanguage()]['language_code'];
        
        $configuratorGroupsTranslations = $this->util->translate('configuratorgroup');
        $configuratorOptionsTranslations = $this->util->translate('configuratoroption');
        
        $repository = Shopware()->Models()->Article();
        $data = $repository->getArticleConfiguratorSetByArticleIdIndexedByIdsQuery($article['id'])
                           ->getArrayResult();
        $configurator =& $data[0]['configuratorSet'];
        
        $response['attributes']['combination_simple']   = array();
        
        // groups are like "size", "color", ... they go into $response['attributes']['names']
        foreach($configurator['groups'] AS $groupID => $group) {
            $translations = isset($configuratorGroupsTranslations[$groupID]) ? $configuratorGroupsTranslations[$groupID] : array();
            $translations[$defaultLanguageCode] = array('name' => $group['name']);
            
            $response['attributes']['names'][$groupID] = array();
            foreach($translations AS $languageCode => $translation) {
                $response['attributes']['names'][$groupID][$languageCode] = $translation['name'];
            }
        }
        
        // options are like "small", "red", ... they go into $response['attributes']['values'] grouped by groupID
        foreach($configurator['options'] AS $option) {
            $translations = isset($configuratorOptionsTranslations[$option['id']]) ? $configuratorOptionsTranslations[$option['id']] : array();
            $translations[$defaultLanguageCode] = array('name' => $option['name']);
            
            $response['attributes']['values'][$option['groupId']][$option['id']] = array();
            foreach($translations AS $languageCode => $translation) {
                $response['attributes']['values'][$option['groupId']][$option['id']][$languageCode] = $translation['name'];
            }
            
            
            $response['attributes']['combination_simple'][$option['groupId']][$option['id']] = array(
                'options_values_price'  => 0,
                'attributes_model'      => 0,
                'options_values_weight' => 0,
                'sortorder'             => 0,
            );
        }
        
        // combination_advanced
        $response['attributes']['combination_advanced'] = array();
        // fetch variant info: ordernumber -> options. not yet exported from the rest api
        $result = Shopware()->Db()->fetchAll('
            SELECT `sad`.`ordernumber`,
                   `sacor`.`option_id`,
                   `saco`.`group_id`
            FROM `s_articles_details` `sad`
            INNER JOIN `s_article_configurator_option_relations` `sacor` ON `sacor`.`article_id` = `sad`.`id`
            INNER JOIN `s_article_configurator_options` `saco` ON `saco`.`id` = `sacor`.`option_id`
            WHERE `sad`.`articleID` = ?
            ORDER BY `sad`.`ordernumber`
        ', $article['id']);
        while($row = array_shift($result)) {
            if(!isset($response['attributes']['combination_advanced'][$row['ordernumber']])) {
                // first row of this variant
                $response['attributes']['combination_advanced'][$row['ordernumber']] = array();
                $combination =& $response['attributes']['combination_advanced'][$row['ordernumber']];
                
                $combination['attribute_name_id'] = array((int) $row['group_id']);
                $combination['attribute_value_id'] = array((int) $row['option_id']);
            }
            else {
                $combination =& $response['attributes']['combination_advanced'][$row['ordernumber']];
                $combination['attribute_name_id'][]  = (int) $row['group_id'];
                $combination['attribute_value_id'][] = (int) $row['option_id'];
            }
        }
        
        // collect all article images and variant information to see what image belongs to what variants
        $imageIds = array();
        foreach($article['images'] AS $image) {
            $imageIds[] = $image['id'];
        }
        $variantImages = $this->_getVariantImages($imageIds);
        
        // combination_advanced is now filled with basic variant infos, fill the rest
        $article['details'][] =& $article['mainDetail']; // the first variant is in mainDetails and not in the details array; add it
        foreach($article['details'] AS &$detail) {
            if(!isset($response['attributes']['combination_advanced'][$detail['number']])) {
                // shouldnt happen, warn someone?
                continue;
            }
            
            $combination =& $response['attributes']['combination_advanced'][$detail['number']];
            $combination = array_merge($combination, array(
                'data' => array(
                    'products_status' => $detail['active'],
                    'products_ean' => (string) $detail['ean'],
                    'products_is_standard' => ($detail['id'] == $article['mainDetail']['id']),
                ),
                'grundpreis' => 0,                  // set below in $this->_exportPriceData()
                'is_brutto' => 0,                   // set below in $this->_exportPriceData()
                'l_bestand' => (float) $detail['inStock'],
                'preisgruppen' => array(),          // filled below in $this->_exportPriceData()
                'products_pseudoprices' => array(), // filled below in $this->_exportPriceData()
                'shop' => array(
                    'images' => array(),            // filled below
                ),
            ));
            
            $this->_exportPrices($detail['id'], $combination);
            
            // export images
            $exportImages = array();
            foreach($variantImages AS $imageID => $variants) { // loop through all article images marked as "variant image"
                if(in_array($detail['id'], $variants)) { // if current variant is in the list for this image
                    $exportImages[] = $imageID;
                }
            }
            foreach($article['images'] AS $image) {
                if(/*empty($variantImages) // "global" image, not associated with any specific variant
                        ||*/ in_array($image['id'], $exportImages))
                {
                    $combination['shop']['images'][] =& $this->_getImageEntry($image);
                }
            }
        }
    }
    
    /**
     * helper function to reduce memory footprint for image export
     * takes one image array of the article export api as parameter and returns the actindo version (to be written into the response array)
     * since variant images may occur several times the resulting image entries are cached and just the reference is returned
     * 
     * @param array $image one image array from api article export
     * @return array reference to the image array
     */
    protected function &_getImageEntry($image) {
        static $i = 0;
        static $cache = array();
        static $typeMap = array(
         // 'extension' => 'mimetype'
            'bmp'  => 'image/bmp',
            'gif'  => 'image/gif',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        );
        $languages = $this->util->getLanguages();
        $languageCode = $languages[$this->util->getDefaultLanguage()]['language_code'];
        $imageDirectory = Shopware()->DocPath('media_image');
        
        if(!isset($cache[$image['id']])) {
            $imagePath = sprintf('%s%s.%s', $imageDirectory, $image['path'], $image['extension']);
            
            $cache[$image['id']] = array(
                'image'       => new Zend_XmlRpc_Value_Base64(file_get_contents($imagePath)),
                'image_size'  => filesize($imagePath),
                'image_type'  => isset($typeMap[$image['extension']]) ? $typeMap[$image['extension']] : 'image/jpeg',
                'image_name'  => sprintf('%s.%s', $image['path'], $image['extension']),
                'image_nr'    => $i++,
                'image_title' => array(
                    $languageCode => $image['description'],
                )
            );
            
            // translations
            $translations = $this->util->translate('articleimage', $image['id']);
            foreach($translations AS $languageCode => $translation) {
                $cache[$image['id']]['image_title'][$languageCode] = $translation['description'];
            }
        }
        return $cache[$image['id']];
    }
    
    /**
     * checks which of the given image ids are "variant images" (only belong to certain variants of the article)
     * 
     * @param array $imageIds array of image ids (from s_articles_img)
     * @return array associative array where the key is the image id and the value is an array of s_articles_details ids (of the variant it belongs to)
     *      if a key is empty or not set it means its a "global" image for this article
     */
    protected function _getVariantImages($imageIds) {
        $variantImages = array();
        if(!empty($imageIds)) {
            $result = Shopware()->Db()->fetchAll(sprintf('
                SELECT `parent_id`, `article_detail_id`
                FROM `s_articles_img`
                WHERE `parent_id` IN (%s)',
                implode(',', $imageIds)));
            while($row = array_shift($result)) {
                isset($variantImages[$row['parent_id']]) or $variantImages[$row['parent_id']] = array();
                $variantImages[$row['parent_id']][] = (int) $row['article_detail_id'];
            }
        }
        return $variantImages;
    }
    
    /**
     * updates the article stock (and possibly article status) of a single article and its variants
     * 
     * @param array $product
     * @return array
     */
    protected function _importStock($product) {
        $articleID = $this->util->findArticleIdByOrdernumber($product['art_nr']); // throws exception if it doesnt find anything
        
        // stock update for variant article
        if(isset($product['attributes'])
                && isset($product['attributes']['combination_advanced'])
                && !empty($product['attributes']['combination_advanced']))
        {
            foreach($product['attributes']['combination_advanced'] AS $ordernumber => $variant) {
                $where = Shopware()->Db()->quoteInto(sprintf('`articleID` = %d AND `ordernumber` = ?', $articleID), $ordernumber);
                
                $update = array(
                    'instock' => (int) $variant['l_bestand'],
                );
                if(isset($variant['data']['products_status'])) {
                    $update['active'] = (int) $variant['data']['products_status'];
                }
                if(isset($variant['data']['shipping_status'])) {
                    $update['shippingtime'] = max(0, (int) $variant['data']['shipping_status'] - 1);
                }
                
                Shopware()->Db()->update('s_articles_details', $update, $where);
            }
        }
        // stock update for regular article
        else {
            $articles = $this->resources->article;
            $articles->update($articleID, array(
                'active'     => (bool) $product['products_status'],
                'mainDetail' => array(
                    'inStock'      => $product['l_bestand'],
                    'shippingTime' => max(0, (int) $product['shipping_status'] - 1),
                ),
            ));
        }
        return array('ok' => true);
    }
    
    /**
     * imports a new product into the shop (using the details from $product)
     * only creates a rudimentary article and then calls updateProduct()
     * 
     * @param array $product the product array coming from actindo
     * @return array
     */
    protected function importProduct(&$product) {
        if(!isset($product['shop']['attributes']) || !is_array($product['shop']['attributes'])) {
            $ordernumber = $product['art_nr'];
        }
        else {
            $ordernumber = key($product['shop']['attributes']['combination_advanced']);
        }
        
        $articles = $this->resources->article;
        try {
            $article = $articles->create(array(
                'name' => $product['art_name'],
                'taxId' => 1, // will be set correctly in updateProduct()
                'notification' => 1,
                'mainDetail' => array(
                    'number' => $ordernumber,
                ),
            ));
            $id = $article->getID();
            unset($article);
            return $this->updateProduct($id, $product);
        } catch(Shopware\Components\Api\Exception\ValidationException $e) {
            throw new Actindo_Components_Exception($e->getViolations()->__toString());
        }
    }
    
    /**
     * updates article with given id with the details provided in $product
     * 
     * @param int $articleID the article id to update
     * @param array $product the product array coming from actindo
     * @return array
     */
    protected function updateProduct($articleID, &$product) {
        $shopArticle =& $product['shop']['art'];
        try {
            if(empty($shopArticle['products_date_available'])) {
                throw new Exception('to set the release date to null instead of now');
            }
            $releaseDate = new DateTime($shopArticle['products_date_available']);
            $releaseDate = $releaseDate->format(DateTime::ISO8601);
        } catch(Exception $e) {
            $releaseDate = null;
        }
        // this array will eventually be put into api method update()
        $update = array(
            'active'          => (bool) $shopArticle['products_status'],
            'availableFrom'   => !in_array($shopArticle['products_date_available'], array('', '0000-00-00 00:00:00')) ? new DateTime($shopArticle['products_date_available']) : null,
            'crossBundleLook' => (int) $shopArticle['bundle'],
            'esd'             => (bool) $shopArticle['products_digital'],
            'filterGroupId'   => (int) $shopArticle['filtergroup_id'],
            'highlight'       => (bool) $shopArticle['topseller'],
            'lastStock'       => (bool) $shopArticle['abverkauf'],
            'pseudoSales'     => (int) $shopArticle['pseudosales'],
            'releaseDate'     => $releaseDate,
            'supplierId'      => (int) $shopArticle['manufacturers_id'],
            #Ticket 91131
            'notification'    => (int) $shopArticle['email_notification'],
            'mainDetail'      => array(
                'ean'            => $product['ean'],
                'height'         => $product['size_h'],
                'inStock'        => (int) $product['l_bestand'],
                'len'            => $product['size_l'],
                'position'       => (int) $shopArticle['products_sort'],
                'releaseDate'    => $releaseDate,
                'shippingFree'   => (bool) $shopArticle['shipping_free'],
                'shippingTime'   => max(0, (int) $shopArticle['shipping_status'] - 1),
                //'stockMin'       => (int) $product['l_minbestand'],
                'supplierNumber' => $shopArticle['suppliernumber'],
                'weight'         => (float)(($shopArticle['products_weight']==(float)0)?$product['weight']:$shopArticle['products_weight']),
                'width'          => $product['size_b'],
            ),
        );
        $this->_updateCategories($product, $update);
        $this->_updateContent($product, $update);
        $this->_updateCrossellings($product, $update);
        $this->_updateCustomerGroupPermissions($product, $update);
        $this->_updateImages($product['shop']['images'], $update, $articleID);
		#return contains data for post processing
        $postData = $this->_updateProperties($product, $update);
        $this->_updateTax($product, $update);
        $this->_updatePrices( // _updateTax() must run before this!
            $product['preisgruppen'],
            $update['mainDetail'],
            $this->util->findTaxRateById($update['taxId']),
            @unserialize($shopArticle['products_pseudoprices']),
            $product['ek']
        );
        $this->_updateTranslations($product, $update);
        $this->_updateVPE($product, $update);
        $this->_updateVariants($product, $update, $articleID);
        
        $articles = $this->resources->article;
        $articles->update($articleID, $update);
		
		#
		if(count($postData)>0){
			foreach($postData as $key=>$value){
				#First Get Entry
				$sql = 'SELECT * FROM s_core_translations WHERE objecttype=\'article\' and objectkey=\''.(int)$articleID.'\' and objectlanguage='.(int)$key.';';
				$result = Shopware()->Db()->fetchRow($sql);
				#Check if result exists
				if(!$result){
					#If Not Create it new
					$data = array();
					foreach($value as $key) $data[$key['field']] = $key['value'];
					$sql = 'INSERT IGNORE INTO s_core_translations (`objecttype`,`objectdata`,`objectkey`,`objectlanguage`) VALUES (\'article\','.Shopware()->Db()->quote(serialize($data)).',\''.(int)$articleID.'\',\''.(int)$key.'\');';
				}else{
					#If exists, unserialize it and update it
					$data = unserialize($result['objectdata']);
					#Data can't bea read, so reinialize it
					if(!$data)
						$data = array();
					foreach($value as $key) $data[$key['field']] = $key['value'];
					$sql = 'UPDATE s_core_translations SET objectdata='.Shopware()->Db()->quote(serialize($data)).' WHERE id='.(int)$result['id'].';';
				}
				#Update DB Data
				Shopware()->Db()->query($sql);
			}
		}
		
        $this->_updateVariantImages($product, $articleID);
        $this->_updateFixVariants($product,$articleID);
        $this->_checkActiveArticles(&$articleID,&$update,&$shopArticle);
        return array('ok' => true, 'success' => 1);
    }
    
    /**
     * writes the article categories into the update array
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put intp api->update()
     */
    protected function _updateCategories(&$product, &$update) {
        is_array($product['shop']['all_categories']) or $product['shop']['all_categories'] = array();
        $categoryIDs = array_merge(array($product['swg']), $product['shop']['all_categories']);
        $categoryIDs = array_unique(array_filter(array_map('intval', $categoryIDs)));
        
        $update['categories'] = array();
        foreach($categoryIDs AS $categoryID) {
            $update['categories'][] = array('id' => $categoryID);
        }
    }
    
    /**
     * writes the article contents into the update array
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put intp api->update()
     */
    protected function _updateContent(&$product, &$update) {
        $update['links'] = $update['downloads'] = array();
        
        foreach($product['shop']['content'] AS &$content) {
            // attach links to article
            if($content['type'] == 'link') {
                $update['links'][] = array(
                    'name'   => $content['content_name'],
                    'link'   => $content['content'],
                    'target' => $content['content_link_target'],
                );
            }
            /*// attach file to article (download)
            elseif($content['type'] == 'file') {
                if(substr($content['content_file_type'], -3) == 'pdf') {
                    $filetype = 'pdf';
                }
                else {
                    $filetype = array_shift(explode('/', $content['content_file_type'], 2));
                }
                
                $path = $this->util->writeMediaFile(
                    $content['content'],
                    $content['content_file_name'],
                    $filetype,
                    $content['content_file_size'],
                    $content['content_file_md5']
                );
                if($path !== false) {
                    $update['downloads'][] = array(
                        'name' => $content['content_name'],
                        'file' => $path,
                        'size' => $content['content_file_size'],
                    );
                }
                else {
                    // do nothing for now, failed file attachment doesn't justify canceling the whole operation
                }
            }*/
        }
    }
    
    /**
     * writes crossellings (similar and related articles)
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put intp api->update()
     */
    protected function _updateCrossellings(&$product, &$update) {
        /*
         * groups:
         * 1 = related = Zubehör-Artikel
         * 2 = similar = Ähnliche Artikel
         */
        $update['related'] = $update['similar'] = array();
        
        foreach($product['shop']['xselling'] AS $item) {
            try {
                $articleID = $this->util->findArticleIdByOrdernumber($item['art_nr']);
                $ordernumber = $this->util->findOrdernumberByArticleId($articleID);
            } catch(Actindo_Components_Exception $e) {
                continue;
            }
            
            switch($item['group']) {
                case 1:
                    $ref =& $update['related'];
                    break;
                case 2:
                    $ref =& $update['similar'];
                    break;
                default:
                    continue;
            }
            $ref[] = array('number' => $ordernumber);
        }
    }
    
    /**
     * writes customer group permissions
     * unfortunately we get a whitelist from actindo but need to write a blacklist into the sw api
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put into api->update()
     */
    protected function _updateCustomerGroupPermissions(&$product, &$update) {
        $update['customerGroups'] = array();
        
        if(!isset($product['shop']['group_permission'])
                || !is_array($product['shop']['group_permission']))
        {
            return; // no data => don't blacklist anything
        }
        
        $result = Shopware()->Db()->fetchAll('SELECT `id` FROM `s_core_customergroups`');
        while($group = array_shift($result)) {
            $groupID = (int) $group['id'];
            if(!in_array($groupID, $product['shop']['group_permission'])) {
                $update['customerGroups'][] = array('id' => $groupID);
            }
        }
    }
    
    /**
     * reads article images from the information array and appends them to the update array
     * 
     * @param array $images array containing the image information
     * @param array $update update array to be put into api()->update()
     * @param int $articleID the sw article id we're working on
     * @throws Actindo_Components_Exception if an image can't be written to the hdd
     */
    protected function _updateImages(&$images, &$update, $articleID) {
        // sw4 api appends images instead of replacing them, delete all images prior to updating
        $this->_deleteAllArticleImages($articleID);
        
        $update['images'] = array();
        
        if(is_array($images)) {
            $languages = $this->util->getLanguages();
            $defaultLanguageId = $this->util->getDefaultLanguage();
            $defaultLanguageCode = $languages[$defaultLanguageId]['language_code'];
            
            //$i = 0;
            foreach(array_keys($images) AS $key) {
                $image =& $images[$key];
				$image['image_name'] = $articleID.'-'.$i.'.'.$image['image_name'];
				$i++;
                $path = $this->util->writeTemporaryFile($image['image_name'], $image['image']);
                
                $description = '';
                foreach($image['image_title'] AS $languageCode => $translation) {
                    if($languageCode == $defaultLanguageCode) {
                        $description = $translation;
                    }
                    else {
                        // @todo save translation, need image id for that first
                    }
                }
                
                $update['images'][] = array(
                    'link'        => sprintf('file://%s', $path),
                    'description' => $description,
                    //'main'        => ($i++ == 0),
                );
                unset($image);
            }
        }
    }
    
    /**
     * sets prices and priceranges for a detail in the update array
     * 
     * @param array $prices price array from actindo
     * @param array $target target array to write the prices into, the key 'prices' will be set in there
     * @param float $taxRate the taxrate, will be subtracted if $prices contains gross prices
     * @param array $pseudoPrices
     * @param float $basePrice
     */
    protected function _updatePrices($prices, &$target, $taxRate, $pseudoPrices = array(), $basePrice = 0) {
        
        $taxRate = (float) $taxRate; // @todo check for false
        $price_old = $target['prices'];
        $target['prices'] = array();
        $basePrice = (float) $basePrice;
        foreach($prices AS $groupID => $info) {
            $ranges = array(); // key = from, val = price
            
            if(false === ($group = $this->util->findCustomerGroupById($groupID))) {
                continue;
            }
            
            if(is_array($pseudoPrices) && isset($pseudoPrices[$groupID])) {
                $pseudoPrice = $pseudoPrices[$groupID];
            }
            else {
                $pseudoPrice = null;
            }
            // ranges
            foreach(array_keys($info) AS $key) {
                if(0 !== strpos($key, 'preis_gruppe')) {
                    continue;
                }
                
                $i = (int) substr($key, 12);
                if((float) $info['preis_range'.$i] > (float)0 && (float) $info['preis_gruppe'.$i] > (float)0) {
                    if($this->util->findCustomerGroupTaxById($groupID)) { // import net price
                        $price = $this->util->calculateNetPrice($info['preis_gruppe'.$i], $taxRate, $info['is_brutto']);
                    }
                    else { // import gross price
                        $price = $this->util->calculateGrossPrice($info['preis_gruppe'.$i], $taxRate, $info['is_brutto']);
                        
                    }
                    $from = (int) $info['preis_range'.$i];
                    $ranges[$from] = array(
                        'from'  => $from,
                        'price' => $price,
                    );
                }
            }
            // baseprice
            if($this->util->findCustomerGroupTaxById($groupID)) { // import net price
                $price = $this->util->calculateNetPrice($info['grundpreis'], $taxRate, $info['is_brutto']);
            }
            else { // import gross price
                $price = $this->util->calculateGrossPrice($info['grundpreis'], $taxRate, $info['is_brutto']);
                
            }
            $ranges[1] = array(
                'from'  => 1,
                'price' => $price,
            );
            
            ksort($ranges, SORT_NUMERIC);
            $ranges = array_values($ranges);
            for($i = 0, $c = count($ranges); $i < $c; $i++) {
                if($pseudoPrice==null && $price_old[$i]['pseudoPrice']!=null && is_float($price_old[$i]['pseudoPrice']) && $price_old[$i]['pseudoPrice']>0){
                    $pseudoPrice = $price_old[$i]['pseudoPrice'];
                }
                $price = array(
                    'customerGroupKey' => $group['groupkey'],
                    'from'  => $ranges[$i]['from'],
                    'price' => $ranges[$i]['price'],
                    'pseudoPrice' => $pseudoPrice,
                    'basePrice' => $basePrice,
                );
                if($i < $c-1) {
                    $price['to'] = $ranges[$i+1]['from'] - 1;
                }
                $target['prices'][] = $price;
            }
        }
    }
    
    /**
     * writes filter fields and property fields into the update array (only for the main details)
     * 
     * @see Actindo_Components_Service_Product::_updateVariantDetailProperties()
     * @param array $product product array coming from actindo
     * @param array $update update array to be put into api->update()
	 * @return array Array containing translation updates
     */
    protected function _updateProperties(&$product, &$update) {
        $languages = $this->util->getLanguages();
        $defaultLangaugeId = $this->util->getDefaultLanguage();
        $defaultLanguage = $languages[$defaultLangaugeId]['language_code'];
        
        $filterFields = $this->util->getArticleFilterFields();
        $attributeFields = $this->util->getArticleAttributeFields();
        
        $update['filterGroupId'] = (int) $product['shop']['art']['filtergroup_id'];
        $update['propertyGroup'] = array();
        $update['propertyValues'] = array();
        
        $update['mainDetail']['attribute'] = array();
        if($update['filterGroupId'] > 0) {
            $update['propertyGroup'] = array('id' => $update['filterGroupId']);
        } else {
            $update['propertyGroup'] = null;
        }
		$datablock = array();
        foreach($product['shop']['properties'] AS &$property) {
            // properties (filterable stuff)
            if(isset($filterFields[$property['field_id']])) {
                if($update['filterGroupId'] > 0) {
					if($property['language_code']!=$defaultLanguage){
						$id = (int)str_replace('filter','',$property['field_id']);
						$sql = 'SELECT id FROM s_filter_values WHERE value=\''.$value.'\';';
						$result = Shopware()->Db()->fetchOne($sql);
						$sql = 'SELECT * FROM s_core_translations WHERE objecttype=\'propertyvalue\' and objectkey='.(int)$result.' and objectlanguage='.(int)$property['language_id'].';';
						$work = Shopware()->Db()->fetchRow($sql);
						if($work!==false){
							$data = unserialize($work['objectdata']);
							if(!$data) $data = array('optionValue'=>'');
							if($data['optionValue']!=$property['field_value']){
								$data['optionValue'] = $property['field_value'];
								$sql = 'UPDATE s_core_translations set objectdata='.Shopware()->Db()->quote(serialize($data)).' WHERE id='.(int)$work['id'].';';
								Shopware()->Db()->query($sql);
							}
						}else{
							$sql = 'INSERT INTO s_core_translations 
								(`id`, `objecttype`, `objectdata`, `objectkey`, `objectlanguage`) 
								VALUES
								(\'\',\'propertyvalue\','.Shopware()->Db()->quote(serialize(array('optionValue'=>$property['field_value']))).','.(int)$result.','.(int)$property['language_id'].');';
							Shopware()->Db()->query($sql);
						}
					}else{
						$option =& $filterFields[$property['field_id']];
						$explodedValues = array_filter(explode('|', $property['field_value']));
						foreach($explodedValues AS $value) {
							$update['propertyValues'][] = array(
								'option' => array('name' => $option['field_name']),
								'value'  => $value,
							);
						}
					}
                }
            }
            // attributes (attrX)
			#Skip Translation for the Beginning.
            elseif(isset($attributeFields[$property['field_id']])) {
				if($property['language_code'] != $defaultLanguage){
					$datablock[$property['language_id']][] = array(
						'value'=>$property['field_value'],
						'field'=>$property['field_id'],
					);
				}else
					$update['mainDetail']['attribute'][$property['field_id']] = $property['field_value'];
            }
        }
		return $datablock;
    }
    /**
     * sets the correct tax class
     * 
     * @todo read s_core_tax for available tax rates
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put intp api->update()
     */
    protected function _updateTax(&$product, &$update) {
        // sw4 doesnt allow empty taxes, so fall back to 19% if no fitting tax key can be found
        switch($product['mwst_stkey']) {
            case '2':
                $update['taxId'] = 4;
                break;
            case '3':
            default:
                $update['taxId'] = 1;
        }
    }
    
    /**
     * writes name, descriptions and keywords (in all languages, also the main language) into the update array
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put intp api->update()
     */
    protected function _updateTranslations(&$product, &$update) {
        $defaultLanguageID = $this->util->getDefaultLanguage();
        
        foreach($product['shop']['desc'] AS $translation) {
            $languageID = (int) $translation['language_id'];
            $name = empty($translation['products_name']) ? $product['art_name'] : $translation['products_name'];
            
            if($languageID == $defaultLanguageID) {
                // default language, write into main update array
                $ref =& $update;
            }
            else {
                // any other translation, write into translations key
                if(false === ($shopID = $this->util->findShopIdByLocale($translation['language_code']))) {
                    continue;
                }
                isset($update['translations']) or $update['translations'] = array();
                $update['translations'][$languageID] = array('shopId' => $shopID);
                $ref =& $update['translations'][$languageID];
            }
            
            $ref['name'] = $name;
            $ref['keywords'] = $translation['products_keywords'];
            $ref['description'] = $translation['products_short_description'];
            $ref['descriptionLong'] = $translation['products_description'];
        }
    }
    
    /**
     * takes care of writing variants into the update aray
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put into api->update()
     * @param int $articleID article id being updated
     */
    protected function _updateVariants(&$product, &$update, $articleID) {
        if(!isset($product['shop']['attributes'])) {
            // no variant info given, do nothing
            return;
        }
        

        $configurator = $this->_getVariantConfiguratorSet($articleID);
        
        if(!is_array($product['shop']['attributes'])) {
            // regular article, has no variants
            if(is_array($configurator)) {
                // @todo article has variants in sw, convert to regular article
            }
            return;
        }
        
        // article has variants
        // check if the "configuratorSet" is the same (same groups and options (in actindo lingo: same names and values))
        $this->_updateVariantConfiguratorSet($articleID, $product, $configurator);
        $this->_updateVariantDetails($product, $update, $articleID);
    }
    
    /**
     * reads the sw configurator set configured for a certain article
     * @param int $articleID article id to look for
     * @return array the configurator set
     */
    protected function _getVariantConfiguratorSet($articleID) {
        $repository = Shopware()->Models()->Article();
        $data = $repository->getArticleConfiguratorSetByArticleIdIndexedByIdsQuery($articleID)->getArrayResult();
        return $data[0]['configuratorSet'];
    }
    
    /**
     * checks if the groups and options (actindo: names and values) of the sw-configurator match the info from $product (=actindo data)
     * if they do; nothing is done.
     * if they don't; an updated configuratorSet is written into the update array
     * 
     * @param int $articleID the article id to update
     * @param array $product product array coming from actindo
     * @param array $configurator configuratorSet info from ArticleRepository->getArticleConfiguratorSetByArticleIdIndexedByIdsQuery()
     */
    protected function _updateVariantConfiguratorSet($articleID, &$product, &$configurator) {
        // at this point we definitely have a variant article coming from actindo, $configurator may be null if its still a regular article in sw
        
        $languages = $this->util->getLanguages();
        $defaultLanguageId = $this->util->getDefaultLanguage();
        $defaultLanguage = $languages[$defaultLanguageId]['language_code'];

        $actindoGroups = $actindoOptions = array();
        foreach($product['shop']['attributes']['names'] AS $id => $group) { // groups
            $actindoGroups[(int) $id] = $group[$defaultLanguage];
        }
        foreach($product['shop']['attributes']['values'] AS $groupedOptions) { // options
            foreach($groupedOptions AS $option) {
                $actindoOptions[] = $option[$defaultLanguage];
            }
        }
        
        if(!is_array($configurator)) { // not a variant in sw yet
            $configuratorSet = array(
                'groups' => array(),
            );
        }
        else { // already have a configurator in sw, compare if it matches the details from $product
            $shopwareGroups = $shopwareOptions = array();
            foreach($configurator['groups'] AS $group) {
                $shopwareGroups[] = $group['name'];
            }
            foreach($configurator['options'] AS $option) {
                $shopwareOptions[] = $option['name'];
            }
            
            $addedGroupDiff    = array_diff($actindoGroups, $shopwareGroups);
            $addedOptionDiff   = array_diff($actindoOptions, $shopwareOptions);
            $removedGroupDiff  = array_diff($shopwareGroups, $actindoGroups);
            $removedOptionDiff = array_diff($shopwareOptions, $actindoOptions);
            if(
                 ((count($addedGroupDiff) + count($addedOptionDiff)) > 0) // new groups or options added by actindo
              || ((count($removedGroupDiff) + count($removedOptionDiff)) > 0) // groups or options removed by actindo
            )
            {
                $configuratorSet = array(
                    'name' => $configurator['name'],
                    'groups' => array(),
                );
            }
        }
        
        if(isset($configuratorSet)) {
            if(is_array($configurator)) {
                // delete previous relations if a configurator existed (to prevent duplicate key errors)
                $where = Shopware()->Db()->quoteInto('`set_id` = ?', $configurator['id']);
                Shopware()->Db()->delete('s_article_configurator_set_option_relations', $where);
                Shopware()->Db()->delete('s_article_configurator_set_group_relations', $where);
            }
            
            $groups = $actindoGroups;
            foreach($groups AS &$group) {
                $group = array(
                    'name'    => $group,
                    'options' => array(),
                );
            }
            // groups and options that don't exist in sw yet are created automatically :)
            foreach($product['shop']['attributes']['values'] AS $groupID => $groupedOptions) {
                $groupID = (int) $groupID;
                foreach($groupedOptions AS $option) {
                    $groups[$groupID]['options'][] = array('name' => $option[$defaultLanguage]);
                }
            }
            
            // update directly, not via global $update array (needs to be set in db to create the variants later on in this request):
            $configuratorSet['groups'] = $groups;
            $this->resources->article->update($articleID, array(
                'configuratorSet' => $configuratorSet,
            ));
        }
    }
    
    /**
     * prepares the db for variant article details; since the api doesn't allow to match variants by group/option unfortunately we need to do this manually
     * - looks up existing details and matches them to actindo-variants using their configured groups and options
     * - creates missing variant details
     * - removes obsolete variant details
     * - writes variant infos into the update array
     * 
     * Some parts operate directly on the db!
     * 
     * @param array $product product array coming from actindo
     * @param array $update update array to be put into api->update(), the key 'variants' will be set & populated there
     * @param int $articleID the article id to operate on
     */
    protected function _updateVariantDetails(&$product, &$update, $articleID) {
        // should always be set & an array here: $product['shop']['attributes']['combination_advanced'] 
        
        $languages = $this->util->getLanguages();
        $defaultLanguageId = $this->util->getDefaultLanguage();
        $defaultLanguage = $languages[$defaultLanguageId]['language_code'];
        
        $mapping = $this->buildVariantConfiguratorMapping($articleID, $product['shop']['attributes']['names'], $product['shop']['attributes']['values']);
        
        // fetch already existing variants detail ids; keyed by detail id, value contains ordernumber and array of group and option ids
        $result = Shopware()->Db()->fetchAll('
            SELECT `sad`.`id`, `sad`.`ordernumber`, `sacor`.`option_id`, `saco`.`group_id`
            FROM `s_articles_details` `sad`
            INNER JOIN `s_article_configurator_option_relations` `sacor` ON `sacor`.`article_id` = `sad`.`id`
            INNER JOIN `s_article_configurator_options` `saco` ON `saco`.`id` = `sacor`.`option_id`
            INNER JOIN `s_article_configurator_groups` `sacg` ON `sacg`.`id` = `saco`.`group_id`
            WHERE `sad`.`articleID` = ?
        ', array($articleID));
        $variants = array();
        while($variant = array_shift($result)) {
            $id = (int) $variant['id'];
            isset($variants[$id]) or $variants[$id] = array(
                'id'      => $id,
                'number'  => $variant['ordernumber'],
                'groups'  => array(),
                'options' => array(),
            );
            
            $variants[$id]['groups'][]  = (int) $variant['group_id'];
            $variants[$id]['options'][] = (int) $variant['option_id'];
        }
        
        // compare existing variants with the ones from actindos update array and match entries (compare by configured options and values)
        $matchingVariants = array();        
        foreach($variants AS $id => $variant) {
            foreach($product['shop']['attributes']['combination_advanced'] AS $ordernumber => &$combination) {
                if(isset($matchingVariants[$ordernumber])) {
                    continue; // already matched this combination
                }
                $compare = $this->util->arrayValuesReplace($combination['attribute_name_id'], $mapping['groups']); // replace actindo group ids with sw group ids
                if($compare === false || !$this->util->arrayValuesEqual($compare, $variant['groups'])) {
                    continue; // different groups
                }
                $compare = $this->util->arrayValuesReplace($combination['attribute_value_id'], $mapping['options']); // replace actindo option ids with sw option ids
                if($compare === false || !$this->util->arrayValuesEqual($compare, $variant['options'])) {
                    continue; // different options
                }
                // combination matches this existing variant
                $matchingVariants[$ordernumber] =& $variants[$id];
                // @todo update variant with product info
            }
        }
        
        $activeDetailIDs = array(); // all s_article_details that are not in here are removed from db after this loop
        foreach($product['shop']['attributes']['combination_advanced'] AS $ordernumber => &$variant) {
            if(!isset($matchingVariants[$ordernumber])) {
                // @todo new combination that doesn't exist in sw yet, create
                $variantID = $this->_createVariantDetail($articleID, $ordernumber, $variant, $mapping);
            }
            else {
                $variantID = $matchingVariants[$ordernumber]['id'];
            }
            
            $activeDetailIDs[] = $variantID;
            
            $optionValues = array();
            foreach($variant['attribute_value_id'] AS $optionID) {
                foreach($product['shop']['attributes']['values'] AS $groups) {
                    if(isset($groups[$optionID])) {
                        $optionValues[] = $groups[$optionID][$defaultLanguage];
                        continue 2;
                    }
                }
            }
            
            $data = array_merge($update['mainDetail'], array(
                'id'           => $variantID,
                
                'active'       => (int) $variant['data']['products_status'],
                'additionalText' => implode(' / ', $optionValues),
                'ean'          => $variant['shop']['art']['products_ean'],
                'inStock'      => (int) $variant['l_bestand'],
                'isMain'       => ($i++ == 0),
                'number'       => $ordernumber,
                'shippingTime' => max(0, (int) $variant['data']['shipping_status'] - 1),
                'weight'       => $variant['shop']['art']['products_weight'],
                'attribute'    => array(),
            ));
            $this->_updatePrices($variant['preisgruppen'], $data, $this->util->findTaxRateById($update['taxId']));
            $this->_updateVariantDetailProperties($variant['shop']['properties'], $data);
            
            $update['variants'][] = $data;
        }
        if(count($activeDetailIDs)) {
            // remove orphaned variants: variants that exist in sw but need to be removed
            $where = implode(',', array_filter(array_map('intval', $activeDetailIDs)));
            Shopware()->Db()->query(sprintf('
                DELETE `sad`.*, `saa`.*, `sacor`.*
                FROM `s_articles_details` `sad`
                LEFT JOIN `s_articles_attributes` `saa` ON `saa`.`articleID` = `sad`.`articleID` AND `saa`.`articledetailsID` = `sad`.`id`
                LEFT JOIN `s_article_configurator_option_relations` `sacor` ON `sacor`.`article_id` = `sad`.`id`
                WHERE `sad`.`articleID` = ?
                  AND `sad`.`id` NOT IN(%s)
            ', $where), array($articleID));
        }
        
        // update s_articles_attributes: write our "master ordernumber"
        $this->util->setOrdernumberForVariantArticle($articleID, $product['art_nr']);
    }
    
    /**
     * writes properties of variant articles into the updated array IF they're configured as "variantable" in sw.
     * While the info is written to the correct places in the db; at this time the sw backend appears to be buggy in handling those properties:
     * It always shows the main details properties for all variants even if they have different values set.
     * 
     * @param array $properties
     * @param array $data a sub-array of the update array to be put into api->update(): $update['variants'][]
     */
    protected function _updateVariantDetailProperties(&$properties, &$data) {
        $attributeFields = $this->util->getArticleAttributeFields();
        
        foreach(array_keys($attributeFields) AS $field) {
            $data['attribute'][$field] = null;
        }
        
        $data['attribute'] = array();
        foreach($properties AS &$property) {
            if(isset($attributeFields[$property['field_id']]) && $attributeFields[$property['field_id']]['variantable']) {
                $data['attribute'][$property['field_id']] = $property['field_value'];
            }
        }
    }
    
    /**
     * creates mapping rules for the article images that only belong to certain variants.
     * this is called after the final api->update(), so the import is finished and only the image mappings are pending
     * 
     * @param array $product product array coming from actindo
     * @param int $articleID the article id to operate on
     */
    public function _updateVariantImages(&$product, $articleID) {
        if(!isset($product['shop']['attributes'])
                || !isset($product['shop']['attributes']['combination_advanced'])
                || !is_array($product['shop']['attributes']['combination_advanced']))
        {
            // no variants given
            return;
        }
        
        $languages = $this->util->getLanguages();
        $defaultLanguageId = $this->util->getDefaultLanguage();
        $defaultLanguageCode = $languages[$defaultLanguageId]['language_code'];
        
        // go through all variants, collect their images and figure out duplicates
        $swImages = array();
        $imageNames = array();
        $imageToVariants = array();
        foreach($product['shop']['attributes']['combination_advanced'] AS $ordernumber => &$variant) {
            foreach($variant['shop']['images'] AS &$image) {
                $key = md5(sprintf('%s // %s', $image['image_name'], round($image['image_size'], 2))); // assume images with the same name and size are the same image
                if(!isset($swImages[$key])) {
                    // img not yet queued
                    $path = $this->util->writeTemporaryFile($image['image_name'], $image['image']);
                    $swImages[$key] = array(
                        'link'         => sprintf('file://%s', $path),
                        'description'  => (isset($image['image_title'][$defaultLanguageCode])) ? $image['image_title'][$defaultLanguageCode] : '',
                    );
                    $imageNames[$key] = $image['image_name'];
                }
                isset($imageToVariants[$key]) or $imageToVariants[$key] = array();
                $imageToVariants[$key][] = $ordernumber;
            }
        }
        
        if(empty($swImages)) {
            return;
        }
        
        // store already added images, those are "global" images for all variants, theyre excluded from further processing
        $mainArticleImages = array_map('intval', Shopware()->Db()->fetchCol('
            SELECT `id`
            FROM `s_articles_img` `sai`
            WHERE `sai`.`articleID` = ?
        ', array($articleID)));
        
        // write variant images into the article
        $this->resources->article->update($articleID, array(
            'images' => array_values($swImages),
        ));
        
        // select variant images to match against
        $whereClause = '';
        if(!empty($mainArticleImages)) { // don't try and compare those
            $whereClause = sprintf('AND `sai`.`id` NOT IN(%s)', implode(',', $mainArticleImages));
        }
        $variantImages = Shopware()->Db()->fetchAll(sprintf('
            SELECT `id`, `img`, `main`, `position`, `extension`
            FROM `s_articles_img` `sai`
            WHERE `sai`.`articleID` = ?
            %s
        ', $whereClause), array($articleID));
        
        $mapping = $this->buildVariantConfiguratorMapping($articleID, $product['shop']['attributes']['names'], $product['shop']['attributes']['values']);
        
        foreach($variantImages AS $image) {
            foreach($imageNames AS $key => $name) {
                if(0 === stripos($name, $image['img'])) { // @todo maybe use md5 to compare images, relying just on name here
                    // found a matching img, go through all variants that have this image and add proper relations
                    foreach($imageToVariants[$key] AS $ordernumber) {
                        $variant =& $product['shop']['attributes']['combination_advanced'][$ordernumber];
                        $inserts = array();
                        foreach($variant['attribute_value_id'] AS $option) {
                            if(isset($mapping['options'][$option])) {
                                $inserts[] = $mapping['options'][$option];
                            }
                            else {
                                continue 2; // unknown configuration, proceed with next variant
                            }
                        }
                        Shopware()->Db()->insert('s_article_img_mappings', array('image_id' => $image['id']));
                        $mappingID = Shopware()->Db()->lastInsertId();
                        foreach($inserts AS $insert) {
                            Shopware()->Db()->insert('s_article_img_mapping_rules', array(
                                'mapping_id' => $mappingID,
                                'option_id' => $insert,
                            ));
                        }
                        Shopware()->Db()->insert('s_articles_img', array(
                            'articleID' => null,
                            'img'       => null,
                            'main'      => $image['main'],
                            'position'  => $image['position'],
                            'extension' => $image['extension'],
                            'parent_id' => $image['id'],
                            'article_detail_id' => $this->util->findDetailIdByOrdernumber($ordernumber), // @todo fixme
                        ));
                    }
                    // skip to next sw-image
                    continue 2;
                }
            }
        }
    }
    
    /**
     * creates a new detail (=variant) for a given articleID and sets configurator options from $details.
     * if a detail with the given ordernumber exists itll just get the correct configurator options attached.
     * 
     * @param int $articleID existing sw article id to create the variant for
     * @param string $ordernumber the ordernumber to set for the new variant
     * @param array $details from actindos update array: one entry of update[shop][attributes][combination_advanced]
     * @param array $mapping map from actindo group and option ids to sw group and option ids, @see buildVariantConfiguratorMapping()
     * @return int the article detail id (s_articles_details.id) of the newly created variant
     */
    protected function _createVariantDetail($articleID, $ordernumber, &$details, $mapping) {
        // s_articles_details
        try {
            Shopware()->Db()->insert('s_articles_details', array(
                'articleID'   => $articleID,
                'ordernumber' => $ordernumber,
                'kind'        => 2,
            ));
            $detailID = Shopware()->Db()->lastInsertId();
            
            // s_articles_attributes
            Shopware()->Db()->insert('s_articles_attributes', array(
                'articleID'        => $articleID,
                'articledetailsID' => $detailID,
            ));            
        } catch(Zend_Db_Statement_Exception $e) {
            // a detail with the ordernumber we tried to create already exists; but with wrong configuration options (first query failed)
            // fix configurator
            $detailID = Shopware()->Db()->fetchOne('SELECT `id` FROM `s_articles_details` WHERE `ordernumber` = ?', array($ordernumber));
            #scan for fragments of variant articles
            $this->_scanForFragments($articleID,$detailID);
            // leave s_articles_attributes intact
            
            // clear s_article_configurator_option_relations, will be refilled below
            Shopware()->Db()->delete('s_article_configurator_option_relations', '`article_id` = ' . $detailID);
        }
        
        // s_article_configurator_option_relations
		#ticket: #88084 added an additional try catch block to prevent blocking upload of variants (article id already exists etc.)
			foreach($details['attribute_value_id'] AS $option) {
				try{
					Shopware()->Db()->insert('s_article_configurator_option_relations', array(
						'article_id' => $detailID,
						'option_id'  => $mapping['options'][$option],
					));
				}catch(Exception $e){
				
				}
			}
        return $detailID;
    }
    
    /**
     * write vpe (=verpackungseinheiten) info into the update array
     * 
     * @param array $product product array coming from actindo
     * @param type $update update array to be put into api->update()
     */
    protected function _updateVPE(&$product, &$update) {
        $mainDetail =& $update['mainDetail'];
        $shopArticle =& $product['shop']['art'];
        
        if(!empty($product['shop']['art']['products_vpe_status'])) {
            //$mainDetail['minPurchase'] = isset($shopArticle['products_vpe_staffelung']) ? (int) $shopArticle['products_vpe_staffelung'] : (float) $shopArticle['products_vpe_value'];
            $mainDetail['purchaseSteps'] = isset($shopArticle['products_vpe_staffelung']) ? (int) $shopArticle['products_vpe_staffelung'] : (float) $shopArticle['products_vpe_value'];
            $mainDetail['purchaseUnit'] = (float) $shopArticle['products_vpe_value'];
            $mainDetail['referenceUnit'] = isset($shopArticle['products_vpe_referenzeinheit']) ? (int) $shopArticle['products_vpe_referenzeinheit'] : (float) $shopArticle['products_vpe_value'];
            $mainDetail['unitId'] = (int) $shopArticle['products_vpe'];
            $mainDetail['packUnit'] = $product['einheit'];
        }
        else {
            //$mainDetail['minPurchase'] = null;
            $mainDetail['packUnit'] = '';
            $mainDetail['purchaseSteps'] = null;
            $mainDetail['purchaseUnit'] = null;
            $mainDetail['referenceUnit'] = null;
            $mainDetail['unitId'] = null;
        }
    }
    
    /**
     * deletes all images linked to an article
     * 
     * @param int $articleID 
     * @return void
     */
    private function _deleteAllArticleImages($articleID) {
        $legacyApi = Shopware()->Api()->Import();
        $legacyApi->sDeleteArticleImages(array('articleID' => $articleID));
        
        // api call sometimes doesn't kill all images
        $result = Shopware()->Db()->fetchAll('
            SELECT `sai`.`id`, `sm`.`path`
            FROM `s_articles_img` `sai`
            LEFT JOIN `s_media` `sm` ON `sm`.`id` = `sai`.`media_id`
            WHERE `sai`.`articleID` = ?
        ', array($articleID));
        if(empty($result)) {
            return;
        }
        
        $images = array();
        while($image = array_shift($result)) {
            $id = (int) $image['id'];
            $images[$id] = $image['path'];
        }
        
        $imageIds = array_map('intval', array_keys($images));
        
        // s_article_img_mappings && s_article_img_mapping_rules
        Shopware()->Db()->query(sprintf('
            DELETE `saim`.*, `saimr`.*
            FROM `s_article_img_mappings` `saim`
            LEFT JOIN `s_article_img_mapping_rules` `saimr` ON `saimr`.`mapping_id` = `saim`.`id`
            WHERE `saim`.`image_id` IN(%s)
        ', implode(',', $imageIds)));
        
        // s_articles_img && s_articles_img_attributes && s_media
        Shopware()->Db()->query(sprintf('
            DELETE `sai`.*, `saia`.*, `sm`.*
            FROM `s_articles_img` `sai`
            LEFT JOIN `s_articles_img_attributes` `saia` ON `saia`.`imageID` = `sai`.`id`
            LEFT JOIN `s_media` `sm` ON `sm`.`id` = `sai`.`media_id`
            WHERE `sai`.`id` IN(%s)
        ', implode(',', $imageIds)));
        
        // childs from s_articles_img
        Shopware()->Db()->query(sprintf('DELETE FROM `s_articles_img` WHERE `parent_id` IN(%s)', implode(',', $imageIds)));
        
        // physically remove images
        foreach($images AS $path) {
            if(false !== ($target = realpath(Shopware()->System()->sBasePath . '/' . $path))) {
                @unlink($target);
            }
        }
    }
    
    /**
     * translates actindo group and option ids (=names and values) to sw group and option ids
     * also saves a map of actindo option id => sw group id.
     * results are returned as associative array with the keys groups, options and optionToGroup
     * 
     * @param int $articleID used to get the configurator values
     * @param array $groups from actindo update array: attributes['names']
     * @param array $options from actindo update array: attributes['values']
     * @return array id mapping array
     */
    private function buildVariantConfiguratorMapping($articleID, $groups, $options) {
        $languages = $this->util->getLanguages();
        $defaultLanguageId = $this->util->getDefaultLanguage();
        $defaultLanguage = $languages[$defaultLanguageId]['language_code'];
        
        $configurator = $this->_getVariantConfiguratorSet($articleID);
        
        $map = array(
            'groups'  => array(),
            'options' => array(),
            'optionToGroup' => array(),
        );
        
        foreach($groups AS $id => $translations) {
            foreach($configurator['groups'] AS $group) {
                if($group['name'] == $translations[$defaultLanguage]) {
                    $map['groups'][$id] = $group['id'];
                    continue 2;
                }
            }
        }
        foreach($options AS $groupID => $acOptions) {
            foreach($acOptions AS $id => $translations) {
                foreach($configurator['options'] AS $option) {
                    if($option['name'] == $translations[$defaultLanguage]) {
                        $map['options'][$id] = $option['id'];
                        $map['optionToGroup'][$id] = $map['groups'][$groupID];
                        continue 2;
                    }
                }
            }
        }
        
        return $map;
    }
	
    /**
     * this protected method scans the database for remaining variant id's, if they where deleted inside of Actindo.
	 * It delete's each variant step by step and leaves only the first variant alive.
	 * After this it rewrites the remaining variants ordernumber and set's it to the main Articles Ordnernumber
	 * After this the main Article is stripped of it's configuration set id (replaced by NULL)
     * @param array $product contains the product container
     * @param int $articleID used to get the configurator values
     * @return void
     */
	protected function _updateFixVariants(&$product,&$articleID){
		if(!is_array($product['shop']['attributes'])){
			$sql = 'SELECT * FROM s_articles_attributes WHERE articleID = '.(int)$articleID.';';
			$results = Shopware()->Db()->fetchAll($sql);
			$res = \Shopware\Components\Api\Manager::getResource('Variant');
			if(is_array($results) && count($results)>0){
				if(count($results)>1){
					for($i=1;$i<count($results);$i++){
						$res->delete($results[$i]['articledetailsID']);
					}
				}
				$core = 'SELECT actindo_masternumber FROM s_articles_attributes WHERE articleID='.(int)$articleID.';';
				$r = Shopware()->Db()->fetchRow($core);
				#ticket #93431
				if($r && $r["actindo_masternumber"]!==null){
					$sql = 'UPDATE s_articles_details SET ordernumber=\''.$r['actindo_masternumber'].'\' WHERE articleID=\''.(int)$articleID.'\';';
					Shopware()->Db()->query($sql);
					$sql = 'UPDATE s_articles set configurator_set_id=NULL WHERE id=\''.(int)$articleID.'\';';
					Shopware()->Db()->query($sql);
				}
			}
		}
	}
    /**
     * Scan for Variant Fragments and sets them to the correct article id
     * @param int $articleId article id
     * @param int $variantid varianten id
     */
    protected function _scanForFragments($articleId,$variantid){
        $sql = 'SELECT articleID from s_articles_details where id= '.(int)$variantid.'; ';
        try{
            $result = Shopware()->Db()->fetchOne($sql);
            if((int)$result !== (int)$articleId){
                #found article fragment
                $sql = 'SELECT * FROM s_articles WHERE id='.(int)$result.';';
                if(count(Shopware()->Db()->fetchAll($sql))<1){
                    $sql = 'UPDATE s_articles_attributes set articleID='.(int)$articleId.' WHERE articleID='.(int)$result.' and articledetailsID='.(int)$variantid.';';
                    try{
                        Shopware()->Db()->query($sql);
                    }catch(Exception $e){}
                    $sql = 'UPDATE s_articles_details set articleID='.(int)$articleId.' WHERE articleID='.(int)$result.' and id='.(int)$variantid.';';
                    try{
                        Shopware()->Db()->query($sql);
                    }catch(Exception $e){}
                }
            }
        }catch(Exception $e){}
    }
    /**
     * check for inactive articles that should be active
     * @param int $articleID article id
     * @param array $update array with article details
     * @param array $shopArticle array containing article information like active/inactive
     */
    protected function _checkActiveArticles($articleID,$update,$shopArticle){
        if((bool) $shopArticle['products_status']!==false){
            $sql = 'UPDATE s_articles set active=1 WHERE id='.(int)$articleID.';';
            try{
                Shopware()->Db()->query($sql);
            }catch(exception $e){ }
            if(!isset($product['shop']['attributes'])) {
                $sql = 'UPDATE s_articles_details set active=1 WHERE articleID='.(int)$articleID.';';
                try{
                    Shopware()->Db()->query($sql);
                }catch(exception $e){ }
            }else{
                foreach($update['variants'] as $key=>$value){
                    if((bool)$value['active']!==false){
                    $sql = 'UPDATE s_articles_details set active=1 WHERE id'.(int)$value['id'].';';
                        try{
                            Shopware()->Db()->query($sql);
                        }catch(exception $e){ }
                    }
                }
            }
        }
    }
}
