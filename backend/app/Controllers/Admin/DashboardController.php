<?php
/**
 * Peakopay — Admin dashboard.
 */
declare(strict_types=1);

final class DashboardController
{
    public static function index(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);

        $stats     = $sid > 0 ? Payment::statsToday($sid) : ['paid_count' => 0, 'paid_income' => 0, 'pending' => 0, 'expired' => 0, 'failed' => 0];
        $total     = $sid > 0 ? Payment::totalIncome($sid) : 0;
        $daily     = $sid > 0 ? Payment::dailyIncome($sid, 14) : [];
        $txnStats  = $sid > 0 ? Transaction::countByStatus($sid) : [];
        $devices   = $sid > 0 ? Device::all($sid) : [];
        $cards     = $sid > 0 ? Card::all($sid) : [];
        $recentPay = $sid > 0 ? Payment::paginate($sid, 1, 7)['rows'] : [];
        $recentTxn = $sid > 0 ? Transaction::paginate($sid, 1, 7)['rows'] : [];
        $wooOk     = Setting::get('woo_site_url') !== '' && Setting::get('woo_webhook_secret') !== '' && ApiKey::maskedActive($sid) !== null;

        View::page('admin/dashboard', [
            'title'      => 'داشبورد',
            'user'       => $user,
            'store'      => $store,
            'stats'      => $stats,
            'total'      => $total,
            'daily'      => $daily,
            'txnStats'   => $txnStats,
            'devices'    => $devices,
            'cards'      => $cards,
            'recentPay'  => $recentPay,
            'recentTxn'  => $recentTxn,
            'wooOk'      => $wooOk,
            'activeNav'  => 'dashboard',
        ]);
    }
}
