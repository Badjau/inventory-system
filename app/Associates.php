<?php
declare(strict_types=1);

namespace Inventory;

use PDO;

// Inventory contacts only. These records are never attendance employees or login accounts.
final class Associates
{
    public function __construct(private PDO $db) {}

    public function all(): array
    {
        return $this->db->query("SELECT id, name, job_title, 'Other' AS branch FROM inv_associates ORDER BY name, id")->fetchAll();
    }

    public function find(int $id): array
    {
        $query = $this->db->prepare("SELECT id, name, job_title, 'Other' AS branch FROM inv_associates WHERE id = ?");
        $query->execute([$id]);
        return $query->fetch() ?: throw new ValidationException('Associate no longer exists.', 404);
    }

    public function add(array $input): array
    {
        $name = InventoryService::text($input, 'name', true);
        $title = InventoryService::text($input, 'job_title');
        $query = $this->db->prepare('INSERT INTO inv_associates (name, job_title) VALUES (?, ?)');
        $query->execute([$name, $title]);
        return $this->find((int) $this->db->lastInsertId());
    }
}
