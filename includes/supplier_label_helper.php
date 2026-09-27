<?php
/** Company-specific display terminology; database keys and routes stay unchanged. */
function supplier_display_text($text)
{
    if (($_SESSION['company_type'] ?? '') !== 'Stock Product') {
        return $text;
    }
    return preg_replace_callback('/\b(suppliers|supplier)\b/i', static function ($match) {
        $word = strtolower($match[0]) === 'suppliers' ? 'vendors' : 'vendor';
        return ctype_upper($match[0][0]) ? ucfirst($word) : $word;
    }, $text);
}
