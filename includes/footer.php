        </div>
    </section>
</div>

<footer class="main-footer">

    <strong>
You2 Biz &copy; <?= date('Y'); ?>
    </strong>

</footer>

</div>

<?php $app_root_path = function_exists('app_root_path') ? app_root_path() : ''; ?>

<!-- jQuery -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/jquery/jquery.min.js'); ?>"></script>

<!-- Bootstrap -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>

<!-- DataTables -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables/jquery.dataTables.min.js'); ?>"></script>

<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js'); ?>"></script>

<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-responsive/js/dataTables.responsive.min.js'); ?>"></script>

<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-responsive/js/responsive.bootstrap4.min.js'); ?>"></script>

<!-- Select2 -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/select2/js/select2.full.min.js'); ?>"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- AdminLTE -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/dist/js/adminlte.min.js'); ?>"></script>

<script>

$(function () {

    $("table.table").each(function(){

        if(!$(this).parent().hasClass("table-responsive")){

            $(this).wrap(
                '<div class="table-responsive"></div>'
            );

        }

    });

    if($("#example1").length){

        var example1Responsive = !$("#example1").is("[data-desktop-table]");

        $("#example1").DataTable({

            "responsive": example1Responsive,
            "autoWidth": false,
            "order": []

        });

    }

});

</script>
<?php
if (isset($page_script)) {
    echo $page_script;
}
?>

</body>
</html>
