<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

/** Closed first-release bounds for one variable-product authority catalog. */
final class VariableProductLimits
{
    public const MAX_VARIATIONS = 1000;
    public const MAX_AXES = 16;
    public const MAX_VALUES_PER_AXIS = 128;
}
