<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What SES did with each sent email: one row per Send, Delivery, Bounce, Complaint, Reject, DeliveryDelay or
 * Rendering Failure event. Joins sent_emails on message_id, not a foreign key, because SES's Send event can
 * arrive before the sent_emails row is committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_email_events', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->index();
            // SNS retries a notification until it gets a 2xx, so its id makes the insert idempotent.
            $table->string('sns_message_id')->unique();
            $table->string('event_type', 32)->index();
            $table->json('recipients')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_email_events');
    }
};
