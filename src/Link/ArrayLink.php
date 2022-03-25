<?php

namespace Phiil\XTraverse\Link;

/**
 * This link makes it possible to create a link with just an array
 */
class ArrayLink extends DataLink
{
    public function __construct(array &$data)
    {
        $this->data = $data; // already defined in the base Data Link
    }
}