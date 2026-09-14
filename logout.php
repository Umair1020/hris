<?php
require_once __DIR__ . '/includes/auth.php';
logout();
redirect(APP_URL . 'index.php');
