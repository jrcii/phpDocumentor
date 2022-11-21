<?php

declare(strict_types=1);

namespace phpDocumentor\Descriptor\Traits;

trait HasName
{
    protected string $name = '';

    /**
     * Sets the name for this element.
     *
     * @internal should not be called by any other class than the assemblers.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Returns the name for this element.
     */
    public function getName(): string
    {
        return $this->name;
    }
}
