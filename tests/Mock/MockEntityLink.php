<?php

namespace Phiil\XTraverse\Tests\Mock;

use Phiil\XTraverse\Link\EntityLink;
use Phiil\XTraverse\Tests\Mock\MockEntity;

class MockEntityLink extends EntityLink
{
    public function __construct(MockEntity $mockEntity)
    {
        parent::__construct($mockEntity, $mockEntity->getData(), 'setData');
    }
}