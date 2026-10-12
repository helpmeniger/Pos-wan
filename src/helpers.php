<?php
declare(strict_types=1);

/** Échappement HTML (protection XSS). */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
