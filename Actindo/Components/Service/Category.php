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


class Actindo_Components_Service_Category extends Actindo_Components_Service {
    /**
     * caches the category repository
     * @see Actindo_Components_Service_Category::getRepository()
     * @var Shopware\Models\Category\Respository
     */
    private $repository = null;
    
    
    /**
     * returns - for each language - the entire categroy tree 
     * the resulting array contains one element per language, the language id is the key and the value is the category tree
     * since shopware doesnt support translating category names the category tree is the same for all languages
     * 
     * @return struct
     */
    public function get() {
        $categoryTree = $this->getCategoryTree();
        $result = array();
        foreach(array_keys($this->util->getLanguages()) AS $langID) {
            $result[$langID] =& $categoryTree;
        }
        return array('ok' => '1', 'categories' => $result);
    }
    
    /**
     * performs operations on single categories or the category tree
     * technical info about param $data: the type should be just "struct", but when $action is "delete" actindo spuriously passes the type "array"
     * 
     * @param string $action known actions are: add, delete, textchange (rename category), append (move category), above (move category), below (move category)
     * @param int $categoryID the category id to perform operations on
     * @param int $parentID the parent id of the category to perform operations on
     * @param int $referenceID this does something aswell
     * @param struct|array $data data required to perform the called action
     * @return struct
     * @throws Actindo_Components_Exception 
     */
    public function action($action, $categoryID, $parentID, $referenceID, $data) {
        switch(strtolower($action)) {
            case 'add':
                return $this->categoryAdd($parentID, $data);
			break;
            case 'delete':
                return $this->categoryDelete($categoryID);
			break;
            case 'textchange':
                return $this->categoryRename($categoryID, $data);
			break;
            case 'append':
            case 'above':
            case 'below':
                return $this->categoryMove($action, $categoryID, ((strtolower($action)!=='append')?$referenceID:$parentID));
			break;
            default:
                throw new Actindo_Components_Exception(sprintf('Unknown category action given: %s', $action));
        }
    }
    
    /**
     * provides access to the category model
     * 
     * @return Shopware\Models\Category\Respository
     */
    protected function getRepository() {
        if($this->repository === null) {
            $this->repository = Shopware()->Models()->getRepository('Shopware\Models\Category\Category');
        }
        return $this->repository;
    }
    
    /**
     * returns the entire category tree
     * 
     * @param boolean $omitRoot if true, the root node is not in the result array - only its children. Defaults to true
     * @return type array category tree
     * @throws Actindo_Components_Exception 
     */
    protected function getCategoryTree($omitRoot = true) {
        $result = $this->getRepository()->getListQuery(array(), array(), null, null, false)->getArrayResult();
        
        $categories = $tree = array();
        while($category = array_shift($result)) {
            $id = (int) $category['id'];
            $parent = (int) $category['parentId'];
            
            $categories[$id] = array(
                'categories_id' => $id,
                'parent_id' => $parent,
                'categories_name' => $category['name'],
                'children' => array(),
            );
            
            if($category['parentId'] === null) { // root node
                $tree[] =& $categories[$id];
            }
            else {
                if(!isset($categories[$parent])) {
                    throw new Actindo_Components_Exception(sprintf("Found a category whos parent I don't know: %s (ID: %d, parentID: %d)", $category['name'], $id, $parent)); // @todo
                }
                $categories[$parent]['children'][$id] =& $categories[$id];
            }
        }
        
        if($omitRoot) {
            return $tree[0]['children'];
        }
        return $tree;
    }
    
    /**
     * creates a new category
     * 
     * @param int $parentID the parent id under which the new category will be created
     * @param array $data associative array that contains the category name (and possibly other stuff)
     */
    protected function categoryAdd($parentID, $data) {
        $defaultLanguageID = $this->util->getDefaultLanguage();
        $name = $data['description'][$defaultLanguageID]['name'];
        
        $parent = $this->getRepository()->find($parentID);
        $category = new \Shopware\Models\Category\Category();
        
        $category->setParent($parent)
                 ->setName($name);
        Shopware()->Models()->persist($category);
        Shopware()->Models()->flush();
        
        return array('ok' => true, 'id' => $category->getId());
    }
    
    /**
     * renames a category
     * 
     * @param int $categoryID the id of the category to rename
     * @param array $data associative array that contains the new category name (and possibly other stuff)
     * @return array
     */
    protected function categoryRename($categoryID, $data) {
        $defaultLanguageID = $this->util->getDefaultLanguage();
        $category = $this->getRepository()->find($categoryID);
        $name = $data['description'][$defaultLanguageID]['name'];
        
        $category->setName($name);
        Shopware()->Models()->persist($category);
        Shopware()->Models()->flush();
        
        return array('ok' => true);
    }
    
    /**
     * deletes a category (and all subcategories if there are any)
     * 
     * @param int $categoryID
     * @return array
     */
    protected function categoryDelete($categoryID) {
        if($category = $this->getRepository()->find($categoryID)) {
            Shopware()->Models()->remove($category);
            Shopware()->Models()->flush();
        }
        return array('ok' => true);
    }
    /**
     * moves a category within the category tree
     * 
     * @see Shopware_Controllers_Backend_Category::moveTreeItemAction()
     * @param string $position type of movement: above/below/append
     * @param int $categoryID the category id to be moved
     * @param int $referenceID reference category id to move above/below/append
     */
    protected function categoryMove($position, $categoryID, $referenceID) {
        $repository = $this->getRepository();
        list($index,$parent,$category,$previousid) = $this->getNewPositionIndex($categoryID,$position,$referenceID);
        if($position!=='append'){
            if($index===0){
                $category->setPosition(0);
                if((int)$category->getParentId()!==(int)$parent->getId())
                    $category->setParent($parent);
                $repository->persistAsFirstChildOf($category, $parent);
            }else{
                $previous = $this->getRepository()->find($previousid);
                $category->setPosition($index);
                $repository->persistAsNextSiblingOf($category, $previous);
            }
        }else{
            $parent = $this->getRepository()->find($referenceID);
            $category->setParent($parent);
            $repository->persistAsLastChildOf($category, $parent);
        }
        Shopware()->Models()->flush();
        return array('ok' => true);
    }
    
    /**
     * gets the index of the new position
     * @param $moveItemId ID of the item to be moved
     * @param $positiontype type of moving (above/below/append)
     * @param $position id of the element, where it should be placed bevor/after null if append
     * @param $parentid ID of the Parent Object
     * @return array index where the new object should be placed and parentid
     */
    protected function getNewPositionIndex($moveItemId,$positiontype,$referenceID){
        $category = $this->getRepository()->find($moveItemId);
    	if(!$category) {
           throw new Actindo_Components_Exception('Could not find the category to be moved');
        }
        if(!$reference = $this->getRepository()->find($referenceID)) {
            $parent = $this->getRepository()->find($category->getId());
        }else{
            $parent = $reference->getParent();
        }
        $childCategories = $this->getRepository()->childrenQuery($parent, true, 'position');
        $children = $childCategories->getArrayResult();
        if($positiontype==='append'){
            $position = count($children);
            foreach($children as $key=>$value){
                if((int)$value['id']==(int)$moveItemId)
                    $position--;
            }
        }else{
            usort($children,'actindo_compareSort');
            foreach($children as $key=>$value){
                if((int)$value['id']==(int)$referenceID){
                    $position = $key;
                    if($positiontype === 'above'){
                        $previous = $children[$position];
                    }else{
                        $previous = $value['id'];
                    }
                }
            }
            if($position===0){
                $position = 1;
                $previous = $children[0]['id'];
            }
            if($position===null){
                $position=0;
                $previous=null;
            }
        }
        return array($position,$parent,$category,$previous);
    }
}

function actindo_compareSort($a,$b){
    return ($a['left']<$b['left'])?-1:1;
}