<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/sidebar_settings_helper.php';

require_admin_user();

$user_id = (int)($_SESSION['user_id'] ?? 0);
$project_package_labels = project_package_labels($conn, $user_id);
ensure_sidebar_settings_table($conn);

$message = '';
$message_type = 'success';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(($_POST['action'] ?? '') === 'reset'){
        $stmt = mysqli_prepare($conn, 'DELETE FROM sidebar_settings WHERE user_id=?');
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        $status = mysqli_stmt_execute($stmt) ? 'reset' : 'error';
    }else{
        $submitted = $_POST['items'] ?? [];
        $items = [];
        foreach($submitted as $id => $item){
            $items[] = [
                'id' => $id,
                'parent' => $item['parent'] ?? '',
                'sort' => $item['sort'] ?? 0,
                'visible' => $item['visible'] ?? 1,
            ];
        }
        $status = sidebar_save_layout($conn, $user_id, $items) ? 'saved' : 'error';
    }
    $_SESSION['sidebar_settings_status'] = $status;
    header('Location: sidebar_settings.php');
    exit;
}

if(isset($_SESSION['sidebar_settings_status'])){
    if($_SESSION['sidebar_settings_status'] === 'saved'){
        $message = 'Sidebar settings saved successfully.';
    }elseif($_SESSION['sidebar_settings_status'] === 'reset'){
        $message = 'Sidebar settings reset successfully.';
    }elseif($_SESSION['sidebar_settings_status'] === 'error'){
        $message = 'Failed to update sidebar settings.';
        $message_type = 'danger';
    }
    unset($_SESSION['sidebar_settings_status']);
}

$items = sidebar_layout_items_for_current_user(sidebar_load_layout($conn, $user_id, $project_package_labels));
$item_map = sidebar_setting_map_by_id($items);
$children_by_parent = [];
foreach($items as $item){
    $children_by_parent[$item['parent'] ?? ''][] = $item;
}
$sidebar_sections = ['MAIN', 'OPERATIONS', 'INSIGHTS', 'ADMIN', 'HELP', 'SESSION'];

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-sliders-h mr-2"></i>Slidebar Settings</h3>
    </div>
    <div class="card-body">
        <?php if($message !== ''){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post" id="sidebar-settings-form">
            <div id="sidebar-hidden-inputs"></div>

            <div class="sidebar-builder">
                <div class="builder-head">
                    <div>
                        <strong>Visual Menu Builder</strong>
                        <span class="text-muted d-block">Drag a card up/down, or drop it inside another card to make it a submenu.</span>
                    </div>
                    <span class="badge badge-info">Drag & Drop</span>
                </div>

                <?php
                $root_items = $children_by_parent[''] ?? [];
                $render_sidebar_builder_item = function($item) use (&$render_sidebar_builder_item, $children_by_parent){
                        $children = $children_by_parent[$item['id']] ?? [];
                    ?>
                        <div class="builder-item<?= (int)($item['visible'] ?? 1) === 1 ? '' : ' is-hidden'; ?>" draggable="true" data-id="<?= htmlspecialchars($item['id']); ?>" data-label="<?= htmlspecialchars($item['label']); ?>" data-section="<?= htmlspecialchars($item['section'] ?? 'MAIN'); ?>" data-visible="<?= (int)($item['visible'] ?? 1); ?>">
                            <div class="builder-card">
                                <div class="drag-handle"><i class="fas fa-grip-vertical"></i></div>
                                <div class="builder-title">
                                    <strong><?= htmlspecialchars($item['label']); ?></strong>
                                    <small><?= htmlspecialchars($item['href'] ?: 'Main menu group'); ?></small>
                                </div>
                                <span class="badge badge-secondary level-badge">Menu</span>
                                <button type="button" class="btn btn-sm visibility-toggle" title="Hide or show" <?= ($item['id'] ?? '') === 'sidebar_settings' ? 'disabled aria-disabled="true"' : ''; ?>>
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="drop-zone child-zone" data-parent="<?= htmlspecialchars($item['id']); ?>">
                                <?php foreach($children as $child){ $render_sidebar_builder_item($child); } ?>
                            </div>
                        </div>
                    <?php
                    };
                ?>

                <?php foreach($sidebar_sections as $section){ ?>
                    <?php
                    $section_items = array_values(array_filter($root_items, static function($item) use ($section){
                        return ($item['section'] ?? 'MAIN') === $section;
                    }));
                    if(empty($section_items)){
                        continue;
                    }
                    ?>
                    <div class="builder-section">
                        <div class="builder-section-title"><?= htmlspecialchars($section); ?></div>
                        <div class="drop-zone root-zone" data-parent="" data-section="<?= htmlspecialchars($section); ?>">
                            <?php foreach($section_items as $root_item){ $render_sidebar_builder_item($root_item); } ?>
                        </div>
                    </div>
                <?php } ?>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Settings</button>
            <button type="submit" name="action" value="reset" class="btn btn-danger" onclick="return confirm('Reset sidebar menu order?');">
                <i class="fas fa-undo mr-1"></i>Reset
            </button>
        </form>
    </div>
</div>

<style>
.sidebar-builder{border:1px solid #d8dee6;border-radius:8px;background:#f8fafc;padding:14px;margin-bottom:18px;}
.builder-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.builder-section{margin-top:14px;}
.builder-section-title{font-weight:700;color:#6c757d;font-size:12px;letter-spacing:.04em;margin:0 0 6px 4px;}
.drop-zone{min-height:18px;border:1px dashed transparent;border-radius:8px;padding:8px;}
.root-zone{background:#fff;border-color:#d8dee6;}
.child-zone{margin:8px 0 0 34px;background:#f3f6fa;}
.drop-zone.drag-over{border-color:#007bff;background:#eaf4ff;}
.builder-item{margin:8px 0;}
.builder-card{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #d7dde5;border-radius:8px;padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.06);cursor:grab;}
.builder-item.dragging{opacity:.45;}
.builder-item.is-hidden > .builder-card{background:#f1f3f5;border-style:dashed;color:#6c757d;}
.builder-item.is-hidden > .builder-card .builder-title strong{text-decoration:line-through;}
.drag-handle{width:22px;color:#697586;text-align:center;}
.builder-title{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1;}
.builder-title small{color:#697586;word-break:break-all;}
.level-badge{min-width:72px;}
.visibility-toggle{min-width:40px;background:#eef2f7;border:1px solid #d7dde5;color:#495057;}
.visibility-toggle.is-off{background:#dc3545;border-color:#dc3545;color:#fff;}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('sidebar-settings-form');
    const hiddenInputs = document.getElementById('sidebar-hidden-inputs');
    const zones = Array.from(document.querySelectorAll('.drop-zone'));
    let dragging = null;

    function closestBuilderItem(element) {
        return element ? element.closest('.builder-item') : null;
    }

    function isInsideSelf(zone, item) {
        return item && zone && item.contains(zone);
    }

    function canDrop(zone, item) {
        if (!zone || !item || isInsideSelf(zone, item)) return false;
        if (zone.dataset.parent === '' && zone.dataset.section && item.dataset.section !== zone.dataset.section) return false;
        return true;
    }

    function updateBadges() {
        document.querySelectorAll('.builder-item').forEach(function (item) {
            const parentZone = item.parentElement;
            const badge = item.querySelector(':scope > .builder-card .level-badge');
            const button = item.querySelector(':scope > .builder-card .visibility-toggle');
            const isRoot = parentZone && parentZone.dataset.parent === '';
            const visible = item.dataset.visible !== '0';
            if (badge) {
                badge.textContent = (isRoot ? 'Menu' : 'Submenu') + (visible ? '' : ' Hidden');
                badge.className = 'badge level-badge ' + (visible ? (isRoot ? 'badge-info' : 'badge-secondary') : 'badge-danger');
            }
            if (button) {
                button.classList.toggle('is-off', !visible);
                const icon = button.querySelector('i');
                if (icon) icon.className = visible ? 'fas fa-eye' : 'fas fa-eye-slash';
            }
            item.classList.toggle('is-hidden', !visible);
        });
    }

    function insertByPointer(zone, event) {
        if (!dragging || !canDrop(zone, dragging)) return;

        const candidates = Array.from(zone.children).filter(function (child) {
            return child.classList && child.classList.contains('builder-item') && child !== dragging;
        });
        const next = candidates.find(function (child) {
            const rect = child.getBoundingClientRect();
            return event.clientY < rect.top + rect.height / 2;
        });

        zone.insertBefore(dragging, next || null);
        updateBadges();
    }

    document.querySelectorAll('.builder-item').forEach(function (item) {
        item.addEventListener('dragstart', function (event) {
            dragging = item;
            item.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.id || '');
        });

        item.addEventListener('dragend', function () {
            item.classList.remove('dragging');
            dragging = null;
            zones.forEach(function (zone) { zone.classList.remove('drag-over'); });
            updateBadges();
        });
    });

    zones.forEach(function (zone) {
        zone.addEventListener('dragover', function (event) {
            if (!dragging || !canDrop(zone, dragging)) return;
            event.preventDefault();
            event.stopPropagation();
            zone.classList.add('drag-over');
            insertByPointer(zone, event);
        });

        zone.addEventListener('dragleave', function () {
            zone.classList.remove('drag-over');
        });

        zone.addEventListener('drop', function (event) {
            if (!dragging || !canDrop(zone, dragging)) return;
            event.preventDefault();
            event.stopPropagation();
            insertByPointer(zone, event);
            zone.classList.remove('drag-over');
        });
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.visibility-toggle');
        if (!button) return;
        event.preventDefault();
        const item = button.closest('.builder-item');
        if (!item) return;
        if (item.dataset.id === 'sidebar_settings') return;
        item.dataset.visible = item.dataset.visible === '0' ? '1' : '0';
        updateBadges();
    });

    form.addEventListener('submit', function () {
        hiddenInputs.innerHTML = '';
        let sort = 10;
        document.querySelectorAll('.builder-item').forEach(function (item) {
            const parentZone = item.parentElement;
            const parent = parentZone && parentZone.classList.contains('drop-zone') ? (parentZone.dataset.parent || '') : '';
            const id = item.dataset.id || '';
            if (!id) return;

            const parentInput = document.createElement('input');
            parentInput.type = 'hidden';
            parentInput.name = 'items[' + id + '][parent]';
            parentInput.value = parent;
            hiddenInputs.appendChild(parentInput);

            const sortInput = document.createElement('input');
            sortInput.type = 'hidden';
            sortInput.name = 'items[' + id + '][sort]';
            sortInput.value = sort;
            hiddenInputs.appendChild(sortInput);

            const visibleInput = document.createElement('input');
            visibleInput.type = 'hidden';
            visibleInput.name = 'items[' + id + '][visible]';
            visibleInput.value = item.dataset.visible === '0' ? '0' : '1';
            hiddenInputs.appendChild(visibleInput);
            sort += 10;
        });
    });

    updateBadges();
});
</script>

<?php require_once '../includes/footer.php'; ?>
