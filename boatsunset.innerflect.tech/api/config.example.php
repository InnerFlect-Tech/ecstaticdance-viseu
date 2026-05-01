<?php
/**
 * Copy this file to config.php and fill in your cPanel MySQL details.
 * Get these from: cPanel → MySQL Databases
 */
return [
  'host'     => 'localhost',  // Try '127.0.0.1' if connection fails
  'database' => 'your_db_name',
  'username' => 'your_db_user',
  'password' => 'your_db_password',
  'password_admin' => 'admin123',  // Same as admin panel login
  'debug'    => true,  // Set false after fixing connection; shows actual DB error when true
];
