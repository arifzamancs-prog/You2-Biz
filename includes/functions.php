<?php

function clean($data)
{
    return htmlspecialchars(trim($data));
}

function redirect($url)
{
    header("Location: $url");
    exit;
}