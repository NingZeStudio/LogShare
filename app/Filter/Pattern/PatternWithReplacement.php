<?php

namespace App\Filter\Pattern;

class PatternWithReplacement extends Pattern
{
    public function __construct(
        string $pattern,
        protected string $replacement
    ) {
        parent::__construct($pattern);
    }

    /**
     * @return string
     */
    public function getReplacement(): string
    {
        return $this->replacement;
    }
}
