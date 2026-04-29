<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'mysql.railway.internal';
$CFG->dbname    = 'moodle_db';
$CFG->dbuser    = 'root';
$CFG->dbpass    = 'YOUR_RAILWAY_PASSWORD'; // Paste your actual password here
$CFG->dbport    = '3306';
$CFG->prefix    = 'mdl_';

$CFG->wwwroot   = 'https://benzaitenlms-production.up.railway.app';
$CFG->dataroot  = '/moodledata';
$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
