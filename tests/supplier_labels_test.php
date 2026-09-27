<?php
require_once __DIR__ . '/../includes/supplier_label_helper.php';
foreach (['Stock Product' => 'Vendors / Add Vendor / vendor', 'Housing' => 'Suppliers / Add Supplier / supplier', 'Others' => 'Suppliers / Add Supplier / supplier'] as $type => $expected) {
    $_SESSION['company_type'] = $type;
    if (supplier_display_text('Suppliers / Add Supplier / supplier') !== $expected) {
        throw new RuntimeException('Incorrect terminology for ' . $type);
    }
    if (supplier_display_text('supplier_name suppliers/index.php') !== ($type === 'Stock Product' ? 'supplier_name vendors/index.php' : 'supplier_name suppliers/index.php')) {
        throw new RuntimeException('Unexpected label transformation');
    }
}
echo "Supplier label tests passed.\n";
