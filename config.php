<?php  // Moodle configuration file
unset($CFG);
global $CFG;
$CFG = new stdClass();

// Database configuration — read from Railway env vars
$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MYSQLHOST');
$CFG->dbname    = getenv('MYSQLDATABASE');
$CFG->dbuser    = getenv('MYSQLUSER');
$CFG->dbpass    = getenv('MYSQLPASSWORD');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist'   => 0,
    'dbport'      => getenv('MYSQLPORT'),
    'dbsocket'    => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

// Site URL
$CFG->wwwroot      = 'https://' . getenv('RAILWAY_PUBLIC_DOMAIN');

// SSL termination at Railway edge — tells Moodle the user-facing URL is HTTPS
$CFG->sslproxy     = true;

// Note: reverseproxy is intentionally NOT set to true here.
// Setting it to true triggers a strict guard that doesn't play well with
// Railway's proxy setup. sslproxy alone is enough for HTTPS handling.

// File storage
$CFG->dataroot     = '/moodledata';
$CFG->directorypermissions = 02777;
$CFG->admin        = 'admin';

require_once(__DIR__ . '/lib/setup.php');