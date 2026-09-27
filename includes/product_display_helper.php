<?php

function product_option_label($product_name, $sku, $show_sku = true)
{
    $label = trim((string)$product_name);
    $code = trim((string)$sku);
    return $show_sku && $code !== '' ? $label . ' [Code: ' . $code . ']' : $label;
}
