/*
 * Best Selling Products chart on the dashboard: top products of a period as horizontal
 * bars, by quantity or by amount. Daily / Weekly / Monthly / Yearly are today, this week
 * (Saturday to today), this month and this year; Custom takes any From / To dates.
 * The rows come from the dashboard itself: ?best_products_from=Y-m-d&best_products_to=Y-m-d
 *
 * BestProductsChart.init({ url: "dashboard.php", defaultPeriod: "monthly" })
 */
(function ($, window) {
    'use strict';

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function pad(value) { return (value < 10 ? '0' : '') + value; }
    function ymd(date) { return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()); }
    function label(value) {
        var parts = value.split('-');
        return parts[2] + ' ' + MONTHS[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
    }
    function money(value) {
        return 'BDT ' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function quantity(value) {
        return Number(value).toLocaleString('en-US', { maximumFractionDigits: 3 });
    }

    function presetRange(period) {
        var today = new Date();
        var from = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (period === 'weekly') from.setDate(from.getDate() - ((from.getDay() + 1) % 7)); // weeks start on Saturday
        if (period === 'monthly') from.setDate(1);
        if (period === 'yearly') from = new Date(today.getFullYear(), 0, 1);
        return { from: ymd(from), to: ymd(today) };
    }

    function init(options) {
        var canvas = document.getElementById('bestProductsChart');
        if (!canvas || !window.Chart) return;

        var $periods = $('#bestProductsPeriods');
        var $metrics = $('#bestProductsMetric');
        var $custom = $('#bestProductsCustom');
        var $error = $('#bestProductsCustomError');
        var $range = $('#bestProductsRange');
        var $empty = $('#bestProductsEmpty');
        var rows = [];
        var metric = 'qty';
        var request = null;
        // Keep the active metric visible on every horizontal bar, not only in its tooltip.
        var valueLabelsPlugin = {
            id: 'bestProductsValueLabels',
            afterDatasetsDraw: function (chartInstance) {
                var context = chartInstance.ctx;
                var dataset = chartInstance.data.datasets[0];
                var bars = chartInstance.getDatasetMeta(0).data;
                if (!dataset || !bars) return;

                context.save();
                context.font = '600 12px Arial, sans-serif';
                context.textBaseline = 'middle';

                bars.forEach(function (bar, index) {
                    var value = Number(dataset.data[index] || 0);
                    var text = metric === 'amount' ? money(value) : quantity(value);
                    var width = context.measureText(text).width;
                    var fitsInside = Math.abs(bar.x - bar.base) >= width + 14;

                    context.fillStyle = fitsInside ? '#ffffff' : '#343a40';
                    context.textAlign = fitsInside ? 'right' : 'left';
                    context.fillText(text, fitsInside ? bar.x - 7 : bar.x + 7, bar.y);
                });

                context.restore();
            }
        };

        var chart = new window.Chart(canvas, {
            type: 'bar',
            plugins: [valueLabelsPlugin],
            data: { labels: [], datasets: [{ label: 'Quantity', data: [], backgroundColor: '#28a745', borderRadius: 4 }] },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var row = rows[context.dataIndex] || {};
                                return 'Qty: ' + quantity(row.qty || 0) + '  |  ' + money(row.amount || 0);
                            }
                        }
                    }
                },
                scales: { x: { beginAtZero: true } }
            }
        });

        function draw() {
            var dataset = chart.data.datasets[0];
            chart.data.labels = rows.map(function (row) { return row.name; });
            dataset.data = rows.map(function (row) { return metric === 'amount' ? row.amount : row.qty; });
            dataset.label = metric === 'amount' ? 'Amount' : 'Quantity';
            dataset.backgroundColor = metric === 'amount' ? '#007bff' : '#28a745';
            chart.update();
            $empty.toggleClass('d-none', rows.length > 0).toggleClass('d-flex', rows.length === 0);
        }

        function load(from, to) {
            if (request) request.abort();
            $error.text('');
            $range.text(from === to ? label(from) : label(from) + ' – ' + label(to));
            request = $.getJSON(options.url, { best_products_from: from, best_products_to: to })
                .done(function (data) {
                    rows = (data && data.rows) || [];
                    draw();
                })
                .fail(function (xhr, status) {
                    if (status === 'abort') return;
                    $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Could not load best selling products.');
                });
        }

        $periods.on('click', 'button', function () {
            var period = $(this).data('period');
            $(this).addClass('active').siblings().removeClass('active');
            $custom.toggleClass('d-none', period !== 'custom');

            if (period === 'custom') {
                $custom.trigger('submit');
                return;
            }
            var range = presetRange(period);
            load(range.from, range.to);
        });

        $custom.on('submit', function (event) {
            event.preventDefault();
            var from = $('#bestProductsFrom').val();
            var to = $('#bestProductsTo').val();
            if (!from || !to || from > to) {
                $error.text('From date must be on or before To date.');
                return;
            }
            load(from, to);
        });

        $metrics.on('click', 'button', function () {
            metric = $(this).data('metric');
            $(this).addClass('active').siblings().removeClass('active');
            draw();
        });

        $periods.find('[data-period="' + (options.defaultPeriod || 'monthly') + '"]').trigger('click');
    }

    window.BestProductsChart = { init: init };
})(jQuery, window);
