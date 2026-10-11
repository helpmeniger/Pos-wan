<?php
declare(strict_types=1);

namespace App\Support;

final class Paginator
{
    public readonly int $page;
    public readonly int $pages;
    public readonly int $offset;

    public function __construct(public readonly int $total, int $page, public readonly int $perPage = 20)
    {
        $this->pages = max(1, (int) ceil($total / $perPage));
        $this->page = min(max(1, $page), $this->pages);
        $this->offset = ($this->page - 1) * $perPage;
    }

    public static function fromRequest(int $total, int $perPage = 20): self
    {
        return new self($total, (int) ($_GET['page'] ?? 1), $perPage);
    }

    /** Liens de pagination conservant les filtres courants. */
    public function links(string $route, array $query = []): string
    {
        if ($this->pages <= 1) {
            return '';
        }
        unset($query['r'], $query['page']);
        $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
        $window = range(max(1, $this->page - 2), min($this->pages, $this->page + 2));
        $item = function (int $p, string $label, bool $disabled = false, bool $active = false) use ($route, $query): string {
            $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
            return '<li class="' . $cls . '"><a class="page-link" href="' . e(url($route, $query + ['page' => $p])) . '">' . $label . '</a></li>';
        };
        $html .= $item(max(1, $this->page - 1), '&laquo;', $this->page === 1);
        if ($window[0] > 1) {
            $html .= $item(1, '1') . ($window[0] > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
        }
        foreach ($window as $p) {
            $html .= $item($p, (string) $p, false, $p === $this->page);
        }
        $last = end($window);
        if ($last < $this->pages) {
            $html .= ($last < $this->pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $item($this->pages, (string) $this->pages);
        }
        $html .= $item(min($this->pages, $this->page + 1), '&raquo;', $this->page === $this->pages);
        return $html . '</ul></nav>';
    }

    public function summary(): string
    {
        if ($this->total === 0) {
            return 'Aucun résultat';
        }
        $from = $this->offset + 1;
        $to = min($this->total, $this->offset + $this->perPage);
        return "Résultats $from à $to sur {$this->total}";
    }
}
