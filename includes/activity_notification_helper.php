<?php
/*
|----------------------------------
| Activity notifications (bell icon)
|----------------------------------
| A sales invoice, an E-shop order or a purchase adds one notification for the company.
| Everyone who may see that branch gets it in the bell until they click it; the click
| marks it read for that person only and opens the record.
*/

// Creates the tables. Call outside a transaction: CREATE TABLE would commit it.
function activity_notification_schema($conn)
{
    static $done = false;
    if($done){
        return;
    }
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS activity_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        branch_id BIGINT NOT NULL DEFAULT 0,
        type VARCHAR(30) NOT NULL,
        reference_id INT NOT NULL DEFAULT 0,
        title VARCHAR(190) NOT NULL,
        meta VARCHAR(255) NOT NULL DEFAULT '',
        url VARCHAR(255) NOT NULL,
        created_by_user_id INT NOT NULL DEFAULT 0,
        created_by_name VARCHAR(150) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY company_latest (user_id, id),
        KEY company_branch (user_id, branch_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS activity_notification_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notification_id INT NOT NULL,
        reader_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY one_read (notification_id, reader_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// The person viewing: a staff/manager login, or the company admin.
function activity_notification_reader_id()
{
    return (int)($_SESSION['login_user_id'] ?? 0) ?: (int)($_SESSION['user_id'] ?? 0);
}

// Adds a notification. Never throws: a notification must not stop a sale or purchase.
// $url is the app-relative page to open, e.g. 'sales/view_invoice.php?id=5'.
function activity_notify($conn, $company_id, $branch_id, $type, $reference_id, $title, $meta, $url)
{
    try{
        activity_notification_schema($conn);
        $stmt = mysqli_prepare($conn, 'INSERT INTO activity_notifications(user_id,branch_id,type,reference_id,title,meta,url,created_by_user_id,created_by_name) VALUES(?,?,?,?,?,?,?,?,?)');
        if(!$stmt){
            return;
        }
        $title = mb_substr((string)$title, 0, 190);
        $meta = mb_substr((string)$meta, 0, 255);
        $creator_id = activity_notification_reader_id();
        $creator_name = mb_substr(trim((string)($_SESSION['login_name'] ?? '')), 0, 150);
        $company_id = (int)$company_id;
        $branch_id = (int)$branch_id;
        $reference_id = (int)$reference_id;
        mysqli_stmt_bind_param($stmt, 'iisisssis', $company_id, $branch_id, $type, $reference_id, $title, $meta, $url, $creator_id, $creator_name);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }catch(Throwable $e){
        error_log('Notification not saved: ' . $e->getMessage());
    }
}

// SQL condition for the branches this viewer may see: the admin sees every branch,
// anyone else only the branch they are working in.
function activity_notification_branch_sql($conn)
{
    if(function_exists('is_admin_user') && is_admin_user() && !(function_exists('is_manager_user') && is_manager_user())){
        return '';
    }
    $branch_id = function_exists('selected_branch_id') ? (int)selected_branch_id($conn, true) : 0;
    return $branch_id > 0 ? ' AND (a.branch_id=0 OR a.branch_id=' . $branch_id . ')' : '';
}

// [count, latest rows] of the viewer's unread notifications from the last 30 days.
function activity_notifications_unread($conn, $company_id, $limit = 10)
{
    activity_notification_schema($conn);
    $company_id = (int)$company_id;
    $reader_id = activity_notification_reader_id();
    $where = "FROM activity_notifications a
              LEFT JOIN activity_notification_reads r ON r.notification_id=a.id AND r.reader_id=?
              WHERE a.user_id=? AND r.id IS NULL AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . activity_notification_branch_sql($conn);
    $count = 0;
    $rows = [];
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) ' . $where);
    if($stmt){
        mysqli_stmt_bind_param($stmt, 'ii', $reader_id, $company_id);
        mysqli_stmt_execute($stmt);
        $count = (int)(mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0] ?? 0);
        mysqli_stmt_close($stmt);
    }
    if($count > 0){
        $stmt = mysqli_prepare($conn, 'SELECT a.* ' . $where . ' ORDER BY a.id DESC LIMIT ' . max(1, (int)$limit));
        if($stmt){
            mysqli_stmt_bind_param($stmt, 'ii', $reader_id, $company_id);
            mysqli_stmt_execute($stmt);
            $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
            mysqli_stmt_close($stmt);
        }
    }
    return [$count, $rows];
}

// Marks one notification (or, with $id = 0, all visible unread ones) read for the viewer.
// Returns the notification's page, or '' when there is none.
function activity_notification_mark_read($conn, $company_id, $id)
{
    activity_notification_schema($conn);
    $company_id = (int)$company_id;
    $reader_id = activity_notification_reader_id();
    if((int)$id > 0){
        $stmt = mysqli_prepare($conn, 'SELECT id,url FROM activity_notifications a WHERE a.id=? AND a.user_id=?' . activity_notification_branch_sql($conn));
        $id = (int)$id;
        mysqli_stmt_bind_param($stmt, 'ii', $id, $company_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if(!$row){
            return '';
        }
        $stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO activity_notification_reads(notification_id,reader_id) VALUES(?,?)');
        mysqli_stmt_bind_param($stmt, 'ii', $id, $reader_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return (string)$row['url'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT IGNORE INTO activity_notification_reads(notification_id,reader_id)
         SELECT a.id, ? FROM activity_notifications a WHERE a.user_id=? AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' . activity_notification_branch_sql($conn)
    );
    mysqli_stmt_bind_param($stmt, 'ii', $reader_id, $company_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return '';
}
