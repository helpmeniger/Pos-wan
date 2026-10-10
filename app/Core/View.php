<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Rendu d'un template (app/Views/<name>.php) dans un layout. Les variables sont échappées dans les vues via e(). */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/admin'): string
    {
        $file = APP_PATH . '/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Vue introuvable : $template");
        }
        $content = (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        })($file, $data);
        if ($layout === null) {
            return $content;
        }
        $data['content'] = $content;
        return self::render($layout, $data, null);
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::render($template, $data, null);
    }
}
