<?php  // Moodle configuration file
unset($CFG);
global $CFG;
$CFG = new stdClass();

// Database configuration
$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = '${MYSQLHOST}';
$CFG->dbname    = '${MYSQLDATABASE}';
$CFG->dbuser    = '${MYSQLUSER}';
$CFG->dbpass    = '${MYSQLPASSWORD}';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist'   => 1,
    'dbport'      => '${MYSQLPORT}',
    'dbsocket'    => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

// Site URL — MOODLE_WWWROOT_HOST is set explicitly per Railway service
// (see Dockerfile init.sh); it falls back to Railway's own per-service
// RAILWAY_PUBLIC_DOMAIN, and then to the production domain, so staging
// and production never again resolve to the same wwwroot.
$CFG->wwwroot      = 'https://${MOODLE_WWWROOT_HOST}';

// SSL & proxy settings
$CFG->sslproxy     = true;

// Cookie settings
$CFG->cookiesecure = true;
$CFG->cookiehttponly = true;

// Use ?file= query string instead of /file/path URLs
$CFG->slasharguments = false;

// File storage
$CFG->dataroot     = '/moodledata';
$CFG->directorypermissions = 02777;
$CFG->admin        = 'admin';

require_once(__DIR__ . '/lib/setup.php');
