<?php
/* ============================================================================
 * Protected-page guard - include at the top of every authenticated page.
 * LAB ENVIRONMENT.
 * ----------------------------------------------------------------------------
 * V4 lives here by omission: require_auth() -> current_customer() accepts any
 * session that is authenticated=1. It NEVER verifies that the OTP step was
 * completed. A token captured from the pre-OTP /api/login response therefore
 * reaches every page below.
 * ==========================================================================*/
require_once __DIR__ . '/../helpers.php';
$customer = require_auth();
