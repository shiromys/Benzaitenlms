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

// SSL termination at Railway edge
$CFG->sslproxy     = true;

// File storage
$CFG->dataroot     = '/moodledata';
$CFG->directorypermissions = 02777;
$CFG->admin        = 'admin';

// Skip site registration prompt (was blocking asset loads)
$CFG->registerauth = '';

require_once(__DIR__ . '/lib/setup.php');