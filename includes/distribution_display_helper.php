<?php

function distribution_product_blocks($products)
{
    if (is_string($products)) {
        $products = json_decode('[' . $products . ']', true) ?: [];
    }
    $html = [];
    foreach ($products as $product) {
        $name = htmlspecialchars((string)$product[0], ENT_QUOTES, 'UTF-8');
        $variants = htmlspecialchars((string)($product[1] ?? ''), ENT_QUOTES, 'UTF-8');
        $html[] = '<div class="mb-2"><div>' . $name . '</div>'
            . ($variants !== '' ? '<small class="d-block text-muted">' . $variants . '</small>' : '') . '</div>';
    }
    return implode('', $html);
}
