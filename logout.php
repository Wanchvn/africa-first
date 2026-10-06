<?php
require_once __DIR__ . '/includes/session.php';
start_secure_session();

destroy_session();

header('Location: login.php');
exit;