<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates exactly one real administrator account, for the first deploy of a
 * database where `APP_ENV=production` — `UserSeeder` refuses to run there
 * (its passwords are public knowledge), so a fresh production database has
 * the `administrator` role and nobody holding it. `misc/todo.md`'s
 * "Blocking deployability" item.
 *
 * The password is never a default or a checked-in value: it comes from
 * `ADMIN_PASSWORD` (via `config('auth.admin_password')`, so a cached config
 * still sees it) for a scripted run — Railway's release/start command, where
 * there is no TTY to prompt on — or a hidden prompt otherwise. Email and
 * name work the same way via `--email=`/`--first-name=`/`--last-name=` or
 * their own prompts.
 *
 * Refuses once an administrator already exists, so re-running a scripted
 * deploy step is a no-op rather than a second account — the command's own
 * name promises "exactly one".
 */
class BootstrapAdmin extends Command
{
    protected $signature = 'admin:bootstrap
        {--email= : Email for the new administrator; prompted if omitted}
        {--first-name= : First name; prompted if omitted}
        {--last-name= : Last name; prompted if omitted}';

    protected $description = 'Create the first real administrator account (refuses if one already exists)';

    public function handle(): int
    {
        if (User::role('administrator')->exists()) {
            $this->error('An administrator account already exists. admin:bootstrap only ever creates the first one.');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: $this->ask('Administrator email');
        $firstName = $this->option('first-name') ?: $this->ask('First name');
        $lastName = $this->option('last-name') ?: $this->ask('Last name');

        $password = config('auth.admin_password') ?: $this->secret('Administrator password');

        $validator = Validator::make(
            [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => $password,
            ],
            [
                'email' => ['required', 'string', 'email:rfc', 'max:100', Rule::unique('users', 'email')],
                'first_name' => 'required|string|min:2|max:50',
                'last_name' => 'required|string|min:2|max:50',
                'password' => ['required', 'string', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        try {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->error('That email address is already in use.');

            return self::FAILURE;
        }

        $user->assignRole('administrator');

        $this->info("Administrator account created: {$user->email}");

        return self::SUCCESS;
    }
}
