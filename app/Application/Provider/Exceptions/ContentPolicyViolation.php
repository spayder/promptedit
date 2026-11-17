<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** The prompt was rejected (400 content_policy_violation). Nothing was created or billed. */
final class ContentPolicyViolation extends ProviderException
{
}
