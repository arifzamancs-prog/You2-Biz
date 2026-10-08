<?php
// Passwordless E-shop customer accounts. Kept separate from the existing
// Customer Portal so enabling shop login never changes portal credentials.
require_once __DIR__.'/sms_helper.php';
require_once __DIR__.'/smtp_mailer.php';

function eshop_customer_auth_schema($conn){
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_customer_accounts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
        customer_id BIGINT UNSIGNED NULL, customer_name VARCHAR(150) NOT NULL, phone VARCHAR(30) NOT NULL,
        email VARCHAR(100) NOT NULL DEFAULT '', address TEXT NOT NULL, verified_channel ENUM('sms','email') NOT NULL,
        verified_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY company_phone (company_id,phone), KEY company_customer (company_id,customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_customer_otp_challenges (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
        channel ENUM('sms','email') NOT NULL, recipient VARCHAR(100) NOT NULL, customer_name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL, email VARCHAR(100) NOT NULL DEFAULT '', address TEXT NOT NULL,
        code_hash VARCHAR(255) NOT NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY otp_lookup (company_id,channel,recipient,consumed_at,expires_at), KEY otp_rate (company_id,recipient,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_checkout_verification_method($conn,$companyId){
    $s=mysqli_prepare($conn,'SELECT customer_verification_method FROM eshop_checkout_settings WHERE company_id=?');
    if(!$s) return 'sms'; mysqli_stmt_bind_param($s,'i',$companyId); mysqli_stmt_execute($s);
    $method=(string)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['customer_verification_method']??'sms');
    return in_array($method,['sms','email'],true)?$method:'sms';
}

function eshop_customer_normalize_phone($phone){ return preg_replace('/[^0-9+]/','',trim((string)$phone)); }
function eshop_customer_session($companyId){
    $row=$_SESSION['eshop_customer'][(int)$companyId]??null;
    return is_array($row) && (int)($row['company_id']??0)===(int)$companyId ? $row : null;
}
function eshop_customer_logout($companyId){ unset($_SESSION['eshop_customer'][(int)$companyId]); }
function eshop_customer_link_sales_customer($conn,$companyId,$accountId,$phone){
    $phone=eshop_customer_normalize_phone($phone); $s=mysqli_prepare($conn,"SELECT id FROM customers WHERE user_id=? AND phone=? AND status='active' LIMIT 1");
    mysqli_stmt_bind_param($s,'is',$companyId,$phone); mysqli_stmt_execute($s); $customerId=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['id']??0);
    if($customerId>0 && $accountId>0){ $u=mysqli_prepare($conn,'UPDATE eshop_customer_accounts SET customer_id=? WHERE id=? AND company_id=?'); mysqli_stmt_bind_param($u,'iii',$customerId,$accountId,$companyId); mysqli_stmt_execute($u); $_SESSION['eshop_customer'][(int)$companyId]['customer_id']=$customerId; }
    return $customerId;
}

function eshop_customer_auth_start($conn,$companyId,$shop,$input,$sender=null){
    eshop_customer_auth_schema($conn);
    $method=eshop_checkout_verification_method($conn,$companyId);
    $name=trim((string)($input['name']??'')); $phone=eshop_customer_normalize_phone($input['phone']??'');
    $email=strtolower(trim((string)($input['email']??''))); $address=trim((string)($input['address']??''));
    if($name==='' || mb_strlen($name)>150 || !preg_match('/\A[+0-9]{7,30}\z/',$phone) || $address==='' || mb_strlen($address)>2000) throw new RuntimeException('Enter a valid name, delivery phone and address.');
    if($email!=='' && (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>100)) throw new RuntimeException('Enter a valid email address.');
    if($method==='email' && $email==='') throw new RuntimeException('Email is required because this shop uses email verification.');
    $recipient=$method==='sms'?$phone:$email;
    $rate=mysqli_prepare($conn,"SELECT COUNT(*) total FROM eshop_customer_otp_challenges WHERE company_id=? AND recipient=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
    mysqli_stmt_bind_param($rate,'is',$companyId,$recipient); mysqli_stmt_execute($rate);
    if((int)(mysqli_fetch_assoc(mysqli_stmt_get_result($rate))['total']??0)>=3) throw new RuntimeException('Too many verification requests. Please wait 15 minutes and try again.');
    $code=(string)random_int(100000,999999); $hash=password_hash($code,PASSWORD_DEFAULT);
    $s=mysqli_prepare($conn,"INSERT INTO eshop_customer_otp_challenges(company_id,channel,recipient,customer_name,phone,email,address,code_hash,expires_at) VALUES(?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
    mysqli_stmt_bind_param($s,'isssssss',$companyId,$method,$recipient,$name,$phone,$email,$address,$hash);
    if(!mysqli_stmt_execute($s)) throw new RuntimeException('Could not start verification. Please try again.');
    $challengeId=mysqli_insert_id($conn);
    $shopName=trim((string)($shop['shop_name']??$shop['name']??'Our shop')); $message='Your '.$shopName.' verification code is '.$code.'. It expires in 10 minutes.';
    $sent=false;
    if($sender!==null) $sent=(bool)$sender($method,$recipient,$message,$shopName,$code);
    elseif($method==='sms'){
        $result=sms_send_bulk_message(sms_get_system_api_token($conn),[$phone],$message); $sent=(bool)$result['success'];
    }else{
        [$sent]=smtp_send_mail($email,$name,$shopName.' verification code','<p>Your verification code is <strong>'.$code.'</strong>.</p><p>It expires in 10 minutes.</p>',[],['from_name'=>$shopName]);
    }
    if(!$sent){ $delete=mysqli_prepare($conn,'DELETE FROM eshop_customer_otp_challenges WHERE id=? AND company_id=?'); mysqli_stmt_bind_param($delete,'ii',$challengeId,$companyId); mysqli_stmt_execute($delete); throw new RuntimeException($method==='sms'?'SMS code could not be sent. Please contact the shop.':'Email code could not be sent. Please contact the shop.'); }
    $_SESSION['eshop_otp_pending'][(int)$companyId]=['channel'=>$method,'recipient'=>$recipient];
    return $method;
}

function eshop_customer_auth_login_start($conn,$companyId,$shop,$input,$sender=null){
    eshop_customer_auth_schema($conn); $method=eshop_checkout_verification_method($conn,$companyId);
    $value=$method==='sms'?eshop_customer_normalize_phone($input['phone']??''):strtolower(trim((string)($input['email']??'')));
    if($method==='sms' && !preg_match('/\A[+0-9]{7,30}\z/',$value)) throw new RuntimeException('Enter the phone number used for a previous order.');
    if($method==='email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter the email address used for a previous order.');
    $field=$method==='sms'?'phone':'email';
    $s=mysqli_prepare($conn,"SELECT customer_name,phone,email,address FROM eshop_customer_accounts WHERE company_id=? AND $field=? LIMIT 1");
    mysqli_stmt_bind_param($s,'is',$companyId,$value); mysqli_stmt_execute($s); $account=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if(!$account) throw new RuntimeException('No E-shop account was found for that contact. Verify your details while checking out first.');
    return eshop_customer_auth_start($conn,$companyId,$shop,['name'=>$account['customer_name'],'phone'=>$account['phone'],'email'=>$account['email'],'address'=>$account['address']],$sender);
}

function eshop_customer_auth_verify($conn,$companyId,$code){
    eshop_customer_auth_schema($conn); $pending=$_SESSION['eshop_otp_pending'][(int)$companyId]??null;
    if(!is_array($pending) || !preg_match('/\A(?:sms|email)\z/',$pending['channel']??'') || !isset($pending['recipient'])) throw new RuntimeException('Start verification first.');
    $channel=$pending['channel']; $recipient=(string)$pending['recipient']; $code=trim((string)$code);
    if(!preg_match('/\A\d{6}\z/',$code)) throw new RuntimeException('Enter the 6-digit verification code.');
    $s=mysqli_prepare($conn,"SELECT * FROM eshop_customer_otp_challenges WHERE company_id=? AND channel=? AND recipient=? AND consumed_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE");
    mysqli_begin_transaction($conn); mysqli_stmt_bind_param($s,'iss',$companyId,$channel,$recipient); mysqli_stmt_execute($s); $row=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if(!$row || (int)$row['attempts']>=5){ mysqli_rollback($conn); throw new RuntimeException('This code has expired. Request a new code.'); }
    if(!password_verify($code,$row['code_hash'])){ $u=mysqli_prepare($conn,'UPDATE eshop_customer_otp_challenges SET attempts=attempts+1 WHERE id=?'); mysqli_stmt_bind_param($u,'i',$row['id']); mysqli_stmt_execute($u); mysqli_commit($conn); throw new RuntimeException('Incorrect code. Please try again.'); }
    $u=mysqli_prepare($conn,'UPDATE eshop_customer_otp_challenges SET consumed_at=NOW() WHERE id=?'); mysqli_stmt_bind_param($u,'i',$row['id']); mysqli_stmt_execute($u);
    $find=mysqli_prepare($conn,'SELECT id,customer_id FROM eshop_customer_accounts WHERE company_id=? AND phone=? LIMIT 1 FOR UPDATE'); mysqli_stmt_bind_param($find,'is',$companyId,$row['phone']); mysqli_stmt_execute($find); $account=mysqli_fetch_assoc(mysqli_stmt_get_result($find));
    $customerName=(string)$row['customer_name']; $phone=(string)$row['phone']; $email=(string)$row['email']; $address=(string)$row['address'];
    if($account){ $accountId=(int)$account['id']; $customerId=(int)$account['customer_id']; $u=mysqli_prepare($conn,'UPDATE eshop_customer_accounts SET customer_name=?,email=?,address=?,verified_channel=?,verified_at=NOW() WHERE id=? AND company_id=?'); mysqli_stmt_bind_param($u,'ssssii',$customerName,$email,$address,$channel,$accountId,$companyId); mysqli_stmt_execute($u); }
    else { $customerId=0; $find=mysqli_prepare($conn,"SELECT id FROM customers WHERE user_id=? AND phone=? AND status='active' LIMIT 1"); mysqli_stmt_bind_param($find,'is',$companyId,$phone); mysqli_stmt_execute($find); $customerId=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($find))['id']??0); $u=mysqli_prepare($conn,'INSERT INTO eshop_customer_accounts(company_id,customer_id,customer_name,phone,email,address,verified_channel,verified_at) VALUES(?,?,?,?,?,?,?,NOW())'); mysqli_stmt_bind_param($u,'iisssss',$companyId,$customerId,$customerName,$phone,$email,$address,$channel); mysqli_stmt_execute($u); $accountId=mysqli_insert_id($conn); }
    mysqli_commit($conn); unset($_SESSION['eshop_otp_pending'][(int)$companyId]);
    $_SESSION['eshop_customer'][(int)$companyId]=['id'=>$accountId,'company_id'=>(int)$companyId,'customer_id'=>$customerId,'name'=>$customerName,'phone'=>$phone,'email'=>$email,'address'=>$address,'channel'=>$channel];
    return $_SESSION['eshop_customer'][(int)$companyId];
}
