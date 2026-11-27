<?php
// Quick load for list pages (tables with "Show entries" and "Search", i.e. DataTables).
//
// When a page opens, each such table is sent with its first QUICK_LOAD_FIRST_ROWS rows
// in the table and the rest in an inert <template> right after it. The browser draws the
// short table at once; quick_load_runtime_script() then adds the remaining rows to the
// DataTable in the background, so paging, sorting and Search work on every row.
//
// Only ordinary page loads are split (Sec-Fetch-Dest: document): pages fetched by AJAX
// (for example to refresh a table after saving) always get every row. A table can opt out
// with the attribute data-no-quick-load.

const QUICK_LOAD_FIRST_ROWS = 50;

function quick_load_start()
{
    static $started = false;
    if ($started || PHP_SAPI === 'cli') return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')) !== 'document') return;
    $started = true;
    ob_start('quick_load_output_filter');
}

function quick_load_output_filter($html, $phase = 0)
{
    try {
        // The runtime script (footer) must be on the page, or the held-back rows would never show.
        if ($html === '' || strpos($html, 'data-ql-runtime') === false) return $html;
        $ids = ['example1', 'example2'];
        if (preg_match_all('/#([A-Za-z][\w-]*)[\'"]\s*\)\s*\.DataTable\(/', $html, $matches)) {
            $ids = array_merge($ids, $matches[1]);
        }
        foreach (array_unique($ids) as $id) {
            $html = quick_load_split_table($html, $id);
        }
        return $html;
    } catch (Throwable $e) {
        return $html;
    }
}

function quick_load_split_table($html, $id)
{
    if (!preg_match('/<table\b[^>]*\bid\s*=\s*["\']' . preg_quote($id, '/') . '["\'][^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
        return $html;
    }
    [$tag, $tag_pos] = $match[0];
    if (stripos($tag, 'data-no-quick-load') !== false) return $html;

    $inner_start = $tag_pos + strlen($tag);
    $table_end = stripos($html, '</table>', $inner_start);
    if ($table_end === false) return $html;
    // Tables inside cells cannot be split safely.
    if (stripos(substr($html, $inner_start, $table_end - $inner_start), '<table') !== false) return $html;

    $tbody_open = stripos($html, '<tbody', $inner_start);
    if ($tbody_open === false || $tbody_open > $table_end) return $html;
    $body_start = strpos($html, '>', $tbody_open);
    if ($body_start === false) return $html;
    $body_start++;
    $body_end = stripos($html, '</tbody>', $body_start);
    if ($body_end === false || $body_end > $table_end) return $html;

    $body = substr($html, $body_start, $body_end - $body_start);
    if (!preg_match_all('/<tr\b.*?<\/tr>/is', $body, $rows, PREG_OFFSET_CAPTURE)) return $html;
    $rows = $rows[0];
    if (count($rows) <= QUICK_LOAD_FIRST_ROWS) return $html;
    // Only plain rows: a colspan/rowspan row (a message or a group) means a table DataTables does not page.
    foreach ($rows as $row) {
        if (preg_match('/\b(colspan|rowspan)\s*=/i', $row[0])) return $html;
    }
    // Nothing but rows (and whitespace / comments) may sit between the rows.
    $between = preg_replace(['/<tr\b.*?<\/tr>/is', '/<!--.*?-->/s'], '', $body);
    if (trim($between) !== '') return $html;

    $keep_end = $rows[QUICK_LOAD_FIRST_ROWS - 1][1] + strlen($rows[QUICK_LOAD_FIRST_ROWS - 1][0]);
    $kept = substr($body, 0, $keep_end);
    $rest = substr($body, $keep_end);
    $pending = count($rows) - QUICK_LOAD_FIRST_ROWS;

    $new_tag = substr($tag, 0, -1) . ' data-ql-pending="' . $pending . '">';
    $template = '<template data-ql-for="' . htmlspecialchars($id, ENT_QUOTES) . '">' . $rest . '</template>';

    return substr($html, 0, $tag_pos)
        . $new_tag
        . substr($html, $inner_start, $body_start - $inner_start)
        . $kept
        . substr($html, $body_end, $table_end + strlen('</table>') - $body_end)
        . $template
        . substr($html, $table_end + strlen('</table>'));
}

// Footer: moves the held-back rows into their tables once the page's scripts have run.
function quick_load_runtime_script()
{
    return <<<'HTML'
<script data-ql-runtime>
(function () {
    var CHUNK = 250;
    function rowsOf(template) {
        var fragment = document.importNode(template.content, true);
        return Array.prototype.filter.call(fragment.childNodes, function (node) {
            return node.nodeType === 1 && node.tagName === 'TR';
        });
    }
    function fillTable(template) {
        var table = document.getElementById(template.getAttribute('data-ql-for'));
        var rows = rowsOf(template);
        template.parentNode.removeChild(template);
        if (!table || !rows.length) return;
        var $ = window.jQuery;
        var done = function () {
            table.removeAttribute('data-ql-pending');
            if ($) $(table).trigger('ql:loaded');
        };
        if ($ && $.fn.DataTable && $.fn.DataTable.isDataTable(table)) {
            var api = $(table).DataTable();
            var info = $(table).closest('.dataTables_wrapper').find('.dataTables_info');
            info.append('<span class="ql-loading text-muted"> &middot; <i class="fas fa-spinner fa-spin"></i> loading more&hellip;</span>');
            var index = 0;
            var step = function () {
                api.rows.add(rows.slice(index, index + CHUNK));
                index += CHUNK;
                if (index < rows.length) { setTimeout(step, 0); return; }
                api.draw(false);
                info.find('.ql-loading').remove();
                done();
            };
            step();
            return;
        }
        // Not a DataTable (yet): give the table its rows back.
        var body = table.tBodies[0] || table.appendChild(document.createElement('tbody'));
        rows.forEach(function (row) { body.appendChild(row); });
        done();
    }
    function fillAll() {
        Array.prototype.forEach.call(document.querySelectorAll('template[data-ql-for]'), fillTable);
    }
    // After every page script that starts a DataTable (they run when the page is ready).
    if (window.jQuery) {
        window.jQuery(function () { setTimeout(fillAll, 0); });
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(fillAll, 0); });
    } else {
        setTimeout(fillAll, 0);
    }
})();
</script>
HTML;
}
