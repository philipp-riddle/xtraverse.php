<?php

namespace Phiil\XTraverse\Tests\Service;

use Phiil\XTraverse\Exception\TraverseException;
use Phiil\XTraverse\Exception\TraverseUpdateException;
use Phiil\XTraverse\Service\XTraverseService;
use PHPUnit\Framework\TestCase;

class XTraverseServiceTest extends TestCase
{
    public function testTraverseData_topLayer()
    {
        $data = ['title' => 'old'];
        $traversed = $this->getTraverseService()->traverseData([], $data);

        $this->assertEquals($data, $traversed);
    }

    public function testTraverseData_topLayer_tooDeep()
    {
        $data = ['title' => 'old'];

        $this->expectException(TraverseException::class, 'Traverse Data did not throw an error although it traversed down to a string');
        $this->getTraverseService()->traverseData(['title'], $data);
    }

    public function testTraverseData_oneLayer()
    {
        $data = ['title' => ['first' => 'yes!']];
        $traversed = $this->getTraverseService()->traverseData(['title'], $data);

        $this->assertEquals($data['title'], $traversed);
    }

    public function testTraverseData_oneLayer_notAccessible()
    {
        $data = ['title' => ['first' => 'yes!']];

        $this->expectException(TraverseException::class, 'Traverse Data did not throw an error although a sub element is not accessible.');
        $this->getTraverseService()->traverseData(['cats'], $data);
    }

    public function testTraverseData_oneLayer_withEmbeddedID()
    {
        $data = ['blocks' => [
            ['id' => 2,'title' => 'asd'],
        ]];

        $this->assertEquals($data['blocks'][0], $this->getTraverseService()->traverseData(['blocks[2]'], $data));
    }

    public function testTraverseData_oneLayer_withNotExistingEmbeddedID()
    {
        $data = ['blocks' => [
            ['id' => 2,'title' => 'asd'],
        ]];

        $this->expectException(TraverseException::class, 'Traverse Data did not throw an error although the embedded ID does not exist in the subnode.');
        $this->getTraverseService()->traverseData(['blocks[5]'], $data);
    }

    public function testUpdate_topLayer()
    {
        $data = ['title' => 'old'];
        $update = $this->getTraverseService()->update($data, 'title', 'new');

        $this->assertEquals('title', $update->path);
        $this->assertEquals('new', $update->data['title']);
    }

    public function testUpdate_oneLayerDown()
    {
        $data = ['meta' => ['title' => 'old']];
        $update = $this->getTraverseService()->update($data, 'meta.title', 'new');

        $this->assertEquals('meta.title', $update->path);
        $this->assertEquals('new', $update->data['meta']['title']);
    }

    public function testUpdate_oneLayerDown_notAccessible()
    {
        $data = ['meta' => ['title' => 'old']];

        $this->expectException(TraverseUpdateException::class, 'Traverse Data did not throw an error although the node tail is not accessible');
        $this->getTraverseService()->update($data, 'meta.description', 'new');
    }

    public function testUpdate_oneLayerDown_addArrayElement()
    {
        $data = ['blocks' => []];
        $insert = ['id' => null, 'content' => 'asd'];
        $update = $this->getTraverseService()->update($data, 'blocks.$', $insert);

        $this->assertEquals('blocks[1]', $update->path);
        $this->assertCount(1, $update->data['blocks']);
        $this->assertEquals($insert['content'], $update->data['blocks'][0]['content']);
    }

    public function testUpdate_oneLayerDown_addArrayElement_toAssociative()
    {
        $data = ['blocks' => ['associative' => true]];
        $insert = ['content' => 'asd'];

        $this->expectException(TraverseUpdateException::class, 'Update Data did not throw an error although an array element was added to an associative array');
        $this->getTraverseService()->update($data, 'blocks.$', $insert);
    }

    public function testUpdate_withAutomaticallyIncrementedID()
    {
        $data = ['blocks' => []];
        $insert = ['id' => null, 'content' => 'asd'];
        $update = $this->getTraverseService()->update($data, 'blocks.$', $insert);

        $this->assertEquals('blocks[1]', $update->path);
        $this->assertEquals(1, $update->data['blocks'][0]['id']);
        $this->assertCount(1, $update->data['_ids']);
    }
    
    public function testUpdate_withAutomaticallyIncrementedID_existing()
    {
        $data = ['blocks' => [], '_ids' => ['blocks' => 5]];
        $insert = ['id' => null, 'content' => 'asd'];
        $update = $this->getTraverseService()->update($data, 'blocks.$', $insert);

        $this->assertEquals('blocks[6]', $update->path);
        $this->assertEquals(6, $update->data['blocks'][0]['id'], 'ID was not correctly incremented.');
        $this->assertCount(1, $update->data['_ids'], 'Too many IDs in the "_ids" array of the data.');
    }

    public function testUpdate_withEmbeddedID_existing()
    {
        $data = ['blocks' => [
            ['id' => 5, 'title' => 'old'],
        ]];
        $insert = 'new'; // the new title
        $update = $this->getTraverseService()->update($data, 'blocks[5].title', $insert);

        $this->assertEquals('blocks[5].title', $update->path);
        $this->assertEquals($insert, $update->data['blocks'][0]['title'], 'Title was not correctly updated.');
    }

    public function testUpdate_withEmbeddedID_notExisting()
    {
        $data = ['blocks' => [
            ['id' => 5, 'title' => 'old'],
        ]];
        $insert = 'new'; // the new title

        $this->expectException(TraverseException::class, 'Update did not throw an error although the provided embedded ID does not exist.');
        $this->getTraverseService()->update($data, 'blocks[10].title', $insert);
    }

    public function testUpdate_twoLayersDown_setBoolean()
    {
        $data = ['settings' => ['hideShareButtons' => ['hideAll' => true]]];
        $insert = false; // set 'hideAll' to false
        $update = $this->getTraverseService()->update($data, 'settings.hideShareButtons.hideAll', $insert);
        $updatedData = $update->data;

        $this->assertEquals($insert, $updatedData['settings']['hideShareButtons']['hideAll']);
    }

    public function testUpdate_incrementCorrectlyWithZeroId()
    {
        $data = ['blocks' => []];
        $insert = ['id' => 0, 'content' => 'asd'];

        $update = $this->getTraverseService()->update($data, 'blocks.$', $insert);
        $this->assertEquals('blocks[1]', $update->path);

        $update = $this->getTraverseService()->update($update->data, 'blocks.$', $insert);
        $this->assertEquals('blocks[2]', $update->path);
        $updatedData = $update->data;

        $this->assertCount(2, $updatedData['blocks']);
        $this->assertEquals(1, $updatedData['blocks'][0]['id']);
        $this->assertEquals(2, $updatedData['blocks'][1]['id']);
    }

    public function testDuplicateBlock_validBlock()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats']], '_ids' => ['blocks' => 1]];
        $duplicate = $this->getTraverseService()->duplicateBlock($data, 'blocks[1]');

        $this->assertEquals('blocks[2]', $duplicate->path);
        $this->assertCount(2, $duplicate->data['blocks'], 'There are no two blocks inside "blocks".');
        $this->assertEquals('cats', $duplicate->data['blocks'][1]['title'], 'Duplicating did not work for titles');
        $this->assertEquals(1, $duplicate->data['blocks'][0]['id'], 'Duplicating a block also changed the ID of the old block - it should stay the same');
        $this->assertEquals(2, $duplicate->data['blocks'][1]['id'], 'Duplicating did not increment the ID for the new block');
    }

    public function testDuplicateBlock_invalidBlock()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats']]];

        $this->expectException(TraverseException::class, 'Duplicate block did not throw an error although the block with ID 2 is not accessible.');
        $this->getTraverseService()->duplicateBlock($data, 'blocks[2]');
    }

    public function testRemove_validBlock_onlyOneBlock()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats']]];
        $data = $this->getTraverseService()->remove($data, 'blocks[1]');

        $this->assertEmpty($data['blocks'], 'There should be no elements inside "blocks".');
    }

    public function testRemove_validBlock_twoBlocks()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats'], ['id' => 2, 'title' => 'cats']]];
        $data = $this->getTraverseService()->remove($data, 'blocks[1]');

        $this->assertCount(1, $data['blocks'], 'There should be one element inside "blocks".');
        $this->assertEquals([0], \array_keys($data['blocks']), 'Array indices did not get realigned.');
        $this->assertEquals(2, $data['blocks'][0]['id'], 'Removed the wrong ID / Indices are wrong');
    }

    public function testRemove_invalidBlock()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats']]];

        $this->expectException(TraverseException::class, 'Remove did not throw an error although the block with ID 2 is not accessible.');
        $data = $this->getTraverseService()->remove($data, 'blocks[2]');
    }

    public function testRemove_plainArray()
    {
        $data = ['hello' => ['planet' => 'earth']];
        $data = $this->getTraverseService()->remove($data, 'hello');

        $this->assertEquals(['hello' => null], $data);
    }

    public function testRemove_plainValue()
    {
        $data = ['hello' => ['planet' => 'earth']];
        $data = $this->getTraverseService()->remove($data, 'hello.planet');

        $this->assertEquals(['planet' => null], $data['hello']);
    }

    public function testRemoveNodetail_flat()
    {
        $data = ['test1' => 'test1', 'test2' => 'test2'];
        $this->getTraverseService()->removeCompletely($data, 'test1');

        $this->assertCount(1, $data);
        $this->assertEquals(['test2' => 'test2'], $data);
    }

    public function testRemoveCompletely_nested()
    {
        $data = ['test' => ['test2' => 'test2', 'test3' => 'test3']];
        $this->getTraverseService()->removeCompletely($data, 'test.test3');

        $this->assertCount(1, $data);
        $this->assertCount(1, $data['test']);
        $this->assertEquals(['test' => ['test2' => 'test2']], $data);
    }

    public function testRemoveCompletely_inaccessible_flat()
    {
        $data = ['test' => 'test'];

        $this->expectException(TraverseUpdateException::class);
        $this->getTraverseService()->removeCompletely($data, 'does_not_exist');
    }

    public function testRemoveCompletely_inaccessible_nested()
    {
        $data = ['test' => ['test3' => 'test3']];

        $this->expectException(TraverseUpdateException::class);
        $this->getTraverseService()->removeCompletely($data, 'test.test4');
    }

    public function testGetIncrementedID_noIdsExisting()
    {
        $data = [];
        $id = $this->getTraverseService()->getIncrementedID(['blocks'], $data);

        $this->assertCount(1, $data['_ids']);
        $this->assertEquals(1, $id);
    }

    public function testGetIncrementedID_idExists()
    {
        $data = ['_ids' => ['blocks' => 5]];
        $id = $this->getTraverseService()->getIncrementedID(['blocks'], $data);

        $this->assertCount(1, $data['_ids']);
        $this->assertEquals(6, $id);
    }

    public function testGetIncrementedID_otherIdExists()
    {
        $data = ['_ids' => ['cats' => 5]];
        $id = $this->getTraverseService()->getIncrementedID(['blocks'], $data);

        $this->assertCount(2, $data['_ids']);
        $this->assertEquals(1, $id);
    }

    public function testFindInArray_exists()
    {
        $nodeHaystack = [
            ['id' => 1],
        ];

        $this->assertEquals($nodeHaystack[0], $this->getTraverseService()->findInArray(1, $nodeHaystack));
    }

    public function testFindInArray_notFirstEntry()
    {
        $nodeHaystack = [
            ['id' => 1],
            ['id' => 10],
        ];

        $this->assertEquals($nodeHaystack[1], $this->getTraverseService()->findInArray(10, $nodeHaystack));
    }

    public function testFindInArray_withInvalidArray()
    {
        $nodeHaystack = [
            ['id' => 1],
            ['_id' => 10], // has no ID column - should not throw an error
            ['id' => 5],
        ];

        $this->assertEquals($nodeHaystack[2], $this->getTraverseService()->findInArray(5, $nodeHaystack));
    }

    public function testFindInArray_noMatchingID()
    {
        $nodeHaystack = [
            ['id' => 1],
        ];

        $this->assertNull($this->getTraverseService()->findInArray(10, $nodeHaystack));
    }

    public function testGetNestedIds_empty()
    {
        $data = ['blocks' => []];

        $this->assertEmpty($this->getTraverseService()->getNestedIds($data, ['blocks']));
    }

    public function testGetNestedIds_filled()
    {
        $data = ['blocks' => [
            ['id' => 5],
            ['_id' => 3], // distraction
            ['id' => 2],
        ]];
        $expectedIds = [5, 2];

        $this->assertEquals($expectedIds, $this->getTraverseService()->getNestedIds($data, ['blocks']));
    }

    public function testGetNestedIds_tooDeep()
    {
        $data = ['blocks' => [
            ['id' => 5],
        ]];

        $this->expectException(TraverseException::class);
        $this->getTraverseService()->getNestedIds($data, ['blocks[5]', 'id']);
    }

    public function testGetEmbeddedID_hasOne()
    {
        $node = 'blocks[2]';

        $this->assertEquals(['blocks', 2], $this->getTraverseService()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_biggerID()
    {
        $node = 'blocks[2512]';

        $this->assertEquals(['blocks', 2512], $this->getTraverseService()->getEmbeddedID($node));
    }
    
    public function testGetEmbeddedID_nothingEmbedded()
    {
        $node = 'blocks';

        $this->assertNull($this->getTraverseService()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_onlyOpened()
    {
        $node = 'blocks[2';

        $this->assertNull($this->getTraverseService()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_onlyClosed()
    {
        $node = 'blocks2]';

        $this->assertNull($this->getTraverseService()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_contentAfterClose()
    {
        $node = 'blocks[2]content';

        $this->expectException(TraverseException::class);
        $this->getTraverseService()->getEmbeddedID($node);
    }

    public function testCreatePath_flat()
    {
        $path = 'cats';
        $data = [];

        $this->getTraverseService()->createPath($path, $data);

        $this->assertCount(1, $data);
        $this->assertEquals(null, $data['cats']);
    }

    public function testCreatePath_nested()
    {
        $path = 'hello.world';
        $data = ['hello2' => 'test']; // distraction

        $this->getTraverseService()->createPath($path, $data);

        $this->assertCount(2, $data);
        $this->assertEquals(null, $data['hello']['world']);
    }

    public function testCreatePath_alreadyExists()
    {
        $path = 'hello.world';
        $data = ['hello' => ['uno' => 'already_exists']]; // already exists - should not be overwritten

        $this->getTraverseService()->createPath($path, $data);

        $this->assertCount(1, $data);
        $this->assertEquals('already_exists', $data['hello']['uno']);
        $this->assertEquals(null, $data['hello']['world']);
    }

    public function testCreatePath_alreadyExists_nested()
    {
        $path = 'font.font1.test1';
        $data = ['font' => 
            [
                'font1' => ['test1' => 'test1'],
            ]
        ];

        $this->getTraverseService()->createPath($path, $data);

        $this->assertCount(1, $data['font']['font1']);
        $this->assertEquals('test1', $data['font']['font1']['test1']);
    }

    public function testCreatePath_tooDeep()
    {
        $data = [
            'hello' => ['test' => 'world'],
        ];

        $this->expectException(TraverseUpdateException::class);
        $this->getTraverseService()->createPath('hello.test.world', $data);
    }

    public function testCreatePath_withEmbeddedId()
    {
        $data = [];
        $this->getTraverseService()->createPath('blocks[5]', $data);

        $this->assertEquals(['blocks' => [['id' => 5]]], $data);
    }

    public function testCreatePath_withEmbeddedId_alreadyExists()
    {
        $data = ['blocks' => [['id' => 5, 'test' => 'test']]];
        $this->getTraverseService()->createPath('blocks[5]', $data);

        $this->assertEquals(['blocks' => [['id' => 5, 'test' => 'test']]], $data);
    }

    public function testCreatePath_withEmbeddedId_mergeChildren()
    {
        $data = ['blocks' => [['id' => 5, 'test' => 'test']]];
        $this->getTraverseService()->createPath('blocks[6]', $data);

        $this->assertEquals(['blocks' => [['id' => 5, 'test' => 'test'], ['id' => 6]]], $data);
    }

    public function testCreatePath_withEmbeddedId_nested()
    {
        $data = [];
        $this->getTraverseService()->createPath('blocks[5].test2', $data);

        $block = $data['blocks'][0];
        $this->assertEquals(5, $block['id']);
        $this->assertArrayHasKey('test2', $block);
        $this->assertNull($block['test2']);
    }

    private function getTraverseService()
    {
        return new XTraverseService();
    }
}