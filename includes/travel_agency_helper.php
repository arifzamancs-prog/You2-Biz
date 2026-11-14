<?php
// Travel Agency company type: travel names for the shared modules, and the
// starting setup a travel company gets once (service types, client passport
// fields, Dummy Air Ticket).

function travel_agency_active()
{
    return ($_SESSION['company_type'] ?? '') === 'Travel Agency';
}

// Menu names per company type (General Service, Travel Agency).
function company_menu_label($label)
{
    if (($_SESSION['company_type'] ?? '') === 'Service type') {
        // General Service: the sales menu is simply "Invoice".
        return $label === 'Sales / Invoice' ? 'Invoice' : $label;
    }
    if (!travel_agency_active()) {
        return $label;
    }
    static $names = [
        'Sales / Invoice' => 'Booking & Invoice',
        'Customer Manage' => 'Client Manage',
        'Create Customer' => 'Create Client',
        'Cus. form settings' => 'Client Form Settings',
        'Cus. Login Settings' => 'Client Login Settings',
    ];
    return $names[$label] ?? $label;
}

// Booking & Invoice menu of a travel company: the travel booking pages.
function travel_booking_menu_items()
{
    return [
        ['href' => app_path('travel/booking_create.php'), 'label' => 'New Booking'],
        ['href' => app_path('travel/bookings.php'), 'label' => 'Booking List'],
        ['href' => app_path('travel/receive_payment.php'), 'label' => 'Receive Payment'],
        ['href' => app_path('travel/client_ledger.php'), 'label' => 'Client Ledger'],
        ['href' => app_path('travel/visa_tracker.php'), 'label' => 'Visa Tracker'],
        ['href' => app_path('travel/groups.php'), 'label' => 'Hajj / Umrah Groups'],
        ['href' => app_path('travel/commissions.php'), 'label' => 'Agent Commission'],
        ['href' => app_path('travel/reminders.php'), 'label' => 'SMS Reminders'],
    ];
}

// Service & Category is the travel company's list of services.
function travel_agency_project_package_labels()
{
    return [
        'module' => 'Travel Services',
        'project' => 'Service Type',
        'project_list' => 'Service Type List',
        'project_name' => 'Service Type Name',
        'project_add' => 'Add Service Type',
        'project_save' => 'Save Service Type',
        'project_update' => 'Update Service Type',
        'project_select' => 'Select Service Type',
        'project_empty' => 'No service type found yet.',
        'package' => 'Package',
        'package_list' => 'Package List',
        'package_name' => 'Package Name',
        'package_add' => 'Add Package',
        'package_save' => 'Save Package',
        'package_update' => 'Update Package',
        'package_empty' => 'No package found yet.',
    ];
}

// Service types and packages a new travel company starts with (all editable).
function travel_agency_default_services()
{
    return [
        ['TA-AIR', 'Air Ticket', [['TA-AIR-DOM', 'Domestic Air Ticket'], ['TA-AIR-INT', 'International Air Ticket']]],
        ['TA-VISA', 'Visa Processing', [['TA-VISA-TOUR', 'Tourist Visa'], ['TA-VISA-BUS', 'Business Visa'], ['TA-VISA-WORK', 'Work Visa'], ['TA-VISA-STU', 'Student Visa']]],
        ['TA-HAJJ', 'Hajj & Umrah', [['TA-HAJJ-PKG', 'Hajj Package'], ['TA-UMRAH-PKG', 'Umrah Package']]],
        ['TA-TOUR', 'Tour Package', [['TA-TOUR-DOM', 'Domestic Tour'], ['TA-TOUR-INT', 'International Tour']]],
        ['TA-HOTEL', 'Hotel Booking', [['TA-HOTEL-BOOK', 'Hotel Booking']]],
    ];
}

// Client form fields for travellers and B2B agents (added as normal, editable fields).
function travel_agency_client_fields()
{
    return [
        ['client_type', 'Client Type', 'dropdown', "Individual (B2C)\nAgent (B2B)\nCorporate", 1, 25],
        ['passport_no', 'Passport No', 'text', '', 0, 61],
        ['passport_expiry', 'Passport Expiry', 'calendar', '', 0, 62],
        ['date_of_birth', 'Date of Birth', 'calendar', '', 0, 63],
        ['nationality', 'Nationality', 'text', '', 0, 64],
        ['passport_copy', 'Passport Copy', 'photo', '', 0, 65],
    ];
}

function travel_agency_setup_table($conn)
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_agency_setup (
            company_id INT NOT NULL PRIMARY KEY,
            done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    return $ready;
}

// Runs once per travel company. Run outside a transaction (it may create tables).
function travel_agency_setup_company($conn, $company_id)
{
    $company_id = (int)$company_id;
    if ($company_id <= 0 || !travel_agency_setup_table($conn)) {
        return;
    }
    if (mysqli_fetch_assoc(mysqli_query($conn, "SELECT company_id FROM travel_agency_setup WHERE company_id={$company_id}"))) {
        return;
    }
    $company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT company_type FROM users WHERE id={$company_id} AND role='admin'"));
    if (!$company || normalize_company_type($company['company_type'] ?? '') !== 'Travel Agency') {
        return;
    }

    // 1. Service types and packages, only for a company that has none yet.
    require_once __DIR__ . '/project_package_helper.php';
    ensure_project_package_tables($conn);
    $has_services = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM projects WHERE user_id={$company_id} LIMIT 1"));
    if (!$has_services) {
        $project_stmt = mysqli_prepare($conn, "INSERT IGNORE INTO projects (user_id, project_name, project_code, description, status) VALUES (?, ?, ?, '', 'active')");
        $package_stmt = mysqli_prepare($conn, "INSERT IGNORE INTO packages (user_id, project_id, package_name, package_code, price, description, status) VALUES (?, ?, ?, ?, 0, '', 'active')");
        foreach (travel_agency_default_services() as [$project_code, $project_name, $packages]) {
            mysqli_stmt_bind_param($project_stmt, 'iss', $company_id, $project_name, $project_code);
            mysqli_stmt_execute($project_stmt);
            $project_id = (int)mysqli_insert_id($conn);
            if ($project_id <= 0) {
                continue;
            }
            foreach ($packages as [$package_code, $package_name]) {
                mysqli_stmt_bind_param($package_stmt, 'iiss', $company_id, $project_id, $package_name, $package_code);
                mysqli_stmt_execute($package_stmt);
            }
        }
    }

    // 2. Client passport fields on the client form.
    require_once __DIR__ . '/customer_form_helper.php';
    customer_form_seed_system_fields($conn, $company_id);
    $field_stmt = mysqli_prepare($conn, "INSERT IGNORE INTO customer_form_fields (user_id, field_key, label, field_type, options, is_required, is_system, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'active')");
    foreach (travel_agency_client_fields() as [$key, $label, $type, $options, $required, $sort]) {
        mysqli_stmt_bind_param($field_stmt, 'issssii', $company_id, $key, $label, $type, $options, $required, $sort);
        mysqli_stmt_execute($field_stmt);
    }
    mysqli_query($conn, "UPDATE customer_form_fields SET label=CASE field_key WHEN 'customer_code' THEN 'Client ID' ELSE 'Client Name' END
        WHERE user_id={$company_id} AND field_key IN ('customer_code','customer_name') AND label IN ('Customer ID','Customer Name')");

    // 3. Dummy Air Ticket (flight itinerary for visa files) is on for a new travel company.
    require_once __DIR__ . '/air_ticket_helper.php';
    ensure_air_ticket_column($conn);
    mysqli_query($conn, "UPDATE users SET air_ticket_enabled=1 WHERE id={$company_id} AND role='admin'");

    mysqli_query($conn, "INSERT IGNORE INTO travel_agency_setup (company_id) VALUES ({$company_id})");
}

// Travel agencies call their customers "clients". Changes the word in visible page text and in
// placeholder / title attributes only; markup, scripts, form names and values stay as they are.
function travel_client_terms($text)
{
    static $words = ['Customers' => 'Clients', 'Customer' => 'Client', 'customers' => 'clients', 'customer' => 'client', 'CUSTOMERS' => 'CLIENTS', 'CUSTOMER' => 'CLIENT'];
    $changed = preg_replace_callback('/\b(Customers|Customer|customers|customer|CUSTOMERS|CUSTOMER)\b/', static fn($m) => $words[$m[1]], $text);
    return $changed === null ? $text : str_replace('Cus. ', 'Client ', $changed);
}

function travel_client_terms_html($html)
{
    $changed = preg_replace_callback('#(<(script|style|textarea)\b.*?</\2\s*>)|(<[^>]+>)|([^<]+)#is', static function ($m) {
        if (($m[1] ?? '') !== '') {
            return $m[1];
        }
        if (($m[3] ?? '') !== '') {
            return preg_replace_callback('/\b(placeholder|title|aria-label|data-original-title)="([^"]*)"/i', static fn($a) => $a[1] . '="' . travel_client_terms($a[2]) . '"', $m[3]) ?? $m[3];
        }
        return travel_client_terms($m[4]);
    }, $html);
    return $changed === null ? $html : $changed;
}
