<?php
/**
 * Peakopay — sample bank SMS fixtures (Persian bank formats).
 * Format: [sender, text, expected(amount_rial, dest_last4, reference) or null to expect rejection]
 *
 * NOTE: these are representative formats based on common Iranian bank SMS
 * templates. Real formats vary by bank/period — the parser rules are data
 * (config/sms-rules.json) and can be tuned per merchant without code changes.
 */
declare(strict_types=1);

return [
    'mellat-deposit' => [
        'sender' => 'Mellat',
        'text'   => "بانک ملت\nواریز به کارت\nمبلغ: 1,500,000 ريال\nکارت مقصد: 6219-86••-••••-1234\nتاریخ: 1405/06/27 ساعت 14:30\nکد رهگیری: 123456789",
        'expect' => ['amount_rial' => 1500000, 'dest_last4' => '1234', 'reference' => '123456789'],
    ],
    'mellat-deposit-toman' => [
        'sender' => 'Mellat',
        'text'   => "واریز مبلغ 150,000 تومان به کارت 6219-86••-••••-1234 انجام شد. کد پیگیری: 987654321",
        'expect' => ['amount_rial' => 1500000, 'dest_last4' => '1234', 'reference' => '987654321'],
    ],
    'melli-deposit' => [
        'sender' => 'BMIBANK',
        'text'   => "بانک ملی ایران\nواریز وجه\nمبلغ:2,500,000 ریال\nکارت به: 6104-33••-••••-5678\nکد ارجاع:552001122334",
        'expect' => ['amount_rial' => 2500000, 'dest_last4' => '5678', 'reference' => '552001122334'],
    ],
    'saman-deposit' => [
        'sender' => 'SamanBank',
        'text'   => "بانک سامان؛ واریز مبلغ 890,000 ريال به کارت 6219-86••-••••-1234 شناسه واریز 77700122 شماره کارت مبدا 6037-70••-••••-9901",
        'expect' => ['amount_rial' => 890000, 'dest_last4' => '1234', 'reference' => ''],
    ],
    'pasargad-deposit' => [
        'sender' => 'Pasargad',
        'text'   => "واریز مبلغ 12,000,000 ريال به کارت 5022-29••-••••-4411 بابت فروش. شماره پیگیری 33001122",
        'expect' => ['amount_rial' => 12000000, 'dest_last4' => '4411', 'reference' => '33001122'],
    ],
    'withdrawal-rejected' => [
        'sender' => 'Mellat',
        'text'   => "بانک ملت\nبرداشت مبلغ 500,000 ريال از حساب شما انجام شد. مانده: 1,200,000 ريال",
        'expect' => null,
    ],
    'purchase-rejected' => [
        'sender' => 'SamanBank',
        'text'   => "خرید اینترنتی مبلغ 350,000 ريال از فروشگاه نمونه انجام شد. کد پیگیری: 111222",
        'expect' => null,
    ],
    'no-amount-rejected' => [
        'sender' => 'SomeBank',
        'text'   => "واریز وجه به حساب شما انجام شد. با تشکر از شما",
        'expect' => null,
    ],
    'non-bank-rejected' => [
        'sender' => '50004000',
        'text'   => "مشترک گرامی، بسته اینترنتی شما فعال شد. اعتبار فعلی: 50000 ریال",
        'expect' => null,
    ],
];
