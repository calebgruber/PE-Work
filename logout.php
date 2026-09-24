<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';
require_once __DIR__ . '/shared/ui.php';

logout_user();
flash('success', 'You have been signed out.');
header('Location: ' . url_for('login'));
exit;
