<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';

require_admin_user();

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $printing_option = normalize_printing_option(
        $_POST['printing_option'] ?? 'general'
    );
    $custom_width = $_POST['custom_width'] ?? 8.27;
    $custom_height = $_POST['custom_height'] ?? 11.69;
    $custom_top_margin = $_POST['custom_top_margin'] ?? 0.50;
    $general_top_margin = $_POST['general_top_margin'] ?? 0.50;
    $print_invoice_notes = $_POST['print_invoice_notes'] ?? 'active';
    $print_invoice_created_by = $_POST['print_invoice_created_by'] ?? 'active';
    $print_company_seal = $_POST['print_company_seal'] ?? 'inactive';
    $print_paid_seal = $_POST['print_paid_seal'] ?? 'inactive';
    $print_company_logo = $_POST['print_company_logo'] ?? 'inactive';
    $print_company_profile = $_POST['print_company_profile'] ?? 'active';
    $print_general_top_margin = $_POST['print_general_top_margin'] ?? 'inactive';
    $current_company_seal_file = current_company_seal_file($conn);
    $current_paid_seal_file = current_paid_seal_file($conn);

    [$company_seal_ok, $company_seal_file, $company_seal_changed, $company_seal_error] = printing_upload_file(
        'company_seal_file',
        $current_company_seal_file,
        'company-seal'
    );
    [$paid_seal_ok, $paid_seal_file, $paid_seal_changed, $paid_seal_error] = printing_upload_file(
        'paid_seal_file',
        $current_paid_seal_file,
        'paid-seal'
    );

    if(!$company_seal_ok){
        $message = $company_seal_error;
        $message_type = 'danger';
    }elseif(!$paid_seal_ok){
        $message = $paid_seal_error;
        $message_type = 'danger';
    }else{
        if(save_printing_option(
            $conn,
            $printing_option,
            $custom_width,
            $custom_height,
            $custom_top_margin,
            $print_invoice_notes,
            $print_invoice_created_by,
            $company_seal_file,
            $paid_seal_file,
            $print_company_seal,
            $print_paid_seal,
            $print_company_logo,
            $print_company_profile,
            $general_top_margin,
            $print_general_top_margin
        )){
            $message = 'Printing option updated successfully.';

            if($company_seal_changed || $paid_seal_changed){
                $message .= ' Seal stamp preview and print settings have been updated.';
            }

            $message_type = 'success';
        }else{
            $message = 'Printing option could not be updated.';
            $message_type = 'danger';
        }
    }
}

$current_option = current_printing_option($conn);
$custom_size = current_printing_custom_size($conn);
$custom_top_margin = current_printing_custom_top_margin($conn);
$general_top_margin = current_printing_general_top_margin($conn);
$print_invoice_notes = current_print_invoice_notes_option($conn);
$print_invoice_created_by = current_print_invoice_created_by_option($conn);
$print_company_seal = current_print_company_seal_option($conn);
$print_paid_seal = current_print_paid_seal_option($conn);
$print_company_logo = current_print_company_logo_option($conn);
$print_company_profile = current_print_company_profile_option($conn);
$print_general_top_margin = current_print_general_top_margin_option($conn);
$company_seal_url = current_company_seal_url($conn);
$paid_seal_url = current_paid_seal_url($conn);
$company_logo_url = current_print_company_logo_url($conn);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Printing Option</h3>
            </div>

            <div class="card-body">
                <?php if($message){ ?>
                    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php } ?>

                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Print Style</label>

                        <div class="custom-control custom-radio mb-2">
                            <input
                                type="radio"
                                id="printing_general"
                                name="printing_option"
                                value="general"
                                class="custom-control-input"
                                <?= $current_option === 'general' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="printing_general">
                                General Print
                            </label>
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input
                                type="radio"
                                id="printing_pos"
                                name="printing_option"
                                value="pos"
                                class="custom-control-input"
                                <?= $current_option === 'pos' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="printing_pos">
                                POS Print
                            </label>
                        </div>

                        <div class="custom-control custom-radio">
                            <input
                                type="radio"
                                id="printing_custom"
                                name="printing_option"
                                value="custom"
                                class="custom-control-input"
                                <?= $current_option === 'custom' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="printing_custom">
                                Custom Size
                            </label>
                        </div>
                    </div>

                    <div
                        id="custom_size_fields"
                        class="border rounded p-3 mb-3"
                        style="<?= $current_option === 'custom' ? '' : 'display:none;'; ?>">

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label>Width (Inch)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="1"
                                        name="custom_width"
                                        class="form-control"
                                        value="<?= htmlspecialchars($custom_size['width']); ?>">
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label>Height (Inch)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="1"
                                        name="custom_height"
                                        class="form-control"
                                        value="<?= htmlspecialchars($custom_size['height']); ?>">
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group mb-0">
                                    <label>Top Margin (Inch)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        name="custom_top_margin"
                                        class="form-control"
                                        value="<?= htmlspecialchars($custom_top_margin); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <label>General Print Top Margin</label>
                        <div class="text-muted small mb-3">
                            When enabled, General Print will begin from the specified top margin value in inches.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-0">
                                    <label>Top Margin (Inch)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        name="general_top_margin"
                                        class="form-control"
                                        value="<?= htmlspecialchars($general_top_margin); ?>">
                                </div>
                            </div>

                            <div class="col-md-6 mt-3 mt-md-0">
                                <div class="custom-control custom-radio mb-2">
                                    <input
                                        type="radio"
                                        id="print_general_top_margin_active"
                                        name="print_general_top_margin"
                                        value="active"
                                        class="custom-control-input"
                                        <?= $print_general_top_margin === 'active' ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="print_general_top_margin_active">
                                        Active
                                    </label>
                                </div>

                                <div class="custom-control custom-radio">
                                    <input
                                        type="radio"
                                        id="print_general_top_margin_inactive"
                                        name="print_general_top_margin"
                                        value="inactive"
                                        class="custom-control-input"
                                        <?= $print_general_top_margin === 'inactive' ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="print_general_top_margin_inactive">
                                        Inactive
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <div class="form-group">
                            <label>Invoice Note Print</label>

                            <div class="custom-control custom-radio mb-2">
                                <input
                                    type="radio"
                                    id="invoice_notes_active"
                                    name="print_invoice_notes"
                                    value="active"
                                    class="custom-control-input"
                                    <?= $print_invoice_notes === 'active' ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="invoice_notes_active">
                                    Active
                                </label>
                            </div>

                            <div class="custom-control custom-radio">
                                <input
                                    type="radio"
                                    id="invoice_notes_inactive"
                                    name="print_invoice_notes"
                                    value="inactive"
                                    class="custom-control-input"
                                    <?= $print_invoice_notes === 'inactive' ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="invoice_notes_inactive">
                                    Inactive
                                </label>
                            </div>
                        </div>

                        <div class="form-group mb-0">
                            <label>Print Invoice Created By (Assistant/Manager)</label>

                            <div class="custom-control custom-radio mb-2">
                                <input
                                    type="radio"
                                    id="invoice_created_by_active"
                                    name="print_invoice_created_by"
                                    value="active"
                                    class="custom-control-input"
                                    <?= $print_invoice_created_by === 'active' ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="invoice_created_by_active">
                                    Active
                                </label>
                            </div>

                            <div class="custom-control custom-radio">
                                <input
                                    type="radio"
                                    id="invoice_created_by_inactive"
                                    name="print_invoice_created_by"
                                    value="inactive"
                                    class="custom-control-input"
                                    <?= $print_invoice_created_by === 'inactive' ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="invoice_created_by_inactive">
                                    Inactive
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                            <div>
                                <label class="mb-1">General Print Seal / Stamp</label>
                                <div class="text-muted small">
                                    Company seal and paid seal will be used on General Print only.
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Company Seal Stamp</label>
                                    <input
                                        type="file"
                                        name="company_seal_file"
                                        id="company_seal_file"
                                        class="form-control-file"
                                        accept=".png,.jpg,.jpeg,.webp">
                                    <small class="form-text text-muted">
                                        Recommended transparent PNG for better print quality.
                                    </small>
                                </div>

                                <div class="border rounded p-3 bg-light text-center mb-3">
                                    <img
                                        id="company_seal_preview"
                src="<?= htmlspecialchars($company_seal_url !== '' ? $company_seal_url : '/assets/you2biz-logo.png'); ?>"
                                        alt="Company Seal Preview"
                                        style="max-width: 180px; max-height: 160px; width: auto; <?= $company_seal_url !== '' ? '' : 'opacity:.18;'; ?>">
                                </div>

                                <div class="form-group mb-0">
                                    <label>Print Company Seal</label>

                                    <div class="custom-control custom-radio mb-2">
                                        <input
                                            type="radio"
                                            id="print_company_seal_active"
                                            name="print_company_seal"
                                            value="active"
                                            class="custom-control-input"
                                            <?= $print_company_seal === 'active' ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="print_company_seal_active">
                                            Active
                                        </label>
                                    </div>

                                    <div class="custom-control custom-radio">
                                        <input
                                            type="radio"
                                            id="print_company_seal_inactive"
                                            name="print_company_seal"
                                            value="inactive"
                                            class="custom-control-input"
                                            <?= $print_company_seal === 'inactive' ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="print_company_seal_inactive">
                                            Inactive
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mt-4 mt-md-0">
                                <div class="form-group">
                                    <label>Paid Seal Stamp</label>
                                    <input
                                        type="file"
                                        name="paid_seal_file"
                                        id="paid_seal_file"
                                        class="form-control-file"
                                        accept=".png,.jpg,.jpeg,.webp">
                                    <small class="form-text text-muted">
                                        This will appear on paid invoices in General Print.
                                    </small>
                                </div>

                                <div class="border rounded p-3 bg-light text-center mb-3">
                                    <img
                                        id="paid_seal_preview"
                src="<?= htmlspecialchars($paid_seal_url !== '' ? $paid_seal_url : '/assets/you2biz-logo.png'); ?>"
                                        alt="Paid Seal Preview"
                                        style="max-width: 180px; max-height: 160px; width: auto; <?= $paid_seal_url !== '' ? '' : 'opacity:.18;'; ?>">
                                </div>

                                <div class="form-group mb-0">
                                    <label>Print Paid Seal</label>

                                    <div class="custom-control custom-radio mb-2">
                                        <input
                                            type="radio"
                                            id="print_paid_seal_active"
                                            name="print_paid_seal"
                                            value="active"
                                            class="custom-control-input"
                                            <?= $print_paid_seal === 'active' ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="print_paid_seal_active">
                                            Active
                                        </label>
                                    </div>

                                    <div class="custom-control custom-radio">
                                        <input
                                            type="radio"
                                            id="print_paid_seal_inactive"
                                            name="print_paid_seal"
                                            value="inactive"
                                            class="custom-control-input"
                                            <?= $print_paid_seal === 'inactive' ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="print_paid_seal_inactive">
                                            Inactive
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <label class="mb-1">Company Profile on Sales Print</label>
                        <div class="text-muted small mb-3">
                            Show or hide company name, address, phone and email on sales invoice print.
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input
                                type="radio"
                                id="print_company_profile_active"
                                name="print_company_profile"
                                value="active"
                                class="custom-control-input"
                                <?= $print_company_profile === 'active' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="print_company_profile_active">
                                Active
                            </label>
                        </div>

                        <div class="custom-control custom-radio">
                            <input
                                type="radio"
                                id="print_company_profile_inactive"
                                name="print_company_profile"
                                value="inactive"
                                class="custom-control-input"
                                <?= $print_company_profile === 'inactive' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="print_company_profile_inactive">
                                Inactive
                            </label>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <label class="mb-1">Company Logo Print</label>
                        <div class="text-muted small mb-3">
                            In General Print, the official You2 Biz logo from the super admin branding settings will be used by default. If the user updates their profile photo, that image will be used as the print logo instead.
                        </div>

                        <div class="row align-items-center">
                            <div class="col-md-4 mb-3 mb-md-0">
                                <div class="border rounded p-3 bg-light text-center">
                                    <img
                src="<?= htmlspecialchars($company_logo_url !== '' ? $company_logo_url : '/assets/you2biz-logo.png'); ?>"
                                        alt="Company Logo Preview"
                                        style="max-width: 170px; max-height: 110px; width: auto; opacity: <?= $company_logo_url !== '' ? '.45' : '.12'; ?>;">
                                </div>
                            </div>

                            <div class="col-md-8">
                                <div class="custom-control custom-radio mb-2">
                                    <input
                                        type="radio"
                                        id="print_company_logo_active"
                                        name="print_company_logo"
                                        value="active"
                                        class="custom-control-input"
                                        <?= $print_company_logo === 'active' ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="print_company_logo_active">
                                        Active
                                    </label>
                                </div>

                                <div class="custom-control custom-radio">
                                    <input
                                        type="radio"
                                        id="print_company_logo_inactive"
                                        name="print_company_logo"
                                        value="inactive"
                                        class="custom-control-input"
                                        <?= $print_company_logo === 'inactive' ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="print_company_logo_inactive">
                                        Inactive
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$page_script = '
<script>
$(function(){
    function toggleCustomSizeFields(){
        if($("#printing_custom").is(":checked")){
            $("#custom_size_fields").slideDown(120);
        }else{
            $("#custom_size_fields").slideUp(120);
        }
    }

    function bindImagePreview(inputId, previewId){
        $("#" + inputId).on("change", function(){
            const file = this.files && this.files[0] ? this.files[0] : null;
            const preview = $("#" + previewId);

            if(!file || !preview.length){
                return;
            }

            const reader = new FileReader();

            reader.onload = function(event){
                preview.attr("src", event.target.result);
                preview.css("opacity", "1");
            };

            reader.readAsDataURL(file);
        });
    }

    $("input[name=\"printing_option\"]").on("change", toggleCustomSizeFields);
    toggleCustomSizeFields();
    bindImagePreview("company_seal_file", "company_seal_preview");
    bindImagePreview("paid_seal_file", "paid_seal_preview");
});
</script>
';

require_once '../includes/footer.php';
?>
