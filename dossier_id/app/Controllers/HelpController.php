<?php
declare(strict_types=1);

namespace App\Controllers;

final class HelpController extends Controller
{
    public function index(): string
    {
        return $this->view('help/index', ['title' => 'Documentation et assistance']);
    }
}
