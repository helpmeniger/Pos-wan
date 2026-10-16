<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    public function __construct(protected readonly View $view, protected readonly Session $session)
    {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    protected function redirect(string $path, ?string $success = null): Response
    {
        if ($success !== null) {
            $this->session->flash('success', $success);
        }
        return Response::redirect($path, 303);
    }

    protected function back(string $path, string $error): Response
    {
        $this->session->flash('error', $error);
        return Response::redirect($path, 303);
    }
}
