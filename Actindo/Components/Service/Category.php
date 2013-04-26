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
				if($referenceID > 0){
					$target = $referenceID;
					$work=false;
				}else{
					$target = $parentID;
					$work = true;
				}
                return $this->categoryMove($action, $categoryID, $target,$work);
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
	 * @
     */
    protected function categoryMove($position, $categoryID, $referenceID,$workaround=false) {
		if(!$category = $this->getRepository()->find($categoryID)) {
            throw new Actindo_Components_Exception('Could not find the category to be moved');
        }
        if($workaround){
			$parent = $this->getRepository()->find($referenceID);
			$newPosition = 0;
		}else{
			if(!$reference = $this->getRepository()->find($referenceID)) {
				// parent did not change
				$parent = $this->getRepository()->find($category->getId());
				$newPosition = 1;
			} else {
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
				} else {
					$newPosition = 1 + (int) $reference->getPosition();
				}
			}
		}
        Actindo_Components_Util::dumpToFile('sort1.dump',$categoryID);
        $category->setParent($parent);
		$parentid = $parent->getId();
        Shopware()->Models()->flush();
        $childCategories = $this->getRepository()->childrenQuery($parent, true, 'position');
        $this->moveCategoryItem($categoryID,$parentid,$newPosition,$childCategories->getArrayResult());
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
	 * @param $parentId Parent Id of move Object
     * @param int $newPosition
     * @param array $cCA category child array
	 * @return void
     */
    protected function moveCategoryItem($moveItemId,$parentId,$position,$cCA){
		$prev = null;
		$found = false;
        $repository = $this->getRepository();
		$item = $this->getRepository()->find($moveItemId);
		if($position>0){
			foreach($cCA as $key){
				if($prev==null){
					$prev = $key['id'];
				}elseif($key['id'] == $moveItemId){
					$found=true;
					break;
				}else{
					$prev = $key['id'];
				}
			}
			$item->setPosition($position);
		}else{
			$item->setPosition(0);
		}
		if($found){
			$previous = $this->getRepository()->find($prev);
			$repository->persistAsNextSiblingOf($item, $previous);
		}else{ 
			$parent = $this->getRepository()->find($parentId);
            $repository->persistAsFirstChildOf($item, $parent);
		}
		Shopware()->Models()->flush();
	}
}