# XTraverse.php

This bundle makes it dead easy to traverse through nested arrays/objects in PHP. 

## Installation
### Via Composer
```terminal
composer require phiil/xtraverse
```
### File download
To use all the functions of this package simply download the ```src/``` directory.

## Getting started

### Traversing paths
Paths are how you specify where you want to traverse to inside the nested object. Let's assume you want to get the title of the Block with ID 1 inside the 'blocks' array.


Our nested object:
```php
$data = [
    'blocks' => [
        [
            'id' => 1,
            'title' => 'First block',
        ],
    ],
];
```

Now we specify which element we want:
```php
$path = 'blocks[1].title';
```

Every step is delimited with a dot ('.') - if you want to query for an ID simply append it to the path with closed brackets.
  
Now let the traversing begin:

```php
use Phiil\XTraverse\Traverser;

$traverser = new Traverser();
$title = $traverser->traverseData($path, $data, traverseArrayLimit: false); // we want a non-array value - pass "false" as the last argument or the service will throw an exception

echo $title;
```

The above will output the following: ```First block```


### Updating a nested value
Updating a value also works with paths:

```php
use Phiil\XTraverse\Traverser;

// We want to update the title of the block we previously traversed to
$updatePath = 'blocks[1].title';

$traverser = new Traverser();
$data = $traverser->update($data, $path, 'New title')->data;
```

**Note:** The update method returns an object with the properties ```path```, ```data``` & ```insert```. Getting the data property from the object straight away is almost always the best option.

### Working with IDs
The traverse service can auto-increment IDs - meaning if you insert a nested object like:

```php
use Phiil\XTraverse\Traverser;

$object = [
    'id' => null,
    'title' => 'Second block',
];
$traverser = new Traverser();
$data = $traverser->update($object, 'blocks.$', $object)->data;
```

The object inside ```$data``` will now have the ID of 2 (First Block: ID 1).

**Note:** The ```path.$``` syntax can be used if you want to add a block to a non-associative (only numeric keys) array.

## Running tests

To run tests run the following commands:
```terminal
composer install --dev
./vendor/bin/phpunit tests/
```

## Problems? Issues?
Just post them here on Github or contact me via email: [philipp@riddle.com](mailto:philipp@riddle.com). Feel free to contribute!