<?php

function normalize_person_name($name)
{
    $name = trim((string)$name);
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

function validate_person_name($name, $label = 'Name')
{
    $name = normalize_person_name($name);

    if($name === ''){
        return $label . ' is required.';
    }

    if(mb_strlen($name) < 2){
        return $label . ' must be at least 2 characters.';
    }

    if(!preg_match('/[A-Za-z]/', $name)){
        return $label . ' must contain letters.';
    }

    if(preg_match('/^\d+$/', $name)){
        return $label . ' cannot be only numbers.';
    }

    if(!preg_match('/^[A-Za-z0-9 .,&()\'-]+$/', $name)){
        return $label . ' contains invalid characters.';
    }

    return '';
}

function normalize_phone_input($phone)
{
    return trim((string)$phone);
}

function validate_phone_input($phone, $label = 'Phone', $required = false)
{
    $phone = normalize_phone_input($phone);

    if($phone === ''){
        return $required ? ($label . ' is required.') : '';
    }

    $digits = preg_replace('/[^0-9]/', '', $phone);

    if(strlen($digits) < 11){
        return $label . ' must contain at least 11 digits.';
    }

    if(strlen($digits) > 14){
        return $label . ' is too long.';
    }

    return '';
}

function normalize_email_input($email)
{
    return trim((string)$email);
}

function validate_email_input($email, $label = 'Email', $required = false)
{
    $email = normalize_email_input($email);

    if($email === ''){
        return $required ? ($label . ' is required.') : '';
    }

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        return 'Invalid ' . strtolower($label) . ' address.';
    }

    return '';
}
