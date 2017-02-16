<?php
ini_set('display_errors',1);
set_time_limit(0);
define('__MAGENTO__', realpath(dirname(__FILE__)));
if(!require_once __MAGENTO__ . '/../shell/abstract.php'):
	echo "required file /shell/abstract.php could not be resolved in " . __MAGENTO__ . PHP_EOL;
	die();
endif; 


// delete all categories
function deleteAllCategories(){
	require_once '../app/Mage.php';
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
	$resource = Mage::getSingleton('core/resource');
	$db_read = $resource->getConnection('core_read');
	$categories = $db_read->fetchCol("SELECT entity_id FROM " . $resource->getTableName("catalog_category_entity") . " WHERE entity_id>1 ORDER BY entity_id DESC");
	foreach ($categories as $category_id) {
    	try {
    	    Mage::getModel("catalog/category")->load($category_id)->delete();
    	    echo "deleted category id" . $category_id.PHP_EOL;
    	} catch (Exception $e) {
    	    echo $e->getMessage() . "\n";
    	}
	}
}
function deleteAllProducts(){
	require_once '../app/Mage.php';  
	Mage::app()->setCurrentStore(Mage::getModel('core/store')->load(Mage_Core_Model_App::ADMIN_STORE_ID));
	$products = Mage :: getResourceModel('catalog/product_collection')->setStoreId(1)->getAllIds();  
    if(is_array($products)){  
        foreach ($products as $key => $pId)  
        {  
            try  
            {  
                $product = Mage::getModel('catalog/product')->load($pId)->delete();  
                echo "successfully deleted product with ID: ". $pId .PHP_EOL;  
            }   
            catch (Exception $e)   
            {  
                echo "Could not delete product with ID: ". $pId .PHP_EOL;  
            }  
        }  
    }
}
// deleteAllCategories();deleteAllProducts();die();

class Magento_Product_Import_Script extends Mage_Shell_Abstract
{
	protected 		$_argname;
	private 		$productData;
	private 		$__create_categories_if_not_exist__;
	private 		$__update_products_if_exist__;
	/*
	*
	*
	*
	* 
	 */
	public function __construct($argv){
		parent::__construct();
		$this->_argname								= $argv;
		$this->__create_categories_if_not_exist__ 	= true;
		$this->__update_products_if_exist__			= false;;
		
	} // end __construct()

	/*
	*
	*
	*
	* 
	 */
	public function run(){
		$scriptStart			= time();
		echo "start..." . PHP_EOL;
		// get filename
		echo "Getting import file handle...".PHP_EOL;
		if( $handle  = $this->getFileHandle($this->_argname[2])){
			// parse .csv file
			echo "Parsing product import file...".PHP_EOL;
			$this->buildProductDataFromCSV($handle);
			$err 		= 0;
			$succ 		= 0;
			foreach($this->productData as $i=>$productData):
				if($this->saveProductData($productData)):
					echo $productData['name'] . ": " . $productData['sku'] . " successfully imported.".PHP_EOL;
					$succ++;
				else:
					echo $productData['name'] . ": " . $productData['sku'] . " failed import.".PHP_EOL;
					$err++;
				endif;
			endforeach;
		}else{
			echo "Could not open import file '".$importFile."'. Ensure file exists and current user has proper permissions to read the file." . PHP_EOL;
			echo $this->usageHelp(),PHP_EOL;
		}
		echo $succ . " products imported successfully with " . $err . " errors.";
		echo "finsihed in " . date('i:s',(time() - $scriptStart)) . PHP_EOL;
	}// end run()

	/*
	*
	*
	*
	* 
	 */
	public function usageHelp()
    {
        return <<<USAGE
Usage:		  				php -f [scriptFileName].php [oldUrl] [importFileName].csv 
-oldUrl							full url of exported site for file resources with trailing slash.  Must be included for export of images and other resource files.
-importFileName: 				comma seperated list of product and attributes from Magento Admin>System>Export
USAGE;
    }// end usageHelp() 

    /*
	*
	*
	*
	* 
	 */
    private function saveProductData($productData=array(), $update=false){
    	// check if product sku already exists
    	$productModel 					= Mage::getModel('catalog/product');
    	$preProduct 					= $productModel->loadByAttribute('sku', $productData['sku']);
    	if($preProduct){
    		if(!$update):
	    		echo "A product with SKU: " . $productData['sku'] . " already exists. This product will be omitted.";
	    		return false;
	    	endif;
		}else{
			// set product site ids
			Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
			$localWebsites 				= $this->getLocalViews();
			$websiteIds					= array();
			$productViewMap 			= $this->getProductViewMap();
			foreach($productData['product_websites'] as $productWebsite):
				// @description  	(string)$productWebsite - website code name passed through .csv import data
				foreach($localWebsites as $lw):
					if($lw['code'] === $productWebsite):
						if(!in_array($lw['id'], $websiteIds)):
							$websiteIds[]			= $lw['id'];
						endif;
					endif;
				endforeach;
				// look for instances in product view map
				if($productViewMap['websites']):
					foreach($productViewMap['websites'] as $from=>$to):
						if( $productWebsite === $from ):
							foreach($localWebsites as $lw){
								if($lw['code'] === $to):
									if(!in_array($lw['id'], $websiteIds)):
										$websiteIds[]			= $lw['id'];
									endif;
								endif;
							}
						endif;
					endforeach;
				endif;
			endforeach;
			// set to default when all else fails
			if(count($websiteIds) < 1):
				$websiteIds[] 		= array(Mage::app()->getWebsite(true)->getDefaultGroup()->getDefaultStoreId());
			endif;
			$productData['product_websites'] 		= $websiteIds;
			$productModel->setWebsiteIds($websiteIds);

			// set ID based parameters
			foreach($productData as $field=>$data):
				$productData['attribute_set_id'] 			= isset($data['attribute_set_id']) ? $data['attribute_set_id'] : (isset($data['attribute_set']) ? Mage::getModel('eav/entity_attribute_set')->getCollection()->setEntityTypeFilter($entityTypeId)->addFieldToFilter('attribute_set_name', $data['attribute_set'])->getFirstItem()->getAttributeSetId() : 4 );
			endforeach;
			
			// set attributes
			$directSet 					= array('sku','name','description','short_description','price','type','attribute_set_id','weight','tax_class_id','visibility','status');
			foreach($productData as $field=>$data):
				$productModel->setTypeId($productData['type']);
				if(in_array($field,$directSet)):
					$methodName 			= 'set'.$this->convertFieldName($field);
					$productModel->$methodName($data);
				else:
					if($field === 'category'):
						$categoryIds 			= $this->setUpCategories($data,$websiteIds,true);
						$productModel->setCategoryIds($categoryIds);
					elseif( $field === 'media_image' ):
						foreach((array)$data as $d):
							if($file = $this->curlResource($d)):
								$productModel->addImageToMediaGallery($file, 'image', false);
							endif;
						endforeach;
					else:
						$attributeValue		= Mage::getModel('catalog/product')->getResource()->getAttribute($field);
						if($attributeValue):
							switch(strtolower($attributeValue->getFrontendInput())){
								case "select" :
									$attributeOptions = $attributeValue->getSource()->getAllOptions(false);
									foreach($attributeOptions as $_i=>$_d):
										if($_d['label'] === $data ){
											$fieldValue 			= $_d['value'];
										}
									endforeach;
									break;
								default :
									$fieldValue 					= $data;
							}
							$productModel->setData($field, $fieldValue);
						endif;
					endif;
				endif;
			endforeach;
		}
		try{
			$productModel->save();
			return true;
		}catch(Exception $e){
			echo "ERROR: " . $e . PHP_EOL;
			return false;
		}
    }

    /*
     *
     *
     *
     * 
     */
    private function setUpCategories($categoryPaths=array(), $websiteIds=array(1)){

    	$categoryModel			= Mage::getModel('catalog/category');
    	$localViews  			= $this->getLocalViews();
    	$websiteId 				= isset($websiteIds[0]) ? $websiteIds[0] : 1;
    	$storeId 				= Mage::app()->getWebsite(true)->getDefaultGroup()->getDefaultStoreId();
    	$categoryIDs 			= array();

    	foreach($localViews as $website=>$data):
    		if((int)$data['id'] === (int)$websiteId && isset($data['stores'])):
    			$storeId 			= $data['stores'][0]['id'];
    		endif;
    	endforeach;

    	if(!function_exists('createCategory')):
    		function createCategory($categoryData=false){
				if(!$categoryData || !$categoryData['store_id']){
					return false;
				}
				$categoryModel			= Mage::getModel('catalog/category');
				$categoryModel->setStoreId((int)$categoryData['store_id']);
				$categoryModel->addData($categoryData);
        		try {
        	  		$categoryModel->save();
        	  		echo $category_name . " created on store id ". $storeId .PHP_EOL;
        	  		return $categoryModel->getId();
				} catch (Exception $e){
					echo "ERROR: $e".PHP_EOL;
					return false;
				}
			}
		endif;
		$newCats 		= 0;
		foreach($categoryPaths as $cPath):
			$category_list  = explode('/',$cPath);
			foreach($category_list as $i=>$category_name):
      			// check for categories
      			$category_collection    = Mage::getResourceModel('catalog/category_collection')->addFieldToFilter('name', $category_name);
      			// check for parent category
      			$parentCategory	        = $i>0 ? Mage::getResourceModel('catalog/category_collection')->addFieldToFilter('name', $category_list[$i-1])->getFirstItem() : Mage::getResourceModel('catalog/category_collection')->addFieldToFilter('name', 'Default Category')->getFirstItem();

      			if(count($category_collection)>0):
      				echo $category_name . " category exists. Adding to product schema.".PHP_EOL;
      				foreach($category_collection as $category):
      					$categoryIDs[]		= $category->getId();
      				endforeach;
      			else:
      				if($this->__create_categories_if_not_exist__):
	      				echo $category_name . " not found. Attempting to create...".PHP_EOL;
    	  				$categoryData     = array(
      						'store_id'		=> (int)$storeId,
          					'name'			=> $category_name,
          					'meta_title'	=> $category_name,
          					'display_mode'	=> Mage_Catalog_Model_Category::DM_PRODUCT,
          					'is_active'		=> 1,
          					'is_anchor'		=> 0
        				);
        				if(count($parentCategory)>0){
							$categoryData['path']     = $parentCategory->getPath();
        				}
	        			if($newId 		= createCategory($categoryData)){
    	    				$categoryIDs[] 		= $newId;
    	    				$newCats++;
    	    			}
    	    		endif;
      			endif;
      		endforeach;
		endforeach;
		if($newCats > 0):
			echo $newCats . " new categories created.".PHP_EOL;
		endif;
		return array_unique($categoryIDs);
    }

  

	/*
    *
    *
    *
    * 
     */
    private function curlResource($data, $size="small"){
    	if(!$this->_argname[1] ){ return false; }
    	$resourceUrl 		= $this->_argname[1];
    	$fullpath 			= 'media/catalog/product'.$data;
    	$newPath			= Mage::getBaseDir('base').'/'.$fullpath;

    	// create directories
    	$directoryPaths		= explode('/',$fullpath);
    	$compound			= "../";
    	foreach($directoryPaths as $i=>$dirPath):
    		if($i < count($directoryPaths) - 1):
    			$compound .= $dirPath;
    			if(!is_dir($compound)):
    				if(!mkdir($compound)):
    					echo "Could not create directory " . $dirPath . ". Please, check permissions.".PHP_EOL;
    				endif;
    			endif;
    			$compound .= "/";
    		endif;
    	endforeach;


    	if($ch = curl_init($resourceUrl.$fullpath)){
    		$fp                 = fopen($newPath,'wb');
    		if($fp):
    			curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
				curl_setopt($ch,CURLOPT_FILE,$fp);
				curl_setopt($ch,CURLOPT_HEADER,0);
				if(curl_exec($ch)){
					$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					if($httpCode == 404){
						echo $resourceUrl.$fullpath . " returned a 404".PHP_EOL;
						return false;
					}
					if(!fclose($fp)):
						echo "could not create file ".$fullpath.". Please, check directory permissions.".PHP_EOL;
						return false;
					endif;
				}else{
					echo "could not resolve ".$resourceUrl.$fullpath.PHP_EOL;
					return false;
				}
			else:
				echo "could not open file " . $newPath . PHP_EOL;
				return false;
    		endif;
    		return $newPath;
    	}
    }    

    /*
    *
    *
    *
    * 
     */
    private function getProductViewMap(){
    	$map 		= array(
    		'websites' 		=> array(
    			'base' 		=> 'shambhala',
    			'base_1'	=> 'shambhala',
    			'roost'		=> 'roost'
    		),
    	);
    	return $map;
    }

    /*
	*
	*
	*
	* 
	 */
    private function getLocalViews(){
    	$localViews			= array();
    	foreach(Mage::app()->getWebsites() as $website):
    		$localViews[$website->getCode()] 	  = isset($localViews[$website->getCode()]) ? $localViews[$website->getCode()] : array(
    			'id'		=> $website->getId(),
    			'code'		=> $website->getCode()
    		);
			foreach ($website->getGroups() as $group) :
				$stores = $group->getStores();
				foreach ($stores as $store) :
					$localViews[$website->getCode()]['stores'] 	= isset($localViews[$website->getCode()]['stores']) ? $localViews[$website->getCode()]['stores'] : array();
					$localViews[$website->getCode()]['stores'][] 	= array(
						'id'				=> $store->getId(),
						'code'				=> $store->getCode(),
						'root_category' 	=> Mage::app()->getStore($store->getId())->getRootCategoryId()
					);
				endforeach;
			endforeach;
		endforeach;
    	return $localViews;
    }


    /*
	*
	*
	*
	* 
	 */
    private function getFileHandle($fileHandle=false){
    	$importFile			= isset($fileHandle) ? $fileHandle : './product_import.csv';
    	if(!file_exists($importFile) || !$handle	= fopen($importFile,'r')):
			return false;
		endif;
		return $handle;
    } // end getFileHandle()

    /*
	*
	*
	*
	* 
	 */
    private function buildProductDataFromCSV($handle){
    	$headerRows					= array();
		$row = 1;
		$skuIndex;
		$dump		= array();
		while (($line = fgetcsv($handle)) !== FALSE) {
			if($row === 1):
				$headerRows			= $line;
				foreach($headerRows as $i=>$header):
					$headerRows[$i] 		= ltrim($header,'_');
					if(strtolower($headerRows[$i])==="sku"):
						$skuIndex				= $i;
					endif;
				endforeach;
			elseif($row > 1):
				$sku;
				foreach($line as $i=>$d):
					if((int)$i === $skuIndex):
						if(strlen($line[$i]) > 0):
							$sku 						= $line[$i];
							$dump[$sku]					= isset($dump[$sku]) ? $dump[$sku] : array();
							$dump[$sku]['sku']			= $sku;
						elseif(isset($sku)):
							$sku 						= $sku;
						endif;
					else:
						if(strlen($d)<1):
							continue;
						endif;
						$dump[$sku][$headerRows[$i]] 	= isset($dump[$sku][$headerRows[$i]]) ? array_unique(array_merge((array)$dump[$sku][$headerRows[$i]], (array)$d)) : $d;
					endif;
				endforeach;
				$productData[]			= $dump[$sku];
			endif;
			$row++;
		}
		$productData 					= $this->validateProductData(array_values($dump));
		$this->productData 			= $productData;
		// cleanup memory
		unset($headerRows);
		unset($productData);
		return true;
    } // end buildProductData()

    /*
	*
	*
	*
	* 
	 */
    private function validateProductData($productData = array()){
    	$invalid						= array();
    	foreach($productData as $i=>$data ):
    		$productData[$i]['store']		= isset($data['store']) ? $data['store'] : $iDefaultStoreId = Mage::app()->getWebsite()->getDefaultGroup()->getDefaultStoreId();
    		$productData[$i]['sku_type']	= 0;
    		$productData[$i]['status']		= isset($data['status']) ? $data['status'] : 0;
    		$required 						= $this->getRequiredAttributes($data['attribute_set']);
    		$fail 							= array('attribute_set','type','sku','description','short_description','name','status','visibility','tax_class_id');
    		$errors 						= array();
    		foreach($required as $k=>$code):
    			if(!isset($data[$code])):
    				if(in_array($code, $fail)):
    					array_push($errors, "Product " . $data['name'] . " - sku: " . $data['sku'] . " is missing required attribute " . $code . " from attribute set " . $data['attribute_set']);
    					if(!in_array($i, $invalid)):
							array_push($invalid, $i);
						endif;
					else:
						$productData[$i][$code] 			= 0;
					endif;
    			endif;
    		endforeach;
    		foreach(array_unique($errors) as $error):
				echo $error . PHP_EOL;
			endforeach;
    	endforeach;
    	// reverse the order so we can't remove by index without problems
		rsort($invalid);
		// unset $invalid indexes in $productData
		foreach($invalid as $inv):
			unset($productData[$inv]);
		endforeach;
    	return $productData;
    } // end validateProductData()

    /*
	*
	*
	*
	* 
	 */
    private function setUpShippingData($productData=array()){
    	$productData['shipment_type']			 	= isset($productData['shipment_type']) ? $productData['shipment_type'] : Mage_Catalog_Model_Product_Type_Abstract::SHIPMENT_SEPARATELY;
    	return $productData;
    }

    /*
	*
	*
	*
	* 
	 */
    private function setUpPriceData($productData=array()){
    	$productData['price']						= isset($productData['price']) ? $productData['price'] : 0;
    	$productData['price_type']					= isset($productData['price_type']) ? $productData['price_type'] : 1;
    	$productData['price_view']					= isset($productData['price_view']) ? $productData['price_view'] : $iDefaultStoreId = Mage::app()->getWebsite()->getDefaultGroup()->getDefaultStoreId();
    	return $productData;
    }

    /*
	*
	*
	*
	* 
	 */
    private function setUpStockData($data=array()){
		if(!isset($data['stockData'])):
		$stockData 						= array(
    			'manage_stock'				=> isset($data['manage_stock']) ? $data['manage_stock'] : 0,
    			'use_config_manage_stock'	=> isset($data['use_config_manage_stock']) ? $data['use_config_manage_stock'] : 0,
    			'qty' 						=> isset($data['qty']) ? $data['qty'] : 0,
    			'is_in_stock'   			=> isset($data['is_in_stock']) ? $data['is_in_stock'] : ($stockData['qty'] > 0 ? 1 : 0)
    		);
    		$data['stockData']				= $data;
    	endif;
    	$data['weight']						= isset($data['weight']) ? $data['weight'] : 0;
    	$data['weight_type']				= isset($data['weight_type']) ? $data['weight_type'] : (strtolower($data['type']) === 'simple' ? 1 : 0);
    	unset($data['manage_stock']);
    	unset($data['use_config_manage_stock']);
    	unset($data['qty']);
    	unset($data['is_in_stock']);
    	return $data;
    }

    /*
	*
	*
	*
	* 
	 */
    private function getRequiredAttributes($attributeSetName="Default"){
    	$entityTypeId 				= Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
    	$attributeSetId = Mage::getModel('eav/entity_attribute_set')->getCollection()->setEntityTypeFilter($entityTypeId)->addFieldToFilter('attribute_set_name', $attributeSetName)->getFirstItem()->getAttributeSetId();
    	$attributes = Mage::getModel('catalog/product_attribute_api')->items($attributeSetId);
    	$required					= array();
    	foreach ($attributes as $attribute){
			if((int)$attribute['required'] === 1):
				array_unique(array_push($required,$attribute['code']));
			endif;
		}
		return $required;
    }

    /*
    *
    *
    *
    * 
     */
    private function convertFieldName($str) {
        $str_parts = explode('_', $str);
		$class = '';
		foreach ($str_parts as $part) {
			$class .= ucfirst($part);
		}
		return $class;
	}
}
$shell = new Magento_Product_Import_Script($argv);
$shell->run();

?>