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
    'dbpersist'   => 0,
    'dbport'      => '${MYSQLPORT}',
    'dbsocket'    => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

// Site URL
$CFG->wwwroot      = 'https://${RAILWAY_PUBLIC_DOMAIN}';

// SSL & proxy settings — Railway terminates SSL at edge
$CFG->sslproxy     = true;

// Cookie settings — explicit for HTTPS behind reverse proxy
$CFG->cookiesecure = true;
$CFG->cookiehttponly = true;

// Use ?file= query string instead of /file/path URLs (works around nginx PATH_INFO quirks)
$CFG->slasharguments = false;

// File storage
$CFG->dataroot     = '/moodledata';
$CFG->directorypermissions = 02777;
$CFG->admin        = 'admin';

// Suppress site registration nag
$CFG->registerauth = '';

require_once(__DIR__ . '/lib/setup.php');
