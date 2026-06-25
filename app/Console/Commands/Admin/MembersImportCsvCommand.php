<?php

declare(strict_types=1);

namespace App\Console\Commands\Admin;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Service\Import\Importers\ImportException;
use App\Service\MemberService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use League\Csv\Reader;
use Throwable;

class MembersImportCsvCommand extends Command
{
    protected $signature = 'admin:members:import-csv
                { path : Path to the CSV file (columns: Name, Email, Role, Password) }
                { --organization=Citrusbug : Organization / workspace name }
                { --dry-run : Validate and preview without saving }';

    protected $description = 'Import verified users into an organization from a CSV file';

    public function handle(MemberService $memberService): int
    {
        $path = $this->argument('path');
        $organizationName = (string) $this->option('organization');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_readable($path)) {
            $this->error('CSV file is not readable: '.$path);

            return self::FAILURE;
        }

        $organization = Organization::query()->where('name', '=', $organizationName)->first();
        if ($organization === null) {
            $this->error('Organization "'.$organizationName.'" not found.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->comment('Dry-run mode — no changes will be saved.');
        }

        $this->info('Importing members into organization "'.$organization->name.'" ('.$organization->getKey().')');

        try {
            $rows = $this->readRows($path);
        } catch (ImportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $lineNumber => $row) {
            try {
                $result = $dryRun
                    ? $this->previewRow($organization, $row, $lineNumber)
                    : $this->importRow($organization, $row, $lineNumber, $memberService);

                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $skipped++,
                };
            } catch (Throwable $exception) {
                $this->error('Line '.$lineNumber.': '.$exception->getMessage());
                $skipped++;
            }
        }

        $this->newLine();
        $this->info('Finished. Created: '.$created.', updated: '.$updated.', skipped: '.$skipped);

        return $skipped > 0 && ($created + $updated) === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array{name: string, email: string, role: Role, password: string}>
     *
     * @throws ImportException
     */
    private function readRows(string $path): array
    {
        $reader = Reader::createFromPath($path);
        $reader->setHeaderOffset(0);
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setEscape('');

        $headerMap = [];
        foreach ($reader->getHeader() as $header) {
            $headerMap[Str::lower(trim($header))] = $header;
        }

        foreach (['name', 'email', 'role', 'password'] as $required) {
            if (! array_key_exists($required, $headerMap)) {
                throw new ImportException('Missing required CSV column: '.$required);
            }
        }

        $rows = [];
        $lineNumber = 1;

        foreach ($reader->getRecords() as $record) {
            $lineNumber++;

            $name = trim((string) $record[$headerMap['name']]);
            $email = Str::lower(trim((string) $record[$headerMap['email']], " \t\n\r\0\x0B,"));
            $roleRaw = trim((string) $record[$headerMap['role']]);
            $password = (string) $record[$headerMap['password']];

            if ($name === '' && $email === '' && $roleRaw === '' && $password === '') {
                continue;
            }

            if ($name === '' || $email === '' || $roleRaw === '' || $password === '') {
                throw new ImportException('Line '.$lineNumber.': name, email, role and password are required');
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ImportException('Line '.$lineNumber.': invalid email "'.$email.'"');
            }

            $rows[$lineNumber] = [
                'name' => $name,
                'email' => $email,
                'role' => $this->parseRole($roleRaw),
                'password' => $password,
            ];
        }

        return $rows;
    }

    private function parseRole(string $role): Role
    {
        $normalized = Str::lower(trim($role));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'employee' => Role::Employee,
            'team_lead', 'teamlead' => Role::TeamLead,
            'manager' => Role::Manager,
            'admin', 'administrator' => Role::Admin,
            'owner' => Role::Owner,
            default => throw new ImportException('Unknown role "'.$role.'"'),
        };
    }

    /**
     * @param  array{name: string, email: string, role: Role, password: string}  $row
     */
    private function previewRow(Organization $organization, array $row, int $lineNumber): string
    {
        /** @var User|null $user */
        $user = User::query()->where('email', '=', $row['email'])->first();
        $member = $user === null
            ? null
            : Member::query()
                ->whereBelongsTo($organization, 'organization')
                ->whereBelongsTo($user, 'user')
                ->first();

        if ($user === null) {
            $this->line('[create] '.$row['email'].' ('.$row['role']->value.')');

            return 'created';
        }

        if ($member === null) {
            $this->line('[add-member] '.$row['email'].' ('.$row['role']->value.')');

            return 'created';
        }

        $this->line('[update] '.$row['email'].' ('.$row['role']->value.')');

        return 'updated';
    }

    /**
     * @param  array{name: string, email: string, role: Role, password: string}  $row
     */
    private function importRow(
        Organization $organization,
        array $row,
        int $lineNumber,
        MemberService $memberService,
    ): string {
        return DB::transaction(function () use ($organization, $row, $lineNumber, $memberService): string {
            /** @var User|null $user */
            $user = User::query()->where('email', '=', $row['email'])->first();

            if ($user === null) {
                $user = $this->createVerifiedUser($row['name'], $row['email'], $row['password']);
                $memberService->addMember($user, $organization, $row['role'], asSuperAdmin: true);
                $this->line('Created '.$row['email'].' ('.$row['role']->value.')');
                $user->currentOrganization()->associate($organization);
                $user->save();

                return 'created';
            }

            $user->name = $row['name'];
            $user->password = Hash::make($row['password']);
            $user->email_verified_at = Carbon::now();
            $user->is_placeholder = false;
            $user->save();

            /** @var Member|null $member */
            $member = Member::query()
                ->whereBelongsTo($organization, 'organization')
                ->whereBelongsTo($user, 'user')
                ->first();

            if ($member === null) {
                $memberService->addMember($user, $organization, $row['role'], asSuperAdmin: true);
                $this->line('Added existing user to workspace: '.$row['email'].' ('.$row['role']->value.')');

                return 'created';
            }

            if ($member->role === Role::Owner->value && $row['role'] !== Role::Owner) {
                $this->warn('Skipped role change for owner '.$row['email'].' on line '.$lineNumber);

                return 'updated';
            }

            $member->role = $row['role']->value;
            $member->save();
            $user->currentOrganization()->associate($organization);
            $user->save();

            $this->line('Updated '.$row['email'].' ('.$row['role']->value.')');

            return 'updated';
        });
    }

    private function createVerifiedUser(string $name, string $email, string $password): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->timezone = 'Asia/Kolkata';
        $user->week_start = Weekday::Monday;
        $user->email_verified_at = Carbon::now();
        $user->is_placeholder = false;
        $user->save();

        return $user;
    }
}
