<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/smtp_mailer.php';
function smtp_config_assert($condition,$message){ if(!$condition) throw new RuntimeException($message); }
$valid=['smtp_host'=>'mail.example.test','smtp_port'=>'465','smtp_secure'=>'ssl','smtp_username'=>'mailer@example.test','smtp_password'=>'not-empty','smtp_from_email'=>'mailer@example.test'];
smtp_config_assert(smtp_configuration_status_from_settings($valid)['ready'],'Complete non-sensitive SMTP settings were rejected');
$missing=$valid; $missing['smtp_password']=''; $result=smtp_configuration_status_from_settings($missing);
smtp_config_assert(!$result['ready'] && in_array('SMTP password',$result['issues'],true),'Missing password was not identified');
$invalid=$valid; $invalid['smtp_from_email']='not-an-email';
smtp_config_assert(!smtp_configuration_status_from_settings($invalid)['ready'],'Invalid sender email was accepted');
echo "PASS: SMTP readiness validates configuration without exposing or sending credentials.\n";
