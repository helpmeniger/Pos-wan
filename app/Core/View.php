<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Moteur de vues PHP natif. Une vue peut déclarer son gabarit avec $this->layout('layouts/app')
 * et des blocs nommés avec $this->start('nom') … $this->stop(). Toujours échapper avec e().
 */
final class View
{
    private ?string $layout = null;
    /** @var array<string, string> */
    private array $sections = [];
    private ?string $currentSection = null;
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $viewsDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $this->layout = null;
        $this->sections = [];
        $content = $this->renderFile($template, $data);
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            $this->sections['content'] ??= $content;
            $content = $this->renderFile($layout, $data);
        }
        return $content;
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $saved = [$this->layout, $this->sections, $this->currentSection];
        $out = $this->renderFile($template, $data);
        [$this->layout, $this->sections, $this->currentSection] = $saved;
        return $out;
    }

    public function layout(string $name): void
    {
        $this->layout = $name;
    }

    public function start(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function stop(): void
    {
        if ($this->currentSection === null) {
            throw new RuntimeException('stop() sans start()');
        }
        $this->sections[$this->currentSection] = (string) ob_get_clean();
        $this->currentSection = null;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#i', $template) || str_contains($template, '..')) {
            throw new RuntimeException("Nom de vue invalide : {$template}");
        }
        $file = $this->viewsDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vue introuvable : {$template}");
        }
        $vars = array_merge($this->shared, $data);
        $level = ob_get_level();
        ob_start();
        try {
            (function () use ($file, $vars) {
                extract($vars, EXTR_SKIP);
                require $file;
            })->call($this);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
