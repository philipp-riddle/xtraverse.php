<?php

namespace Phiil\XTraverse\Link;

/**
 * This class makes it very easy to link the data to the traversals / updates, called by any action of the TraverseService
 */
abstract class DataLink
{
    public array $data;
    public string $path = '';
    public mixed $insert = null;

    /**
     * Updates the link to the specified data.
     */
    public function update(array $data, string $path, $insert): self
    {
        $this->data = $data;
        $this->path = $path;
        $this->insert = $insert;

        return $this;
    }

    /**
     * This class gets called by the TraverseService to let the link know that the data changed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * This function gets called when the link gets "dissolved" and is not longer needed.
     */
    public function destroy()
    {
        return $this->getData();
    }
}