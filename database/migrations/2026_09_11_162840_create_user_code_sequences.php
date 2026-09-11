<?php

use App\Models\User;
use App\RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_code_sequences', function (Blueprint $table) {
            $table->string('role')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        if (filled(config('adminconfig.admin-email')) && filled(config('adminconfig.admin-password'))) {
            DB::transaction(function (): void {
                $admin = User::registerUser([
                    'email' => config('adminconfig.admin-email'),
                    'full_name' => config('adminconfig.admin-name') ?: 'Dealnews Admin',
                    'password' => config('adminconfig.admin-password'),
                ], RoleEnum::ADMIN);

                $admin->email_verified_at = now();
                $admin->save();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_code_sequences');
    }
};
