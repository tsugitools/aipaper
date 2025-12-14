<?php
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\Core\LTIX;
use \Tsugi\Util\FakeName;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();
$p = $CFG->dbprefix;

// Security check: Only allow if instructor and key is '12345'
if ( !$USER->instructor ) {
    http_response_code(403);
    die('Not authorized');
}

$key = $LAUNCH->key->key ?? '';
if ( $key !== '12345' ) {
    http_response_code(403);
    die('Not authorized - key mismatch');
}

// Verify key_id is available
if ( !isset($LAUNCH->key->id) || empty($LAUNCH->key->id) ) {
    http_response_code(500);
    die('Error: key_id is not available');
}

// Handle POST request to generate test data
if ( count($_POST) > 0 && isset($_POST['generate_test_data']) ) {
    $count = 100;
    $created = 0;
    $errors = array();
    
    for ( $i = 1; $i <= $count; $i++ ) {
        try {
            // Generate a unique email and displayname
            $email = "testuser{$i}@example.com";
            $displayname = "Test User {$i}";
            
            // Check if user already exists
            $existing_user = $PDOX->rowDie(
                "SELECT user_id FROM {$p}lti_user WHERE email = :EMAIL",
                array(':EMAIL' => $email)
            );
            
            if ( $existing_user ) {
                $user_id = $existing_user['user_id'];
            } else {
                // Get key_id (reuse the same logic as for lti_result)
                $key_id = null;
                if ( isset($LAUNCH->key->id) && !empty($LAUNCH->key->id) ) {
                    $key_id = $LAUNCH->key->id;
                } else if ( isset($LAUNCH->link->key_id) && !empty($LAUNCH->link->key_id) ) {
                    $key_id = $LAUNCH->link->key_id;
                } else {
                    $link_key_row = $PDOX->rowDie(
                        "SELECT key_id FROM {$p}lti_link WHERE link_id = :LID",
                        array(':LID' => $LAUNCH->link->id)
                    );
                    if ( $link_key_row && isset($link_key_row['key_id']) ) {
                        $key_id = $link_key_row['key_id'];
                    }
                }
                
                if ( empty($key_id) ) {
                    throw new Exception("key_id is not available for creating user {$i}");
                }
                
                // Create new user
                $PDOX->queryDie(
                    "INSERT INTO {$p}lti_user (email, displayname, key_id, created_at)
                     VALUES (:EMAIL, :DISPLAYNAME, :KID, NOW())",
                    array(
                        ':EMAIL' => $email,
                        ':DISPLAYNAME' => $displayname,
                        ':KID' => $key_id
                    )
                );
                $user_id = $PDOX->lastInsertId();
            }
            
            // Check if result already exists
            $existing_result = $PDOX->rowDie(
                "SELECT result_id FROM {$p}lti_result 
                 WHERE user_id = :UID AND link_id = :LID",
                array(
                    ':UID' => $user_id,
                    ':LID' => $LAUNCH->link->id
                )
            );
            
            if ( $existing_result ) {
                $result_id = $existing_result['result_id'];
            } else {
                // Create new result
                // Try multiple ways to get key_id
                $key_id = null;
                if ( isset($LAUNCH->key->id) && !empty($LAUNCH->key->id) ) {
                    $key_id = $LAUNCH->key->id;
                } else if ( isset($LAUNCH->link->key_id) && !empty($LAUNCH->link->key_id) ) {
                    $key_id = $LAUNCH->link->key_id;
                } else {
                    // Try to get key_id from the link's key
                    $link_key_row = $PDOX->rowDie(
                        "SELECT key_id FROM {$p}lti_link WHERE link_id = :LID",
                        array(':LID' => $LAUNCH->link->id)
                    );
                    if ( $link_key_row && isset($link_key_row['key_id']) ) {
                        $key_id = $link_key_row['key_id'];
                    }
                }
                
                // lti_result is many-to-many between user and link, doesn't need key_id
                $PDOX->queryDie(
                    "INSERT INTO {$p}lti_result (user_id, link_id, created_at)
                     VALUES (:UID, :LID, NOW())",
                    array(
                        ':UID' => $user_id,
                        ':LID' => $LAUNCH->link->id
                    )
                );
                $result_id = $PDOX->lastInsertId();
            }
            
            // Create or update paper result
            $existing_paper = $PDOX->rowDie(
                "SELECT result_id FROM {$p}aipaper_result WHERE result_id = :RID",
                array(':RID' => $result_id)
            );
            
            if ( !$existing_paper ) {
                // Create paper result
                $PDOX->queryDie(
                    "INSERT INTO {$p}aipaper_result (result_id, raw_submission, ai_enhanced_submission, submitted, created_at)
                     VALUES (:RID, :RAW, :AI, 1, NOW())",
                    array(
                        ':RID' => $result_id,
                        ':RAW' => "This is a test paper submission from Test User {$i}. " . str_repeat("Lorem ipsum dolor sit amet. ", 10),
                        ':AI' => "This is the AI-enhanced version of Test User {$i}'s paper. " . str_repeat("AI enhanced content here. ", 10)
                    )
                );
            } else {
                // Update existing paper to be submitted
                $PDOX->queryDie(
                    "UPDATE {$p}aipaper_result 
                     SET raw_submission = :RAW, ai_enhanced_submission = :AI, submitted = 1, updated_at = NOW()
                     WHERE result_id = :RID",
                    array(
                        ':RID' => $result_id,
                        ':RAW' => "This is a test paper submission from Test User {$i}. " . str_repeat("Lorem ipsum dolor sit amet. ", 10),
                        ':AI' => "This is the AI-enhanced version of Test User {$i}'s paper. " . str_repeat("AI enhanced content here. ", 10)
                    )
                );
            }
            
            $created++;
        } catch ( Exception $e ) {
            $errors[] = "Error creating user {$i}: " . $e->getMessage();
        }
    }
    
    $_SESSION['success'] = "Test data generation complete. Created/updated {$created} test users with submitted papers.";
    if ( count($errors) > 0 ) {
        $_SESSION['error'] = "Some errors occurred: " . implode("; ", array_slice($errors, 0, 5));
    }
    header( 'Location: '.addSession('testdata.php') ) ;
    return;
}

// Render view
$menu = new \Tsugi\UI\MenuSet();
$menu->addLeft(__('Back'), 'index.php');

$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

?>
<h2>Generate Test Data</h2>
<p>This tool will create 100 test users with submitted papers for testing review priority and paging.</p>
<p><strong>Warning:</strong> This will create or update test users in the database. Use only for testing.</p>

<form method="post">
    <input type="hidden" name="generate_test_data" value="1">
    <p>
        <input type="submit" value="Generate 100 Test Users with Submitted Papers" class="btn btn-warning">
    </p>
</form>

<?php
$OUTPUT->footer();

