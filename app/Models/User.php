<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\RoleEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['full_name', 'email', 'password', 'role', 'user_code'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public static function registerUser(array $attributes, RoleEnum $role = RoleEnum::USER): self
    {
        return DB::transaction(function () use ($attributes, $role): self {
            $user = new self($attributes);

            $user->role = match ($role) {
                RoleEnum::USER => RoleEnum::USER->value,
                RoleEnum::ADMIN => RoleEnum::ADMIN->value,
                RoleEnum::EDITOR => RoleEnum::EDITOR->value,
            };

            $user->user_code = self::generateUserCode($role);
            $user->save();

            return $user;
        }, attempts: 3);
    }


    public static function generateUserCode(RoleEnum $role): string
    {
        return DB::transaction(function () use ($role): string {
            DB::table('user_code_sequences')->insertOrIgnore([
                'role' => $role->value,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('user_code_sequences')->where('role', $role->value)->lockForUpdate()->first();

            if (! $sequence) {
                abort(422, "Không tìm thấy vai trò của: {$role->value} để tạo mã người dùng");
            }

            $number = $sequence->last_number + 1;

            DB::table('user_code_sequences')->where('role', $role->value)->update(['last_number' => $number, 'updated_at' => now()]);

            return sprintf('%s-%06d', $role->value, $number);
        });
    }
}
