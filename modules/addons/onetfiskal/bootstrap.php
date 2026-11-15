<?php
declare(strict_types=1);
if (!defined('WHMCS')) { http_response_code(403); exit; }
require_once __DIR__ . '/lib/Core.php';
require_once __DIR__ . '/lib/Synthetic.php';
require_once __DIR__ . '/lib/Runtime.php';
require_once __DIR__ . '/lib/Admin.php';
require_once __DIR__ . '/lib/Receipt.php';
require_once __DIR__ . '/lib/Journal.php';
require_once __DIR__ . '/lib/TestAdmin.php';
require_once __DIR__ . '/lib/Refund.php';
require_once __DIR__ . '/lib/RefundAdmin.php';
require_once __DIR__ . '/lib/Copy.php';
require_once __DIR__ . '/lib/CopyAdmin.php';
require_once __DIR__ . '/lib/Advance.php';
require_once __DIR__ . '/lib/AdvanceAdmin.php';
