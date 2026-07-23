<?php

namespace Filter\Pattern;

class Pattern
{
    public function __construct(
        protected string $pattern
    ) {
    }

    /**
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }
}
