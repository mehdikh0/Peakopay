<?php
/**
 * Peakopay — Tiny view renderer with layouts.
 *
 * View::page('admin/dashboard', $data)  → renders the view, captures it into
 * $content and wraps it in app/Views/layout_admin.php.
 * View::renderPlain('partials/404')     → renders without a layout.
 */
declare(strict_types=1);

final class View
{
    public static function page(string $template, array $data = [], string $layout = 'layout_admin'): void
    {
        $content = self::capture($template, $data);
        echo self::capture($layout, array_merge($data, ['content' => $content]));
    }

    public static function renderPlain(string $template, array $data = []): void
    {
        echo self::capture($template, $data);
    }

    private static function capture(string $template, array $vars): string
    {
        $file = HP_BASE . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$template}");
        }
        // نکته حیاتی: پارامتر نباید $data نام‌گذاری شود — چون extract کلید «data»
        // را می‌سازد و با EXTR_SKIP متغیر view تصادماً حذف می‌شد (باگ صفحه عیب‌یابی
        // که هیچ‌وقت چک‌هایش را نشان نمی‌داد — دور ۱۸).
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
