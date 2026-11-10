<?php
// Suggests the next code from the last one created: the last run of digits goes up by one
// and keeps its zero padding (P-009 -> P-010, ST12 -> ST13, A1B -> A2B). A code without
// digits gets "1" added. The admin can still type any other code.

function code_sequence_increment($code)
{
    $code = trim((string)$code);
    if($code === ''){
        return '';
    }
    if(!preg_match('/^(.*?)(\d+)(\D*)$/u', $code, $match)){
        return $code . '1';
    }
    $digits = $match[2];
    $next = (string)((int)ltrim($digits, '0') + 1);
    if(strlen($digits) > 18){
        return $code . '1';
    }
    return $match[1] . str_pad($next, strlen($digits), '0', STR_PAD_LEFT) . $match[3];
}

// Next free code after $last; $exists($code) says whether a code is already taken.
function code_sequence_next($last, callable $exists)
{
    $code = code_sequence_increment($last);
    for($attempt = 0; $code !== '' && $attempt < 500; $attempt++){
        if(!$exists($code)){
            return $code;
        }
        $code = code_sequence_increment($code);
    }
    return '';
}

// "Last created code: X" shown under a code box.
function code_sequence_last_hint($last, $label = 'code')
{
    $last = trim((string)$last);
    if($last === ''){
        return '<small class="form-text text-muted">No ' . htmlspecialchars($label) . ' created yet.</small>';
    }
    return '<small class="form-text text-muted">Last created ' . htmlspecialchars($label) . ': <strong>' . htmlspecialchars($last) . '</strong></small>';
}
