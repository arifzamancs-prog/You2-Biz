<?php

require_once '../includes/auth.php';
require_admin_user();
require_once '../includes/db.php';

function ensure_video_tutorial_table($conn)
{
    if (function_exists('mysqli_set_charset')) {
        @mysqli_set_charset($conn, 'utf8mb4');
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS video_tutorials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            video_url TEXT NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $charset_result = mysqli_query(
        $conn,
        "SELECT CHARACTER_SET_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME='video_tutorials'
           AND COLUMN_NAME='title'
         LIMIT 1"
    );
    $charset_row = $charset_result ? mysqli_fetch_assoc($charset_result) : null;

    // Older installations stored UTF-8 Bengali bytes in latin1 columns.
    // Temporarily changing the columns to binary preserves those bytes, then
    // restoring them as utf8mb4 makes existing and new Bengali text readable.
    if(($charset_row['CHARACTER_SET_NAME'] ?? '') !== 'utf8mb4'){
        mysqli_query(
            $conn,
            "ALTER TABLE video_tutorials
             MODIFY title VARBINARY(1020) NOT NULL,
             MODIFY description BLOB NULL"
        );

        mysqli_query(
            $conn,
            "ALTER TABLE video_tutorials
             DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
             MODIFY title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
             MODIFY description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL"
        );
    }
}

function normalize_video_tutorial_status($status)
{
    return $status === 'inactive' ? 'inactive' : 'active';
}

function video_tutorial_youtube_id($url)
{
    $url = trim((string)$url);

    if($url === ''){
        return '';
    }

    $parts = @parse_url($url);

    if(!$parts){
        return '';
    }

    $host = strtolower((string)($parts['host'] ?? ''));

    if(strpos($host, 'youtu.be') !== false){
        $path = trim((string)($parts['path'] ?? ''), '/');
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $path);
    }

    if(strpos($host, 'youtube.com') !== false || strpos($host, 'youtube-nocookie.com') !== false){
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);

        if(!empty($query['v'])){
            return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$query['v']);
        }

        $path = trim((string)($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        if(isset($segments[0], $segments[1]) && in_array($segments[0], ['embed', 'shorts'], true)){
            return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$segments[1]);
        }
    }

    return '';
}

function video_tutorial_thumbnail_url($url)
{
    $youtube_id = video_tutorial_youtube_id($url);

    if($youtube_id !== ''){
        return 'https://img.youtube.com/vi/' . $youtube_id . '/hqdefault.jpg';
    }

    return '';
}

function video_tutorial_embed_url($url)
{
    $youtube_id = video_tutorial_youtube_id($url);

    if($youtube_id !== ''){
        return 'https://www.youtube.com/embed/' . $youtube_id . '?autoplay=1&rel=0';
    }

    return trim((string)$url);
}

ensure_video_tutorial_table($conn);

$form_mode = 'create';
$edit_tutorial = null;
$message = '';
$error = '';

if(is_super_admin_user() && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = trim((string)($_POST['action'] ?? ''));
    $tutorial_id = (int)($_POST['tutorial_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $video_url = trim((string)($_POST['video_url'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $status = normalize_video_tutorial_status($_POST['status'] ?? 'active');

    if($action === 'save'){
        if($title === '' || $video_url === ''){
            $error = 'Title and video link are required.';
        }else{
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO video_tutorials
                 (
                    title,
                    video_url,
                    description,
                    status,
                    sort_order
                 )
                 VALUES
                 (
                    ?, ?, ?, ?, ?
                 )"
            );

            if($stmt){
                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssi",
                    $title,
                    $video_url,
                    $description,
                    $status,
                    $sort_order
                );
                mysqli_stmt_execute($stmt);
                $message = 'Video tutorial added successfully.';
            }else{
                $error = 'Video tutorial could not be saved.';
            }
        }
    }

    if($action === 'update'){
        if($tutorial_id <= 0){
            $error = 'Invalid video tutorial selected.';
        }elseif($title === '' || $video_url === ''){
            $error = 'Title and video link are required.';
        }else{
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE video_tutorials
                 SET title=?,
                     video_url=?,
                     description=?,
                     status=?,
                     sort_order=?
                 WHERE id=?"
            );

            if($stmt){
                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssii",
                    $title,
                    $video_url,
                    $description,
                    $status,
                    $sort_order,
                    $tutorial_id
                );
                mysqli_stmt_execute($stmt);
                $message = 'Video tutorial updated successfully.';
            }else{
                $error = 'Video tutorial could not be updated.';
            }
        }
    }

    if($action === 'delete'){
        if($tutorial_id <= 0){
            $error = 'Invalid video tutorial selected.';
        }else{
            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM video_tutorials
                 WHERE id=?"
            );

            if($stmt){
                mysqli_stmt_bind_param($stmt, "i", $tutorial_id);
                mysqli_stmt_execute($stmt);
                $message = 'Video tutorial deleted successfully.';
            }else{
                $error = 'Video tutorial could not be deleted.';
            }
        }
    }
}

$edit_id = is_super_admin_user()
    ? (int)($_GET['edit_id'] ?? 0)
    : 0;

if($edit_id > 0){
    $stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM video_tutorials
         WHERE id=?
         LIMIT 1"
    );

    if($stmt){
        mysqli_stmt_bind_param($stmt, "i", $edit_id);
        mysqli_stmt_execute($stmt);
        $edit_tutorial = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if($edit_tutorial){
            $form_mode = 'edit';
        }
    }
}

$tutorial_rows = [];
$tutorial_sql = "SELECT *
                 FROM video_tutorials";

if(!is_super_admin_user()){
    $tutorial_sql .= " WHERE status='active'";
}

$tutorial_sql .= " ORDER BY sort_order ASC, id DESC";

$tutorial_result = mysqli_query($conn, $tutorial_sql);

if($tutorial_result){
    while($row = mysqli_fetch_assoc($tutorial_result)){
        $tutorial_rows[] = $row;
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<style>
.tutorial-media{
    position:relative;
    display:block;
    width:100%;
    aspect-ratio:16 / 9;
    border-radius:8px;
    overflow:hidden;
    background:#e9ecef;
    margin-bottom:14px;
}

.tutorial-thumb{
    position:absolute;
    inset:0;
    display:block;
    width:100%;
    height:100%;
}

.tutorial-thumb img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.tutorial-thumb-placeholder{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg, #1f2937, #374151);
    color:#fff;
    font-size:16px;
    font-weight:600;
}

.tutorial-thumb-play{
    position:absolute;
    top:50%;
    left:50%;
    transform:translate(-50%, -50%);
    width:58px;
    height:58px;
    border-radius:50%;
    background:rgba(0, 0, 0, .65);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:22px;
    line-height:1;
}

.tutorial-thumb-button{
    border:0;
    padding:0;
    cursor:pointer;
}

.tutorial-inline-frame{
    width:100%;
    height:100%;
    border:0;
    background:#000;
    position:absolute;
    inset:0;
}
</style>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Video Tutorial</h3>
    </div>

    <div class="card-body">

        <?php if($message !== ''){ ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <?php if($error !== ''){ ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php } ?>

        <?php if(is_super_admin_user()){ ?>
        <div class="card card-outline card-primary mb-4">
            <div class="card-header">
                <h3 class="card-title">
                    <?= $form_mode === 'edit' ? 'Edit Video Tutorial' : 'Add Video Tutorial'; ?>
                </h3>
            </div>

            <div class="card-body">
                <form method="post">
                    <input
                        type="hidden"
                        name="action"
                        value="<?= $form_mode === 'edit' ? 'update' : 'save'; ?>">

                    <input
                        type="hidden"
                        name="tutorial_id"
                        value="<?= (int)($edit_tutorial['id'] ?? 0); ?>">

                    <div class="row">
                        <div class="col-md-4">
                            <label>Title</label>
                            <input
                                type="text"
                                name="title"
                                class="form-control"
                                value="<?= htmlspecialchars($edit_tutorial['title'] ?? ''); ?>"
                                required>
                        </div>

                        <div class="col-md-5">
                            <label>Video Link</label>
                            <input
                                type="url"
                                name="video_url"
                                class="form-control"
                                value="<?= htmlspecialchars($edit_tutorial['video_url'] ?? ''); ?>"
                                placeholder="https://www.youtube.com/watch?v=..."
                                required>
                        </div>

                        <div class="col-md-3">
                            <label>Sort Order</label>
                            <input
                                type="number"
                                name="sort_order"
                                class="form-control"
                                value="<?= (int)($edit_tutorial['sort_order'] ?? 0); ?>">
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-8">
                            <label>Description</label>
                            <textarea
                                name="description"
                                class="form-control"
                                rows="3"><?= htmlspecialchars($edit_tutorial['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="col-md-4">
                            <label>Status</label>
                            <select
                                name="status"
                                class="form-control">
                                <option
                                    value="active"
                                    <?= (($edit_tutorial['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>
                                    Active
                                </option>
                                <option
                                    value="inactive"
                                    <?= (($edit_tutorial['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>
                                    Inactive
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button
                            type="submit"
                            class="btn btn-primary">
                            <?= $form_mode === 'edit' ? 'Update Tutorial' : 'Add Tutorial'; ?>
                        </button>

                        <?php if($form_mode === 'edit'){ ?>
                            <a
                                            href="<?= htmlspecialchars(app_path('help/video_tutorial.php')); ?>"
                                class="btn btn-secondary">
                                Cancel
                            </a>
                        <?php } ?>
                    </div>
                </form>
            </div>
        </div>
        <?php } ?>

        <div class="row">
            <?php if(empty($tutorial_rows)){ ?>
                <div class="col-12">
                    <div class="alert alert-warning mb-0">
                        No video tutorial found yet.
                    </div>
                </div>
            <?php } ?>

            <?php foreach($tutorial_rows as $tutorial){ ?>
                <div class="col-md-4 mb-4">
                    <div class="border rounded p-3 h-100">
                        <?php
                        $thumbnail_url = video_tutorial_thumbnail_url($tutorial['video_url'] ?? '');
                        $tutorial_iframe_id = 'tutorial-video-' . (int)$tutorial['id'];
                        $tutorial_embed_url = video_tutorial_embed_url($tutorial['video_url'] ?? '');
                        ?>
                        <div class="tutorial-media">
                            <button
                                type="button"
                                class="tutorial-thumb tutorial-thumb-button js-play-tutorial"
                                data-target-frame="<?= htmlspecialchars($tutorial_iframe_id); ?>"
                                data-embed-url="<?= htmlspecialchars($tutorial_embed_url); ?>">
                                <?php if($thumbnail_url !== ''){ ?>
                                    <img
                                        src="<?= htmlspecialchars($thumbnail_url); ?>"
                                        alt="<?= htmlspecialchars($tutorial['title']); ?>">
                                <?php }else{ ?>
                                    <div class="tutorial-thumb-placeholder">
                                        Video Preview
                                    </div>
                                <?php } ?>
                                <div class="tutorial-thumb-play">&#9658;</div>
                            </button>

                            <iframe
                                id="<?= htmlspecialchars($tutorial_iframe_id); ?>"
                                class="tutorial-inline-frame d-none"
                                src=""
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                allowfullscreen
                                referrerpolicy="strict-origin-when-cross-origin"></iframe>
                        </div>

                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="mb-0"><?= htmlspecialchars($tutorial['title']); ?></h5>
                            <?php if(is_super_admin_user()){ ?>
                                <span class="badge badge-<?= $tutorial['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                    <?= htmlspecialchars(ucfirst($tutorial['status'])); ?>
                                </span>
                            <?php } ?>
                        </div>

                        <p class="mb-3">
                            <?= nl2br(htmlspecialchars($tutorial['description'] ?? '')); ?>
                        </p>

                        <?php if(is_super_admin_user()){ ?>
                            <a
                                                href="<?= htmlspecialchars(app_path('help/video_tutorial.php?edit_id=' . (int)$tutorial['id'])); ?>"
                                class="btn btn-warning btn-sm ml-1">
                                Edit
                            </a>

                            <form
                                method="post"
                                class="d-inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="tutorial_id" value="<?= (int)$tutorial['id']; ?>">
                                <button
                                    type="submit"
                                    class="btn btn-danger btn-sm ml-1"
                                    onclick="return confirm('Are you sure you want to delete this video tutorial?');">
                                    Delete
                                </button>
                            </form>
                        <?php } ?>

                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function(event){
    const trigger = event.target.closest('.js-play-tutorial');

    if(!trigger){
        return;
    }

    const frameId = trigger.getAttribute('data-target-frame');
    const embedUrl = trigger.getAttribute('data-embed-url');
    const frame = document.getElementById(frameId);

    if(!frame){
        return;
    }

    if(frame.getAttribute('src') !== embedUrl){
        frame.setAttribute('src', embedUrl);
    }

    frame.classList.remove('d-none');
    trigger.classList.add('d-none');
});
</script>

<?php require_once '../includes/footer.php'; ?>
