<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Rend une vue dans un gabarit. Les vues utilisent e() pour tout affichage. */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): void
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo self::capture($layout, $data + ['content' => $content]);
    }

    public static function capture(string $view, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!preg_match('#^[a-z0-9_/\-]+$#i', $view) || !is_file($file)) {
            throw new \RuntimeException('Vue introuvable : ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
