<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Moteur de vues PHP natif avec gabarits (layouts) et sections.
 * Toute donnée affichée doit passer par e() pour éviter les failles XSS.
 */
final class View
{
    /** Variables qu'une vue peut définir pour son gabarit (ex. $title = '…'). */
    private const LAYOUT_VARS = ['title', 'cardClass'];

    private static array $sections = [];
    private static array $sectionStack = [];
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($view, $data);
        if ($layout === null) {
            return $content;
        }
        self::$sections['content'] = $content;
        return self::renderFile($layout, $data);
    }

    public static function renderFile(string $view, array $data = []): string
    {
        $file = VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Vue introuvable : {$view}");
        }
        extract(array_merge(self::$shared, $data), EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        foreach (self::LAYOUT_VARS as $__var) {
            if (isset($$__var)) {
                self::$shared[$__var] = $$__var;
            }
        }
        return (string) ob_get_clean();
    }

    public static function partial(string $view, array $data = []): string
    {
        return self::renderFile($view, $data);
    }

    public static function start(string $name): void
    {
        self::$sectionStack[] = $name;
        ob_start();
    }

    public static function stop(): void
    {
        $name = array_pop(self::$sectionStack);
        self::$sections[$name] = (self::$sections[$name] ?? '') . ob_get_clean();
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }
}
