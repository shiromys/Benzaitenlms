<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb'; 
$CFG->dblibrary = 'native';
// These pull from the Railway Variables you already set up
$CFG->dbhost    = getenv('MYSQLHOST');
$CFG->dbname    = getenv('MYSQLDATABASE');
$CFG->dbuser    = getenv('MYSQLUSER');
$CFG->dbpass    = getenv('MYSQLPASSWORD');
$CFG->dbport    = getenv('MYSQLPORT');
$CFG->prefix    = 'mdl_';

$CFG->wwwroot   = 'https://benzaitenlms-production.up.railway.app';
$CFG->dataroot  = '/moodledata';
$CFG->directorypermissions = 0777;
$CFG->sslproxy  = true;

require_once(__DIR__ . '/lib/setup.php');