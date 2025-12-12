<?php
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\Core\LTIX;

$LAUNCH = LTIX::requireData();

$user_id = U::safe_href(U::get($_GET, 'user_id'));
if ( $user_id && $user_id != $LAUNCH->user->id && ! $LAUNCH->user->instructor ){
    http_response_code(403);
    die('Only instructor can specify user_id');
}

header('Content-Type:text/plain');

$old_content = $LAUNCH->result->getJsonKeyForUser('content', '', $user_id);
echo($old_content);
