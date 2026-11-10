<?php
/**
 * Peakopay — Admin: Android devices (pairing codes + QR, approve/revoke/rename).
 */
declare(strict_types=1);

final class DevicesController
{
    public static function index(): void
    {
        $user  = AdminGuard::require();
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        View::page('admin/devices', [
            'title'     => 'دستگاه‌های اندروید',
            'user'      => $user,
            'store'     => $store,
            'devices'   => $sid > 0 ? Device::all($sid) : [],
            'pairing'   => Session::flash('pairing'),
            'activeNav' => 'devices',
        ]);
    }

    /** Generate a fresh pairing code (invalidates previous unused codes). */
    public static function newCode(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'devices_manage', 'ساخت کد همراه‌سازی');
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        [$code, $expires] = Device::issuePairingCode($sid);
        Session::flash('pairing', json_encode([
            'code'    => $code,
            'expires' => $expires,
            'url'     => Config::baseUrl(),
            'qr'      => 'peakopay://pair?host=' . rawurlencode(Config::baseUrl()) . '&code=' . $code,
        ], JSON_UNESCAPED_SLASHES));
        Http::redirect(Config::url('admin/devices'));
    }

    public static function action(): void
    {
        $user = AdminGuard::requirePost();
        AdminGuard::denyUnless($user, 'devices_manage', 'مدیریت دستگاه‌ها');
        $store = Store::current();
        $sid   = (int) ($store['id'] ?? 0);
        $do    = (string) Http::post('do', '');
        $id    = (int) Http::post('device_id', 0);
        $device = Device::find($id);
        if ($device === null || (int) $device['store_id'] !== $sid) {
            Session::flash('error', 'دستگاه یافت نشد.');
            Http::redirect(Config::url('admin/devices'));
        }
        switch ($do) {
            case 'approve':
                Device::setStatus($id, $sid, 'active');
                Session::flash('success', 'دستگاه «' . $device['name'] . '» تأیید شد و از این پس می‌تواند تراکنش ارسال کند.');
                break;
            case 'revoke':
                Device::setStatus($id, $sid, 'revoked');
                Session::flash('success', 'دسترسی دستگاه «' . $device['name'] . '» لغو شد.');
                break;
            case 'rename':
                $name = trim((string) Http::post('name', ''));
                if (mb_strlen($name) < 2) {
                    Session::flash('error', 'نام دستگاه باید حداقل ۲ حرف باشد.');
                } else {
                    Device::rename($id, $sid, $name);
                    Session::flash('success', 'نام دستگاه تغییر کرد.');
                }
                break;
            case 'delete':
                if (in_array($device['status'], ['revoked'], true)) {
                    DB::q('DELETE FROM devices WHERE id = ? AND store_id = ?', [$id, $sid]);
                    Session::flash('success', 'دستگاه حذف شد.');
                } else {
                    Session::flash('error', 'ابتدا دسترسی دستگاه را لغو کنید، سپس حذف کنید.');
                }
                break;
            default:
                Session::flash('error', 'عملیات نامعتبر.');
        }
        Http::redirect(Config::url('admin/devices'));
    }
}
