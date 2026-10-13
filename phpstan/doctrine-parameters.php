<?php

declare(strict_types=1);

return [
    'parameters' => [
        'doctrine' => [
            'objectManagerLoader'                 => __DIR__.'/object-manager.php',
            'allowNullablePropertyForRequiredField' => true,
        ],
    ],
];
