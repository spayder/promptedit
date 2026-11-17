<?php

declare(strict_types=1);

namespace App\Application\Generation;

use RuntimeException;

final class GenerationNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Generation {$id} not found.");
    }
}
