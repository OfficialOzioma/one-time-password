<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('otp.table', 'otps'), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('identifier_hash', 64);
            $table->string('purpose', 64);
            $table->char('token_hash', 64);
            $table->unsignedBigInteger('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['identifier_hash', 'purpose'], 'ozioma_otp_identifier_purpose_unique');
            $table->index('expires_at', 'ozioma_otp_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('otp.table', 'otps'));
    }
};
