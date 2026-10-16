<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Lecture du catalogue des métiers (catégories / sous-catégories actives). */
class CatalogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> catégories actives avec nombre de métiers et d'annonces ouvertes */
    public function categoriesWithCounts(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.slug, c.name, c.description, c.icon,
                    (SELECT COUNT(*) FROM job_subcategories s WHERE s.category_id = c.id AND s.is_active = 1) AS trades_count,
                    (SELECT COUNT(*) FROM job_posts p WHERE p.category_id = c.id
                       AND p.status IN ('PUBLIEE','CANDIDATURES_OUVERTES') AND p.is_demo = 0) AS open_posts
             FROM job_categories c WHERE c.is_active = 1 ORDER BY c.sort_order, c.name"
        );
    }

    /** @return array<string, mixed>|null */
    public function categoryBySlug(string $slug): ?array
    {
        return $this->db->fetch('SELECT * FROM job_categories WHERE slug = ? AND is_active = 1', [$slug]);
    }

    /** @return list<array<string, mixed>> */
    public function subcategories(int $categoryId): array
    {
        return $this->db->fetchAll(
            'SELECT id, slug, name, risk_level, min_age, required_verification_level, requires_qualification,
                    involves_minors_or_vulnerable, is_remote_possible
             FROM job_subcategories WHERE category_id = ? AND is_active = 1 ORDER BY sort_order, name',
            [$categoryId]
        );
    }

    public function countTrades(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM job_subcategories WHERE is_active = 1');
    }
}
