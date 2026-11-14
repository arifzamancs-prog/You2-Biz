<?php
// Travel Agency bookings (Air Ticket first; Visa, Hajj & Umrah, Tour and Hotel use the
// same record). Each booking is one sale to a client (B2C customer or B2B agent):
// - the selling price is owed by the client (client ledger, Receive Payment);
// - the purchase price is owed to the vendor as a `purchases` row, so the existing
//   Vendor ledger, Vendor Due Payment and Due Pay History work unchanged.
require_once __DIR__ . '/wallet_helper.php';
require_once __DIR__ . '/transaction_helper.php';
require_once __DIR__ . '/expense_helper.php';
require_once __DIR__ . '/supplier_label_helper.php';

function travel_service_kinds()
{
    return [
        'air_ticket' => 'Air Ticket',
        'visa' => 'Visa Processing',
        'hajj_umrah' => 'Hajj & Umrah',
        'tour' => 'Tour Package',
        'hotel' => 'Hotel Booking',
        'other' => 'Other Service',
    ];
}

// Run outside a transaction (CREATE TABLE commits implicitly).
function travel_booking_schema($conn)
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            branch_id INT NOT NULL DEFAULT 0,
            booking_no VARCHAR(40) NOT NULL,
            request_key VARCHAR(64) NOT NULL,
            service_kind VARCHAR(20) NOT NULL DEFAULT 'air_ticket',
            project_id INT NOT NULL DEFAULT 0,
            package_id INT NOT NULL DEFAULT 0,
            client_id INT NOT NULL,
            booking_date DATE NOT NULL,
            travel_date DATE NULL,
            return_date DATE NULL,
            airline VARCHAR(100) NOT NULL DEFAULT '',
            pnr VARCHAR(40) NOT NULL DEFAULT '',
            route VARCHAR(150) NOT NULL DEFAULT '',
            trip_type VARCHAR(20) NOT NULL DEFAULT '',
            details TEXT NULL,
            vendor_id INT NOT NULL DEFAULT 0,
            purchase_id INT NOT NULL DEFAULT 0,
            cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_by_user_id INT NOT NULL DEFAULT 0,
            created_by_name VARCHAR(150) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY travel_booking_key (user_id, request_key),
            UNIQUE KEY travel_booking_no (user_id, booking_no),
            KEY travel_booking_client (user_id, client_id),
            KEY travel_booking_date (user_id, booking_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_booking_passengers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            passenger_name VARCHAR(150) NOT NULL,
            passport_no VARCHAR(40) NOT NULL DEFAULT '',
            ticket_no VARCHAR(60) NOT NULL DEFAULT '',
            KEY travel_passenger_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_client_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            branch_id INT NOT NULL DEFAULT 0,
            payment_no VARCHAR(40) NOT NULL,
            client_id INT NOT NULL,
            booking_id INT NULL,
            wallet_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_date DATE NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_by_user_id INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY travel_payment_client (user_id, client_id),
            KEY travel_payment_booking (booking_id),
            KEY travel_payment_no (user_id, payment_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_booking_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            booking_id INT NOT NULL,
            adjust_no VARCHAR(40) NOT NULL,
            request_key VARCHAR(64) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            adjust_date DATE NOT NULL,
            client_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
            client_credit DECIMAL(12,2) NOT NULL DEFAULT 0,
            extra_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            vendor_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
            cost_credit DECIMAL(12,2) NOT NULL DEFAULT 0,
            extra_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            vendor_id INT NOT NULL DEFAULT 0,
            purchase_id INT NOT NULL DEFAULT 0,
            purchase_return_id INT NOT NULL DEFAULT 0,
            vendor_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
            client_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
            details VARCHAR(500) NOT NULL DEFAULT '',
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_by_name VARCHAR(150) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY travel_adjust_key (user_id, request_key),
            KEY travel_adjust_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = $ready && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_booking_status_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            booking_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            status_date DATE NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            sms_status VARCHAR(20) NOT NULL DEFAULT '',
            created_by_name VARCHAR(150) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY travel_status_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Hajj / Umrah / Tour departure groups, and installment plans of bookings.
    $ready = $ready && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            group_code VARCHAR(40) NOT NULL,
            group_name VARCHAR(150) NOT NULL,
            service_kind VARCHAR(20) NOT NULL DEFAULT 'hajj_umrah',
            package_id INT NOT NULL DEFAULT 0,
            departure_date DATE NULL,
            return_date DATE NULL,
            seats INT NOT NULL DEFAULT 0,
            price_per_person DECIMAL(12,2) NOT NULL DEFAULT 0,
            cost_per_person DECIMAL(12,2) NOT NULL DEFAULT 0,
            vendor_id INT NOT NULL DEFAULT 0,
            airline VARCHAR(100) NOT NULL DEFAULT '',
            hotel_info VARCHAR(300) NOT NULL DEFAULT '',
            note VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY travel_group_code (user_id, group_code),
            KEY travel_group_departure (user_id, departure_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_booking_installments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            installment_no INT NOT NULL,
            due_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            KEY travel_installment_booking (booking_id, installment_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Refund, Void and Reissue change what the booking is worth to each side;
    // Visa (and other processed services) keep their file details and progress.
    $columns = [
        'group_id' => 'INT NOT NULL DEFAULT 0',
        'client_credit' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'extra_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'cost_credit' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'extra_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'country' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'visa_type' => "VARCHAR(60) NOT NULL DEFAULT ''",
        'application_no' => "VARCHAR(60) NOT NULL DEFAULT ''",
        'expected_date' => 'DATE NULL',
        'process_status' => "VARCHAR(20) NOT NULL DEFAULT ''",
        // Commission owed to the agent who brought the booking (a cost of the sale).
        'commission_agent_id' => 'INT NOT NULL DEFAULT 0',
        'commission' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        'commission_credit' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    ];
    $table_columns = [
        'travel_bookings' => $columns,
        // A full refund / void takes the commission back on its own date.
        'travel_booking_adjustments' => ['commission_change' => 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
    ];
    foreach ($table_columns as $table => $list) {
        foreach ($list as $column => $definition) {
            $exists = $ready ? mysqli_query($conn, "SHOW COLUMNS FROM {$table} LIKE '{$column}'") : false;
            if ($exists && mysqli_num_rows($exists) === 0) {
                $ready = $ready && (bool)mysqli_query($conn, "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }
    $ready = $ready && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_commission_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            branch_id INT NOT NULL DEFAULT 0,
            payment_no VARCHAR(40) NOT NULL,
            request_key VARCHAR(64) NOT NULL,
            agent_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(10) NOT NULL DEFAULT 'cash',
            wallet_id INT NOT NULL DEFAULT 0,
            payment_date DATE NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_by_name VARCHAR(150) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY travel_commission_key (user_id, request_key),
            KEY travel_commission_agent (user_id, agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($ready) {
        // A vendor refund is a purchase return without items (Vendor ledger "Return").
        require_once __DIR__ . '/purchase_return_helper.php';
        purchase_return_schema($conn);
    }
    return $ready;
}

// What the booking is worth now: selling price and purchase cost after refunds and reissues.
function travel_effective_price_sql($alias = 'b')
{
    return "({$alias}.price + {$alias}.extra_price - {$alias}.client_credit)";
}

function travel_effective_cost_sql($alias = 'b')
{
    return "({$alias}.cost + {$alias}.extra_cost - {$alias}.cost_credit)";
}

function travel_valid_date($value)
{
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function travel_unique_no($conn, $user_id, $table, $column, $prefix)
{
    $base = $prefix . '-' . date('ymd');
    $last = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM `{$table}` WHERE user_id=" . (int)$user_id . " AND `{$column}` LIKE '" . mysqli_real_escape_string($conn, $base) . "-%'"));
    for ($n = (int)($last[0] ?? 0) + 1; ; $n++) {
        $candidate = $base . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $taken = mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM `{$table}` WHERE user_id=" . (int)$user_id . " AND `{$column}`='" . mysqli_real_escape_string($conn, $candidate) . "' LIMIT 1"));
        if (!$taken) {
            return $candidate;
        }
    }
}

// Client (customers row) of this company, with its Client Type from the client form.
function travel_client($conn, $user_id, $client_id)
{
    $client = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, customer_code, customer_name, phone, email, address, extra_data FROM customers WHERE id=" . (int)$client_id . " AND user_id=" . (int)$user_id));
    if (!$client) {
        return null;
    }
    $extra = json_decode((string)($client['extra_data'] ?? ''), true);
    $client['client_type'] = is_array($extra) ? trim((string)($extra['client_type'] ?? '')) : '';
    $client['is_agent'] = stripos($client['client_type'], 'agent') !== false;
    return $client;
}

function travel_clients($conn, $user_id)
{
    $clients = [];
    $result = mysqli_query($conn, "SELECT id FROM customers WHERE user_id=" . (int)$user_id . " AND (status IS NULL OR status='active') ORDER BY customer_name");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $clients[] = travel_client($conn, $user_id, (int)$row['id']);
    }
    return $clients;
}

function travel_vendors($conn, $user_id)
{
    $vendors = [];
    $result = mysqli_query($conn, "SELECT id, supplier_name, supplier_code, phone FROM suppliers WHERE user_id=" . (int)$user_id . " AND (status IS NULL OR status='active') ORDER BY supplier_name");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $vendors[] = $row;
    }
    return $vendors;
}

function travel_require_wallet($conn, $user_id, $wallet_id)
{
    $wallet = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM wallets WHERE id=" . (int)$wallet_id . " AND user_id=" . (int)$user_id . " AND status='active'"));
    if (!$wallet) {
        throw new RuntimeException('Select an active wallet.');
    }
}

/**
 * Saves a booking. $data: service_kind, project_id, package_id, client_id, booking_date,
 * travel_date, return_date, airline, pnr, route, trip_type, vendor_id, cost, price, note.
 * $passengers: [[name, passport_no, ticket_no], ...].
 * $client_paid / $client_wallet: money received from the client now.
 * $vendor_paid / $vendor_wallet: money paid to the vendor now.
 */
function travel_booking_create($conn, $user_id, $branch_id, array $data, array $passengers, $client_paid, $client_wallet, $vendor_paid, $vendor_wallet, $request_key)
{
    $user_id = (int)$user_id;
    $branch_id = (int)$branch_id;
    if (!preg_match('/^[a-f0-9]{32,64}$/', (string)$request_key)) {
        throw new RuntimeException('Invalid request. Reload the page and try again.');
    }
    $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM travel_bookings WHERE user_id={$user_id} AND request_key='" . mysqli_real_escape_string($conn, $request_key) . "'"));
    if ($existing) {
        return (int)$existing['id'];
    }

    $kind = array_key_exists($data['service_kind'] ?? '', travel_service_kinds()) ? $data['service_kind'] : 'other';
    $client = travel_client($conn, $user_id, (int)($data['client_id'] ?? 0));
    if (!$client) throw new RuntimeException('Select a client.');
    $booking_date = travel_valid_date($data['booking_date'] ?? '');
    if ($booking_date === '' || $booking_date > date('Y-m-d')) throw new RuntimeException('Enter a valid booking date (not in the future).');
    $travel_date = travel_valid_date($data['travel_date'] ?? '');
    $return_date = travel_valid_date($data['return_date'] ?? '');
    if ($return_date !== '' && $travel_date !== '' && $return_date < $travel_date) throw new RuntimeException('Return date cannot be before the travel date.');

    $price = round((float)($data['price'] ?? 0), 2);
    $cost = round((float)($data['cost'] ?? 0), 2);
    $client_paid = round((float)$client_paid, 2);
    $vendor_paid = round((float)$vendor_paid, 2);
    if (!is_finite($price) || $price <= 0) throw new RuntimeException('Enter the selling price.');
    if (!is_finite($cost) || $cost < 0) throw new RuntimeException('Purchase price cannot be negative.');
    if ($client_paid < 0 || $client_paid > $price) throw new RuntimeException('Received amount must be between 0 and the selling price.');
    if ($vendor_paid < 0 || $vendor_paid > $cost) throw new RuntimeException('Paid to vendor must be between 0 and the purchase price.');

    $vendor_id = (int)($data['vendor_id'] ?? 0);
    $vendor = null;
    if ($vendor_id > 0) {
        $vendor = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, supplier_name FROM suppliers WHERE id={$vendor_id} AND user_id={$user_id}"));
        if (!$vendor) throw new RuntimeException('Select a valid vendor.');
    }
    if ($cost > 0 && !$vendor) throw new RuntimeException('Select the vendor for the purchase price.');
    if ($client_paid > 0) travel_require_wallet($conn, $user_id, $client_wallet);
    if ($vendor_paid > 0) travel_require_wallet($conn, $user_id, $vendor_wallet);

    $clean_passengers = [];
    foreach ($passengers as [$name, $passport, $ticket]) {
        $name = mb_substr(trim((string)$name), 0, 150);
        if ($name === '') continue;
        $clean_passengers[] = [$name, mb_substr(trim((string)$passport), 0, 40), mb_substr(trim((string)$ticket), 0, 60)];
    }
    if ($kind === 'air_ticket' && !$clean_passengers) throw new RuntimeException('Add at least one passenger.');

    $text = static fn($key, $max) => mb_substr(trim((string)($data[$key] ?? '')), 0, $max);
    $airline = $text('airline', 100);
    $pnr = strtoupper($text('pnr', 40));
    $route = strtoupper($text('route', 150));
    $trip_type = in_array($data['trip_type'] ?? '', ['one_way', 'round_trip', 'multi_city'], true) ? $data['trip_type'] : '';
    $details = $text('details', 2000);
    $note = $text('note', 500);
    $project_id = (int)($data['project_id'] ?? 0);
    $package_id = (int)($data['package_id'] ?? 0);
    $by_id = (int)($_SESSION['login_user_id'] ?? 0);
    $by_name = mb_substr(trim((string)($_SESSION['login_name'] ?? '')), 0, 150);
    $kind_label = travel_service_kinds()[$kind];

    mysqli_begin_transaction($conn);
    try {
        $booking_no = travel_unique_no($conn, $user_id, 'travel_bookings', 'booking_no', 'TB');

        // Vendor bill: a purchases row, so the vendor ledger and due payment pages see it.
        $purchase_id = 0;
        if ($vendor && $cost > 0) {
            $due = round($cost - $vendor_paid, 2);
            $status = $due <= 0 ? 'paid' : ($vendor_paid > 0 ? 'partial' : 'due');
            $purchase_note = $kind_label . ' booking ' . $booking_no . ' · ' . $client['customer_name'] . ($pnr !== '' ? ' · PNR ' . $pnr : '');
            $vendor_wallet_id = $vendor_paid > 0 ? (int)$vendor_wallet : 0;
            $stmt = mysqli_prepare($conn, 'INSERT INTO purchases (user_id, branch_id, purchase_no, supplier_id, payment_wallet_id, purchase_date, total_amount, paid_amount, due_amount, payment_status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'iisiisdddss', $user_id, $branch_id, $booking_no, $vendor_id, $vendor_wallet_id, $booking_date, $cost, $vendor_paid, $due, $status, $purchase_note);
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Vendor bill could not be saved.');
            $purchase_id = (int)mysqli_insert_id($conn);
            if ($vendor_paid > 0) {
                debit_wallet($conn, (int)$vendor_wallet, $user_id, $vendor_paid);
                record_wallet_transaction($conn, $booking_no, $user_id, (int)$vendor_wallet, 'purchase', $purchase_id, $vendor_paid, supplier_display_text('Supplier Payment - ') . $booking_no, $booking_date, $branch_id);
                record_supplier_payment_expense($conn, $user_id, (int)$vendor_wallet, $vendor_paid, $booking_date, supplier_display_text('Supplier Payment - ') . $booking_no, 'purchase_payment', $purchase_id);
            }
        }

        $stmt = mysqli_prepare($conn, 'INSERT INTO travel_bookings (user_id, branch_id, booking_no, request_key, service_kind, project_id, package_id, client_id, booking_date, travel_date, return_date, airline, pnr, route, trip_type, details, vendor_id, purchase_id, cost, price, note, created_by_user_id, created_by_name) VALUES (?,?,?,?,?,?,?,?,?,NULLIF(?,\'\'),NULLIF(?,\'\'),?,?,?,?,?,?,?,?,?,?,?,?)');
        $client_id = (int)$client['id'];
        mysqli_stmt_bind_param($stmt, 'iisssiiissssssssiiddsis', $user_id, $branch_id, $booking_no, $request_key, $kind, $project_id, $package_id, $client_id, $booking_date, $travel_date, $return_date, $airline, $pnr, $route, $trip_type, $details, $vendor_id, $purchase_id, $cost, $price, $note, $by_id, $by_name);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Booking could not be saved.');
        $booking_id = (int)mysqli_insert_id($conn);

        $passenger_stmt = mysqli_prepare($conn, 'INSERT INTO travel_booking_passengers (booking_id, passenger_name, passport_no, ticket_no) VALUES (?,?,?,?)');
        foreach ($clean_passengers as [$name, $passport, $ticket]) {
            mysqli_stmt_bind_param($passenger_stmt, 'isss', $booking_id, $name, $passport, $ticket);
            mysqli_stmt_execute($passenger_stmt);
        }

        // Hajj / Umrah / Tour group seat, checked with the group row locked.
        $group_id = in_array($kind, ['hajj_umrah', 'tour'], true) ? (int)($data['group_id'] ?? 0) : 0;
        if ($group_id > 0) {
            $group = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, seats, status FROM travel_groups WHERE id={$group_id} AND user_id={$user_id} FOR UPDATE"));
            if (!$group) throw new RuntimeException('Select a valid group.');
            if ($group['status'] !== 'open') throw new RuntimeException('This group is closed for booking.');
            $seats_left = (int)$group['seats'] - travel_group_seats_used($conn, $user_id, $group_id, $booking_id);
            if ((int)$group['seats'] > 0 && count($clean_passengers) > $seats_left) throw new RuntimeException('Only ' . max(0, $seats_left) . ' seat(s) left in this group.');
            mysqli_query($conn, "UPDATE travel_bookings SET group_id={$group_id} WHERE id={$booking_id}");
        }
        // Commission to the agent who brought the booking.
        $commission = round((float)($data['commission'] ?? 0), 2);
        if (!is_finite($commission) || $commission < 0) throw new RuntimeException('Commission cannot be negative.');
        if ($commission > 0) {
            $agent = travel_client($conn, $user_id, (int)($data['commission_agent_id'] ?? 0));
            if (!$agent) throw new RuntimeException('Select the commission agent.');
            if ($commission > $price) throw new RuntimeException('Commission cannot be more than the selling price.');
            mysqli_query($conn, 'UPDATE travel_bookings SET commission_agent_id=' . (int)$agent['id'] . ', commission=' . $commission . ' WHERE id=' . $booking_id);
        }
        // Installment plan (2 or more installments).
        if ((int)($data['installment_count'] ?? 0) >= 2) {
            travel_installments_create($conn, $booking_id, $price, (int)$data['installment_count'], (string)($data['installment_first'] ?? ''), (int)($data['installment_every'] ?? 1));
        }
        if ($kind === 'visa') {
            // Visa file details; progress starts at "Documents Received".
            $country = $text('country', 80);
            $visa_type = $text('visa_type', 60);
            $application_no = $text('application_no', 60);
            $expected_date = travel_valid_date($data['expected_date'] ?? '');
            $stmt = mysqli_prepare($conn, "UPDATE travel_bookings SET country=?, visa_type=?, application_no=?, expected_date=NULLIF(?,''), process_status='received' WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'ssssi', $country, $visa_type, $application_no, $expected_date, $booking_id);
            mysqli_stmt_execute($stmt);
            travel_status_log($conn, $user_id, $booking_id, 'received', $booking_date, 'Booking created', '');
        }
        if ($client_paid > 0) {
            travel_client_payment_insert($conn, $user_id, $branch_id, $client_id, [[$booking_id, $client_paid]], (int)$client_wallet, $booking_date, 'Received with booking ' . $booking_no);
        }
        travel_apply_advance($conn, $user_id, $client_id);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    return $booking_id;
}

// One received amount, split over bookings ([[booking_id|null, amount], ...]); one wallet posting.
function travel_client_payment_insert($conn, $user_id, $branch_id, $client_id, array $allocations, $wallet_id, $payment_date, $note)
{
    $total = round(array_sum(array_map(static fn($line) => (float)$line[1], $allocations)), 2);
    if ($total <= 0) {
        return '';
    }
    $payment_no = travel_unique_no($conn, $user_id, 'travel_client_payments', 'payment_no', 'TP');
    $by_id = (int)($_SESSION['login_user_id'] ?? 0);
    $note = mb_substr(trim((string)$note), 0, 500);
    $stmt = mysqli_prepare($conn, 'INSERT INTO travel_client_payments (user_id, branch_id, payment_no, client_id, booking_id, wallet_id, amount, payment_date, note, created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $first_id = 0;
    foreach ($allocations as [$booking_id, $amount]) {
        $amount = round((float)$amount, 2);
        if ($amount <= 0) continue;
        $booking_id = $booking_id ? (int)$booking_id : null;
        mysqli_stmt_bind_param($stmt, 'iisiiidssi', $user_id, $branch_id, $payment_no, $client_id, $booking_id, $wallet_id, $amount, $payment_date, $note, $by_id);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Payment could not be saved.');
        $first_id = $first_id ?: (int)mysqli_insert_id($conn);
    }
    credit_wallet($conn, $wallet_id, $user_id, $total);
    record_wallet_transaction($conn, $payment_no, $user_id, $wallet_id, 'travel_payment', $first_id, $total, 'Client payment ' . $payment_no . ($note !== '' ? ' · ' . $note : ''), $payment_date, $branch_id);
    return $payment_no;
}

// Received from a client: first to the chosen booking, then oldest due bookings; the rest is advance.
function travel_receive_payment($conn, $user_id, $branch_id, $client_id, $amount, $wallet_id, $payment_date, $note, $booking_id = 0)
{
    $user_id = (int)$user_id;
    $client = travel_client($conn, $user_id, $client_id);
    if (!$client) throw new RuntimeException('Select a client.');
    $amount = round((float)$amount, 2);
    if (!is_finite($amount) || $amount <= 0) throw new RuntimeException('Enter the received amount.');
    $payment_date = travel_valid_date($payment_date);
    if ($payment_date === '' || $payment_date > date('Y-m-d')) throw new RuntimeException('Enter a valid payment date (not in the future).');
    travel_require_wallet($conn, $user_id, $wallet_id);

    mysqli_begin_transaction($conn);
    try {
        $dues = travel_booking_dues($conn, $user_id, (int)$client['id'], true);
        $booking_id = (int)$booking_id;
        if ($booking_id > 0) {
            if (!isset($dues[$booking_id])) throw new RuntimeException('This booking has no due.');
            $dues = [$booking_id => $dues[$booking_id]] + $dues;
        }
        $allocations = [];
        $left = $amount;
        foreach ($dues as $id => $due) {
            if ($left <= 0) break;
            $part = min($left, $due);
            $allocations[] = [$id, $part];
            $left = round($left - $part, 2);
        }
        if ($left > 0) {
            $allocations[] = [null, $left];
        }
        $payment_no = travel_client_payment_insert($conn, $user_id, (int)$branch_id, (int)$client['id'], $allocations, (int)$wallet_id, $payment_date, $note);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    return $payment_no;
}

// A client's advance (payments not tied to a booking) pays their due bookings, oldest first.
// Only the booking link changes; the money was already received into a wallet. Call inside a transaction.
function travel_apply_advance($conn, $user_id, $client_id)
{
    $user_id = (int)$user_id;
    $client_id = (int)$client_id;
    $advances = mysqli_query($conn, "SELECT id, payment_no, branch_id, wallet_id, amount, payment_date, note, created_by_user_id FROM travel_client_payments
        WHERE user_id={$user_id} AND client_id={$client_id} AND booking_id IS NULL ORDER BY payment_date, id FOR UPDATE");
    $rows = [];
    $net_advance = 0.0;
    while ($advances && ($row = mysqli_fetch_assoc($advances))) {
        $net_advance += (float)$row['amount'];
        if ((float)$row['amount'] > 0) {
            $rows[] = $row;
        }
    }
    // Money already paid back to the client (negative rows) is not advance any more.
    $net_advance = round($net_advance, 2);
    if (!$rows || $net_advance <= 0) {
        return;
    }
    $dues = travel_booking_dues($conn, $user_id, $client_id, true);
    foreach ($rows as $row) {
        $left = round(min((float)$row['amount'], $net_advance), 2);
        if ($left <= 0) break;
        $net_advance = round($net_advance - $left, 2);
        $row_left = round((float)$row['amount'], 2);
        foreach ($dues as $booking_id => $due) {
            if ($left <= 0 || $due <= 0) continue;
            $part = round(min($left, $due), 2);
            $row_left = round($row_left - $part, 2);
            if ($row_left <= 0) {
                // The whole (remaining) row goes to this booking.
                mysqli_query($conn, "UPDATE travel_client_payments SET booking_id={$booking_id} WHERE id=" . (int)$row['id']);
            } else {
                // Split: this part goes to the booking, the rest stays as advance.
                mysqli_query($conn, "UPDATE travel_client_payments SET amount=amount-{$part} WHERE id=" . (int)$row['id']);
                $stmt = mysqli_prepare($conn, 'INSERT INTO travel_client_payments (user_id, branch_id, payment_no, client_id, booking_id, wallet_id, amount, payment_date, note, created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $branch = (int)$row['branch_id'];
                $wallet = (int)$row['wallet_id'];
                $by = (int)$row['created_by_user_id'];
                mysqli_stmt_bind_param($stmt, 'iisiiidssi', $user_id, $branch, $row['payment_no'], $client_id, $booking_id, $wallet, $part, $row['payment_date'], $row['note'], $by);
                mysqli_stmt_execute($stmt);
            }
            $dues[$booking_id] = round($due - $part, 2);
            $left = round($left - $part, 2);
        }
    }
}

// [booking_id => due] for a client's active bookings, oldest first (locked when $lock).
function travel_booking_dues($conn, $user_id, $client_id, $lock = false)
{
    $dues = [];
    $result = mysqli_query($conn, "SELECT b.id, " . travel_effective_price_sql() . " - COALESCE((SELECT SUM(p.amount) FROM travel_client_payments p WHERE p.booking_id=b.id), 0) AS due
        FROM travel_bookings b WHERE b.user_id=" . (int)$user_id . " AND b.client_id=" . (int)$client_id . "
        ORDER BY b.booking_date, b.id" . ($lock ? ' FOR UPDATE' : ''));
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        if ((float)$row['due'] > 0.004) {
            $dues[(int)$row['id']] = round((float)$row['due'], 2);
        }
    }
    return $dues;
}

// Client balance: what the client owes (negative = advance with us).
function travel_client_balance($conn, $user_id, $client_id)
{
    $user_id = (int)$user_id;
    $client_id = (int)$client_id;
    $sold = mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(" . travel_effective_price_sql() . "),0) FROM travel_bookings b WHERE b.user_id={$user_id} AND b.client_id={$client_id}"));
    $paid = mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) FROM travel_client_payments WHERE user_id={$user_id} AND client_id={$client_id}"));
    return round((float)$sold[0] - (float)$paid[0], 2);
}

// Ledger rows for a client: bookings (debit) and payments (credit), with running balance.
function travel_client_ledger($conn, $user_id, $client_id, $from = '', $to = '')
{
    $user_id = (int)$user_id;
    $client_id = (int)$client_id;
    $rows = [];
    $bookings = mysqli_query($conn, "SELECT id, booking_no, booking_date, service_kind, route, pnr, price FROM travel_bookings WHERE user_id={$user_id} AND client_id={$client_id}");
    while ($bookings && ($b = mysqli_fetch_assoc($bookings))) {
        $label = travel_service_kinds()[$b['service_kind']] ?? 'Booking';
        $rows[] = ['date' => $b['booking_date'], 'sort' => 1, 'id' => (int)$b['id'], 'type' => $label, 'reference' => $b['booking_no'], 'detail' => trim($b['route'] . ($b['pnr'] !== '' ? ' · PNR ' . $b['pnr'] : '')), 'debit' => (float)$b['price'], 'credit' => 0.0];
    }
    // Refund / Void lower the sale (credit); a Reissue charge raises it (debit).
    $adjustments = mysqli_query($conn, "SELECT a.id, a.adjust_no, a.kind, a.adjust_date, a.client_credit, a.extra_price, a.details, b.booking_no FROM travel_booking_adjustments a INNER JOIN travel_bookings b ON b.id=a.booking_id WHERE a.user_id={$user_id} AND b.client_id={$client_id}");
    while ($adjustments && ($a = mysqli_fetch_assoc($adjustments))) {
        $rows[] = ['date' => $a['adjust_date'], 'sort' => 2, 'id' => (int)$a['id'], 'type' => travel_adjustment_kinds()[$a['kind']] ?? 'Adjustment', 'reference' => $a['adjust_no'], 'detail' => trim($a['booking_no'] . ($a['details'] !== '' ? ' · ' . $a['details'] : '')), 'debit' => (float)$a['extra_price'], 'credit' => (float)$a['client_credit']];
    }
    // Money paid back to the client is stored as a negative payment.
    $payments = mysqli_query($conn, "SELECT payment_no, MIN(id) AS id, payment_date, SUM(amount) AS amount, MAX(note) AS note FROM travel_client_payments WHERE user_id={$user_id} AND client_id={$client_id} GROUP BY payment_no, payment_date");
    while ($payments && ($p = mysqli_fetch_assoc($payments))) {
        $amount = (float)$p['amount'];
        $rows[] = ['date' => $p['payment_date'], 'sort' => 3, 'id' => (int)$p['id'], 'type' => $amount < 0 ? 'Refund Paid' : 'Payment', 'reference' => $p['payment_no'], 'detail' => (string)$p['note'], 'debit' => $amount < 0 ? -$amount : 0.0, 'credit' => $amount > 0 ? $amount : 0.0];
    }
    usort($rows, static fn($a, $b) => [$a['date'], $a['sort'], $a['id']] <=> [$b['date'], $b['sort'], $b['id']]);
    $balance = 0.0;
    $opening = 0.0;
    $visible = [];
    foreach ($rows as $row) {
        $balance = round($balance + $row['debit'] - $row['credit'], 2);
        $row['balance'] = $balance;
        if ($from !== '' && $row['date'] < $from) {
            $opening = $balance;
            continue;
        }
        if ($to !== '' && $row['date'] > $to) continue;
        $visible[] = $row;
    }
    return ['opening' => $opening, 'rows' => $visible, 'closing' => $balance];
}

function travel_booking_get($conn, $user_id, $booking_id)
{
    $booking = mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.*, s.supplier_name AS vendor_name, pr.project_name, pk.package_name,
            COALESCE((SELECT SUM(p.amount) FROM travel_client_payments p WHERE p.booking_id=b.id), 0) AS paid
        FROM travel_bookings b
        LEFT JOIN suppliers s ON s.id=b.vendor_id AND s.user_id=b.user_id
        LEFT JOIN projects pr ON pr.id=b.project_id AND pr.user_id=b.user_id
        LEFT JOIN packages pk ON pk.id=b.package_id AND pk.user_id=b.user_id
        WHERE b.id=" . (int)$booking_id . " AND b.user_id=" . (int)$user_id));
    if (!$booking) {
        return null;
    }
    $booking['client'] = travel_client($conn, $user_id, (int)$booking['client_id']);
    $booking['passengers'] = [];
    $result = mysqli_query($conn, 'SELECT passenger_name, passport_no, ticket_no FROM travel_booking_passengers WHERE booking_id=' . (int)$booking['id'] . ' ORDER BY id');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $booking['passengers'][] = $row;
    }
    $booking['net_price'] = round((float)$booking['price'] + (float)$booking['extra_price'] - (float)$booking['client_credit'], 2);
    $booking['net_cost'] = round((float)$booking['cost'] + (float)$booking['extra_cost'] - (float)$booking['cost_credit'], 2);
    $booking['due'] = round($booking['net_price'] - (float)$booking['paid'], 2);
    $booking['net_commission'] = round((float)$booking['commission'] - (float)$booking['commission_credit'], 2);
    $booking['commission_agent'] = (int)$booking['commission_agent_id'] > 0 ? travel_client($conn, $user_id, (int)$booking['commission_agent_id']) : null;
    $booking['adjustments'] = [];
    $result = mysqli_query($conn, 'SELECT * FROM travel_booking_adjustments WHERE booking_id=' . (int)$booking['id'] . ' ORDER BY id');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $booking['adjustments'][] = $row;
    }
    return $booking;
}

function travel_adjustment_kinds()
{
    return ['refund' => 'Refund', 'void' => 'Void', 'reissue' => 'Reissue'];
}

function travel_adjustment_existing($conn, $user_id, $request_key)
{
    if (!preg_match('/^[a-f0-9]{32,64}$/', (string)$request_key)) {
        throw new RuntimeException('Invalid request. Reload the page and try again.');
    }
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM travel_booking_adjustments WHERE user_id=" . (int)$user_id . " AND request_key='" . mysqli_real_escape_string($conn, $request_key) . "'"));
    return $row ? (int)$row['id'] : 0;
}

function travel_adjustment_insert($conn, $user_id, $booking_id, $kind, $request_key, $date, array $values)
{
    $prefix = ['refund' => 'RF', 'void' => 'VD', 'reissue' => 'RI'][$kind];
    $adjust_no = travel_unique_no($conn, $user_id, 'travel_booking_adjustments', 'adjust_no', $prefix);
    $v = array_merge(['client_charge' => 0, 'client_credit' => 0, 'extra_price' => 0, 'vendor_charge' => 0, 'cost_credit' => 0, 'extra_cost' => 0, 'vendor_id' => 0, 'purchase_id' => 0, 'purchase_return_id' => 0, 'vendor_cash' => 0, 'client_cash' => 0, 'details' => '', 'note' => ''], $values);
    $details = mb_substr((string)$v['details'], 0, 500);
    $note = mb_substr(trim((string)$v['note']), 0, 500);
    $by_name = mb_substr(trim((string)($_SESSION['login_name'] ?? '')), 0, 150);
    $stmt = mysqli_prepare($conn, 'INSERT INTO travel_booking_adjustments (user_id, booking_id, adjust_no, request_key, kind, adjust_date, client_charge, client_credit, extra_price, vendor_charge, cost_credit, extra_cost, vendor_id, purchase_id, purchase_return_id, vendor_cash, client_cash, details, note, created_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    mysqli_stmt_bind_param($stmt, 'iissssddddddiiiddsss', $user_id, $booking_id, $adjust_no, $request_key, $kind, $date,
        $v['client_charge'], $v['client_credit'], $v['extra_price'], $v['vendor_charge'], $v['cost_credit'], $v['extra_cost'],
        $v['vendor_id'], $v['purchase_id'], $v['purchase_return_id'], $v['vendor_cash'], $v['client_cash'], $details, $note, $by_name);
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Adjustment could not be saved.');
    return [(int)mysqli_insert_id($conn), $adjust_no];
}

/**
 * Refund or Void of a booking.
 * $vendor_refund: what the vendor gives back (purchase price minus the vendor's charge). It first
 *   lowers what we still owe on the booking's vendor bill; the rest comes back as cash into
 *   $vendor_wallet (a purchase return, shown as "Return" on the vendor ledger).
 * $client_refund: what the client gets back (selling price minus our charge). It first lowers the
 *   booking due; anything the client paid above the new total becomes advance, of which $pay_back
 *   is paid out now from $client_wallet.
 * $full: the whole booking is refunded/void (status changes); a partial refund keeps it active.
 */
function travel_booking_refund($conn, $user_id, $booking_id, $kind, $date, $vendor_refund, $client_refund, $vendor_wallet, $pay_back, $client_wallet, $full, $note, $request_key)
{
    $user_id = (int)$user_id;
    if (!in_array($kind, ['refund', 'void'], true)) throw new RuntimeException('Unknown adjustment.');
    if ($existing = travel_adjustment_existing($conn, $user_id, $request_key)) return $existing;
    $date = travel_valid_date($date);
    if ($date === '' || $date > date('Y-m-d')) throw new RuntimeException('Enter a valid date (not in the future).');
    $cost_credit = round((float)$vendor_refund, 2);
    $client_credit = round((float)$client_refund, 2);
    $pay_back = round((float)$pay_back, 2);
    $note = mb_substr(trim((string)$note), 0, 500);

    mysqli_begin_transaction($conn);
    try {
        $booking = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM travel_bookings WHERE id=' . (int)$booking_id . " AND user_id={$user_id} FOR UPDATE"));
        if (!$booking) throw new RuntimeException('Booking not found.');
        if ($booking['status'] !== 'active') throw new RuntimeException('This booking is already ' . $booking['status'] . '.');
        $net_price = round((float)$booking['price'] + (float)$booking['extra_price'] - (float)$booking['client_credit'], 2);
        $net_cost = round((float)$booking['cost'] + (float)$booking['extra_cost'] - (float)$booking['cost_credit'], 2);
        if (!is_finite($cost_credit) || $cost_credit < 0 || $cost_credit > $net_cost) throw new RuntimeException('Refund from vendor must be between 0 and ' . number_format($net_cost, 2) . '.');
        if (!is_finite($client_credit) || $client_credit < 0 || $client_credit > $net_price) throw new RuntimeException('Refund to client must be between 0 and ' . number_format($net_price, 2) . '.');
        if ($cost_credit <= 0 && $client_credit <= 0 && !$full) throw new RuntimeException('Enter a refund amount.');
        $new_price = round($net_price - $client_credit, 2);
        $paid = (float)mysqli_fetch_row(mysqli_query($conn, 'SELECT COALESCE(SUM(amount),0) FROM travel_client_payments WHERE booking_id=' . (int)$booking['id']))[0];
        $excess = round(max(0, $paid - $new_price), 2);
        if ($pay_back < 0 || $pay_back > $excess) throw new RuntimeException('Pay back can be at most ' . number_format($excess, 2) . ' (what the client paid above the new total).');
        if ($pay_back > 0) travel_require_wallet($conn, $user_id, $client_wallet);

        // Vendor side: the refund first clears what we still owe on this booking's bills from
        // the same vendor (the booking bill, then reissue bills); the rest comes back in cash.
        // Each bill touched gets its own purchase return, so the vendor ledger shows each one.
        $purchase_return_id = 0;
        $vendor_cash = 0.0;
        if ($cost_credit > 0) {
            if ((int)$booking['purchase_id'] <= 0) throw new RuntimeException('This booking has no vendor bill to refund.');
            $bills = travel_booking_vendor_bills($conn, $user_id, $booking, true);
            if (!$bills) throw new RuntimeException('The vendor bill of this booking was not found.');
            $left = $cost_credit;
            $settle = [];
            foreach ($bills as $bill) {
                $part = round(min($left, max(0, (float)$bill['due_amount'])), 2);
                if ($part > 0) {
                    $settle[] = [$bill, $part, 0.0];
                    $left = round($left - $part, 2);
                }
            }
            $vendor_cash = $left;
            if ($vendor_cash > 0) {
                travel_require_wallet($conn, $user_id, $vendor_wallet);
                $settle[] = [$bills[0], 0.0, $vendor_cash];
            }
            $return_note = travel_adjustment_kinds()[$kind] . ' of booking ' . $booking['booking_no'] . ($note !== '' ? ' · ' . $note : '');
            $by_id = (int)($_SESSION['login_user_id'] ?? 0);
            $by_name = mb_substr(trim((string)($_SESSION['login_name'] ?? '')), 0, 150);
            $branch = (int)$booking['branch_id'];
            foreach ($settle as [$bill, $due_part, $cash]) {
                $return_no = generate_short_unique_txn_no($conn, 'PRT', 'purchase_returns', 'return_no');
                $purchase_id = (int)$bill['id'];
                $supplier_id = (int)$bill['supplier_id'];
                $total = round($due_part + $cash, 2);
                $return_wallet = $cash > 0 ? (int)$vendor_wallet : 0;
                $stmt = mysqli_prepare($conn, 'INSERT INTO purchase_returns (user_id, branch_id, purchase_id, supplier_id, return_no, return_date, total_amount, due_adjusted, cash_refund, wallet_id, note, actor_id, actor_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                mysqli_stmt_bind_param($stmt, 'iiiissdddisis', $user_id, $branch, $purchase_id, $supplier_id, $return_no, $date, $total, $due_part, $cash, $return_wallet, $return_note, $by_id, $by_name);
                if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Vendor refund could not be saved.');
                $return_id = (int)mysqli_insert_id($conn);
                $purchase_return_id = $purchase_return_id ?: $return_id;
                if ($due_part > 0) {
                    $new_due = max(0, round((float)$bill['due_amount'] - $due_part, 2));
                    $status = $new_due <= 0 ? 'paid' : ((float)$bill['paid_amount'] > 0 ? 'partial' : 'due');
                    mysqli_query($conn, "UPDATE purchases SET due_amount={$new_due}, payment_status='{$status}' WHERE id={$purchase_id}");
                }
                if ($cash > 0) {
                    credit_wallet($conn, (int)$vendor_wallet, $user_id, $cash);
                    record_wallet_transaction($conn, $return_no, $user_id, (int)$vendor_wallet, 'purchase_return', $return_id, $cash, supplier_display_text('Vendor refund - ') . $return_note, $date, $branch);
                }
            }
        }

        // Booking and history.
        $status = $full ? ($kind === 'void' ? 'void' : 'refunded') : 'active';
        // A full refund / void cancels the agent's commission (a partial refund keeps it).
        $commission_back = $full ? round((float)$booking['commission'] - (float)$booking['commission_credit'], 2) : 0.0;
        mysqli_query($conn, "UPDATE travel_bookings SET client_credit=client_credit+{$client_credit}, cost_credit=cost_credit+{$cost_credit}, commission_credit=commission_credit+{$commission_back}, status='{$status}' WHERE id=" . (int)$booking['id']);
        [$adjust_id, $adjust_no] = travel_adjustment_insert($conn, $user_id, (int)$booking['id'], $kind, $request_key, $date, [
            'client_charge' => $new_price, 'client_credit' => $client_credit,
            'vendor_charge' => round($net_cost - $cost_credit, 2), 'cost_credit' => $cost_credit,
            'vendor_id' => (int)$booking['vendor_id'], 'purchase_id' => (int)$booking['purchase_id'], 'purchase_return_id' => $purchase_return_id,
            'vendor_cash' => $vendor_cash, 'client_cash' => $pay_back,
            'details' => ($full ? 'Full ' : 'Partial ') . strtolower(travel_adjustment_kinds()[$kind]) . ($commission_back > 0 ? ', commission ' . number_format($commission_back, 2) . ' cancelled' : ''), 'note' => $note,
        ]);
        if ($commission_back > 0) {
            mysqli_query($conn, 'UPDATE travel_booking_adjustments SET commission_change=-' . $commission_back . ' WHERE id=' . (int)$adjust_id);
        }

        // Client side: what was paid above the new total leaves the booking as advance.
        if ($excess > 0) {
            travel_keep_on_booking($conn, $user_id, (int)$booking['id'], (int)$booking['client_id'], $new_price);
        }
        if ($pay_back > 0) {
            travel_client_payout($conn, $user_id, (int)$booking['branch_id'], (int)$booking['client_id'], $pay_back, (int)$client_wallet, $date, $adjust_no . ' · ' . $booking['booking_no']);
        }
        travel_apply_advance($conn, $user_id, (int)$booking['client_id']);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    return $adjust_id;
}

// The booking's vendor bills from its own vendor: the booking bill first, then reissue bills.
function travel_booking_vendor_bills($conn, $user_id, $booking, $lock = false)
{
    $ids = [(int)$booking['purchase_id']];
    $result = mysqli_query($conn, "SELECT purchase_id FROM travel_booking_adjustments WHERE booking_id=" . (int)$booking['id'] . " AND kind='reissue' AND purchase_id>0 ORDER BY id");
    while ($result && ($row = mysqli_fetch_row($result))) {
        $ids[] = (int)$row[0];
    }
    $ids = array_values(array_filter(array_unique($ids)));
    if (!$ids) {
        return [];
    }
    $bills = [];
    $result = mysqli_query($conn, 'SELECT * FROM purchases WHERE user_id=' . (int)$user_id . ' AND id IN (' . implode(',', $ids) . ') AND supplier_id=' . (int)$booking['vendor_id'] . ' ORDER BY FIELD(id,' . implode(',', $ids) . ')' . ($lock ? ' FOR UPDATE' : ''));
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $bills[] = $row;
    }
    return $bills;
}

// Leave exactly $keep of a booking's payments on it; the rest becomes the client's advance.
function travel_keep_on_booking($conn, $user_id, $booking_id, $client_id, $keep)
{
    $left = round((float)$keep, 2);
    $result = mysqli_query($conn, 'SELECT * FROM travel_client_payments WHERE booking_id=' . (int)$booking_id . ' AND user_id=' . (int)$user_id . ' AND amount>0 ORDER BY id FOR UPDATE');
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    foreach ($rows as $row) {
        $amount = round((float)$row['amount'], 2);
        if ($left >= $amount) {
            $left = round($left - $amount, 2);
            continue;
        }
        if ($left > 0) {
            // Split: $left stays on the booking, the rest becomes advance.
            mysqli_query($conn, "UPDATE travel_client_payments SET amount={$left} WHERE id=" . (int)$row['id']);
            $rest = round($amount - $left, 2);
            $stmt = mysqli_prepare($conn, 'INSERT INTO travel_client_payments (user_id, branch_id, payment_no, client_id, booking_id, wallet_id, amount, payment_date, note, created_by_user_id) VALUES (?,?,?,?,NULL,?,?,?,?,?)');
            $uid = (int)$user_id;
            $branch = (int)$row['branch_id'];
            $cid = (int)$client_id;
            $wallet = (int)$row['wallet_id'];
            $by = (int)$row['created_by_user_id'];
            mysqli_stmt_bind_param($stmt, 'iisiidssi', $uid, $branch, $row['payment_no'], $cid, $wallet, $rest, $row['payment_date'], $row['note'], $by);
            mysqli_stmt_execute($stmt);
            $left = 0;
        } else {
            mysqli_query($conn, 'UPDATE travel_client_payments SET booking_id=NULL WHERE id=' . (int)$row['id']);
        }
    }
}

// Money paid back to a client from their advance: a negative payment row and a wallet debit.
function travel_client_payout($conn, $user_id, $branch_id, $client_id, $amount, $wallet_id, $date, $note)
{
    $amount = round((float)$amount, 2);
    $payment_no = travel_unique_no($conn, $user_id, 'travel_client_payments', 'payment_no', 'TR');
    $by_id = (int)($_SESSION['login_user_id'] ?? 0);
    $negative = -$amount;
    $note = mb_substr('Refund paid · ' . $note, 0, 500);
    $stmt = mysqli_prepare($conn, 'INSERT INTO travel_client_payments (user_id, branch_id, payment_no, client_id, booking_id, wallet_id, amount, payment_date, note, created_by_user_id) VALUES (?,?,?,?,NULL,?,?,?,?,?)');
    mysqli_stmt_bind_param($stmt, 'iisiidssi', $user_id, $branch_id, $payment_no, $client_id, $wallet_id, $negative, $date, $note, $by_id);
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Refund payment could not be saved.');
    $payout_id = (int)mysqli_insert_id($conn);
    debit_wallet($conn, $wallet_id, $user_id, $amount);
    record_wallet_transaction($conn, $payment_no, $user_id, $wallet_id, 'travel_refund', $payout_id, $amount, $note, $date, $branch_id);
    return $payment_no;
}

/**
 * Reissue (date / route change): the vendor's extra cost becomes a new vendor bill, the client's
 * extra charge is added to the booking. New dates, PNR and ticket numbers replace the old ones.
 */
function travel_booking_reissue($conn, $user_id, $booking_id, $date, $extra_cost, $vendor_id, $vendor_paid, $vendor_wallet, $extra_price, $client_paid, $client_wallet, array $changes, $note, $request_key)
{
    $user_id = (int)$user_id;
    if ($existing = travel_adjustment_existing($conn, $user_id, $request_key)) return $existing;
    $date = travel_valid_date($date);
    if ($date === '' || $date > date('Y-m-d')) throw new RuntimeException('Enter a valid date (not in the future).');
    $extra_cost = round((float)$extra_cost, 2);
    $extra_price = round((float)$extra_price, 2);
    $vendor_paid = round((float)$vendor_paid, 2);
    $client_paid = round((float)$client_paid, 2);
    $note = mb_substr(trim((string)$note), 0, 500);
    if ($extra_cost < 0 || $extra_price < 0) throw new RuntimeException('Charges cannot be negative.');
    if ($vendor_paid < 0 || $vendor_paid > $extra_cost) throw new RuntimeException('Paid to vendor must be between 0 and the vendor charge.');
    if ($client_paid < 0 || $client_paid > $extra_price) throw new RuntimeException('Received must be between 0 and the client charge.');
    $travel_date = travel_valid_date($changes['travel_date'] ?? '');
    $return_date = travel_valid_date($changes['return_date'] ?? '');
    if ($travel_date !== '' && $return_date !== '' && $return_date < $travel_date) throw new RuntimeException('Return date cannot be before the travel date.');

    mysqli_begin_transaction($conn);
    try {
        $booking = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM travel_bookings WHERE id=' . (int)$booking_id . " AND user_id={$user_id} FOR UPDATE"));
        if (!$booking) throw new RuntimeException('Booking not found.');
        if ($booking['status'] !== 'active') throw new RuntimeException('This booking is already ' . $booking['status'] . '.');
        $vendor_id = (int)$vendor_id ?: (int)$booking['vendor_id'];
        $purchase_id = 0;
        $count = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM travel_booking_adjustments WHERE booking_id=" . (int)$booking['id'] . " AND kind='reissue'"))[0];
        if ($extra_cost > 0) {
            $vendor = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM suppliers WHERE id={$vendor_id} AND user_id={$user_id}"));
            if (!$vendor) throw new RuntimeException('Select the vendor for the reissue charge.');
            if ($vendor_paid > 0) travel_require_wallet($conn, $user_id, $vendor_wallet);
            $bill_no = $booking['booking_no'] . '-R' . ($count + 1);
            $due = round($extra_cost - $vendor_paid, 2);
            $status = $due <= 0 ? 'paid' : ($vendor_paid > 0 ? 'partial' : 'due');
            $bill_note = 'Reissue of booking ' . $booking['booking_no'] . ($note !== '' ? ' · ' . $note : '');
            $wallet_for_bill = $vendor_paid > 0 ? (int)$vendor_wallet : 0;
            $branch = (int)$booking['branch_id'];
            $stmt = mysqli_prepare($conn, 'INSERT INTO purchases (user_id, branch_id, purchase_no, supplier_id, payment_wallet_id, purchase_date, total_amount, paid_amount, due_amount, payment_status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'iisiisdddss', $user_id, $branch, $bill_no, $vendor_id, $wallet_for_bill, $date, $extra_cost, $vendor_paid, $due, $status, $bill_note);
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Vendor bill could not be saved.');
            $purchase_id = (int)mysqli_insert_id($conn);
            if ($vendor_paid > 0) {
                debit_wallet($conn, (int)$vendor_wallet, $user_id, $vendor_paid);
                record_wallet_transaction($conn, $bill_no, $user_id, (int)$vendor_wallet, 'purchase', $purchase_id, $vendor_paid, supplier_display_text('Supplier Payment - ') . $bill_no, $date, $branch);
                record_supplier_payment_expense($conn, $user_id, (int)$vendor_wallet, $vendor_paid, $date, supplier_display_text('Supplier Payment - ') . $bill_no, 'purchase_payment', $purchase_id);
            }
        }
        if ($client_paid > 0) travel_require_wallet($conn, $user_id, $client_wallet);

        // New dates, PNR and ticket numbers; old values are kept in the reissue history.
        $set = ["extra_price=extra_price+{$extra_price}", "extra_cost=extra_cost+{$extra_cost}"];
        $history = [];
        foreach (['travel_date' => $travel_date, 'return_date' => $return_date] as $column => $value) {
            if ($value !== '' && $value !== (string)$booking[$column]) {
                $set[] = "{$column}='{$value}'";
                $history[] = ($column === 'travel_date' ? 'Departure ' : 'Return ') . ($booking[$column] ?: '—') . ' → ' . $value;
            }
        }
        $pnr = strtoupper(mb_substr(trim((string)($changes['pnr'] ?? '')), 0, 40));
        if ($pnr !== '' && $pnr !== $booking['pnr']) {
            $set[] = "pnr='" . mysqli_real_escape_string($conn, $pnr) . "'";
            $history[] = 'PNR ' . ($booking['pnr'] ?: '—') . ' → ' . $pnr;
        }
        mysqli_query($conn, 'UPDATE travel_bookings SET ' . implode(', ', $set) . ' WHERE id=' . (int)$booking['id']);
        foreach ((array)($changes['tickets'] ?? []) as $passenger_id => $ticket) {
            $ticket = mb_substr(trim((string)$ticket), 0, 60);
            if ($ticket === '') continue;
            $stmt = mysqli_prepare($conn, 'UPDATE travel_booking_passengers SET ticket_no=? WHERE id=? AND booking_id=?');
            $pid = (int)$passenger_id;
            $bid = (int)$booking['id'];
            mysqli_stmt_bind_param($stmt, 'sii', $ticket, $pid, $bid);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) > 0) $history[] = 'Ticket ' . $ticket;
        }

        [$adjust_id, $adjust_no] = travel_adjustment_insert($conn, $user_id, (int)$booking['id'], 'reissue', $request_key, $date, [
            'extra_price' => $extra_price, 'extra_cost' => $extra_cost, 'vendor_id' => $extra_cost > 0 ? $vendor_id : 0,
            'purchase_id' => $purchase_id, 'vendor_cash' => $vendor_paid, 'client_cash' => $client_paid,
            'details' => $history ? implode('; ', $history) : 'Reissue', 'note' => $note,
        ]);
        if ($client_paid > 0) {
            travel_client_payment_insert($conn, $user_id, (int)$booking['branch_id'], (int)$booking['client_id'], [[(int)$booking['id'], $client_paid]], (int)$client_wallet, $date, 'Reissue ' . $adjust_no);
        }
        travel_apply_advance($conn, $user_id, (int)$booking['client_id']);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    return $adjust_id;
}

// Visa progress, in order. "rejected" ends the file like "delivered".
function travel_visa_statuses()
{
    return [
        'received' => 'Documents Received',
        'submitted' => 'Submitted to Embassy',
        'approved' => 'Visa Approved',
        'rejected' => 'Visa Rejected',
        'delivered' => 'Passport Delivered',
    ];
}

// The usual next step of a visa file, preselected in the update form.
function travel_visa_next_status($status)
{
    return ['received' => 'submitted', 'submitted' => 'approved', 'approved' => 'delivered', 'rejected' => 'delivered'][$status ?: 'received'] ?? '';
}

function travel_visa_status_badge($status)
{
    return ['received' => 'secondary', 'submitted' => 'info', 'approved' => 'success', 'rejected' => 'danger', 'delivered' => 'primary'][$status] ?? 'light';
}

function travel_status_log($conn, $user_id, $booking_id, $status, $date, $note, $sms_status)
{
    $by_name = mb_substr(trim((string)($_SESSION['login_name'] ?? '')), 0, 150);
    $note = mb_substr(trim((string)$note), 0, 500);
    $stmt = mysqli_prepare($conn, 'INSERT INTO travel_booking_status_log (user_id, booking_id, status, status_date, note, sms_status, created_by_name) VALUES (?,?,?,?,?,?,?)');
    mysqli_stmt_bind_param($stmt, 'iisssss', $user_id, $booking_id, $status, $date, $note, $sms_status, $by_name);
    mysqli_stmt_execute($stmt);
}

// One SMS to a client through the system gateway, counted against the company SMS quota.
// Returns [sent(bool), message].
function travel_send_client_sms($conn, $user_id, $phone, $message)
{
    require_once __DIR__ . '/sms_helper.php';
    $phone = trim((string)$phone);
    if ($phone === '') return [false, 'Client has no phone number.'];
    $token = sms_get_system_api_token($conn);
    if ($token === '') return [false, 'SMS gateway is not configured.'];
    $parts = max(1, (int)sms_estimate_message_parts($message));
    $quota = sms_get_user_quota($conn, $user_id);
    if ($parts > (int)$quota['remaining']) return [false, 'SMS quota is finished (' . (int)$quota['remaining'] . ' left).'];
    $result = sms_send_bulk_message($token, [$phone], $message);
    $sent = (int)($result['sent'] ?? 0) > 0;
    if ($sent) sms_consume_user_quota($conn, $user_id, $parts);
    sms_record_history($conn, $user_id, 'travel_client', 'single', 1, $sent ? $parts : 0, $sent ? 0 : 1, $message, (string)(($result['raw'] ?? '') ?: ($result['error'] ?? '')));
    return [$sent, $sent ? 'SMS sent.' : 'SMS failed: ' . (string)($result['error'] ?? 'unknown error')];
}

function travel_visa_sms_text($conn, $user_id, $booking, $status, $note)
{
    $company = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT name FROM users WHERE id=' . (int)$user_id));
    $client = travel_client($conn, $user_id, (int)$booking['client_id']);
    $what = trim(($booking['country'] !== '' ? $booking['country'] . ' ' : '') . 'visa');
    $text = 'Dear ' . ($client['customer_name'] ?? 'Client') . ', your ' . $what . ' (' . $booking['booking_no'] . ') status: ' . (travel_visa_statuses()[$status] ?? $status) . '.';
    if ($status === 'approved') $text .= ' Please collect your passport.';
    if (trim((string)$note) !== '') $text .= ' ' . trim((string)$note);
    return $text . ' - ' . ($company['name'] ?? '');
}

// Moves a visa file to a new status; optionally tells the client by SMS. Returns the SMS result text.
function travel_visa_update_status($conn, $user_id, $booking_id, $status, $date, $note, $send_sms)
{
    $statuses = travel_visa_statuses();
    if (!isset($statuses[$status])) throw new RuntimeException('Select a status.');
    $date = travel_valid_date($date);
    if ($date === '' || $date > date('Y-m-d')) throw new RuntimeException('Enter a valid date (not in the future).');
    $booking = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM travel_bookings WHERE id=' . (int)$booking_id . ' AND user_id=' . (int)$user_id));
    if (!$booking || $booking['service_kind'] !== 'visa') throw new RuntimeException('Visa file not found.');
    if ($booking['status'] !== 'active') throw new RuntimeException('This booking is ' . $booking['status'] . '.');
    if ($booking['process_status'] === $status) throw new RuntimeException('The file is already "' . $statuses[$status] . '".');
    $sms_result = '';
    $sms_status = '';
    if ($send_sms) {
        $client = travel_client($conn, $user_id, (int)$booking['client_id']);
        [$sent, $sms_result] = travel_send_client_sms($conn, $user_id, $client['phone'] ?? '', travel_visa_sms_text($conn, $user_id, $booking, $status, $note));
        $sms_status = $sent ? 'sent' : 'failed';
    }
    $stmt = mysqli_prepare($conn, 'UPDATE travel_bookings SET process_status=? WHERE id=? AND user_id=?');
    $id = (int)$booking['id'];
    $uid = (int)$user_id;
    mysqli_stmt_bind_param($stmt, 'sii', $status, $id, $uid);
    mysqli_stmt_execute($stmt);
    travel_status_log($conn, $user_id, $id, $status, $date, $note, $sms_status);
    return $sms_result;
}

function travel_status_history($conn, $booking_id)
{
    $rows = [];
    $result = mysqli_query($conn, 'SELECT * FROM travel_booking_status_log WHERE booking_id=' . (int)$booking_id . ' ORDER BY id');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    return $rows;
}

// Seats taken in a group: passengers of its bookings that are not refunded or void.
function travel_group_seats_used($conn, $user_id, $group_id, $exclude_booking = 0)
{
    $row = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM travel_booking_passengers p INNER JOIN travel_bookings b ON b.id=p.booking_id
        WHERE b.user_id=" . (int)$user_id . " AND b.group_id=" . (int)$group_id . " AND b.status='active' AND b.id<>" . (int)$exclude_booking));
    return (int)($row[0] ?? 0);
}

function travel_group_get($conn, $user_id, $group_id)
{
    $group = mysqli_fetch_assoc(mysqli_query($conn, "SELECT g.*, s.supplier_name AS vendor_name, pk.package_name FROM travel_groups g
        LEFT JOIN suppliers s ON s.id=g.vendor_id AND s.user_id=g.user_id
        LEFT JOIN packages pk ON pk.id=g.package_id AND pk.user_id=g.user_id
        WHERE g.id=" . (int)$group_id . " AND g.user_id=" . (int)$user_id));
    if ($group) {
        $group['seats_used'] = travel_group_seats_used($conn, $user_id, (int)$group['id']);
        $group['seats_left'] = max(0, (int)$group['seats'] - $group['seats_used']);
    }
    return $group;
}

function travel_groups($conn, $user_id, $open_only = false)
{
    $groups = [];
    $result = mysqli_query($conn, "SELECT id FROM travel_groups WHERE user_id=" . (int)$user_id . ($open_only ? " AND status='open'" : '') . " ORDER BY (departure_date IS NULL), departure_date DESC, id DESC");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $groups[] = travel_group_get($conn, $user_id, (int)$row['id']);
    }
    return $groups;
}

// Creates or updates a group. Returns its id.
function travel_group_save($conn, $user_id, array $data, $group_id = 0)
{
    $user_id = (int)$user_id;
    $group_id = (int)$group_id;
    $name = mb_substr(trim((string)($data['group_name'] ?? '')), 0, 150);
    if ($name === '') throw new RuntimeException('Enter the group name.');
    $kind = in_array($data['service_kind'] ?? '', ['hajj_umrah', 'tour'], true) ? $data['service_kind'] : 'hajj_umrah';
    $departure = travel_valid_date($data['departure_date'] ?? '');
    $return = travel_valid_date($data['return_date'] ?? '');
    if ($departure !== '' && $return !== '' && $return < $departure) throw new RuntimeException('Return date cannot be before the departure date.');
    $seats = (int)($data['seats'] ?? 0);
    if ($seats < 0) throw new RuntimeException('Seats cannot be negative.');
    if ($group_id > 0 && $seats > 0 && $seats < travel_group_seats_used($conn, $user_id, $group_id)) throw new RuntimeException('More passengers are already booked than these seats.');
    $price = round((float)($data['price_per_person'] ?? 0), 2);
    $cost = round((float)($data['cost_per_person'] ?? 0), 2);
    if ($price < 0 || $cost < 0) throw new RuntimeException('Prices cannot be negative.');
    $vendor_id = (int)($data['vendor_id'] ?? 0);
    if ($vendor_id > 0 && !mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM suppliers WHERE id={$vendor_id} AND user_id={$user_id}"))) throw new RuntimeException('Select a valid vendor.');
    $package_id = (int)($data['package_id'] ?? 0);
    if ($package_id > 0 && !mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM packages WHERE id={$package_id} AND user_id={$user_id}"))) $package_id = 0;
    $airline = mb_substr(trim((string)($data['airline'] ?? '')), 0, 100);
    $hotel = mb_substr(trim((string)($data['hotel_info'] ?? '')), 0, 300);
    $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 500);
    $status = ($data['status'] ?? 'open') === 'closed' ? 'closed' : 'open';

    if ($group_id > 0) {
        if (!mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM travel_groups WHERE id={$group_id} AND user_id={$user_id}"))) throw new RuntimeException('Group not found.');
        $stmt = mysqli_prepare($conn, "UPDATE travel_groups SET group_name=?, service_kind=?, package_id=?, departure_date=NULLIF(?,''), return_date=NULLIF(?,''), seats=?, price_per_person=?, cost_per_person=?, vendor_id=?, airline=?, hotel_info=?, note=?, status=? WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'ssissiddissssii', $name, $kind, $package_id, $departure, $return, $seats, $price, $cost, $vendor_id, $airline, $hotel, $note, $status, $group_id, $user_id);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Group could not be saved.');
        return $group_id;
    }
    $code = travel_unique_no($conn, $user_id, 'travel_groups', 'group_code', $kind === 'tour' ? 'TG' : 'HG');
    $stmt = mysqli_prepare($conn, "INSERT INTO travel_groups (user_id, group_code, group_name, service_kind, package_id, departure_date, return_date, seats, price_per_person, cost_per_person, vendor_id, airline, hotel_info, note, status) VALUES (?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'isssissiddissss', $user_id, $code, $name, $kind, $package_id, $departure, $return, $seats, $price, $cost, $vendor_id, $airline, $hotel, $note, $status);
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Group could not be saved.');
    return (int)mysqli_insert_id($conn);
}

// Splits $total into $count installments, the first on $first_date, then every $every_months months.
function travel_installments_create($conn, $booking_id, $total, $count, $first_date, $every_months)
{
    $count = (int)$count;
    $total = round((float)$total, 2);
    if ($count < 2) return;
    if ($count > 36) throw new RuntimeException('At most 36 installments.');
    $first_date = travel_valid_date($first_date);
    if ($first_date === '') throw new RuntimeException('Enter the first installment date.');
    $every_months = max(1, min(12, (int)$every_months));
    $part = floor($total / $count * 100) / 100;
    $stmt = mysqli_prepare($conn, 'INSERT INTO travel_booking_installments (booking_id, installment_no, due_date, amount) VALUES (?,?,?,?)');
    for ($i = 1; $i <= $count; $i++) {
        $amount = $i === $count ? round($total - $part * ($count - 1), 2) : $part;
        $due = (new DateTime($first_date))->modify('+' . (($i - 1) * $every_months) . ' month')->format('Y-m-d');
        $bid = (int)$booking_id;
        mysqli_stmt_bind_param($stmt, 'iisd', $bid, $i, $due, $amount);
        mysqli_stmt_execute($stmt);
    }
}

// Installments of a booking with what is paid on each (payments fill them in order).
function travel_installment_schedule($conn, $booking)
{
    $rows = [];
    $paid_left = (float)($booking['paid'] ?? 0);
    $result = mysqli_query($conn, 'SELECT * FROM travel_booking_installments WHERE booking_id=' . (int)$booking['id'] . ' ORDER BY installment_no');
    $today = date('Y-m-d');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $amount = (float)$row['amount'];
        $row['paid'] = round(min($amount, max(0, $paid_left)), 2);
        $paid_left = round($paid_left - $row['paid'], 2);
        $row['due'] = round($amount - $row['paid'], 2);
        $row['state'] = $row['due'] <= 0 ? 'paid' : ($row['due_date'] < $today ? 'overdue' : ($row['paid'] > 0 ? 'partial' : 'upcoming'));
        $rows[] = $row;
    }
    return $rows;
}

// Travel sales by period for dashboards and reports: a booking counts on its booking date;
// a reissue charge adds and a refund/void subtracts on the date it was made.
// $group: 'day' (Y-m-d), 'month' (Y-m) or 'year' (Y). Returns [period => amount].
function travel_sales_by_period($conn, $user_id, $from, $to, $group = 'month', $column = 'sales')
{
    $user_id = (int)$user_id;
    $from = travel_valid_date($from);
    $to = travel_valid_date($to);
    if ($from === '' || $to === '') return [];
    $format = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$group] ?? '%Y-%m';
    [$base, $plus, $minus] = ['cost' => ['cost', 'extra_cost', 'cost_credit'], 'commission' => ['commission', 'commission_change', '0']][$column] ?? ['price', 'extra_price', 'client_credit'];
    $sql = "SELECT period, SUM(amount) AS amount FROM (
            SELECT DATE_FORMAT(booking_date, '{$format}') AS period, {$base} AS amount FROM travel_bookings WHERE user_id={$user_id} AND booking_date BETWEEN '{$from}' AND '{$to}'
            UNION ALL
            SELECT DATE_FORMAT(adjust_date, '{$format}'), {$plus} - {$minus} FROM travel_booking_adjustments WHERE user_id={$user_id} AND adjust_date BETWEEN '{$from}' AND '{$to}'
        ) x GROUP BY period";
    $rows = [];
    $result = mysqli_query($conn, $sql);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[$row['period']] = round((float)$row['amount'], 2);
    }
    return $rows;
}

function travel_sales_total($conn, $user_id, $from, $to, $column = 'sales')
{
    return round(array_sum(travel_sales_by_period($conn, $user_id, $from, $to, 'year', $column)), 2);
}

// Commission still owed to the booking's agent: commission minus what a full refund / void cancelled.
function travel_effective_commission_sql($alias = 'b')
{
    return "({$alias}.commission - {$alias}.commission_credit)";
}

// Agents with commission: earned, paid and payable now.
function travel_commission_agents($conn, $user_id)
{
    $user_id = (int)$user_id;
    $agents = [];
    $result = mysqli_query($conn, "SELECT b.commission_agent_id AS agent_id, SUM(" . travel_effective_commission_sql() . ") AS earned, COUNT(*) AS bookings
        FROM travel_bookings b WHERE b.user_id={$user_id} AND b.commission_agent_id>0 AND b.commission>0 GROUP BY b.commission_agent_id");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $agents[(int)$row['agent_id']] = ['agent_id' => (int)$row['agent_id'], 'earned' => (float)$row['earned'], 'bookings' => (int)$row['bookings'], 'paid' => 0.0];
    }
    $result = mysqli_query($conn, "SELECT agent_id, SUM(amount) AS paid FROM travel_commission_payments WHERE user_id={$user_id} GROUP BY agent_id");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $id = (int)$row['agent_id'];
        $agents[$id] = ($agents[$id] ?? ['agent_id' => $id, 'earned' => 0.0, 'bookings' => 0]) + ['paid' => 0.0];
        $agents[$id]['paid'] = (float)$row['paid'];
    }
    foreach ($agents as $id => &$agent) {
        $agent['client'] = travel_client($conn, $user_id, $id);
        $agent['payable'] = round($agent['earned'] - $agent['paid'], 2);
    }
    unset($agent);
    uasort($agents, static fn($a, $b) => $b['payable'] <=> $a['payable']);
    return $agents;
}

function travel_commission_payable($conn, $user_id, $agent_id)
{
    $user_id = (int)$user_id;
    $agent_id = (int)$agent_id;
    $earned = (float)mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(" . travel_effective_commission_sql() . "),0) FROM travel_bookings b WHERE b.user_id={$user_id} AND b.commission_agent_id={$agent_id}"))[0];
    $paid = (float)mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) FROM travel_commission_payments WHERE user_id={$user_id} AND agent_id={$agent_id}"))[0];
    return round($earned - $paid, 2);
}

// Agent commission ledger: earned (credit), cancelled by refund (debit), paid (debit); balance = payable.
function travel_commission_ledger($conn, $user_id, $agent_id)
{
    $user_id = (int)$user_id;
    $agent_id = (int)$agent_id;
    $rows = [];
    $result = mysqli_query($conn, "SELECT b.id, b.booking_no, b.booking_date, b.service_kind, b.route, b.commission, c.customer_name
        FROM travel_bookings b LEFT JOIN customers c ON c.id=b.client_id AND c.user_id=b.user_id
        WHERE b.user_id={$user_id} AND b.commission_agent_id={$agent_id} AND b.commission>0");
    while ($result && ($b = mysqli_fetch_assoc($result))) {
        $rows[] = ['date' => $b['booking_date'], 'sort' => 1, 'id' => (int)$b['id'], 'type' => 'Commission', 'reference' => $b['booking_no'], 'booking_id' => (int)$b['id'],
            'detail' => trim((travel_service_kinds()[$b['service_kind']] ?? '') . ' · ' . $b['customer_name'] . ($b['route'] !== '' ? ' · ' . $b['route'] : '')), 'earned' => (float)$b['commission'], 'paid' => 0.0];
    }
    $result = mysqli_query($conn, "SELECT a.id, a.adjust_no, a.adjust_date, a.commission_change, b.booking_no, b.id AS booking_id FROM travel_booking_adjustments a INNER JOIN travel_bookings b ON b.id=a.booking_id
        WHERE a.user_id={$user_id} AND b.commission_agent_id={$agent_id} AND a.commission_change<>0");
    while ($result && ($a = mysqli_fetch_assoc($result))) {
        $rows[] = ['date' => $a['adjust_date'], 'sort' => 2, 'id' => (int)$a['id'], 'type' => 'Cancelled (refund)', 'reference' => $a['adjust_no'], 'booking_id' => (int)$a['booking_id'],
            'detail' => $a['booking_no'], 'earned' => (float)$a['commission_change'], 'paid' => 0.0];
    }
    $result = mysqli_query($conn, "SELECT * FROM travel_commission_payments WHERE user_id={$user_id} AND agent_id={$agent_id}");
    while ($result && ($p = mysqli_fetch_assoc($result))) {
        $rows[] = ['date' => $p['payment_date'], 'sort' => 3, 'id' => (int)$p['id'], 'type' => $p['method'] === 'adjust' ? 'Adjusted with due' : 'Paid', 'reference' => $p['payment_no'], 'booking_id' => 0,
            'detail' => $p['note'], 'earned' => 0.0, 'paid' => (float)$p['amount']];
    }
    usort($rows, static fn($a, $b) => [$a['date'], $a['sort'], $a['id']] <=> [$b['date'], $b['sort'], $b['id']]);
    $balance = 0.0;
    foreach ($rows as &$row) {
        $balance = round($balance + $row['earned'] - $row['paid'], 2);
        $row['balance'] = $balance;
    }
    unset($row);
    return $rows;
}

/**
 * Pays an agent's commission. 'cash': out of a wallet. 'adjust': set against what the agent
 * owes us as a client (their own bookings' due), so no money moves.
 */
function travel_commission_pay($conn, $user_id, $branch_id, $agent_id, $amount, $method, $wallet_id, $date, $note, $request_key)
{
    $user_id = (int)$user_id;
    $agent_id = (int)$agent_id;
    if (!preg_match('/^[a-f0-9]{32,64}$/', (string)$request_key)) throw new RuntimeException('Invalid request. Reload the page and try again.');
    $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT payment_no FROM travel_commission_payments WHERE user_id={$user_id} AND request_key='" . mysqli_real_escape_string($conn, $request_key) . "'"));
    if ($existing) return $existing['payment_no'];
    $agent = travel_client($conn, $user_id, $agent_id);
    if (!$agent) throw new RuntimeException('Select an agent.');
    $amount = round((float)$amount, 2);
    $method = $method === 'adjust' ? 'adjust' : 'cash';
    $date = travel_valid_date($date);
    $note = mb_substr(trim((string)$note), 0, 500);
    if ($date === '' || $date > date('Y-m-d')) throw new RuntimeException('Enter a valid date (not in the future).');
    if (!is_finite($amount) || $amount <= 0) throw new RuntimeException('Enter the amount.');

    mysqli_begin_transaction($conn);
    try {
        // Lock the agent's commission bookings so two payments cannot both pass the check.
        mysqli_query($conn, "SELECT id FROM travel_bookings WHERE user_id={$user_id} AND commission_agent_id={$agent_id} FOR UPDATE");
        $payable = travel_commission_payable($conn, $user_id, $agent_id);
        if ($amount > $payable) throw new RuntimeException('Commission payable is only ' . number_format(max(0, $payable), 2) . '.');
        $payment_no = travel_unique_no($conn, $user_id, 'travel_commission_payments', 'payment_no', 'TC');
        if ($method === 'adjust') {
            $client_due = travel_client_balance($conn, $user_id, $agent_id);
            if ($amount > $client_due) throw new RuntimeException('The agent owes only ' . number_format(max(0, $client_due), 2) . ' on their own bookings.');
            travel_client_credit_insert($conn, $user_id, (int)$branch_id, $agent_id, $amount, $date, 'Commission ' . $payment_no . ' adjusted');
            $wallet_id = 0;
        } else {
            travel_require_wallet($conn, $user_id, $wallet_id);
            debit_wallet($conn, (int)$wallet_id, $user_id, $amount);
        }
        $by_name = mb_substr(trim((string)($_SESSION['login_name'] ?? '')), 0, 150);
        $stmt = mysqli_prepare($conn, 'INSERT INTO travel_commission_payments (user_id, branch_id, payment_no, request_key, agent_id, amount, method, wallet_id, payment_date, note, created_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $branch = (int)$branch_id;
        $wallet = (int)$wallet_id;
        mysqli_stmt_bind_param($stmt, 'iissidsisss', $user_id, $branch, $payment_no, $request_key, $agent_id, $amount, $method, $wallet, $date, $note, $by_name);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Commission payment could not be saved.');
        $payment_id = (int)mysqli_insert_id($conn);
        if ($method === 'cash') {
            record_wallet_transaction($conn, $payment_no, $user_id, (int)$wallet_id, 'travel_commission', $payment_id, $amount, 'Agent commission ' . $payment_no . ' · ' . $agent['customer_name'] . ($note !== '' ? ' · ' . $note : ''), $date, $branch);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    return $payment_no;
}

// Credit to a client without money moving (commission set against their due): fills their
// oldest due bookings like a payment; wallet 0 marks it as an adjustment.
function travel_client_credit_insert($conn, $user_id, $branch_id, $client_id, $amount, $date, $note)
{
    $payment_no = travel_unique_no($conn, $user_id, 'travel_client_payments', 'payment_no', 'TA');
    $by_id = (int)($_SESSION['login_user_id'] ?? 0);
    $left = round((float)$amount, 2);
    $stmt = mysqli_prepare($conn, 'INSERT INTO travel_client_payments (user_id, branch_id, payment_no, client_id, booking_id, wallet_id, amount, payment_date, note, created_by_user_id) VALUES (?,?,?,?,?,0,?,?,?,?)');
    foreach (travel_booking_dues($conn, $user_id, $client_id, true) as $booking_id => $due) {
        if ($left <= 0) break;
        $part = round(min($left, $due), 2);
        $bid = (int)$booking_id;
        mysqli_stmt_bind_param($stmt, 'iisiidssi', $user_id, $branch_id, $payment_no, $client_id, $bid, $part, $date, $note, $by_id);
        mysqli_stmt_execute($stmt);
        $left = round($left - $part, 2);
    }
    if ($left > 0) {
        // Due on bookings is lower than the client balance only when an advance exists; keep it unlinked.
        $null = null;
        mysqli_stmt_bind_param($stmt, 'iisiidssi', $user_id, $branch_id, $payment_no, $client_id, $null, $left, $date, $note, $by_id);
        mysqli_stmt_execute($stmt);
    }
    return $payment_no;
}
