<?php

namespace Phiil\XTraverse;

use Phiil\XTraverse\Exception\TraverseArrayLimitException;
use Phiil\XTraverse\Exception\TraverseException;
use Phiil\XTraverse\Exception\TraverseUpdateException;
use Phiil\XTraverse\Link\ArrayLink;
use Phiil\XTraverse\Link\DataLink;

/**
 * To obtain this service you do not need auto-wiring.
 * To create a new instance simply call $... = new TraverseService();
 * 
 * This traverser works in all classes (even small object classes).
 */
class Traverser
{
    public const PATH_DELIMITER = '.'; // example for a valid path: meta.blocks[2].title
    public const ARRAY_ADD_ELEMENT_OPERATOR = '$'; // operator in a update path to add an element to an array

    // if set to this strategy a new id will be stored in the _ids array of the given object
    public const ID_STRATEGY_STORE = 'store';

    // if set to this strategy a new id will always be dynamically generated from the given path
    // this strategy should be used if you don't want to put the _ids in the database / in the object itself
    public const ID_STRATEGY_DYNAMIC = 'dynamic';

    /**
     * This function removes the data at $path completely, meaning removing the whole key/value pair from the $data.
     * If you only want to set the $path to NULL use $this->remove(..) instead.
     */
    public function removeCompletely(array|DataLink &$dataLink, string $path): DataLink
    {
        $dataLink = $this->getDataLink($dataLink);
        $data = $dataLink->getData();
        $nodes = self::getNodes($path);
        $nodeTail = $nodes[\count($nodes) - 1];
        \array_pop($nodes);
        
        if (0 === \count($nodes)) { // when only one was given, e.g. $path = 'name' => removes name in root array
            $nodeArray = &$data;
        } else {
            $nodeArray = &$this->traverseData($nodes, $data);
        }

        if (null !== $embeddedID = $this->getEmbeddedID($nodeTail)) { // special case if the nodeTail contains an embedded ID
            if ($embeddedID[0]) {
                $nodeArray = &$nodeArray[$embeddedID[0]];
            }

            $nodeTail = $this->getNestedIdIndex($nodeArray, [], $embeddedID[1]);
        } elseif (!\array_key_exists($nodeTail, $nodeArray)) {
            throw new TraverseUpdateException(\sprintf('Invalid path: "%s". Cannot access nodeTail "%s"', $path, $nodeTail));
        }

        unset($nodeArray[$nodeTail]);

        if (!$this->isAssociative($nodeArray)) { // if it is a normal array we can also rearrange the array values to avoid gaps in between
            $nodeArray = \array_values($nodeArray);
        }

        $dataLink->update(
            $data,
            $path,
            null
        );
        $dataLinkCopy = clone $dataLink; // to return it in the end

        // This is the step where we destroy the link by setting the original DataLink back to the original link value
        if ($dataLink instanceof ArrayLink) {
            $dataLink = $dataLink->destroy();
        }

        return $dataLinkCopy;
    }

    /**
     * Sets a node to NULL at the given $path, if the nodeTail of $path is an array with an ID set (e.g. an inserted default object) this Block will be removed from the Array.
     * Use $this->removeNodeTail(...) instead if you want to remove the last element completely.
     *
     * @param array $data the data which you want to remove the data from
     * @param string $path the path to the data, works with embedded ID as well as simple paths ('blocks[1]' vs. 'hello.world')
     *
     * @throws TraverseException if the node with the given path could not be found
     *
     * @return DataLink $dataLink link with the data + path
     */
    public function remove(array|DataLink &$dataLink, string $path): DataLink
    {
        $dataLink = $this->getDataLink($dataLink);
        $data = $dataLink->getData();
        $nodes = $this->getNodes($path);
        $embeddedID = $this->getEmbeddedID($nodes[\count($nodes)-1]); // get embedded ID of node tail

        $valueToRemove = &$this->traverseData($nodes, $data, false);
        $removeCompletely = \is_array($valueToRemove) && \array_key_exists('id', $valueToRemove); // only remove the element completely if it's an array and also has an ID
        $valueToRemove = null; // set it to equals null - will be removed in the next step

        if (null !== $embeddedID) {
            $nodes[\count($nodes)-1] = $embeddedID[0]; // last element: blocks[2] => blocks
        }

        if ($removeCompletely) {
            $blocksArray = &$this->traverseData($nodes, $data); // get the parent blocks array
            $blocksArray = \array_filter($blocksArray, function ($value) {
                return null !== $value; // remove all null values
            });
            $blocksArray = \array_values($blocksArray); // reset indices
        }

        $dataLink->update(
            $data,
            $path,
            null
        );
        $dataLinkCopy = clone $dataLink; // to return it in the end

        // This is the step where we destroy the link by setting the original DataLink back to the original link value
        if ($dataLink instanceof ArrayLink) {
            $dataLink = $dataLink->destroy();
        }

        return $dataLinkCopy;
    }

    /**
     * Duplicates a block with a given ID in $data.
     *
     * @param array $data the data that should be updated
     * @param string $path the path of the block that should be duplicated, e.g. 'blocks[2]' to duplicate the block with ID 2 inside the sub array 'blocks'
     *
     * @return DataLink DataLink object containing information about the update action; check out the @return from $this->update(...) to get more information about the returned object
     */
    public function duplicateBlock(array|DataLink &$dataLink, string $path): DataLink
    {
        $dataLink = $this->getDataLink($dataLink);
        $nodes = $this->getNodes($path);
        $embeddedID = $this->getEmbeddedID($nodes[\count($nodes)-1]); // get embedded ID of node tail

        if (null === $embeddedID) {
            throw new TraverseException('No embeddedID found in path: '.$path);
        }

        $data = $dataLink->getData();
        $blockToDuplicate = $this->traverseData($nodes, $data);
        $blockToDuplicate['id'] = null; // reset ID to null, otherwise it wouldn't be set in the next step

        $insertNodes = $nodes; // copy array
        $insertNodes[\count($nodes)-1] = $embeddedID[0]; // e.g. 'blocks[1]' => 'blocks'
        $insertNodes[] = '$'; // so it gets added to the array

        return $this->update($dataLink, $this->getPath($insertNodes), $blockToDuplicate);
    }

    /**
     * Updates a data array.
     *
     * @param array $data the data you want to update
     * @param string $path the path in the data array (e.g. blocks.0.title to update the title of the first block)
     * @param mixed $insert the value you want to insert at $path. could be a string, array, ...
     * @param bool $autoIds (optional, default: true) if set to true any 'id' fields in the $insert object will be set to a new ID
     * @param string $idStrategy (optional, default: store) if set to store the ID will be stored in the _ids array of the given object; otherwise always dynamically generated from the given path
     *
     * @return DataLink DataLink object containing information about the update + the updated data. Properties: [1] 'data' => updated data, [2] 'path' => path to the updated value/object, [3] 'insert' => the data that was inserted/updated at the given path
     */
    public function update(array|DataLink &$dataLink, string $path, $insert, bool $autoIds = true, string $idStrategy = self::ID_STRATEGY_STORE): DataLink
    {
        $dataLink = $this->getDataLink($dataLink);
        $data = $dataLink->getData();
        $nodes = $this->getNodes($path);
        $nodeTail = $nodes[\count($nodes)-1]; // last element of the $nodes array
        $embeddedID = $this->getEmbeddedID($nodeTail);

        /**
         * It's getting a bit complicated if the $path ends with an embedded ID.
         * If it does not end with an embedded ID we simply remove the nodeTail and we get to the right array (always the array one level above the level you want to update a property from)
         *
         * If there's an embeddedID we traverse right down to the array in which to look for the embeddedID (blocks[5] => traverse down to 'blocks')
         * The node tail is in this case the index of the element with the ID of the embeddedID (blocks[5] => get the index of the block with ID 5 inside the 'blocks' array)
         */
        if (null === $embeddedID) {
            \array_pop($nodes); // remove the end / node tail
        } else {
            list($nodeName, $id) = $embeddedID;

            if ('' === $nodeName) { // if there's no node name it is a minimal access like '[1]' instead of 'blocks[1]'
                $nodes = [];
            } else {
                $nodes[\count($nodes)-1] = $nodeName;
            }

            $nodeTail = $this->getNestedIdIndex($data, $nodes, $id);
        }

        $subNode = &$this->traverseData($nodes, $data); // get the array to the given path
        $tailIsAddElementOperator = self::ARRAY_ADD_ELEMENT_OPERATOR === $nodeTail;

        // @todo add validation to make sure that no garbage gets inserted

        if ($tailIsAddElementOperator || \is_numeric($nodeTail)) { // add an element to an array (e.g. add a block to the blocks array)
            if ($this->isAssociative($subNode)) {
                throw new TraverseUpdateException('Trying to add an array element to an associative array. Path: '.$this->getPath($nodes));
            }

            if ($autoIds && \is_array($insert) && \array_key_exists('id', $insert) && \in_array($insert['id'], [null, 0])) { // we need to set an auto incremented ID if the ID key exists and is null or 0
                // set ID for this block / structure
                if (self::ID_STRATEGY_STORE === $idStrategy) {
                    $insert['id'] = $this->getIncrementedID($nodes, $data); // set the ID to the next available ID; info retrieved from store
                } elseif (self::ID_STRATEGY_DYNAMIC === $idStrategy) {
                    $insert['id'] = $this->getMaxId($subNode) + 1; // get the max ID of the array and add 1 to it 
                } else {
                    throw new TraverseUpdateException(\sprintf('Unknown ID strategy: %s. Available: %s, %s.', $idStrategy, self::ID_STRATEGY_STORE, self::ID_STRATEGY_DYNAMIC));
                }
            }

            $insertIndex = $tailIsAddElementOperator ? \count($subNode) : \intval($nodeTail); // either the index is given or we just add it to the end of the array
        } else { // add a simple key / value prop, e.g. setting a title with 'meta.title'
            if (!\array_key_exists($nodeTail, $subNode)) { // if the property $nodeTail does not exist in the $subnode
                throw new TraverseUpdateException(sprintf('Node tail is not accessible: %s (available: %s)', $nodeTail, \implode(', ', \array_keys($subNode))));
            }

            $insertIndex = $nodeTail;
        }

        $subNode[$insertIndex] = $insert; // insert the data
        $baseUpdatePath = $this->getPath($nodes);

        if (\is_array($insert) && \array_key_exists('id', $insert)) {
            $updatePath = \sprintf('%s[%s]', $baseUpdatePath, $insert['id']);
        } else {
            $updatePath = $baseUpdatePath.('' === $baseUpdatePath ? '' : '.').$insertIndex; // if there is no ID given just use the index of the inserted element
        }

        $dataLink->update(
            $data, // the complete $data array updated with the new value(s)
            $updatePath, // the path to the object that was updated / inserted
            $insert // the object that was updated / inserted
        );
        $dataLinkCopy = clone $dataLink; // to return it in the end

        // This is the step where we destroy the link by setting the original DataLink back to the original link value
        if ($dataLink instanceof ArrayLink) {
            $dataLink = $dataLink->destroy();
        }

        return $dataLinkCopy;
    }

    /**
     * Traverses the data with a given $path
     *
     * @param string|array $nodes the path in the $data array (e.g. blocks.0.title) OR the already splitted up nodes (e.g. ['blocks', 0, 'title'])
     * @param array &$data the concerned data
     * @param bool $traverseArrayLimit (optional, default: true) if set to true this exception will throw an error if the path ends in a node which is not an array
     *
     * @throws TraverseException if a sub node is not accessible
     *
     * @return mixed the value at $path; if $traverseArrayLimit is true the value can only be an array
     */
    public function &traverseData(string|array $nodes, array|DataLink &$dataLink, bool $traverseArrayLimit = true)
    {
        if ($dataLink instanceof DataLink) {
            $dataLink = $this->getDataLink($dataLink);
            $data = $dataLink->getData();
        } else {
            $data = &$dataLink;
        }

        $nodes = \is_array($nodes) ? $nodes : $this->getNodes($nodes);
        $current = &$data;

        if ('' === ($nodes[0] ?? null)) {
            $nodes = [];
        }

        // iterate over every node and try to access it in the sub array of the current node
        foreach ($nodes as $i => $node) {
            if (!\is_string($node) && !\is_numeric($node)) {
                throw new TraverseException(\sprintf('Current node "%s" at position %s is not a valid node (must be a string/int). Found %s instead', var_export($node, true), $i, \gettype($node)));
            }

            $embeddedID = $this->getEmbeddedID($node);

            if (null !== $embeddedID) { // has an embedded ID, e.g. blocks[2]
                list($nodeName, $embeddedID) = $embeddedID;
                
                if ('' === $nodeName) { // e.g. only passed [1] instead of blocks[1] => use the current node
                    $idElements = &$current;
                } else {
                    $idElements = &$current[$nodeName] ?? null;
                }

                if (!\is_array($idElements)) {
                    throw new TraverseException(\sprintf('Wanted to find an ID in an array but node "%s" is not an array - found %s instead.', $nodeName, \gettype($idElements)));
                }

                $current = &$this->findInArray($embeddedID, $idElements);

                if (null === $current) {
                    throw new TraverseException(\sprintf(
                        'Could not find ID %s in "%s" (full path: %s)',
                        $embeddedID,
                        $nodeName,
                        $this->getPath($nodes)
                    ));
                }
            } elseif (\array_key_exists($node, $current)) { // sub node is accessible via simple key access - traverse one layer down
                $current = &$current[$node];
            } else { // sub node is not accessible - throw exception / return null
                throw new TraverseException(\sprintf(
                    'Node is not accessible: %s (full path: %s, available in current node: %s)',
                    $node,
                    $this->getPath($nodes),
                    \implode(', ', \array_keys($current))
                ));
            }

            if (null === $current && $i < \count($nodes) - 1) { // if the current node is null and we are not at the end of the path, throw an exception
                throw new TraverseException(\sprintf(
                    'Current is null and not at the node end yet. Last accessed node: %s',
                    $node
                ));
            }

            if ($traverseArrayLimit && !\is_array($current)) {
                throw new TraverseArrayLimitException(\sprintf(
                    'Invalid path: Traversed too deep down and ended up having no array but type "%s" after accessing node "%s"',
                    \gettype($current),
                    $node
                ));
            }
        }

        return $current;
    }

    /**
     * Checks if the data has any value at the given $path.
     * This value can either be an array or a simple String value.
     * 
     * @return bool whether there is any data at the given $path
     */
    public function hasPath(string $path, array|DataLink $data): bool
    {
        try {
            $this->traverseData($path, $data, false);

            return true;
        } catch (TraverseException $ex) {
            return false;
        }
    }

    /**
     * Creates a path by setting values along $path.
     *
     * E.g. 'hello.world' results in ['hello' => ['world' => null]] being written to $data.
     * This enables us to quickly create paths that do not exist and write data to it.
     * 
     * Note that Embedded IDs (e.g. blocks[1]) also work when creating a path.
     * 
     * @throws TraverseUpdateException if an invalid path is given which traverses too deep down and ends up with having no array
     */
    public function createPath(array|DataLink &$dataLink, string $path): DataLink
    {
        $dataLink = $this->getDataLink($dataLink);
        $data = $dataLink->getData();
        $current = &$data;
        $nodes = self::getNodes($path);

        if ('' === $path || empty($nodes)) {
            return $dataLink;
        }

        foreach ($nodes as $i => $node) {
            $isLastNode = $i === \count($nodes) - 1;

            if ($isLastNode && !\is_array($current)) {
                throw new TraverseUpdateException(\sprintf('Creating path "%s" is not possible because it traverses too deep down and does not end up in an array at node "%s"', $path, $node));
            }

            if (null !== $embeddedId = $this->getEmbeddedID($node)) { // node has an embedded ID inside - this is a special case
                $currentChildren = $current[$embeddedId[0]] ?? [];

                try {
                    $idIndex = $this->getNestedIdIndex($currentChildren, [], $embeddedId[1]); // try to find the ID in the current subnode
                    $current = &$current[$embeddedId[0]][$idIndex]; // already exists - simply setup the reference to it and save it to $current
                } catch (TraverseException $ex) {
                    // an exception was thrown because the ID could not be found in the children.
                    // => add an empty element array if no element with that ID already exists 
                    $current[$embeddedId[0]] = \array_merge($currentChildren, [
                        ['id' => $embeddedId[1]]
                    ]);

                    $current = &$current[$embeddedId[0]][\count($currentChildren)]; // go into the element where the ID was set
                }
            } else {
                // only overwrite the value in the current node if either the array key does not exist or the value is NULL - then we can safely overwrite it without having to worry
                if (!\array_key_exists($node, $current) || null === $current[$node]) {
                    $current[$node] = $isLastNode ? null : []; // extend the array - we'll dive it into it in the next iteration step (if given, otherwise set it to NULL)
                }

                $current = &$current[$node];
            }
        }

        $dataLink->update(
            $data, // the complete $data array updated with the new value(s)
            $path, // the path to the object that was updated / inserted
            null // the object that was updated / inserted
        );
        $dataLinkCopy = clone $dataLink; // to return it in the end

        // This is the step where we destroy the link by setting the original DataLink back to the original link value
        if ($dataLink instanceof ArrayLink) {
            $dataLink = $dataLink->destroy();
        }

        return $dataLinkCopy;
    }

    /**
     * Returns the incremented ID for a given $nodes path.
     * With the $nodes path we know in which array to insert and we keep a counter for every array we add to
     *
     * @param array $nodes the path to the array you want to add a block to and need an ID for
     * @param array $data the data which gets edited in the process (sub-array '_ids' gets changed)
     *
     * @return int the auto incremented ID
     */
    public function getIncrementedID(array $nodes, array &$data): int
    {
        $idName = \implode(self::PATH_DELIMITER, $nodes);

        if (!isset($data['_ids'][$idName])) {
            $data['_ids'][$idName] = 0; // sub-array does not exist yet - let's initialize it with 0
        }

        $data['_ids'][$idName]++;

        return $data['_ids'][$idName];
    }

    /**
     * Finds an element with a given ID in an array / "nodeHaystack"
     *
     * @param int $id the ID that the other array should be scanned for
     * @param array &$nodeHaystack the array in which to look for the right ID element
     *
     * @return array|null returns the array with the matching ID if it could be found, null otherwise
     */
    public function &findInArray(int $id, array &$nodeHaystack): ?array
    {
        $foundElement = null; // preset: null. if no matching ID gets found it stays null

        foreach ($nodeHaystack as &$element) {
            if (($element['id'] ?? null) === $id) { // if the IDs match return the element
                $foundElement = &$element;

                break;
            }
        }

        return $foundElement;
    }

    /**
     * Find all IDs there are in a specific destination of the $data array.
     * This function could e.g. be used to get all block IDs of a $data array.
     *
     * @return array all IDs from the defined $nodes array
     */
    public function getNestedIds(array $data, array $nodes, string $type = null, string $typeGroup = null): array
    {
        $elements = $this->traverseData($nodes, $data);

        if (null !== $type || null !== $typeGroup) {
            $elements = \array_filter($elements, function ($value) use ($type, $typeGroup) {
                if (null !== $type && $type !== ($value['type'] ?? null)) { // only elements that match the given $type
                    return false;
                }

                if (null !== $typeGroup && $typeGroup !== ($value['typeGroup'] ?? null)) { // only elements that match the given $typeGroup
                    return false;
                }

                return true;
            });
        }

        return \array_values(\array_column($elements, 'id'));
    }

    /**
     * Get the index of the element with a given ID with given nodes.
     *
     * @throws TraverseException if the ID could not be found
     *
     * @return int the index of the element with the given ID in the given nodes destination array
     */
    public function getNestedIdIndex(array $data, array $nodes, int $id): ?int
    {
        $elements = $this->traverseData($nodes, $data);

        foreach ($elements as $i => $element) {
            if (($element['id'] ?? null) === $id) { // if the IDs match return the index of the element
                return $i;
            }
        }

        throw new TraverseException(\sprintf('The ID "%s" could not be found inside %s.', $id, $this->getPath($nodes)));
    }

    public function getMaxId(array $elements): int
    {
        $maxId = 0;

        foreach ($elements as $element) {
            $maxId = \max($maxId, $element['id'] ?? 0);
        }

        return $maxId;
    }

    /**
     * Returns the embedded ID in a node string.
     * For example: blocks[2] => embedded ID: 2
     *
     * @param string $node the current node to analyse
     *
     * @return array|null either an array with [nodeName, embedded ID] or if there is no ID it returns null
     */
    public function getEmbeddedID(string $node): ?array
    {
        // get the position where the brackets open
        $parenthesesOpen = \strpos($node, '[');
        // offset the strpos so that the program only searches for brackets after this position
        $parenthesesClosed = \strpos($node, ']', false !== $parenthesesOpen ? $parenthesesOpen : 0);

        if (false === $parenthesesOpen || false === $parenthesesClosed) {
            return null; // no embedded ID found
        }

        if ($parenthesesClosed !== strlen($node) - 1) { // e.g. blocks[2]invalid
            throw new TraverseException('Illegal content after parentheses close: '.\substr($node, $parenthesesClosed, strlen($node)-$parenthesesClosed));
        }

        $embeddedID = \substr($node, $parenthesesOpen + 1, $parenthesesClosed - $parenthesesOpen - 1); // + 1 & - 1 to remove the brackets on each side
        $nodeName = \substr($node, 0, $parenthesesOpen); // returns the node in which to look for the ID (e.g. blocks[2] => blocks)

        return [$nodeName, \intval($embeddedID)];
    }

    /**
     * Returns all occurrences of a given $type in the given $data.
     * This can be used to find specific blocks in an array.
     * 
     * @return array all occurrences of the given $type; each element has 'path' and 'id' (which is nullable)
     */
    public function findTypesInData(array $data, string $type, int $maxDepth = 100, array $visitedNodes = [], array &$foundObjects = []): array
    {
        if (\count($visitedNodes) >= $maxDepth) { // to prevent the program from going into an infinite loop
            return $foundObjects;
        }

        if (!\is_array($data)) { // no need to investigate further
            return $foundObjects;
        }

        if ($type === ($data['type'] ?? null)) {
            $foundObjects[] = [
                'id' => $data['id'] ?? null,
                'path' => $this->getPath($visitedNodes),
            ];
        }

        foreach ($data as $nodeName => $node) {
            if (\is_array($node)) {
                $visitedNotesChild = $visitedNodes; // create a copy of the array
                $visitedNotesChild[] = $nodeName;
                $this->findTypesInData($node, $type, $maxDepth, $visitedNotesChild, $foundObjects); // recursive call
            }
        }

        return $foundObjects;
    }

    /**
     * New system which makes it *way* easier to update entities & objects in fewer lines of code.
     * These links basically glue the entity + the property together - if it gets updated the entity property will also update. This would not be possible otherwise.
     */
    public function getDataLink(array|DataLink &$dataLink): DataLink
    {
        if ($dataLink instanceof DataLink) {
            return $dataLink; // already a DataLink - go aheaad!
        }

        return new ArrayLink($dataLink); // simply create a new ArrayLink object with the array
    }

    /**
     * Get nodes from a given string.
     *
     * @param string $nodePath the node path, e.g. blocks.0.title
     *
     * @return array the node elements, e.g. blocks.0.title => [blocks, 0, title]
     */
    public static function getNodes(string $nodePath): array
    {
        return \explode(self::PATH_DELIMITER, $nodePath);
    }

    public static function getPath(array $nodes): string
    {
        return \implode(self::PATH_DELIMITER, $nodes);
    }

    /**
     * @return bool returns whether the given $array is associative (= has any string keys)
     */
    public function isAssociative(array $array): bool
    {
        return \count(\array_filter(\array_keys($array), 'is_string')) > 0;
    }
}