<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/app_config.php';
require_once __DIR__.'/../includes/bill_reminder_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
// Temporary shadows keep business data untouched; schema provisioning is skipped.
$_SESSION['bill_reminder_schema_v2']=true;
$conn->query("CREATE TEMPORARY TABLE users (id INT PRIMARY KEY,name VARCHAR(100),role VARCHAR(20),bill_reminder_enabled INT,bill_reminder_day INT,service_charge_amount DECIMAL(12,2),bill_reminder_start_date DATE NULL)");
$conn->query("CREATE TEMPORARY TABLE company_bill_payment_references (id INT AUTO_INCREMENT PRIMARY KEY,company_id INT,bill_reminder_date DATE,reference_no VARCHAR(150),status VARCHAR(20))");
$conn->query("INSERT INTO users VALUES (10,'Shop','admin',1,10,500,'2026-08-10'),(11,'Month end','admin',1,31,300,'2026-01-31'),(12,'Legacy','admin',1,1,200,NULL),(13,'Disabled','admin',0,10,500,'2026-08-10')");

function check($label,$actual,$expected){ if($actual!==$expected) throw new Exception($label.': expected '.var_export($expected,true).', got '.var_export($actual,true)); }
$status=function($company,$date) use ($conn){ return bill_reminder_status_for_company($conn,$company,new DateTimeImmutable($date)); };
$ref=function($date,$status,$company=10) use ($conn){ $s=$conn->prepare('INSERT INTO company_bill_payment_references(company_id,bill_reminder_date,reference_no,status) VALUES(?,?,?,?)'); $no='REF-'.$date; $s->bind_param('isss',$company,$date,$no,$status); $s->execute(); };

check('before first bill',$status(10,'2026-08-09'),null);
check('disabled company',$status(13,'2026-09-20'),null);
check('bill day',$status(10,'2026-08-10')['mode'],'notice');
check('third grace day',$status(10,'2026-08-12')['mode'],'notice');
check('after grace days',$status(10,'2026-08-13')['mode'],'countdown');
check('countdown until next bill',$status(10,'2026-09-09')['mode'],'countdown');
$ref('2026-08-10','pending');
check('pending is a notice',$status(10,'2026-08-20')['mode'],'notice');
$ref('2026-08-10','rejected');
check('rejected counts down again',$status(10,'2026-08-20')['mode'],'countdown');
$locked=$status(10,'2026-09-10');
check('next bill locks',$locked['mode'],'locked');
check('locked total',$locked['total'],1000.0);
check('locked bills',array_column($locked['bills'],'bill_cycle_date'),['2026-08-10','2026-09-10']);
check('locked first bill status',$locked['bills'][0]['reference_status'],'rejected');
check('not all pending',$locked['all_pending'],false);
$ref('2026-08-10','pending'); $ref('2026-09-10','pending');
$waiting=$status(10,'2026-09-11');
check('still locked while pending',$waiting['mode'],'locked');
check('all pending',$waiting['all_pending'],true);
$ref('2026-08-10','approved');
check('older bill approved unlocks',$status(10,'2026-09-11')['mode'],'notice');
check('one bill left',count($status(10,'2026-09-11')['bills']),1);
$ref('2026-09-10','approved');
check('all approved',$status(10,'2026-09-20'),null);
$ref('2026-08-10','rejected');
check('approval wins over later rejection',$status(10,'2026-09-20'),null);
check('three months unpaid',$status(10,'2026-12-10')['total'],1500.0);

$monthEnd=$status(11,'2026-02-28');
check('short month clamps bill day',array_column($monthEnd['bills'],'bill_cycle_date'),['2026-01-31','2026-02-28']);
check('short month locks',$monthEnd['mode'],'locked');

$legacy=$status(12,'2026-10-24');
check('legacy start is this month',array_column($legacy['bills'],'bill_cycle_date'),['2026-10-01']);
check('legacy not locked',$legacy['mode'],'countdown');
check('legacy start saved',$conn->query('SELECT bill_reminder_start_date FROM users WHERE id=12')->fetch_row()[0],'2026-10-01');

// Start date when Super Admin saves settings.
$resolve=function($company,$enabled,$day,$date) use ($conn){ return bill_reminder_resolve_start_date($conn,$company,$enabled,$day,new DateTimeImmutable($date)); };
check('unchanged settings keep start',$resolve(10,1,10,'2026-11-12'),'2026-08-10');
check('disabling keeps start',$resolve(10,0,10,'2026-11-12'),'2026-08-10');
check('day still ahead bills this month',$resolve(13,1,15,'2026-11-12'),'2026-11-15');
check('selected day is today',$resolve(13,1,15,'2026-11-15'),'2026-11-15');
check('day already passed bills next month',$resolve(13,1,15,'2026-11-25'),'2026-12-15');
check('day 31 in a short month',$resolve(13,1,31,'2026-02-10'),'2026-02-28');
check('day 31 after a short month end',$resolve(13,1,31,'2027-02-28'),'2027-02-28');
$ref('2026-11-01','approved',13);
check('paid month restarts next month',$resolve(13,1,20,'2026-11-12'),'2026-12-20');
$conn->close();
echo "PASS: grace days, countdown, pending/rejected/approved states, lock on next bill with total, short months, legacy start and start-date resolution.\n";
