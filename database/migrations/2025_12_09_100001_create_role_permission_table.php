<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            // Links to roles table - each role has one permission record
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            // JSON structure: {"module_name": ["permission.slug", ...]}
            // Example: {"employees": ["employees.view", "employees.create"], "reimbursements": ["reimbursements.view", ...]}
            // Contains all permission slugs assigned to this role, grouped by module
            $table->json('permissions');
            $table->timestamps();
            
            // Ensures one permission record per role
            $table->unique('role_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
