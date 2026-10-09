<?php
/**
 * Cafe navigation adapted from arifzamancs-prog/You2-cafe
 * revision a8f2c2012bb8108881f63a44ab38609cad3001fd.
 * Controllers share Biz's tenant, branch, CSRF, wallet and invoice protections.
 */
function restaurant_module_active()
{
    return ($_SESSION['company_type'] ?? '') === 'Restaurant & Cafe';
}

function restaurant_module_menu($label, $items)
{
    if (!restaurant_module_active()) return $items;
    $menus = [
        'Products' => [
            ['product_categories/index.php', 'Categories'],
            ['products/index.php', 'Add Product'],
        ],
        'Suppliers' => [
            ['suppliers/index.php', 'Suppliers'],
            ['purchases/index.php', 'Purchases'],
            ['suppliers/supplier_payment.php', 'Supplier Due Payment'],
            ['suppliers/supplier_payment_history.php', 'Payment History'],
        ],
        'Sales' => [
            ['sales/create_invoice.php', 'Create Invoice'],
            ['restaurant/create_invoice_ultra.php', 'Create Invoice (Ultra)'],
            ['sales/invoice_list.php', 'Invoice List'],
        ],
    ];
    if (!isset($menus[$label])) return $items;
    $result = [];
    foreach ($menus[$label] as [$path, $title]) {
        $result[] = ['href' => app_path($path), 'label' => $title];
    }
    return $result;
}
