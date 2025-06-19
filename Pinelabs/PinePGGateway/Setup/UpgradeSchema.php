<?php

namespace Pinelabs\PinePGGateway\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '2.3.0', '<')) {

            // Modify sales_order_item table
            $orderItemTable = $setup->getTable('sales_order_item');
            $itemColumns = [
                'pinepg_product_amount' => [
                    'type' => Table::TYPE_DECIMAL,
                    'length' => '12,4',
                    'nullable' => true,
                    'comment' => 'PinePG Product Amount'
                ],
                'pinepg_cashback_discount' => [
                    'type' => Table::TYPE_DECIMAL,
                    'length' => '12,4',
                    'nullable' => true,
                    'comment' => 'PinePG Cashback Discount'
                ],
                'pinepg_product_discount' => [
                    'type' => Table::TYPE_DECIMAL,
                    'length' => '12,4',
                    'nullable' => true,
                    'comment' => 'PinePG Product Discount'
                ],
                'pinepg_cashback_discount_percentage' => [
                    'type' => Table::TYPE_DECIMAL,
                    'length' => '5,2',
                    'nullable' => true,
                    'comment' => 'PinePG Cashback Discount %'
                ],
                'pinepg_oem_name' => [
                    'type' => Table::TYPE_TEXT,
                    'length' => 255,
                    'nullable' => true,
                    'comment' => 'OEM Name'
                ],
                'pinepg_oem_id' => [
                    'type' => Table::TYPE_INTEGER,
                    'nullable' => true,
                    'comment' => 'OEM ID'
                ],
            ];

            foreach ($itemColumns as $name => $definition) {
                if (!$setup->getConnection()->tableColumnExists($orderItemTable, $name)) {
                    $setup->getConnection()->addColumn($orderItemTable, $name, $definition);
                }
            }

            // Modify sales_order table to add pinepg_captured_amount
            $orderTable = $setup->getTable('sales_order');
            if (!$setup->getConnection()->tableColumnExists($orderTable, 'pinepg_captured_amount')) {
                $setup->getConnection()->addColumn(
                    $orderTable,
                    'pinepg_captured_amount',
                    [
                        'type' => Table::TYPE_DECIMAL,
                        'length' => '12,4',
                        'nullable' => true,
                        'default' => null,
                        'comment' => 'PinePG Captured Amount'
                    ]
                );
            }
        }

        $setup->endSetup();
    }
}
