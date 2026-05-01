<?php  // Moodle configuration file (rendered at container startup from template)
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

// SSL & reverse proxy settings — Railway terminates SSL at edge
$CFG->sslproxy     = true;
$CFG->reverseproxy = true;

// File storage
$CFG->dataroot     = '/moodledata';
$CFG->directorypermissions = 02777;
$CFG->admin        = 'admin';

// Skip the strict reverse proxy IP check that previously caused 500 errors.
// Railway's edge proxy IPs are dynamic, so we can't list them. This tells Moodle
// to trust X-Forwarded-* headers without validating their source IP.
$CFG->reverseproxyignore = '0.0.0.0/0,::/0';

require_once(__DIR__ . '/lib/setup.php');
