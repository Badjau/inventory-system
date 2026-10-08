<?php
declare(strict_types=1);
namespace Inventory;

final class ValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
