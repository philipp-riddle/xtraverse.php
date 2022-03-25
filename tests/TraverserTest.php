<?php

namespace Phiil\XTraverse\Tests;

use Phiil\XTraverse\Exception\TraverseException;
use Phiil\XTraverse\Exception\TraverseUpdateException;
use Phiil\XTraverse\Link\ArrayLink;
use Phiil\XTraverse\Tests\Mock\MockEntity;
use Phiil\XTraverse\Tests\Mock\MockEntityLink;
use Phiil\XTraverse\Traverser;
use PHPUnit\Framework\TestCase;

class TraverserTest extends TestCase
{
    public function testTraverseData_topLayer()
    {
        $data = ['title' => 'old'];
        $traversed = $this->getTraverser()->traverseData([], $data);

        $this->assertEquals($data, $traversed);
    }

    public function testTraverseData_topLayer_tooDeep()
    {
        $data = ['title' => 'old'];

        $this->expectException(TraverseException::class, 'Traverse Data did not throw an error although it traversed down to a string');
        $this->getTraverser()->traverseData(['title'], $data);
    }

    public function testTraverseData_oneLayer()
    {
        $data = ['title' => ['first' => 'yes!']];
        $traversed = $this->getTraverser()->traverseData(['title'], $data);

        $this->assertEquals($data['title'], $traversed);
    }

    public function testTraverseData_oneLayer_notAccessible()
    {
        $data = ['title' => ['first' => 'yes!']];

        $this->expectException(TraverseException::class, 'Traverse Data did not throw an error although a sub element is not accessible.');
        $this->getTraverser()->traverseData(['cats'], $data);
    }

    public function testTraverseData_oneLayer_withEmbeddedID()
    {
        $data = ['blocks' => [
            ['id' => 2,'title' => 'asd'],
        ]];

        $this->assertEquals($data['blocks'][0], $this->getTraverser()->traverseData(['blocks[2]'], $data));
    }

    public function testTraverseData_oneLayer_withNotExistingEmbeddedID()
    {
        $data = ['blocks' => [
            ['id' => 2,'title' => 'asd'],
        ]];

        $this->expectException(TraverseException::class, 'Traverse Data did not throw an error although the embedded ID does not exist in the subnode.');
        $this->getTraverser()->traverseData(['blocks[5]'], $data);
    }

    public function testTraverseData_startsWithOnlyId_atTheBeginning()
    {
        // that's a very simple structure which should be possible to traverse with the Service
        $data = [
            ['id' => 1, 'name' => '_name'],
        ];

        $this->assertEquals($data[0], $this->getTraverser()->traverseData(['[1]'], $data));
        $this->assertEquals('_name', $this->getTraverser()->traverseData(['[1]', 'name'], $data, false));
    }

    /**
     * This also adds a new possibility:
     * Generating a path is much easier if you do not have to mind IDs, e.g. children[2] vs children.[2] => the latter is easier to generate and has less conditions ('.' is always given)
     */
    public function testTraverseData_startsWithOnlyId_inTheMiddle()
    {
        $data = ['name' => [['id' => 1]]];

        $this->assertEquals($data['name'][0]['id'], $this->getTraverser()->traverseData(['name', '[1]', 'id'], $data, false));
    }

    public function testTraverseData_withPath()
    {
        $data = [
            ['id' => 1, 'name' => '_name'],
        ];

        $this->assertEquals($data[0]['name'], $this->getTraverser()->traverseData('[1].name', $data, false), 'Passing a string path to traverseData does not work.');
    }

    public function testTraverseData_withDataLink()
    {
        $entity = (new MockEntity())->setData(['hello' => ['world' => 1]]);
        $link = new MockEntityLink($entity);

        $this->assertEquals(1, $this->getTraverser()->traverseData('hello.world', $link, false));
    }

    public function testUpdate_topLayer()
    {
        $data = ['title' => 'old'];
        $update = $this->getTraverser()->update($data, 'title', 'new');

        $this->assertEquals('title', $update->path);
        $this->assertEquals('new', $update->data['title']);
    }

    public function testUpdate_topLayer_embeddedId()
    {
        $data = [
            ['id' => 1, 'title' => 'old'],
        ];
        $update = $this->getTraverser()->update($data, '[1]', ['id' => 1, 'title' => 'new']);

        $this->assertEquals('[1]', $update->path);
        $this->assertEquals('new', $data[0]['title']);
    }

    public function testUpdate_oneLayerDown()
    {
        $data = ['meta' => ['title' => 'old']];
        $update = $this->getTraverser()->update($data, 'meta.title', 'new');

        $this->assertEquals('meta.title', $update->path);
        $this->assertEquals('new', $update->data['meta']['title']);
    }

    public function testUpdate_oneLayerDown_notAccessible()
    {
        $data = ['meta' => ['title' => 'old']];

        $this->expectException(TraverseUpdateException::class, 'Traverse Data did not throw an error although the node tail is not accessible');
        $this->getTraverser()->update($data, 'meta.description', 'new');
    }

    public function testUpdate_oneLayerDown_addArrayElement()
    {
        $data = ['blocks' => []];
        $insert = ['id' => null, 'content' => 'asd'];
        $update = $this->getTraverser()->update($data, 'blocks.$', $insert);

        $this->assertEquals('blocks[1]', $update->path);
        $this->assertCount(1, $update->data['blocks']);
        $this->assertEquals($insert['content'], $update->data['blocks'][0]['content']);
    }

    public function testUpdate_oneLayerDown_addArrayElement_toAssociative()
    {
        $data = ['blocks' => ['associative' => true]];
        $insert = ['content' => 'asd'];

        $this->expectException(TraverseUpdateException::class, 'Update Data did not throw an error although an array element was added to an associative array');
        $this->getTraverser()->update($data, 'blocks.$', $insert);
    }

    public function testUpdate_withAutomaticallyIncrementedID()
    {
        $data = ['blocks' => []];
        $insert = ['id' => null, 'content' => 'asd'];
        $update = $this->getTraverser()->update($data, 'blocks.$', $insert);

        $this->assertEquals('blocks[1]', $update->path);
        $this->assertEquals(1, $update->data['blocks'][0]['id']);
        $this->assertCount(1, $update->data['_ids']);
    }
    
    public function testUpdate_withAutomaticallyIncrementedID_existing()
    {
        $data = ['blocks' => [], '_ids' => ['blocks' => 5]];
        $insert = ['id' => null, 'content' => 'asd'];
        $update = $this->getTraverser()->update($data, 'blocks.$', $insert);

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
        $update = $this->getTraverser()->update($data, 'blocks[5].title', $insert);

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
        $this->getTraverser()->update($data, 'blocks[10].title', $insert);
    }

    public function testUpdate_twoLayersDown_setBoolean()
    {
        $data = ['settings' => ['hideShareButtons' => ['hideAll' => true]]];
        $insert = false; // set 'hideAll' to false
        $update = $this->getTraverser()->update($data, 'settings.hideShareButtons.hideAll', $insert);
        $updatedData = $update->data;

        $this->assertEquals($insert, $updatedData['settings']['hideShareButtons']['hideAll']);
    }

    public function testUpdate_incrementCorrectlyWithZeroId()
    {
        $data = ['blocks' => []];
        $insert = ['id' => 0, 'content' => 'asd'];

        $update = $this->getTraverser()->update($data, 'blocks.$', $insert);
        $this->assertEquals('blocks[1]', $update->path);

        $data = $update->getData();
        $update = $this->getTraverser()->update($data, 'blocks.$', $insert);
        $this->assertEquals('blocks[2]', $update->path);
        $updatedData = $update->data;

        $this->assertCount(2, $updatedData['blocks']);
        $this->assertEquals(1, $updatedData['blocks'][0]['id']);
        $this->assertEquals(2, $updatedData['blocks'][1]['id']);
    }

    public function testUpdate_withCustomDataLink_array()
    {
        $data = [
            'name' => 'old_name',
        ];
        $data = new ArrayLink($data);

        // the data is not passed directly, but a link to it!
        // by setting same variable names it's very easy to work with it afterwards
        $this->getTraverser()->update($data, 'name', 'new_name');

        $this->assertEquals(['name' => 'new_name'], $data);
    }

    public function testUpdate_withCustomDataLink_entity()
    {
        $entity = (new MockEntity())->setData(['links' => 'are awesome']);
        $link = new MockEntityLink($entity);
    
        $this->getTraverser()->update($link, 'links', 'are mega');

        $this->assertEquals(['links' => 'are mega'], $entity->getData());
    }

    public function testUpdate_addToArray_root_dynamicIdStrategy()
    {
        $data = [];
        $insert = ['id' => null, 'content' => 'asd'];
        $this->getTraverser()->update($data, '$', $insert, true, Traverser::ID_STRATEGY_DYNAMIC);

        $this->assertEquals([['id' => 1, 'content' => 'asd']], $data); // no other array should have been created (dynamic ID strategy!)
    }

    public function testUpdate_addToArray_root_notEmpty_dynamicIdStrategy()
    {
        $data = [
            ['id' => 55],
        ];
        $insert = ['id' => null];
        $this->getTraverser()->update($data, '$', $insert, true, Traverser::ID_STRATEGY_DYNAMIC);

        $this->assertEquals([['id' => 55], ['id' => 56]], $data);
    }

    public function testDuplicateBlock_validBlock()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats']], '_ids' => ['blocks' => 1]];
        $duplicate = $this->getTraverser()->duplicateBlock($data, 'blocks[1]');

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
        $this->getTraverser()->duplicateBlock($data, 'blocks[2]');
    }

    public function testRemove_validBlock_onlyOneBlock()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats']]];
        $data = $this->getTraverser()->remove($data, 'blocks[1]')->data;

        $this->assertEmpty($data['blocks'], 'There should be no elements inside "blocks".');
    }

    public function testRemove_validBlock_twoBlocks()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats'], ['id' => 2, 'title' => 'cats']]];
        $data = $this->getTraverser()->remove($data, 'blocks[1]')->data;

        $this->assertCount(1, $data['blocks'], 'There should be one element inside "blocks".');
        $this->assertEquals([0], \array_keys($data['blocks']), 'Array indices did not get realigned.');
        $this->assertEquals(2, $data['blocks'][0]['id'], 'Removed the wrong ID / Indices are wrong');
    }

    public function testRemove_invalidBlock()
    {
        $data = ['blocks' => [['id' => 1, 'title' => 'cats']]];

        $this->expectException(TraverseException::class, 'Remove did not throw an error although the block with ID 2 is not accessible.');
        $data = $this->getTraverser()->remove($data, 'blocks[2]');
    }

    public function testRemove_plainArray()
    {
        $data = ['hello' => ['planet' => 'earth']];
        $data = $this->getTraverser()->remove($data, 'hello')->data;

        $this->assertEquals(['hello' => null], $data);
    }

    public function testRemove_plainValue()
    {
        $data = ['hello' => ['planet' => 'earth']];
        $data = $this->getTraverser()->remove($data, 'hello.planet')->data;

        $this->assertEquals(['planet' => null], $data['hello']);
    }

    public function testRemove_withDataLink()
    {
        $entity = (new MockEntity())->setData(['hello' => ['planet' => 'earth']]);
        $link = new MockEntityLink($entity);
        $this->getTraverser()->remove($link, 'hello.planet');

        $this->assertEquals(['hello' => ['planet' => null]], $entity->getData());
    }

    public function testRemoveNodetail_flat()
    {
        $data = ['test1' => 'test1', 'test2' => 'test2'];
        $this->getTraverser()->removeCompletely($data, 'test1');

        $this->assertCount(1, $data);
        $this->assertEquals(['test2' => 'test2'], $data);
    }

    public function testRemoveCompletely_nested()
    {
        $data = ['test' => ['test2' => 'test2', 'test3' => 'test3']];
        $this->getTraverser()->removeCompletely($data, 'test.test3');

        $this->assertCount(1, $data);
        $this->assertCount(1, $data['test']);
        $this->assertEquals(['test' => ['test2' => 'test2']], $data);
    }

    public function testRemoveCompletely_inaccessible_flat()
    {
        $data = ['test' => 'test'];

        $this->expectException(TraverseUpdateException::class);
        $this->getTraverser()->removeCompletely($data, 'does_not_exist');
    }

    public function testRemoveCompletely_inaccessible_nested()
    {
        $data = ['test' => ['test3' => 'test3']];

        $this->expectException(TraverseUpdateException::class);
        $this->getTraverser()->removeCompletely($data, 'test.test4');
    }

    public function testRemoveCompletely_withEmbeddedId()
    {
        $data = ['test' => [['id'=> 2]]];
        $this->getTraverser()->removeCompletely($data, 'test[2]');

        $this->assertEmpty($data['test']);
    }

    public function testRemoveCompletely_withEmbeddedId_doesNotExist()
    {
        $data = ['test' => [['id'=> 2]]];

        $this->expectException(TraverseException::class);
        $this->getTraverser()->removeCompletely($data, 'test[3]');
    }

    public function testRemoveCompletely_inNonAssociativeArray()
    {
        $data = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ];
        $this->getTraverser()->removeCompletely($data, '[2]'); // remove item in the middle - item on the third position should now be shifted because of the gap

        $this->assertEquals([
            ['id' => 1],
            ['id' => 3],
        ], $data);
    }

    public function testRemoveCompletely_withDataLink()
    {
        $entity = (new MockEntity())->setData(['hello' => ['world' => 1]]);
        $link = new MockEntityLink($entity);

        $this->getTraverser()->removeCompletely($link, 'hello.world');
        $this->assertSame(['hello' => []], $entity->getData());
    }

    public function testGetIncrementedID_noIdsExisting()
    {
        $data = [];
        $id = $this->getTraverser()->getIncrementedID(['blocks'], $data);

        $this->assertCount(1, $data['_ids']);
        $this->assertEquals(1, $id);
    }

    public function testGetIncrementedID_idExists()
    {
        $data = ['_ids' => ['blocks' => 5]];
        $id = $this->getTraverser()->getIncrementedID(['blocks'], $data);

        $this->assertCount(1, $data['_ids']);
        $this->assertEquals(6, $id);
    }

    public function testGetIncrementedID_otherIdExists()
    {
        $data = ['_ids' => ['cats' => 5]];
        $id = $this->getTraverser()->getIncrementedID(['blocks'], $data);

        $this->assertCount(2, $data['_ids']);
        $this->assertEquals(1, $id);
    }

    public function testFindInArray_exists()
    {
        $nodeHaystack = [
            ['id' => 1],
        ];

        $this->assertEquals($nodeHaystack[0], $this->getTraverser()->findInArray(1, $nodeHaystack));
    }

    public function testFindInArray_notFirstEntry()
    {
        $nodeHaystack = [
            ['id' => 1],
            ['id' => 10],
        ];

        $this->assertEquals($nodeHaystack[1], $this->getTraverser()->findInArray(10, $nodeHaystack));
    }

    public function testFindInArray_withInvalidArray()
    {
        $nodeHaystack = [
            ['id' => 1],
            ['_id' => 10], // has no ID column - should not throw an error
            ['id' => 5],
        ];

        $this->assertEquals($nodeHaystack[2], $this->getTraverser()->findInArray(5, $nodeHaystack));
    }

    public function testFindInArray_noMatchingID()
    {
        $nodeHaystack = [
            ['id' => 1],
        ];

        $this->assertNull($this->getTraverser()->findInArray(10, $nodeHaystack));
    }

    public function testGetNestedIds_empty()
    {
        $data = ['blocks' => []];

        $this->assertEmpty($this->getTraverser()->getNestedIds($data, ['blocks']));
    }

    public function testGetNestedIds_filled()
    {
        $data = ['blocks' => [
            ['id' => 5],
            ['_id' => 3], // distraction
            ['id' => 2],
        ]];
        $expectedIds = [5, 2];

        $this->assertEquals($expectedIds, $this->getTraverser()->getNestedIds($data, ['blocks']));
    }

    public function testGetNestedIds_tooDeep()
    {
        $data = ['blocks' => [
            ['id' => 5],
        ]];

        $this->expectException(TraverseException::class);
        $this->getTraverser()->getNestedIds($data, ['blocks[5]', 'id']);
    }

    public function testGetNestedIds_filterByType()
    {
        $data = ['blocks' => [
            ['id' => 5, 'type' => 'test1'],
            ['id' => 6, 'type' => 'test2'],
        ]];

        $this->assertEquals([5], $this->getTraverser()->getNestedIds($data, ['blocks'], 'test1'));
        $this->assertEquals([6], $this->getTraverser()->getNestedIds($data, ['blocks'], 'test2'));
    }

    public function testGetNestedIds_filterByTypeGroup()
    {
        $data = ['blocks' => [
            ['id' => 5, 'type' => 'test', 'typeGroup' => 'testGroup1'],
            ['id' => 6, 'type' => 'test2', 'typeGroup' => 'testGroup2'],
        ]];

        $this->assertEquals([5], $this->getTraverser()->getNestedIds($data, ['blocks'], typeGroup: 'testGroup1'));
        $this->assertEquals([6], $this->getTraverser()->getNestedIds($data, ['blocks'], typeGroup: 'testGroup2'));
    }

    public function testGetEmbeddedID_hasOne()
    {
        $node = 'blocks[2]';

        $this->assertEquals(['blocks', 2], $this->getTraverser()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_biggerID()
    {
        $node = 'blocks[2512]';

        $this->assertEquals(['blocks', 2512], $this->getTraverser()->getEmbeddedID($node));
    }
    
    public function testGetEmbeddedID_nothingEmbedded()
    {
        $node = 'blocks';

        $this->assertNull($this->getTraverser()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_onlyOpened()
    {
        $node = 'blocks[2';

        $this->assertNull($this->getTraverser()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_onlyClosed()
    {
        $node = 'blocks2]';

        $this->assertNull($this->getTraverser()->getEmbeddedID($node));
    }

    public function testGetEmbeddedID_contentAfterClose()
    {
        $node = 'blocks[2]content';

        $this->expectException(TraverseException::class);
        $this->getTraverser()->getEmbeddedID($node);
    }

    public function testHasPath_flat()
    {
        $this->assertTrue($this->getTraverser()->hasPath('message', ['message' => 'asd']));
    }

    public function testHasPath_nested()
    {
        $this->assertTrue($this->getTraverser()->hasPath('[2].test.hello', [
            [
                'id' => 2,
                'test' => [
                    'hello' => 'world',
                ],
            ],
        ]));
    }

    public function testHasPath_false()
    {
        $this->assertFalse($this->getTraverser()->hasPath('[2].test.hello', []));
    }

    public function testCreatePath_flat()
    {
        $path = 'cats';
        $data = [];

        $this->getTraverser()->createPath($data, $path);

        $this->assertCount(1, $data);
        $this->assertEquals(null, $data['cats']);
    }

    public function testCreatePath_nested()
    {
        $path = 'hello.world';
        $data = ['hello2' => 'test']; // distraction

        $this->getTraverser()->createPath($data, $path);

        $this->assertCount(2, $data);
        $this->assertEquals(null, $data['hello']['world']);
    }

    public function testCreatePath_alreadyExists()
    {
        $path = 'hello.world';
        $data = ['hello' => ['uno' => 'already_exists']]; // already exists - should not be overwritten

        $this->getTraverser()->createPath($data, $path);

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

        $this->getTraverser()->createPath($data, $path);

        $this->assertCount(1, $data['font']['font1']);
        $this->assertEquals('test1', $data['font']['font1']['test1']);
    }

    public function testCreatePath_tooDeep()
    {
        $data = [
            'hello' => ['test' => 'world'],
        ];

        $this->expectException(TraverseUpdateException::class);
        $this->getTraverser()->createPath($data, 'hello.test.world');
    }

    public function testCreatePath_withEmbeddedId()
    {
        $data = [];
        $this->getTraverser()->createPath($data, 'blocks[5]');

        $this->assertEquals(['blocks' => [['id' => 5]]], $data);
    }

    public function testCreatePath_withEmbeddedId_alreadyExists()
    {
        $data = ['blocks' => [['id' => 5, 'test' => 'test']]];
        $this->getTraverser()->createPath($data, 'blocks[5]');

        $this->assertEquals(['blocks' => [['id' => 5, 'test' => 'test']]], $data);
    }

    public function testCreatePath_withEmbeddedId_mergeChildren()
    {
        $data = ['blocks' => [['id' => 5, 'test' => 'test']]];
        $this->getTraverser()->createPath($data, 'blocks[6]');

        $this->assertEquals(['blocks' => [['id' => 5, 'test' => 'test'], ['id' => 6]]], $data);
    }

    public function testCreatePath_withEmbeddedId_nested()
    {
        $data = [];
        $this->getTraverser()->createPath($data, 'blocks[5].test2');

        $block = $data['blocks'][0];
        $this->assertEquals(5, $block['id']);
        $this->assertArrayHasKey('test2', $block);
        $this->assertNull($block['test2']);
    }

    public function testCreatePath_withDataLink()
    {
        $entity = (new MockEntity())->setData(['hello' => 'world']);
        $link = new MockEntityLink($entity);
        $this->getTraverser()->createPath($link, 'blocks[5].test2');

        $this->assertSame([
            'hello' => 'world',
            'blocks' => [
                [
                    'id' => 5,
                    'test2' => null,
                ],
            ],
        ], $entity->getData());
    }

    public function testFindTypesInData_emptyArray()
    {
        $this->assertEquals([], $this->getTraverser()->findTypesInData([], 'SingleChoice'));
    }

    public function testFindTypesInData_none()
    {
        $data = [];
        $this->assertEmpty($this->getTraverser()->findTypesInData($data, 'SingleChoice'));
    }

    public function testFindTypesInData_root()
    {
        $data = [
            'id' => 1,
            'type' => 'SingleChoice',
        ];
        $this->assertEquals([['id' => 1, 'path' => '']], $this->getTraverser()->findTypesInData($data, 'SingleChoice'));
    }

    public function testFindTypesInData_nested()
    {
        $data = [
            'blocks' => [
                [
                    'id' => 1,
                    'type' => 'SingleChoice',
                    'media' => [
                        'id' => 20,
                        'type' => 'Media',
                    ]
                ],
                [
                    'id' => 2,
                    'type' => 'SingleChoice',
                ],
            ],
        ];

        $singleChoiceOccurrences = [
            ['id' => 1, 'path' => 'blocks.0'],
            ['id' => 2, 'path' => 'blocks.1'],
        ];
        $this->assertEquals($singleChoiceOccurrences, $this->getTraverser()->findTypesInData($data, 'SingleChoice'));

        $mediaOccurrences = [
            ['id' => 20, 'path' => 'blocks.0.media'],
        ];
        $this->assertEquals($mediaOccurrences, $this->getTraverser()->findTypesInData($data, 'Media'));
    }

    protected function getTraverser(): Traverser
    {
        return new Traverser();
    }
}