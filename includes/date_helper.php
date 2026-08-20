<?php

function app_date($date)
{
    if($date === null || $date === '' || $date === '0000-00-00'){
        return '-';
    }

    $timestamp = strtotime((string)$date);

    if($timestamp === false){
        return (string)$date;
    }

    return date('d-m-Y', $timestamp);
}

function app_datetime($date)
{
    if($date === null || $date === '' || $date === '0000-00-00 00:00:00'){
        return '-';
    }

    $timestamp = strtotime((string)$date);

    if($timestamp === false){
        return (string)$date;
    }

    return date('d-m-Y h:i A', $timestamp);
}
