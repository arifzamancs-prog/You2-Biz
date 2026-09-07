<?php
session_start();

unset(
    $_SESSION['customer_portal_id'],
    $_SESSION['customer_portal_user_id'],
    $_SESSION['customer_portal_name']
);

header('Location: ../login.php');
exit;
