<?php

namespace Filter\Pattern;

class PatternWithReplacement extends Pattern
{
    public function __construct(
        protected string $pattern,
        protected string $replacement
    ) {
    }

    /**
     * @return string
     */
    public function getReplacement(): string
    {
        return $this->replacement;
    }
}
