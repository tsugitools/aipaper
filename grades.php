<?php
require_once "../config.php";
\Tsugi\Core\LTIX::getConnection();

use \Tsugi\UI\MenuSet;
use \Tsugi\Grades\GradeUtil;
use \Tsugi\Core\LTIX;
use \Tsugi\Util\U;
use \Tsugi\Util\FakeName;
use \Tsugi\Core\Settings;

$LAUNCH = LTIX::requireData();

$menu = new \Tsugi\UI\MenuSet();
$menu->addLeft(__('Back'), 'index.php');

$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

$p = $CFG->dbprefix;
$link_id = $LAUNCH->link->id;

// Get search parameters - mutually exclusive
// If regular_search button was clicked, clear fake_name_search
// If fake_search button was clicked, clear search
$search_term = '';
$fake_name_search = '';
if ( isset($_GET['regular_search']) || (isset($_GET['search']) && !isset($_GET['fake_search'])) ) {
    $search_term = trim(U::get($_GET, 'search', ''));
    $fake_name_search = ''; // Clear fake name search
} elseif ( isset($_GET['fake_search']) || (isset($_GET['fake_name_search']) && !isset($_GET['regular_search'])) ) {
    $fake_name_search = trim(U::get($_GET, 'fake_name_search', ''));
    $search_term = ''; // Clear regular search
} else {
    // Fallback: get both but they should be mutually exclusive
    $search_term = trim(U::get($_GET, 'search', ''));
    $fake_name_search = trim(U::get($_GET, 'fake_name_search', ''));
    // If both are set, prefer the one that was actually searched
    if ( !empty($search_term) && !empty($fake_name_search) ) {
        // If both are present, clear the one that wasn't actively searched
        // This shouldn't happen with the onclick handlers, but just in case
        $fake_name_search = '';
    }
}

// Handle fake name search - collect matching user_ids
$fake_name_user_ids = array();
if ( !empty($fake_name_search) ) {
    // Query all users for this link (one SQL query, iterator-based)
    $all_users = $PDOX->allRowsDie(
        "SELECT lr.user_id, u.displayname, u.email
         FROM {$p}lti_result lr
         JOIN {$p}lti_user u ON lr.user_id = u.user_id
         WHERE lr.link_id = :LID
         ORDER BY u.displayname",
        array(':LID' => $link_id)
    );
    
    // Iterate through results, generate fake names, and filter matches
    // This processes as we iterate, not loading everything into memory
    foreach ( $all_users as $user_row ) {
        $user_id = intval($user_row['user_id']);
        $fake_name = FakeName::getName($user_id);
        
        // Case-insensitive partial match
        if ( stripos($fake_name, $fake_name_search) !== false ) {
            $fake_name_user_ids[] = $user_id;
        }
    }
}

// Get pagination and sorting parameters
$page = max(1, intval(U::get($_GET, 'page', 1)));
$sort_col = U::get($_GET, 'sort', '');
$sort_dir = U::get($_GET, 'dir', 'asc');
$valid_sort_cols = array('displayname', 'email', 'grade', 'updated_at', 'comments_given', 'comments_received', 'flags', 'deleted_comments');

// Default sort: flags DESC, then displayname ASC
// If explicitly sorting on any column, use that column only (with displayname as secondary)
$is_default_sort = empty($sort_col) || !in_array($sort_col, $valid_sort_cols);
if ($is_default_sort) {
    $sort_col = 'flags';
    $sort_dir = 'desc';
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
    'flags' => 'COALESCE(fl.cnt, 0) + COALESCE(ar.flagged, 0)',
    'deleted_comments' => 'COALESCE(dc.cnt, 0)'
);
// Use whitelist to prevent SQL injection
$sort_sql = isset($sort_sql_map[$sort_col]) ? $sort_sql_map[$sort_col] : 'COALESCE(fl.cnt, 0)';
$sort_dir_sql = ($sort_dir == 'desc') ? 'DESC' : 'ASC';

// Build ORDER BY clause: primary sort, then displayname as secondary (except when already sorting by displayname)
// When fake name search is active, disable sorting and use simple displayname order
if ( !empty($fake_name_search) ) {
    $order_by = 'u.displayname ASC';
} elseif ($sort_col == 'displayname') {
    $order_by = $sort_sql . ' ' . $sort_dir_sql;
} else {
    $order_by = $sort_sql . ' ' . $sort_dir_sql . ', u.displayname ASC';
}

// Build search WHERE clause
$search_where = '';
$search_params = array(':LID' => $link_id);
if ( !empty($search_term) ) {
    $search_where = " AND (u.displayname LIKE :SEARCH OR u.email LIKE :SEARCH)";
    $search_params[':SEARCH'] = '%' . $search_term . '%';
}
// If fake name search is active, filter by matching user_ids
if ( !empty($fake_name_search) && count($fake_name_user_ids) > 0 ) {
    $placeholders = array();
    foreach ( $fake_name_user_ids as $idx => $uid ) {
        $key = ':FAKE_UID' . $idx;
        $placeholders[] = $key;
        $search_params[$key] = $uid;
    }
    $search_where .= " AND lr.user_id IN (" . implode(',', $placeholders) . ")";
} elseif ( !empty($fake_name_search) && count($fake_name_user_ids) == 0 ) {
    // No matches - return empty result set
    $search_where .= " AND 1=0";
}

// Get total count for pagination (single query)
$total_rows = $PDOX->rowDie(
    "SELECT COUNT(*) as cnt
     FROM {$p}lti_result lr
     JOIN {$p}lti_user u ON lr.user_id = u.user_id
     WHERE lr.link_id = :LID" . $search_where,
    $search_params
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
        COALESCE(fl.cnt, 0) + COALESCE(ar.flagged, 0) as flags,
        COALESCE(dc.cnt, 0) as deleted_comments
     FROM {$p}lti_result lr
     JOIN {$p}lti_user u ON lr.user_id = u.user_id
     LEFT JOIN {$p}aipaper_result ar ON ar.result_id = lr.result_id
     LEFT JOIN (
         SELECT user_id, COUNT(*) as cnt
         FROM {$p}aipaper_comment
         WHERE user_id IS NOT NULL
         GROUP BY user_id
     ) cg ON cg.user_id = lr.user_id
     LEFT JOIN (
         SELECT ar2.result_id, COUNT(*) as cnt
         FROM {$p}aipaper_comment ac
         JOIN {$p}aipaper_result ar2 ON ac.result_id = ar2.result_id
         WHERE ac.deleted = 0
         GROUP BY ar2.result_id
     ) cr ON cr.result_id = lr.result_id
     LEFT JOIN (
         SELECT user_id, COUNT(*) as cnt
         FROM {$p}aipaper_comment
         WHERE user_id IS NOT NULL AND flagged = 1
         GROUP BY user_id
     ) fl ON fl.user_id = lr.user_id
     LEFT JOIN (
         SELECT user_id, COUNT(*) as cnt
         FROM {$p}aipaper_comment
         WHERE user_id IS NOT NULL AND deleted = 1
         GROUP BY user_id
     ) dc ON dc.user_id = lr.user_id
     WHERE lr.link_id = :LID" . $search_where . "
     ORDER BY $order_by
     LIMIT " . intval($per_page) . " OFFSET " . intval($offset),
    $search_params
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
    // Preserve search term if present
    if ( isset($params['search']) && empty($params['search']) ) {
        unset($params['search']);
    }
    return addSession('grades.php?' . http_build_query($params));
}

// Build pagination URL helper
function buildPageUrl($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    return addSession('grades.php?' . http_build_query($params));
}

// Render sortable header (disabled when fake name search is active)
function renderSortHeader($label, $col, $current_sort, $current_dir, $disable_sort = false) {
    if ( $disable_sort ) {
        // Just show the label without link or arrow
        return htmlentities($label);
    }
    $url = buildSortUrl($col, $current_sort, $current_dir);
    $arrow = '';
    if ($current_sort == $col) {
        $arrow = $current_dir == 'asc' ? ' ↑' : ' ↓';
    }
    return '<a href="' . htmlentities($url) . '">' . htmlentities($label) . $arrow . '</a>';
}

// Get userealnames setting (used for display and to determine if fake name search should be shown)
$use_real_names = Settings::linkGet('userealnames', false);

// Build and render the table
echo '<h2>Student Data</h2>';

// Search form with two separate search sections
echo '<form method="get" action="' . addSession('grades.php') . '" id="searchForm" style="margin-bottom: 20px;">';
echo '<div class="form-inline">';
// Regular search box with button
echo '<div class="form-group">';
echo '<label for="search" class="sr-only">Search</label>';
echo '<input type="text" class="form-control" id="search" name="search" placeholder="Search by name or email" value="' . htmlentities($search_term) . '" style="width: 300px;">';
echo '<button type="submit" class="btn btn-primary" name="regular_search" style="margin-left: 5px;" onclick="document.getElementById(\'fake_name_search\').value = \'\';">Search</button>';
echo '</div>';
// Fake name search (only shown if userealnames is false)
if ( !$use_real_names ) {
    echo '<div class="form-group" style="margin-left: 15px;">';
    echo '<label for="fake_name_search" class="sr-only">Search by Fake Name</label>';
    echo '<input type="text" class="form-control" id="fake_name_search" name="fake_name_search" placeholder="Search by fake name" value="' . htmlentities($fake_name_search) . '" style="width: 300px;">';
    echo '<button type="submit" name="fake_search" class="btn btn-info" style="margin-left: 5px;" onclick="document.getElementById(\'search\').value = \'\';">Search by Fake Name</button>';
    echo '</div>';
}
// Preserve sort parameters
if ( !empty($sort_col) ) {
    echo '<input type="hidden" name="sort" value="' . htmlentities($sort_col) . '">';
}
if ( !empty($sort_dir) ) {
    echo '<input type="hidden" name="dir" value="' . htmlentities($sort_dir) . '">';
}
if ( !empty($search_term) || !empty($fake_name_search) ) {
    echo '<a href="' . addSession('grades.php') . '" class="btn btn-default" style="margin-left: 15px;">Clear</a>';
}
echo '</div>';
echo '</form>';

// Display search results
if ( !empty($search_term) ) {
    echo '<p class="text-muted">Found ' . $total_rows . ' student' . ($total_rows == 1 ? '' : 's') . ' matching "' . htmlentities($search_term) . '"</p>';
}
if ( !empty($fake_name_search) ) {
    if ( count($fake_name_user_ids) > 0 ) {
        echo '<p class="text-muted">Found ' . $total_rows . ' student' . ($total_rows == 1 ? '' : 's') . ' matching fake name "' . htmlentities($fake_name_search) . '" (sorting disabled)</p>';
    } else {
        echo '<div class="alert alert-warning" style="margin-bottom: 20px;">';
        echo 'No matches found for fake name "' . htmlentities($fake_name_search) . '"';
        echo '</div>';
    }
}

// Disable sorting when fake name search is active
$disable_sort = !empty($fake_name_search);

echo '<table class="table table-striped">';
echo '<thead><tr>';
echo '<th>' . renderSortHeader('Name', 'displayname', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '<th>' . renderSortHeader('Email', 'email', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '<th>' . renderSortHeader('Grade', 'grade', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '<th>' . renderSortHeader('Updated', 'updated_at', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '<th>' . renderSortHeader('Comments Given', 'comments_given', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '<th>' . renderSortHeader('Comments Received', 'comments_received', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '<th>' . renderSortHeader('Flags', 'flags', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '<th>' . renderSortHeader('Deleted Comments', 'deleted_comments', $sort_col, $sort_dir, $disable_sort) . '</th>';
echo '</tr></thead>';
echo '<tbody>';

foreach ($rows as $row) {
    $user_id = intval($row['user_id']);
    $real_displayname = htmlentities($row['displayname'] ?? '');
    $fake_displayname = FakeName::getName($user_id);
    
    // Determine display name based on setting and user role
    if ( $use_real_names ) {
        $displayname = $real_displayname;
    } else {
        // If userealnames is false: students see fake name, instructors see real name (fake in parentheses)
        if ( $LAUNCH->user->instructor ) {
            $displayname = $real_displayname;
            if ( !empty($fake_displayname) ) {
                $displayname .= ' (' . htmlentities($fake_displayname) . ')';
            }
        } else {
            $displayname = htmlentities($fake_displayname);
        }
    }
    
    $email = htmlentities($row['email'] ?? '');
    $grade = isset($row['grade']) ? number_format(floatval($row['grade']) * 100.0, 1) . '%' : '0.0%';
    $updated_at = htmlentities($row['updated_at'] ?? '');
    $comments_given_val = intval($row['comments_given']);
    $comments_received_val = intval($row['comments_received']);
    $flags_val = intval($row['flags']);
    $deleted_comments_val = intval($row['deleted_comments']);
    
    // Preserve pagination, sorting, and search when linking to detail page
    $detail_params = array(
        'user_id' => $user_id,
        'page' => $page,
        'sort' => $sort_col,
        'dir' => $sort_dir
    );
    if ( !empty($search_term) ) {
        $detail_params['search'] = $search_term;
    }
    if ( !empty($fake_name_search) ) {
        $detail_params['fake_name_search'] = $fake_name_search;
    }
    $detail_url = addSession('grade-detail.php?' . http_build_query($detail_params));
    
    echo '<tr>';
    echo '<td><a href="' . htmlentities($detail_url) . '">' . $displayname . '</a></td>';
    echo '<td>' . $email . '</td>';
    echo '<td>' . $grade . '</td>';
    echo '<td>' . $updated_at . '</td>';
    echo '<td>' . $comments_given_val . '</td>';
    echo '<td>' . $comments_received_val . '</td>';
    echo '<td>' . $flags_val . '</td>';
    echo '<td>' . $deleted_comments_val . '</td>';
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
