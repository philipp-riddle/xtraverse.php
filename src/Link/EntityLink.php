<?php

namespace Phiil\XTraverse\Link;

class EntityLink extends DataLink
{
    protected object $entity;
    protected string $setter;

    public function __construct(object &$entity, array $data, string $setter)
    {
        $this->entity = $entity;
        $this->data = $data;
        $this->setter = $setter;
    }

    public function update(array $data, string $path, $insert): self
    {
        parent::update($data, $path, $insert);
        $this->entity->{$this->setter}($this->data);

        return $this;
    }

    public function destroy(): object
    {
        return $this; // destroying an EntityLink actually does nothing - this feature exists solely for ArrayLinks to get back to the original structure & content of the variable
    }
}