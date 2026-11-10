<?php
declare(strict_types=1);
define('WH_SECRET', 'whsec_test00000000000000000000000000000');
define('ORDER_ID', 1001);
define('PRE_AMOUNT', 2500000);
define('ORDER_STATUS', 'pending');
define('PRE_MARK_PAID', false);
define('PRE_TOKEN', 'tok_1001aaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

require __DIR__ . '/_webhook_common.php';
Peakopay_Webhook::handle();
echo "MARKER handle_returned_without_exit\n"; // shouldn't happen — handle() exits
