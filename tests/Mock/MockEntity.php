<?php

namespace Phiil\XTraverse\Tests\Mock;

class MockEntity
{
    protected array $data;

    public function __construct()
    {
        $this->data = [];   
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }
}