<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Provider\PricingSnapshot;
use App\Application\Provider\Exceptions\ProviderException;

/**
 * Pixelforge's current prices and token tables, used to estimate a cost before
 * a generation exists. Never used for billing: a finished generation is billed
 * with the rates Pixelforge froze on it.
 */
interface PricingSource
{
    /** @throws ProviderException when nothing current or remembered is available */
    public function current(): PricingSnapshot;
}
