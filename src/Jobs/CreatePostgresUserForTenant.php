<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class CreatePostgresUserForTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        protected TenantWithDatabase&Model $tenant,
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $name = $this->tenant->database()->getUsername() ?? $this->tenant->getTenantKey();
        $password = $this->tenant->database()->getPassword() ?? 'password';

        // Create the user only if it doesn't already exist
        if (! count(DB::select("SELECT usename FROM pg_user WHERE usename = '$name';")) > 0) {
            DB::transaction(function () use ($name, $password) {
                DB::statement("CREATE USER \"$name\" LOGIN PASSWORD '$password';");
            });
        }

        $this->grantPermissions((string) $name);
    }

    protected function grantPermissions(string $userName): void
    {
        /**
         * @var \Stancl\Tenancy\Database\Contracts\StatefulTenantDatabaseManager $databaseManager
         */
        $databaseManager = $this->tenant->database()->manager();

        /**
         * @var Model[] $rlsModels
         */
        $rlsModels = array_map(fn (string $modelName) => (new $modelName), config('tenancy.models.rls'));

        foreach ($rlsModels as $model) {
            $table = $model->getTable();

            $databaseManager->database()->transaction(function () use ($databaseManager, $table, $userName) {
                $databaseManager->database()->statement("GRANT ALL ON {$table} TO \"{$userName}\"");
                $databaseManager->database()->statement("GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO \"{$userName}\"");
            });
        }
    }
}
