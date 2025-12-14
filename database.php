<?php

// To allow this to be called directly or from admin/upgrade.php
if ( !isset($PDOX) ) {
    require_once "../config.php";
    $CURRENT_FILE = __FILE__;
    require $CFG->dirroot."/admin/migrate-setup.php";
}

// Dropping tables
$DATABASE_UNINSTALL = array(
"drop table if exists {$CFG->dbprefix}aipaper_like",
"drop table if exists {$CFG->dbprefix}aipaper_comment",
"drop table if exists {$CFG->dbprefix}aipaper_result"
);

// Creating tables
$DATABASE_INSTALL = array(
array( "{$CFG->dbprefix}aipaper_result",
"create table {$CFG->dbprefix}aipaper_result (
    result_id    INTEGER NOT NULL,
    raw_submission TEXT NULL,
    ai_enhanced_submission TEXT NULL,
    student_comment TEXT NULL,
    submitted    TINYINT(1) NOT NULL DEFAULT 0,
    flagged      TINYINT(1) NOT NULL DEFAULT 0,
    flagged_by   INTEGER NULL,  -- user_id who flagged this submission
    json         TEXT NULL,

    updated_at  TIMESTAMP NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT `{$CFG->dbprefix}aipaper_result_ibfk_1`
        FOREIGN KEY (`result_id`)
        REFERENCES `{$CFG->dbprefix}lti_result` (`result_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `{$CFG->dbprefix}aipaper_result_ibfk_2`
        FOREIGN KEY (`flagged_by`)
        REFERENCES `{$CFG->dbprefix}lti_user` (`user_id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    PRIMARY KEY(result_id)
) ENGINE = InnoDB DEFAULT CHARSET=utf8"),

array( "{$CFG->dbprefix}aipaper_comment",
"create table {$CFG->dbprefix}aipaper_comment (
    comment_id   INTEGER NOT NULL KEY AUTO_INCREMENT,
    result_id    INTEGER NOT NULL,
    user_id      INTEGER NULL,  -- NULL for AI comments
    comment_text TEXT NOT NULL,
    comment_type ENUM('student', 'instructor', 'AI') NOT NULL,
    flagged      TINYINT(1) NOT NULL DEFAULT 0,
    flagged_by   INTEGER NULL,  -- user_id who flagged this comment
    json         TEXT NULL,

    updated_at  TIMESTAMP NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT `{$CFG->dbprefix}aipaper_comment_ibfk_1`
        FOREIGN KEY (`result_id`)
        REFERENCES `{$CFG->dbprefix}lti_result` (`result_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `{$CFG->dbprefix}aipaper_comment_ibfk_2`
        FOREIGN KEY (`user_id`)
        REFERENCES `{$CFG->dbprefix}lti_user` (`user_id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `{$CFG->dbprefix}aipaper_comment_ibfk_3`
        FOREIGN KEY (`flagged_by`)
        REFERENCES `{$CFG->dbprefix}lti_user` (`user_id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_result_id (result_id),
    INDEX idx_user_id (user_id)
) ENGINE = InnoDB DEFAULT CHARSET=utf8"),

array( "{$CFG->dbprefix}aipaper_like",
"create table {$CFG->dbprefix}aipaper_like (
    like_id      INTEGER NOT NULL KEY AUTO_INCREMENT,
    comment_id   INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,

    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT `{$CFG->dbprefix}aipaper_like_ibfk_1`
        FOREIGN KEY (`comment_id`)
        REFERENCES `{$CFG->dbprefix}aipaper_comment` (`comment_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `{$CFG->dbprefix}aipaper_like_ibfk_2`
        FOREIGN KEY (`user_id`)
        REFERENCES `{$CFG->dbprefix}lti_user` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    UNIQUE(comment_id, user_id),
    INDEX idx_comment_id (comment_id),
    INDEX idx_user_id (user_id)
) ENGINE = InnoDB DEFAULT CHARSET=utf8")

);

// Database upgrade
$DATABASE_UPGRADE = function($oldversion) {
    global $CFG, $PDOX;

    // Initial version
    if ( $oldversion < 202512120000 ) {
        // Tables created in initial install
        return 202512120000;
    }

    return 202512120000;
}; // Don't forget the semicolon on anonymous functions :)

// Do the actual migration if we are not in admin/upgrade.php
if ( isset($CURRENT_FILE) ) {
    include $CFG->dirroot."/admin/migrate-run.php";
}
