<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('profile_name')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->string('opt_out_source', 20)->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();
            $table->index('last_inbound_at');
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wa_contact_id')->constrained()->cascadeOnDelete();
            $table->string('meta_id', 128)->nullable();
            $table->string('direction', 3);
            $table->string('type', 24)->default('text');
            $table->longText('body')->nullable();
            $table->string('template_name')->nullable();
            $table->string('template_lang', 10)->nullable();
            $table->string('status', 24)->nullable();
            $table->timestamp('status_at')->nullable();
            $table->string('error_code', 12)->nullable();
            $table->string('error_title')->nullable();
            $table->boolean('auto_reply')->default(false);
            $table->timestamp('sent_at')->useCurrent();
            $table->longText('raw')->nullable();
            $table->timestamps();
            $table->index(['wa_contact_id', 'sent_at']);
            $table->index(['direction', 'status']);
        });

        Schema::create('wa_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('received_at')->useCurrent();
            $table->string('entry_id', 64)->nullable();
            $table->string('phone_number_id', 64)->nullable();
            $table->longText('changes')->nullable();
            $table->string('signature_valid', 12)->default('unchecked');
            $table->string('status', 20)->default('stored');
            $table->timestamps();
            $table->index(['received_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_webhook_events');
        Schema::dropIfExists('wa_contacts');
    }
};