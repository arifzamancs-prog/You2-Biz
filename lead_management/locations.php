<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

require_lead_management_access();
ensure_lead_management_table($conn);

if(!is_admin_user()){
    header('Location: index.php?filter=lead');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$message = $_SESSION['lead_location_flash_message'] ?? '';
$message_type = $_SESSION['lead_location_flash_type'] ?? 'success';
unset($_SESSION['lead_location_flash_message'], $_SESSION['lead_location_flash_type']);

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete'){
    $location_id = (int)($_POST['id'] ?? 0);
    $transfer_to_id = (int)($_POST['transfer_to'] ?? 0);

    $find_stmt = mysqli_prepare($conn, 'SELECT id, name FROM lead_locations WHERE id IN (?, ?) AND user_id=?');
    mysqli_stmt_bind_param($find_stmt, 'iii', $location_id, $transfer_to_id, $user_id);
    mysqli_stmt_execute($find_stmt);
    $found = [];
    foreach(mysqli_fetch_all(mysqli_stmt_get_result($find_stmt), MYSQLI_ASSOC) as $row){
        $found[(int)$row['id']] = (string)$row['name'];
    }
    mysqli_stmt_close($find_stmt);

    $flash = ['Location not found.', 'danger'];
    if(isset($found[$location_id]) && $transfer_to_id > 0 && ($transfer_to_id === $location_id || !isset($found[$transfer_to_id]))){
        $flash = ['Select a valid location to transfer the leads to.', 'danger'];
    }elseif(isset($found[$location_id])){
        $old_name = $found[$location_id];
        mysqli_begin_transaction($conn);
        try {
            $moved = 0;
            // Without a transfer, leads keep their saved location text; only the dropdown option is removed.
            if($transfer_to_id > 0){
                $new_name = $found[$transfer_to_id];
                $move_stmt = mysqli_prepare($conn, 'UPDATE leads SET location=? WHERE user_id=? AND location=?');
                mysqli_stmt_bind_param($move_stmt, 'sis', $new_name, $user_id, $old_name);
                mysqli_stmt_execute($move_stmt);
                $moved = mysqli_stmt_affected_rows($move_stmt);
                mysqli_stmt_close($move_stmt);
            }

            $delete_stmt = mysqli_prepare($conn, 'DELETE FROM lead_locations WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($delete_stmt, 'ii', $location_id, $user_id);
            mysqli_stmt_execute($delete_stmt);
            mysqli_stmt_close($delete_stmt);

            mysqli_commit($conn);
            $flash = [
                $transfer_to_id > 0
                    ? 'Location deleted. ' . $moved . ' lead(s) transferred to ' . $found[$transfer_to_id] . '.'
                    : 'Location deleted successfully.',
                'success',
            ];
        }catch(Throwable $exception){
            mysqli_rollback($conn);
            $flash = ['Unable to delete the location.', 'danger'];
        }
    }

    $_SESSION['lead_location_flash_message'] = $flash[0];
    $_SESSION['lead_location_flash_type'] = $flash[1];
    header('Location: locations.php');
    exit;
}

$locations = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT ll.id, ll.name, ll.created_at, u.name AS created_by_name,
            (SELECT COUNT(*) FROM leads l WHERE l.user_id=ll.user_id AND l.location=ll.name) AS lead_count
     FROM lead_locations ll
     LEFT JOIN users u ON u.id=ll.created_by_user_id
     WHERE ll.user_id=?
     ORDER BY ll.name ASC"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while($result && $row = mysqli_fetch_assoc($result)){
    $locations[] = $row;
}
mysqli_stmt_close($stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Lead Locations</h3>
        <div class="card-tools">
            <a href="index.php?filter=lead" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <p class="text-muted">When a location is in use, its leads can be transferred to another location before it is deleted.</p>

        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Location</th>
                    <th width="140">Leads</th>
                    <th width="200">Added By</th>
                    <th width="160">Added On</th>
                    <th width="90">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($locations)){ ?>
                    <tr><td colspan="5" class="text-center text-muted">No location added yet</td></tr>
                <?php } ?>
                <?php foreach($locations as $location){ ?>
                    <tr>
                        <td><?= htmlspecialchars($location['name']); ?></td>
                        <td><?= (int)$location['lead_count']; ?></td>
                        <td><?= htmlspecialchars($location['created_by_name'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($location['created_at'] ? date('d-m-Y', strtotime($location['created_at'])) : '-'); ?></td>
                        <td>
                            <?php if((int)$location['lead_count'] > 0){ ?>
                                <button type="button"
                                        class="btn btn-danger btn-sm lead-location-delete"
                                        data-id="<?= (int)$location['id']; ?>"
                                        data-name="<?= htmlspecialchars($location['name']); ?>"
                                        data-count="<?= (int)$location['lead_count']; ?>"
                                        title="Delete Location"><i class="fas fa-trash"></i></button>
                            <?php } else { ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this location?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$location['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete Location"><i class="fas fa-trash"></i></button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="deleteLocationModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-location-id">
            <div class="modal-header">
                <h5 class="modal-title">Delete Location</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p><strong id="delete-location-count"></strong> lead(s) use <strong id="delete-location-name"></strong>.</p>
                <div class="form-group mb-0">
                    <label>Transfer leads to</label>
                    <select name="transfer_to" id="delete-location-transfer" class="form-control">
                        <option value="0">Don't transfer (leads keep the current location)</option>
                        <?php foreach($locations as $option){ ?>
                            <option value="<?= (int)$option['id']; ?>"><?= htmlspecialchars($option['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Location</button>
            </div>
        </form>
    </div>
</div>

<?php
$page_script = <<<'HTML'
<script>
$(function(){
    $('.lead-location-delete').on('click', function(){
        var button = $(this);
        var id = String(button.data('id'));
        $('#delete-location-id').val(id);
        $('#delete-location-name').text(button.data('name'));
        $('#delete-location-count').text(button.data('count'));
        $('#delete-location-transfer').val('0').find('option').each(function(){
            $(this).prop('hidden', this.value === id).prop('disabled', this.value === id);
        });
        $('#deleteLocationModal').modal('show');
    });
});
</script>
HTML;
require_once '../includes/footer.php';
?>
