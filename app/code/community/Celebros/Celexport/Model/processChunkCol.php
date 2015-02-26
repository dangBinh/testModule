<?php
/**
 * Celebros Conversion Pro - Magento Extension
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish correct extension functionality.
 * If you wish to customize it, please contact Celebros.
 *
 * @category    Celebros
 * @package     Celebros_Conversionpro
 * @author      Shay Acrich (email: me@shayacrich.com)
 *
 */
require_once $argv[3] . '/abstract.php';

class Celebros_Shell_ProcessChunkCol extends Mage_Shell_abstract
{
    protected $_storeId = NULL;
    protected $_processId = NULL;
    
    public function __construct() {
        parent::__construct();
        $this->_storeId = (int)$_SERVER['argv'][2];
        $this->_processId = (int)$_SERVER['argv'][1];
    }
    
    function getCalculatedPrice($product)
    {
        //$product = Mage::getModel('catalog/product')->load($product->getId());
        $price = "";
        if ($product->getData("type_id") == "giftcard")
        {
            $min_amount = PHP_INT_MAX;
            $product = Mage::getModel('catalog/product')->load($product->getId());
            if ($product->getData("open_amount_min") != NULL && $product->getData("allow_open_amount")) {
                $min_amount = $product->getData("open_amount_min");
            }
            foreach ($product->getData("giftcard_amounts") as $amount)
            {
                if($min_amount > $amount["value"]) $min_amount = $amount["value"];
            }
            $price =  $min_amount;
        } else {
            $price = $product->getPrice();
        }
        
        if ($price == 0) {
            $priceModel  = $product->getPriceModel();
            
            //This fixes a bug with PHP 5.4 that causes the type instance to be simple (the default) instead of the one for bundled.
            $product->setTypeInstance(Mage::getSingleton('catalog/product_type')->factory($product, TRUE), TRUE);
            
            if ($product->getData("type_id") == "bundle") {
                $isgetTotalPrices = is_callable(array($priceModel,'getTotalPrices'));
                if (!$isgetTotalPrices)
                    list($minimalPriceTax, $maximalPriceTax) = $priceModel->getPrices($product);
                else
                    list($minimalPriceTax, $maximalPriceTax) = $priceModel->getTotalPrices($product, NULL, NULL, FALSE);
                $price = $minimalPriceTax;
            } elseif ($product->getData("type_id") == "grouped") {
                $price = $product->getMinimalPrice();
            }
        }
        
        return number_format($price, 2, ".", ""); 
    }
    
    function logProfiler($msg)
    {
        Mage::log(date("Y-m-d, H:i:s:: ").$msg, NULL, 'celebros.log',TRUE);
    }
    
    function getProductImage($product, $type)
    {
        $bImageExists = 'no_errors';
        $url = NULL;
        try {
            if ($type == 'image') {
                $url = $product->getImageUrl();
            } elseif ($type == 'thumbnail') {
                $url = Mage::helper('catalog/image')->init($product, 'thumbnail')->resize(66);
            } elseif ($type == 'original') {
                $url = Mage::getModel('catalog/product_media_config')->getMediaUrl($product->getImage());
            }
            
        } catch (Exception $e) {  
            // We get here in case that there is no product image and no placeholder image is set.
            $bImageExists = FALSE;
        }
        
        if (!$bImageExists 
            || (stripos($url, 'no_selection') != FALSE)
            || (substr($url, -1) == DS)) {
            
            $this->logProfiler('Warning: '. $type . ' Error: Product ID: '. $product->getEntityId() . ', image url: ' . $url);
            $url = '';
        }
        
        return $url;
    }
    
    public function getStoreId()
    {
        if (!$this->_storeId) {
            $this->_storeId = (int)$_SERVER['argv'][2];
        }
        
        return $this->_storeId;
    }
    
    public function getProcessId()
    {
        if (!$this->_processId) {
            $this->_processId = (int)$_SERVER['argv'][1];
        }
        
        return $this->_processId;
    }
    
    public function run()
    {
        $bExportProductLink = TRUE;
        $process_error = 'no_errors';

        try {
            Mage::app()->setCurrentStore($this->getStoreId());
            $_fStore = Mage::app()->getStore();
            $_fPath = Mage::helper('celexport')->getExportPath((int)$_SERVER['argv'][4]) . '/' . $_fStore->getWebsite()->getCode() . '/' . $_fStore->getCode();

            if (!is_dir($_fPath)) $dir = @mkdir($_fPath, 0777, TRUE);
            $filePath = $_fPath . '/' . 'export_chunk_' . $this->getProcessId() . "." . 'txt';
            
            $fh = fopen($filePath, 'ab');
            if (!$fh) {
                $this->logProfiler('Cannot create file from separate process.');
                exit;
            }
            
            $item = Mage::getModel('celexport/cache')->getCollection()->addFieldToFilter('name', 'export_chunk_' . $this->getProcessId())->getFirstItem();
            $rows = json_decode($item->getContent());
            $item->delete();
            $hasData = count($rows);
            
            $str='';
            $productNum=0;
            
            $ids = array();
            foreach ($rows as $row) {
                $ids[] = $row->entity_id;
            }
            
            //Prepare custom attributes list.
            $customAttributes = json_decode(Mage::getModel('celexport/cache')
                                                ->getCollection()
                                                ->addFieldToFilter('name', 'export_custom_fields')
                                                ->getFirstItem()
                                                ->getContent());
            
            $collection = Mage::getModel('catalog/product')->getCollection()
                ->addFieldToFilter('entity_id', array('in' => $ids))
                ->setStoreId($_fStore->getGroupId())
                ->addAttributeToSelect(array('sku', 'price', 'image', 'thumbnail', 'type', 'is_salable'))
                ->addAttributeToSelect($customAttributes)
                ->joinTable('cataloginventory_stock_item', 'product_id=entity_id', array('is_in_stock', 'qty', 'min_sale_qty'), NULL, 'left');
            
            foreach ($collection as $product) {
                $values["id"] = $product->getEntityId();
                $values["price"] = Mage::helper('core')->currency($this->getCalculatedPrice($product), FALSE, FALSE);
                $values["image_link"] = $this->getProductImage($product, 'image');
                $values["original_product_image_link"] = $this->getProductImage($product, 'original');
                $values["thumbnail"] = $this->getProductImage($product, 'thumbnail');
                $values["type_id"] = $product->getTypeId();
                $values["product_sku"] = $product->getSku();
                $values["is_salable"] = ($product->getIsSalable() == '1') ? "1" : "0";
                $values["is_in_stock"] = $product->getIsInStock() ? "1" : "0";
                $values["qty"] = (int)$product->getQty();
                $values["min_qty"] = (int)$product->getSaleMinQty();
                $values["link"] = $product->getProductUrl();
                
                //Process custom attributes.
                foreach ($customAttributes as $customAttribute) {
                    $values[$customAttribute] = ($product->getData($customAttribute) == "")
                        ? ""
                        : trim($product->getResource()->getAttribute($customAttribute)->getFrontend()->getValue($product), " , ");
                }
                
                        
                //Dispatching an event so that custom modules would be able to extend the functionality of the export,
                // by adding their own fields to the products export file.
                Mage::dispatchEvent('celexport_product_export', array(
                        'values'             => &$values,
                        'product'            => &$product,
                    ));
                
                $fDel = Mage::getStoreConfig('celexport/export_settings/delimiter');
                if ($fDel === '\t') $fDel = chr(9);
                
                $str.= "^" . implode("^" . $fDel . "^",$values) . "^" . "\r\n";
                
                $productNum++;
                
                $product->clearInstance();
                $product->reset();
            }
            
            fwrite($fh, $str);
            fclose($fh);
            $this->logProfiler("Exported {$productNum} out of {$hasData} products\n");
        } catch (Exception $e) {
            $process_error = $e->getMessage();
            $this->logProfiler('Caught exception: '.$e->getMessage());
        }
        
        Mage::getModel('celexport/cache')
            ->setName('process_' . $this->getProcessId())
            ->setContent($process_error)
            ->save();
    }
    
}

$shell = new Celebros_Shell_ProcessChunkCol();
$shell->run();