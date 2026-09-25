<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates sent_emails, or upgrades a table left by dcblogdev/laravel-sent-emails (same columns) in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sent_emails')) {
            Schema::create('sent_emails', function (Blueprint $table) {
                $table->increments('id');
                $table->date('date')->nullable();
                $table->string('from')->nullable();
                $table->text('to')->nullable();
                $table->text('cc')->nullable();
                $table->text('bcc')->nullable();
                $table->string('subject')->nullable();
                $table->longText('body');
                $table->timestamps();
            });
        }

        Schema::table('sent_emails', function (Blueprint $table) {
            if (! Schema::hasColumn('sent_emails', 'message_id')) {
                $table->string('message_id')->nullable()->index();
            }
            if (! Schema::hasColumn('sent_emails', 'mailable')) {
                $table->string('mailable')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->dropIndex(['message_id']);
            $table->dropColumn(['message_id', 'mailable']);
        });
    }
};
