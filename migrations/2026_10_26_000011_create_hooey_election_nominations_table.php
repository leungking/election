<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('hooey_election_nominations', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('election_id')->unsigned();
    $table->integer('nominator_id')->unsigned();
    $table->integer('nominee_id')->unsigned();
    // candidate | removed. Removals keep the row (status only) so the
    // publicity history stays visible; they never reach the ballot.
    $table->string('status', 20)->default('candidate');
    $table->timestamps();
    $table->unique(['election_id', 'nominee_id']);
    $table->index(['election_id', 'id']);
    $table->foreign('election_id')->references('id')->on('hooey_elections')->onDelete('cascade');
    $table->foreign('nominator_id')->references('id')->on('users')->onDelete('cascade');
    $table->foreign('nominee_id')->references('id')->on('users')->onDelete('cascade');
});
