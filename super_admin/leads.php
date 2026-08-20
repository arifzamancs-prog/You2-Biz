<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/trial_lead_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

require_super_admin_user();
ensure_trial_leads_table($conn);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $form_action = trim((string)($_POST['form_action'] ?? ''));
    $lead_id = (int)($_POST['lead_id'] ?? 0);

    if($form_action === 'delete_lead' && $lead_id > 0){
        $stmt = mysqli_prepare($conn, "DELETE FROM trial_leads WHERE id=? LIMIT 1");

        if($stmt){
            mysqli_stmt_bind_param($stmt, "i", $lead_id);
            mysqli_stmt_execute($stmt);
        }

        header("Location: " . app_path('super_admin/leads.php'));
        exit;
    }
}

$result = mysqli_query(
    $conn,
    "SELECT *
     FROM trial_leads
     ORDER BY id DESC"
);
?>

<section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1>Leads</h1>
            </div>
            <div class="col-sm-6 text-right">
                <a href="<?= htmlspecialchars(app_path('super_admin/leads_export_excel.php')); ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i>
                    Export Excel
                </a>
                <a href="<?= htmlspecialchars(app_path('super_admin/leads_export_pdf.php')); ?>" class="btn btn-danger btn-sm" target="_blank">
                    <i class="fas fa-file-pdf"></i>
                    Export PDF
                </a>
            </div>
        </div>
    </div>
</section>

<section class="content">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Trial Leads</h3>
            </div>
            <div class="card-body">
                <table id="example1" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Business</th>
                            <th>Source</th>
                            <th>Landing Page</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($result)){ ?>
                            <tr>
                                <td><?= htmlspecialchars(app_datetime($row['created_at'])); ?></td>
                                <td><?= htmlspecialchars($row['full_name']); ?></td>
                                <td><?= htmlspecialchars($row['phone']); ?></td>
                                <td><?= htmlspecialchars($row['business_name']); ?></td>
                                <td>
                                    <?php
                                    $source_bits = array_filter([
                                        trim((string)$row['utm_source']),
                                        trim((string)$row['utm_medium']),
                                        trim((string)$row['utm_campaign'])
                                    ]);
                                    echo htmlspecialchars(empty($source_bits) ? 'Direct / Unknown' : implode(' | ', $source_bits));
                                    ?>
                                </td>
                                <td style="max-width:220px; word-break:break-word;">
                                    <?= htmlspecialchars($row['landing_page']); ?>
                                </td>
                                <td>
                                    <a href="tel:<?= htmlspecialchars($row['phone']); ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-phone"></i>
                                        Call
                                    </a>
                                    <form method="post" class="d-inline-block ml-1" onsubmit="return confirm('Delete this lead permanently?');">
                                        <input type="hidden" name="form_action" value="delete_lead">
                                        <input type="hidden" name="lead_id" value="<?= (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i>
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

</div>

<?php require_once '../includes/footer.php'; ?>
