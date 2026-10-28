<?php
declare(strict_types=1);
namespace Inventory;

/** Session-owned approval state, never accept reviewed rows back from the browser. */
final class BulkPreview
{
    public static function create(array $plan): array
    {
        return ['token' => bin2hex(random_bytes(32)), 'expires' => time() + 1800, 'plan' => $plan];
    }

    public static function display(array $preview): array
    {
        $rows = array_map(static fn ($row) => array_intersect_key($row, array_flip(['id', 'name', 'holder', 'diffs'])), $preview['plan']['rows']);
        $changed = count(array_filter($rows, static fn ($row) => (bool) $row['diffs']));
        return ['token' => $preview['token'], 'rows' => $rows, 'changed' => $changed, 'unchanged' => count($rows) - $changed];
    }

    public static function approved(?array $preview, array $input): array
    {
        if (!$preview || $preview['expires'] <= time() || !is_string($input['token'] ?? null)
            || !hash_equals($preview['token'], $input['token'])) {
            throw new ValidationException('Bulk preview expired or was replaced. Review again.', 409);
        }
        return $preview['plan'];
    }
}
