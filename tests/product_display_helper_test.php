<?php
require_once __DIR__ . '/../includes/product_display_helper.php';

$cases = [
    ['A1', '111', true, 'A1 [Code: 111]'],
    ['A1', '', true, 'A1'],
    ['A1', '111', false, 'A1'],
];
foreach($cases as [$name, $sku, $show, $expected]){
    if(product_option_label($name, $sku, $show) !== $expected){
        throw new RuntimeException('Product option label test failed.');
    }
}
echo "Product display helper tests passed.\n";
