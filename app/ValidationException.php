<?php
declare(strict_types=1);
namespace Inventory;

final class ValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $conflicts = [])
    {
        parent::__construct($message);
    }
}
