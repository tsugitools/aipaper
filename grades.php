<?php
require_once "../config.php";
\Tsugi\Core\LTIX::getConnection();

use \Tsugi\UI\MenuSet;
use \Tsugi\Grades\GradeUtil;
use \Tsugi\Core\LTIX;
use \Tsugi\Util\U;

$LAUNCH = LTIX::requireData();

$menu = new \Tsugi\UI\MenuSet();
$menu->addLeft(__('Back'), 'index.php');

$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

$p = $CFG->dbprefix;
$link_id = $LAUNCH->link->id;

// Get pagination and sorting parameters
$page = max(1, intval(U::get($_GET, 'page', 1)));
$sort_col = U::get($_GET, 'sort', 'displayname');
$sort_dir = U::get($_GET, 'dir', 'asc');
$valid_sort_cols = array('displayname', 'email', 'grade', 'updated_at', 'comments_given', 'comments_received', 'flags');
if (!in_array($sort_col, $valid_sort_cols)) {
    $sort_col = 'displayname';
}
if ($sort_dir != 'asc' && $sort_dir != 'desc') {
    $sort_dir = 'asc';
}
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Map sort columns to SQL expressions (whitelist for security)
$sort_sql_map = array(
    'displayname' => 'u.displayname',
    'email' => 'u.email',
    'grade' => 'lr.grade',
    'updated_at' => 'lr.updated_at',
    'comments_given' => 'COALESCE(cg.cnt, 0)',
    'comments_received' => 'COALESCE(cr.cnt, 0)',
    'flags' => 'COALESCE(fl.cnt, 0)'
);
// Use whitelist to prevent SQL injection
$sort_sql = isset($sort_sql_map[$sort_col]) ? $sort_sql_map[$sort_col] : 'u.displayname';
$sort_dir_sql = ($sort_dir == 'desc') ? 'DESC' : 'ASC';
$order_by = $sort_sql . ' ' . $sort_dir_sql;

// Get total count for pagination (single query)
$total_rows = $PDOX->rowDie(
    "SELECT COUNT(*) as cnt
     FROM {$p}lti_result lr
     JOIN {$p}lti_user u ON lr.user_id = u.user_id
     WHERE lr.link_id = :LID",
    array(':LID' => $link_id)
);
$total_rows = intval($total_rows['cnt']);
$total_pages = ceil($total_rows / $per_page);

// Optimized query: Get only the 20 students we need, with all counts in one query
// This uses LEFT JOINs with subqueries to get counts efficiently
$rows = $PDOX->allRowsDie(
    "SELECT 
        lr.user_id,
        lr.result_id,
        u.displayname,
        u.email,
        lr.grade,
        lr.updated_at,
        COALESCE(cg.cnt, 0) as comments_given,
        COALESCE(cr.cnt, 0) as comments_received,
        COALESCE(fl.cnt, 0) as flags
     FROM {$p}lti_result lr
     JOIN {$p}lti_user u ON lr.user_id = u.user_id
     LEFT JOIN (
         SELECT user_id, COUNT(*) as cnt
         FROM {$p}aipaper_comment
         WHERE user_id IS NOT NULL
         GROUP BY user_id
     ) cg ON cg.user_id = lr.user_id
     LEFT JOIN (
         SELECT ar.result_id, COUNT(*) as cnt
         FROM {$p}aipaper_comment ac
         JOIN {$p}aipaper_result ar ON ac.result_id = ar.result_id
         WHERE ac.deleted = 0
         GROUP BY ar.result_id
     ) cr ON cr.result_id = lr.result_id
     LEFT JOIN (
         SELECT user_id, COUNT(*) as cnt
         FROM {$p}aipaper_comment
         WHERE user_id IS NOT NULL AND flagged = 1
         GROUP BY user_id
     ) fl ON fl.user_id = lr.user_id
     WHERE lr.link_id = :LID
     ORDER BY $order_by
     LIMIT " . intval($per_page) . " OFFSET " . intval($offset),
    array(':LID' => $link_id)
);

// Build sort URL helper
function buildSortUrl($col, $current_sort, $current_dir) {
    $params = $_GET;
    $params['sort'] = $col;
    if ($current_sort == $col && $current_dir == 'asc') {
        $params['dir'] = 'desc';
    } else {
        $params['dir'] = 'asc';
    }
    $params['page'] = 1; // Reset to page 1 when sorting
    return addSession('grades.php?' . http_build_query($params));
}

// Build pagination URL helper
function buildPageUrl($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    return addSession('grades.php?' . http_build_query($params));
}

// Render sortable header
function renderSortHeader($label, $col, $current_sort, $current_dir) {
    $url = buildSortUrl($col, $current_sort, $current_dir);
    $arrow = '';
    if ($current_sort == $col) {
        $arrow = $current_dir == 'asc' ? ' ↑' : ' ↓';
    }
    return '<a href="' . htmlentities($url) . '">' . htmlentities($label) . $arrow . '</a>';
}

// Build and render the table
echo '<h2>Student Data</h2>';
echo '<table class="table table-striped">';
echo '<thead><tr>';
echo '<th>' . renderSortHeader('Name', 'displayname', $sort_col, $sort_dir) . '</th>';
echo '<th>' . renderSortHeader('Email', 'email', $sort_col, $sort_dir) . '</th>';
echo '<th>' . renderSortHeader('Grade', 'grade', $sort_col, $sort_dir) . '</th>';
echo '<th>' . renderSortHeader('Updated', 'updated_at', $sort_col, $sort_dir) . '</th>';
echo '<th>' . renderSortHeader('Comments Given', 'comments_given', $sort_col, $sort_dir) . '</th>';
echo '<th>' . renderSortHeader('Comments Received', 'comments_received', $sort_col, $sort_dir) . '</th>';
echo '<th>' . renderSortHeader('Flags', 'flags', $sort_col, $sort_dir) . '</th>';
echo '</tr></thead>';
echo '<tbody>';

foreach ($rows as $row) {
    $user_id = intval($row['user_id']);
    $displayname = htmlentities($row['displayname'] ?? '');
    $email = htmlentities($row['email'] ?? '');
    $grade = isset($row['grade']) ? number_format(floatval($row['grade']) * 100.0, 1) . '%' : '0.0%';
    $updated_at = htmlentities($row['updated_at'] ?? '');
    $comments_given_val = intval($row['comments_given']);
    $comments_received_val = intval($row['comments_received']);
    $flags_val = intval($row['flags']);
    
    $detail_url = addSession('grade-detail.php?user_id=' . $user_id);
    
    echo '<tr>';
    echo '<td><a href="' . htmlentities($detail_url) . '">' . $displayname . '</a></td>';
    echo '<td>' . $email . '</td>';
    echo '<td>' . $grade . '</td>';
    echo '<td>' . $updated_at . '</td>';
    echo '<td>' . $comments_given_val . '</td>';
    echo '<td>' . $comments_received_val . '</td>';
    echo '<td>' . $flags_val . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

// Pagination controls
if ($total_pages > 1) {
    echo '<nav aria-label="Page navigation">';
    echo '<ul class="pagination">';
    
    // Previous button
    if ($page > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . htmlentities(buildPageUrl($page - 1)) . '">Previous</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $start_page = max(1, $page - 2);
    $end_page = min($total_pages, $page + 2);
    
    if ($start_page > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . htmlentities(buildPageUrl(1)) . '">1</a></li>';
        if ($start_page > 2) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $page) {
            echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            echo '<li class="page-item"><a class="page-link" href="' . htmlentities(buildPageUrl($i)) . '">' . $i . '</a></li>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        echo '<li class="page-item"><a class="page-link" href="' . htmlentities(buildPageUrl($total_pages)) . '">' . $total_pages . '</a></li>';
    }
    
    // Next button
    if ($page < $total_pages) {
        echo '<li class="page-item"><a class="page-link" href="' . htmlentities(buildPageUrl($page + 1)) . '">Next</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    echo '</ul>';
    echo '</nav>';
    
    echo '<p class="text-muted">Showing ' . ($offset + 1) . '-' . min($offset + $per_page, $total_rows) . ' of ' . $total_rows . ' students</p>';
}

$OUTPUT->footer();
