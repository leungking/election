<?php

use Flarum\Extend;
use Hooey\Election\Api\Controllers\ElectionActionController;
use Hooey\Election\Api\Controllers\ElectionController;
use Hooey\Election\Api\Controllers\ElectionNominationController;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->route('/elections', 'hooey.elections')
        ->route('/elections/{id}', 'hooey.election'),

    (new Extend\Routes('api'))
        ->get('/hooey/elections', 'hooey.elections.index', ElectionController::class)
        ->post('/hooey/elections', 'hooey.elections.store', ElectionController::class)
        ->get('/hooey/elections/{id}', 'hooey.elections.show', ElectionController::class)
        ->post('/hooey/elections/{id}/nominations', 'hooey.elections.nominations.store', ElectionNominationController::class)
        ->post('/hooey/elections/{id}/nominations/{nid}/remove', 'hooey.elections.nominations.remove', ElectionNominationController::class)
        ->post('/hooey/elections/{id}/publicity', 'hooey.elections.publicity', ElectionActionController::class)
        ->post('/hooey/elections/{id}/advance', 'hooey.elections.advance', ElectionActionController::class)
        ->post('/hooey/elections/{id}/publish', 'hooey.elections.publish', ElectionActionController::class),
    // Migrations in this extension's migrations/ directory are picked up
    // automatically by Flarum 2.x (Extension::hasMigrations); no extender
    // exists for them.
];
