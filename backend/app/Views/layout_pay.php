<?php /** Payment page layout. Expects: $content */ ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>پرداخت سفارش — پیکو پی</title>
<link rel="stylesheet" href="<?= Helpers::asset('css/pay.css') ?>">
    <?php $hpCustomCss = trim((string) Setting::get('pay_custom_css', '')); if ($hpCustomCss !== ''): ?>
    <style id="hp-custom-css"><?= str_ireplace(['</style', '<script', '</script', '<iframe'], '', $hpCustomCss) ?></style>
    <?php endif; ?>
<link rel="icon" href="<?= Helpers::asset('img/favicon.png') ?>">
</head>
<body>
<?= $content ?>
<script src="<?= Helpers::asset('js/pay.js') ?>" defer></script>
</body>
</html>
