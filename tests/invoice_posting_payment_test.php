<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/invoice_posting_helper.php';
// total, cash received, signed prior balance, current cash, previous due paid,
// new advance, remaining invoice due, payment status.
$cases = [
    'unpaid COD' => [180,0,0,0,0,0,180,'due'],
    'COD with old due' => [180,0,50,0,0,0,180,'due'],
    'partial receipt' => [180,50,0,50,0,0,130,'partial'],
    'full receipt' => [180,180,0,180,0,0,0,'paid'],
    'partial credit' => [180,0,-50,0,0,0,130,'partial'],
    'full credit' => [180,0,-200,0,0,0,0,'paid'],
    'cash and credit' => [180,80,-100,80,0,0,0,'paid'],
    'settle old due' => [180,210,50,180,30,0,0,'paid'],
    'new advance' => [180,250,50,180,50,20,-20,'paid'],
    'zero invoice' => [0,0,0,0,0,0,0,'paid'],
    'decimal receipt' => [180.75,80.25,0,80.25,0,0,100.5,'partial'],
    'existing negative total behavior' => [-50,0,0,-50,0,50,-50,'paid'],
];
foreach ($cases as $name => $values) {
    [$total,$paid,$balance,$cash,$previous,$advance,$due,$status] = $values;
    $actual = invoice_posting_payment_breakdown($total,$paid,$balance);
    $expected = ['current_invoice_cash_payment'=>$cash,'previous_due_payment'=>$previous,'outstanding_payable'=>$advance,'new_due_amount'=>$due];
    foreach ($expected as $key=>$value) {
        if (abs($actual[$key]-$value)>0.000001) throw new RuntimeException("$name: $key mismatch");
    }
    if ($actual['new_payment_status'] !== $status) throw new RuntimeException("$name: status mismatch");
}
echo 'PASS: '.count($cases)." Sales payment calculation cases. No database writes or payments.\n";
