<?php
declare(strict_types=1);

namespace Inventory;

use PDO;

final class HrDirectory
{
    public function __construct(private PDO $db) {}

    // Authentication-only projections. Never include these in directory/API data.
    public function loginUser(string $login): ?array
    {
        $query = $this->db->prepare('SELECT id, name, username, email, password, is_admin, is_it_admin
            FROM users WHERE username = ? OR email = ? LIMIT 2');
        $query->execute([$login, $login]);
        $matches = $query->fetchAll();
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function loginUserById(int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, name, username, email, password, is_admin, is_it_admin
            FROM users WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function hasInventoryEditor(): bool
    {
        return $this->db->query('SELECT id FROM users WHERE is_admin = 1 OR is_it_admin = 1 LIMIT 1')->fetchColumn() !== false;
    }

    public function all(): array
    {
        // Explicit projection excludes birthdays, passwords, attendance and biometrics.
        return $this->db->query("SELECT e.id, e.employee_id,
            TRIM(CONCAT_WS(' ', e.first_name, e.last_name)) AS name,
            e.position AS job_title, e.role AS hr_role, e.department_id,
            COALESCE(d.name, '') AS department,
            COALESCE((SELECT b.name FROM branch_employee be
                JOIN branches b ON b.id = be.branch_id WHERE be.employee_id = e.id
                ORDER BY be.is_primary DESC, be.id ASC LIMIT 1), e.branch, '') AS branch
            FROM employees e LEFT JOIN departments d ON d.id = e.department_id
            ORDER BY e.first_name, e.last_name, e.id")->fetchAll();
    }

    public function find(int $id): array
    {
        foreach ($this->all() as $employee) {
            if ((int) $employee['id'] === $id) {
                return $employee;
            }
        }
        throw new ValidationException('That HR employee no longer exists. Refresh the employee list.');
    }

    public function branches(): array
    {
        return array_map(static fn (array $branch): array => ['id' => (int) $branch['id'], 'name' => $branch['name']],
            $this->db->query('SELECT id, name FROM branches ORDER BY name, id')->fetchAll());
    }

    public function branch(int $id): array
    {
        foreach ($this->branches() as $branch) {
            if ($branch['id'] === $id) return $branch;
        }
        throw new ValidationException('That location branch no longer exists in HR. Refresh the branch list.');
    }

    public function branchNamed(string $name): array
    {
        $matches = array_values(array_filter($this->branches(),
            static fn (array $branch): bool => mb_strtoupper(trim($branch['name'])) === mb_strtoupper(trim($name))));
        if (count($matches) !== 1) throw new ValidationException('Location must match exactly one HR branch: ' . $name);
        return $matches[0];
    }

    public function departments(): array
    {
        return array_map(static fn (array $department): array => ['id' => (int) $department['id'], 'name' => $department['name']],
            $this->db->query('SELECT id, name FROM departments ORDER BY name, id')->fetchAll());
    }

    public function department(int $id): array
    {
        foreach ($this->departments() as $department) {
            if ($department['id'] === $id) return $department;
        }
        throw new ValidationException('That HR department no longer exists. Refresh the department list.');
    }
}
