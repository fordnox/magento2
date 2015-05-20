<?php
/**
 * Import entity of bundle product type
 *
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\BundleImportExport\Model\Import\Product\Type;

class Bundle extends \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
{
    const BEFORE_OPTION_VALUE_DELIMITER = ';';

    const PAIR_VALUE_SEPARATOR = '=';

    const VALUE_DYNAMIC = 'dynamic';

    const VALUE_FIXED = 'fixed';

    const SELECTION_PRICE_TYPE_FIXED = 0;

    const SELECTION_PRICE_TYPE_PERCENT = 1;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;

    /**
     * @var \Magento\Framework\App\Resource
     */
    protected $_resource;

    /**
     * @var \Magento\Catalog\Model\Resource\Product\Collection
     */
    protected $_productCollection;

    /**
     * @var array
     */
    protected $_cachedOptions = [];

    /**
     * @var array
     */
    protected $_cachedSkus = [];

    /**
     * @var array
     */
    protected $_cachedSkuToProducts = [];

    /**
     * @var array
     */
    protected $_cachedOptionSelectQuery = [];

    /**
     * Column names that holds values with particular meaning.
     *
     * @var string[]
     */
    protected $_specialAttributes = [
        'price_type',
        'weight_type',
        'sku_type',
    ];

    /**
     * @inherited
     */
    protected $_customFieldsMapping = [
        'price_type' => 'bundle_price_type',
        'price_view' => 'bundle_price_view',
        'weight_type' => 'bundle_weight_type',
        'sku_type' => 'bundle_sku_type',
    ];

    /**
     * @var array
     */
    protected $_bundleFieldMapping = [
        'is_default' => 'default',
        'selection_price_value' => 'price',
        'selection_qty' => 'default_qty',
    ];

    /**
     * @var array
     */
    protected $_optionTypeMapping = [
        'dropdown' => 'select',
        'radiobutton' => 'radio',
        'checkbox'  => 'checkbox',
        'multiselect' => 'multi',
    ];

    /**
     * @param \Magento\Eav\Model\Resource\Entity\Attribute\Set\CollectionFactory $attrSetColFac
     * @param \Magento\Catalog\Model\Resource\Product\Attribute\CollectionFactory $prodAttrColFac
     * @param \Magento\Framework\App\Resource $resource
     * @param array $params
     */
    public function __construct(
        \Magento\Eav\Model\Resource\Entity\Attribute\Set\CollectionFactory $attrSetColFac,
        \Magento\Catalog\Model\Resource\Product\Attribute\CollectionFactory $prodAttrColFac,
        \Magento\Framework\App\Resource $resource,
        array $params
    ) {
        parent::__construct($attrSetColFac, $prodAttrColFac, $params);
        $this->_resource = $resource;
        $this->connection = $resource->getConnection('write');
    }

    /**
     * @param array $rowData
     * @param int $entity_id
     * @return array
     */
    protected function _parseSelections($rowData, $entity_id)
    {
        $rowData['bundle_values'] = str_replace(
            self::BEFORE_OPTION_VALUE_DELIMITER,
            $this->_entityModel->getMultipleValueSeparator(),
            $rowData['bundle_values']
        );
        $selections = explode(\Magento\CatalogImportExport\Model\Import\Product::PSEUDO_MULTI_LINE_SEPARATOR, $rowData['bundle_values']);
        foreach ($selections as $selection) {
            $values = explode($this->_entityModel->getMultipleValueSeparator(), $selection);
            $option = $this->_parseOption($values);
            if (isset($option['sku']) && isset($option['name'])) {
                if (!isset($this->_cachedOptions[$entity_id])) {
                    $this->_cachedOptions[$entity_id] = [];
                }
                $this->_cachedSkus[] = $option['sku'];
                if (!isset($this->_cachedOptions[$entity_id][$option['name']])) {
                    $this->_cachedOptions[$entity_id][$option['name']] = [];
                    $this->_cachedOptions[$entity_id][$option['name']] = $option;
                    $this->_cachedOptions[$entity_id][$option['name']]['selections'] = [];
                }
                $this->_cachedOptions[$entity_id][$option['name']]['selections'][] = $option;
                $this->_cachedOptionSelectQuery[] = $this->connection->select()->getAdapter()->quoteInto('(parent_id = '.(int)$entity_id.' AND title = ?)', $option['name']);
            }
        }
        return $selections;
    }

    /**
     * @param array $values
     * @return array
     */
    protected function _parseOption($values)
    {
        $option = [];
        foreach ($values as $keyValue) {
            $keyValue = trim($keyValue);
            if ($pos = strpos($keyValue, self::PAIR_VALUE_SEPARATOR)) {
                $key = substr($keyValue, 0, $pos);
                $value = substr($keyValue, $pos + 1);
                if ($key == 'type') {
                    if (isset($this->_optionTypeMapping[$value])) {
                        $value = $this->_optionTypeMapping[$value];
                    }
                }
                $option[$key] = $value;
            }
        }
        return $option;
    }

    /**
     * @param array $option
     * @param int $entity_id
     * @param int $index
     * @return array
     */
    protected function _populateOptionTemplate($option, $entity_id, $index = null)
    {
        $populatedOption = [
            'parent_id' => $entity_id,
            'required' => isset($option['required']) ? $option['required'] : 1,
            'position' => ($index === null ? 0 : $index),
            'type' => isset($option['type']) ? $option['type'] : 'select',
        ];
        if (isset($option['option_id'])) {
            $populatedOption['option_id'] = $option['option_id'];
        }
        return $populatedOption;
    }

    /**
     * @param array $option
     * @param int $option_id
     * @param int $store_id
     * @return array|bool
     */
    protected function _populateOptionValueTemplate($option, $option_id, $store_id = 0)
    {
        if (!isset($option['name']) || !$option_id) {
            return false;
        }
        return [
            'option_id' => $option_id,
            'store_id' => $store_id,
            'title' => $option['name'],
        ];
    }

    /**
     * @param array $selection
     * @param int $option_id
     * @param int $parent_id
     * @param int $index
     * @return array
     */
    protected function _populateSelectionTemplate($selection, $option_id, $parent_id, $index)
    {
        if (!isset($selection['parent_product_id'])) {
            if (!isset($this->_cachedSkuToProducts[$selection['sku']])) {
                return false;
            }
            $product_id = $this->_cachedSkuToProducts[$selection['sku']];
        } else {
            $product_id = $selection['parent_product_id'];
        }
        $populatedSelection = [
            'option_id' => (int)$option_id,
            'parent_product_id' => (int)$parent_id,
            'product_id' => (int)$product_id,
            'position' => (int)$index,
            'is_default' => (isset($selection['default']) && $selection['default']) ? 1 : 0,
            'selection_price_type' => (isset($selection['price_type']) && $selection['price_type'] == self::VALUE_FIXED)
                ? self::SELECTION_PRICE_TYPE_FIXED : self::SELECTION_PRICE_TYPE_PERCENT,
            'selection_price_value' => (isset($selection['price'])) ? (float)$selection['price'] : 0.0,
            'selection_qty' => (isset($selection['default_qty'])) ? (float)$selection['default_qty'] : 1.0,
            'selection_can_change_qty' => 1,
        ];
        if (isset($selection['selection_id'])) {
            $populatedSelection['selection_id'] = $selection['selection_id'];
        }
        return $populatedSelection;
    }

    /**
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function _retrieveProductHash()
    {
        $this->_cachedSkuToProducts = $this->connection->fetchPairs(
            $this->connection->select()->from(
                $this->_resource->getTableName('catalog_product_entity'),
                ['sku', 'entity_id']
            )->where(
                'sku IN (?)',
                $this->_cachedSkus
            )
        );
        return $this;
    }

    /**
     * Save product type specific data.
     *
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    public function saveData()
    {
        if ($this->_entityModel->getBehavior() == \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE) {
            $productIds = [];
            $newSku = $this->_entityModel->getNewSku();
            while ($bunch = $this->_entityModel->getNextBunch()) {
                foreach ($bunch as $rowNum => $rowData) {
                    $productData = $newSku[$rowData[\Magento\CatalogImportExport\Model\Import\Product::COL_SKU]];
                    $productIds[] = $productData['entity_id'];
                }
                $this->deleteOptionsAndSelections($productIds);
            }
        } else {
            $newSku = $this->_entityModel->getNewSku();
            while ($bunch = $this->_entityModel->getNextBunch()) {
                foreach ($bunch as $rowNum => $rowData) {
                    if (!$this->_entityModel->isRowAllowedToImport($rowData, $rowNum)) {
                        continue;
                    }
                    $productData = $newSku[$rowData[\Magento\CatalogImportExport\Model\Import\Product::COL_SKU]];
                    if ($this->_type != $productData['type_id']) {
                        continue;
                    }
                    $this->_parseSelections($rowData, $productData['entity_id']);
                }
                if (!empty($this->_cachedOptions)) {
                    $this->_retrieveProductHash();
                    $this->_populateExistingOptions();
                    $this->_insertOptions();
                    $this->_insertSelections();
                    $this->_clear();
                }
            }
        }
        return $this;
    }

    /**
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function _populateExistingOptions()
    {
        $existingOptions = $this->connection->fetchAssoc(
            $this->connection->select()->from(
                ['bo' => $this->_resource->getTableName('catalog_product_bundle_option')],
                ['option_id', 'parent_id', 'required', 'position', 'type']
            )->joinLeft(
                ['bov' => $this->_resource->getTableName('catalog_product_bundle_option_value')],
                'bo.option_id = bov.option_id',
                ['value_id', 'title']
            )->where(
                implode(' OR ', $this->_cachedOptionSelectQuery)
            )
        );
        foreach ($existingOptions as $option_id => $option) {
            $this->_cachedOptions[$option['parent_id']][$option['title']]['option_id'] = $option_id;
            foreach ($option as $key => $value) {
                if (!isset($this->_cachedOptions[$option['parent_id']][$option['title']][$key])) {
                    $this->_cachedOptions[$option['parent_id']][$option['title']][$key] = $value;
                }
            }
        }
        $this->_populateExistingSelections($existingOptions);
        return $this;
    }

    /**
     * @param array $existingOptions
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function _populateExistingSelections($existingOptions)
    {
        $existingSelections = $this->connection->fetchAll(
            $this->connection->select()->from(
                $this->_resource->getTableName('catalog_product_bundle_selection')
            )->where(
                'option_id IN (?)',
                array_keys($existingOptions)
            )
        );
        foreach ($existingSelections as $existingSelection) {
            $optionTitle = $existingOptions[$existingSelection['option_id']]['title'];
            foreach ($this->_cachedOptions[$existingSelection['parent_product_id']][$optionTitle]['selections'] as $selectIndex => $selection) {
                $product_id = $this->_cachedSkuToProducts[$selection['sku']];
                if ($product_id == $existingSelection['product_id']) {
                    foreach ($existingSelection as $origKey => $value) {
                        $key = isset($this->_bundleFieldMapping[$origKey]) ? $this->_bundleFieldMapping[$origKey] : $origKey;
                        if (!isset($this->_cachedOptions[$existingSelection['parent_product_id']][$optionTitle]['selections'][$selectIndex][$key])) {
                            $this->_cachedOptions[$existingSelection['parent_product_id']][$optionTitle]['selections'][$selectIndex][$key] = $existingSelection[$origKey];
                        }
                    }
                    break;
                }
            }
        }
        return $this;
    }

    /**
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function _insertOptions()
    {
        $optionTable = $this->_resource->getTableName('catalog_product_bundle_option');
        $optionValueTable = $this->_resource->getTableName('catalog_product_bundle_option_value');
        $productIds = [];
        $insert = [];
        foreach ($this->_cachedOptions as $entity_id => $options) {
            $index = 0;
            $productIds[] = $entity_id;
            foreach ($options as $key => $option) {
                if (isset($option['position'])) {
                    $index = $option['position'];
                }
                if ($tmpArray = $this->_populateOptionTemplate($option, $entity_id, $index)) {
                    $insert[] = $tmpArray;
                    $this->_cachedOptions[$entity_id][$key]['index'] = $index;
                    $index++;
                }
            }
        }
        $this->connection->insertOnDuplicate($optionTable, $insert, ['required', 'position', 'type']);
        $optionIds = $this->connection->fetchAssoc(
            $this->connection->select()->from(
                $optionTable,
                ['option_id', 'position', 'parent_id']
            )->where(
                'parent_id IN (?)',
                $productIds
            )
        );
        $insertValues = [];
        foreach ($this->_cachedOptions as $entity_id => $options) {
            foreach ($options as $key => $option) {
                foreach ($optionIds as $option_id => $assoc) {
                    if ($assoc['position'] == $this->_cachedOptions[$entity_id][$key]['index']
                        && $assoc['parent_id'] == $entity_id) {
                        $insertValues[] = $this->_populateOptionValueTemplate($option, $option_id);
                        $this->_cachedOptions[$entity_id][$key]['option_id'] = $option_id;
                        break;
                    }
                }
            }
        }
        if (!empty($insertValues)) {
            $this->connection->insertOnDuplicate($optionValueTable, $insertValues, ['title']);
        }
        return $this;
    }

    /**
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function _insertSelections()
    {
        $selectionTable = $this->_resource->getTableName('catalog_product_bundle_selection');
        $selections = [];
        foreach ($this->_cachedOptions as $product_id => $options) {
            foreach ($options as $title => $option) {
                $index = 0;
                foreach ($option['selections'] as $selection) {
                    if (isset($selection['position'])) {
                        $index = $selection['position'];
                    }
                    if ($tmpArray = $this->_populateSelectionTemplate($selection, $option['option_id'], $product_id, $index)) {
                        $selections[] = $tmpArray;
                        $index++;
                    }
                }
            }
        }
        if (!empty($selections)) {
            $this->connection->insertOnDuplicate($selectionTable, $selections, ['product_id', 'position', 'is_default', 'selection_price_type', 'selection_price_value', 'selection_qty', 'selection_can_change_qty']);
        }
        return $this;
    }

    /**
     * @param array $productIds
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function deleteOptionsAndSelections($productIds)
    {
        $optionTable = $this->_resource->getTableName('catalog_product_bundle_option');
        $optionValueTable = $this->_resource->getTableName('catalog_product_bundle_option_value');
        $valuesIds =  $this->connection->fetchAssoc($this->connection->select()->from(
            ['bov' => $optionValueTable],
            ['value_id']
        )->joinLeft(
            ['bo' => $optionTable],
            'bo.option_id = bov.option_id',
            ['option_id']
        )->where(
            'parent_id IN (?)',
            $productIds
        ));
        $this->connection->delete($optionTable, $this->connection->quoteInto('value_id IN (?)', array_keys($valuesIds)));
        $productIdsInWhere = $this->connection->quoteInto('parent_id IN (?)', $productIds);
        $this->connection->delete($optionTable, $this->connection->quoteInto('parent_id IN (?)', $productIdsInWhere));
        $this->connection->delete($optionTable, $this->connection->quoteInto('parent_product_id IN (?)', $productIdsInWhere));
        return $this;
    }

    /**
     * Clear cached values between bunches
     *
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType
     */
    protected function _clear()
    {
        $this->_cachedOptions = [];
        $this->_cachedOptionSelectQuery = [];
        $this->_cachedSkus = [];
        $this->_cachedSkuToProducts = [];
        return $this;
    }

}
