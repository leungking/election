<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('hooey_elections', function (Blueprint $table) {
    $table->increments('id');
    $table->string('title', 200);
    $table->integer('seat_count')->unsigned()->default(1);
    // nomination | publicity | voting | ended. Stage dates are display
    // only (no cron); progression is a button press by admin / group 7.
    $table->string('status', 20)->default('nomination');
    $table->dateTime('nomination_ends_at')->nullable();
    $table->dateTime('publicity_ends_at')->nullable();
    // Copied to the poll end_date when voting starts; the publish gate
    // reads the poll's own end_date, not this column.
    $table->dateTime('vote_ends_at')->nullable();
    $table->text('publicity_text')->nullable();
    $table->integer('discussion_id')->unsigned()->nullable();
    $table->integer('poll_id')->unsigned()->nullable();
    // JSON: [{option_id, nominee_id}] — maps poll options back to nominees.
    $table->text('poll_option_map')->nullable();
    $table->integer('result_discussion_id')->unsigned()->nullable();
    // JSON snapshot of the published result (winners + per-candidate votes).
    $table->text('result_data')->nullable();
    $table->integer('created_by')->unsigned();
    $table->timestamps();
    $table->index(['status', 'id']);
    $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
});
