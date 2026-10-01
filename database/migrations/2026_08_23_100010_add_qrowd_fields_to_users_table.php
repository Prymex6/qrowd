<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('company_name')->nullable()->after('phone');
            $table->enum('plan', ['free', 'pro'])->default('free')->after('company_name');
            $table->timestamp('plan_expires_at')->nullable()->after('plan');
            $table->boolean('is_admin')->default(false)->after('plan_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'company_name', 'plan', 'plan_expires_at', 'is_admin']);
        });
    }
};
