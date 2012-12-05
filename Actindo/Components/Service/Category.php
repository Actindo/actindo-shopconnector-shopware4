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
                return $this->categoryMove($action, $categoryID, $referenceID);
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
        if(!$category = $this->getRepository()->find($categoryID)) {
            throw new Actindo_Components_Exception('Could not find the category to be moved');
        }
        
        if(!$reference = $this->getRepository()->find($referenceID)) {
            // parent did not change
            $parent = $this->getRepository()->find($category->getId());
            $newPosition = 1;
        }
        else {
            $parent = $reference->getParent();
            if($position == 'append') {
                $newPosition = 1 + (int) Shopware()->Models()->getQueryCount(
                    $this->getRepository()->getListQuery(array(
                        array(
                            'property' => 'c.parentId',
                            'value' => $parent->getId(),
                        )),
                        array(),
                        null,
                        null,
                        false
                    )
                );
            }
            else {
                $newPosition = 1 + (int) $reference->getPosition();
            }
        }
        
        $category->setParent($parent);
        Shopware()->Models()->flush();
        
        $childCategories = $this->getRepository()->childrenQuery($parent, true, 'position');
        $categoryChildArray = $this->moveCategoryItem($categoryID, $newPosition, $childCategories->getArrayResult());
        
        // use batch size to improve the performance on a large amount of category items
        $i = 0;
        foreach($categoryChildArray AS $key => $child) {
            $category = $this->getRepository()->find($child['id']);
            $category->setPosition($key);
            if($i++ % 100 == 0) {
                Shopware()->Models()->flush();
                Shopware()->Models()->clear();
            }
        }
        Shopware()->Models()->flush();
        Shopware()->Models()->clear();
        
        try {
            $this->getRepository()->reorder($category->getParent(), 'position');
        } catch(Gedmo\Exception\InvalidArgumentException $e) {
            // Node is not managed by UnitOfWork
        }
        
        return array('ok' => true);
    }
    
    /**
     * helper method to move the category item to the right position
     *
     * @see Shopware_Controllers_Backend_Category::moveCategoryItem()
     * @param int $moveItemId
     * @param int $newPosition
     * @param array $categoryChildArray
     * @return array sorted category
     */
    protected function moveCategoryItem($moveItemId, $newPosition, $categoryChildArray)
    {
        $movedChildKey = 0;
        foreach ($categoryChildArray as $key => $child) {
            if ($child["id"] == $moveItemId) {
                $movedChildKey = $key;
            }
        }

        if ($newPosition != 0) {
            //set the right position based on the array index
            $newPosition--;
        }
        $temporaryCategoryArray = array_splice($categoryChildArray, $movedChildKey, 1);
        array_splice($categoryChildArray, $newPosition, 0, $temporaryCategoryArray);
        return $categoryChildArray;
    }
}
